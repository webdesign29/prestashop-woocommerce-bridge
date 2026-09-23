<?php
/** GPL-2.0-or-later. */
if (!defined('_PS_VERSION_')) { exit; }
if (!defined('WD29_WOOBRIDGE_VERSION')) { define('WD29_WOOBRIDGE_VERSION', '0.4.0'); }
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Licence.php';
require_once __DIR__ . '/includes/LicenceAdmin.php';
require_once __DIR__ . '/includes/ModuleUpdater.php';
require_once __DIR__ . '/includes/Engine.php';
require_once __DIR__ . '/includes/AdminDesign.php';
require_once __DIR__ . '/includes/OrderConflicts.php';
require_once __DIR__ . '/includes/CustomerAccounts.php';
require_once __DIR__ . '/includes/Refunds.php';
require_once __DIR__ . '/includes/Suppliers.php';
require_once __DIR__ . '/includes/Gallery.php';
require_once __DIR__ . '/includes/DiagnosticsAdmin.php';
require_once __DIR__ . '/includes/FieldMirrorAdmin.php';
require_once __DIR__ . '/includes/PrestaAdapter.php';

class Wd29woobridge extends Module
{
    private $bridgeEngine;
    private $pending = [];
    private $shutdownRegistered = false;

    public function __construct()
    {
        $this->name = 'wd29woobridge'; $this->tab = 'administration'; $this->version = '0.4.0';
        $this->author = 'Webdesign29'; $this->need_instance = 0; $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => '8.99.99'];
        parent::__construct();
        $this->displayName = 'WD29 WooCommerce Bridge';
        $this->description = 'Direct signed webhooks and catalog reconciliation with WooCommerce.';
    }
    public function bridge(): \WD29\Bridge\Engine
    {
        if (!$this->bridgeEngine) {
            $adapter = new \WD29\Bridge\PrestaAdapter();
            $this->bridgeEngine = new \WD29\Bridge\Engine($adapter); $adapter->engine = $this->bridgeEngine;
        }
        if (Configuration::get('WD29_BRIDGE_SCHEMA') !== '6') { $this->bridgeEngine->install(); Configuration::updateValue('WD29_BRIDGE_SCHEMA','6'); }
        // Hooks added after 0.3: stores updated in place never re-run install().
        if (Configuration::get('WD29_BRIDGE_HOOKS') !== '1' && $this->id) {
            $this->registerHook('displayBackOfficeHeader'); Configuration::updateValue('WD29_BRIDGE_HOOKS', '1');
        }
        return $this->bridgeEngine;
    }
    public function install()
    {
        if (Shop::isFeatureActive() || !extension_loaded('curl')) { $this->_errors[] = 'Le mode boutique unique et l\'extension PHP cURL sont nécessaires.'; return false; }
        if (!parent::install()) { return false; }
        $this->bridge()->install();
        $native = [];
        foreach (['PS_OS_PAYMENT' => 'processing','PS_OS_PREPARATION' => 'processing','PS_OS_SHIPPING' => 'processing',
            'PS_OS_DELIVERED' => 'completed','PS_OS_CANCELED' => 'cancelled','PS_OS_REFUND' => 'refunded',
            'PS_OS_ERROR' => 'failed','PS_OS_CHEQUE' => 'on-hold','PS_OS_BANKWIRE' => 'on-hold'] as $key => $value) {
            if (Configuration::get($key)) { $native[(string) Configuration::get($key)] = $value; }
        }
        $mirror = [];
        foreach (['pending','on-hold','processing','completed','cancelled','refunded','failed'] as $name) {
            $state = new OrderState();
            $state->name = []; foreach (Language::getLanguages(false) as $language) { $state->name[$language['id_lang']] = 'WooCommerce: ' . $name; }
            $state->color = '#596b82'; $state->module_name = $this->name;
            $state->logable = false; $state->invoice = false; $state->paid = false; $state->send_email = false;
            $state->delivery = false; $state->shipped = false; $state->unremovable = true;
            if (!$state->add()) { return false; } $mirror[$name] = (int) $state->id;
        }
        $all = $native; foreach ($mirror as $name => $id) { $all[(string) $id] = $name; }
        Configuration::updateValue('WD29_BRIDGE_CONFIG', json_encode(['mode' => 'disabled', 'peer' => '', 'secret' => '',
            'display_tax_rate' => '', 'display_basis' => 'gross', 'native_states' => $native, 'mirror_states' => $mirror, 'order_states' => $all]));
        foreach (['actionProductAdd','actionProductUpdate','actionObjectProductUpdateAfter','actionObjectCombinationAddAfter',
            'actionObjectCombinationUpdateAfter','actionUpdateQuantity','actionObjectOrderAddAfter','actionObjectOrderUpdateAfter','actionOrderStatusUpdate','actionOrderStatusPostUpdate'] as $hook) {
            if (!$this->registerHook($hook)) { return false; }
        }
        $this->registerHook('displayBackOfficeHeader'); Configuration::updateValue('WD29_BRIDGE_HOOKS', '1');
        return true;
    }
    /** Back-office banner while live mode is paused or the licence grace period runs. */
    public function hookDisplayBackOfficeHeader()
    {
        try {
            if (Tools::getValue('configure') === $this->name) { return ''; }
            $engine = $this->bridge();
            if (($engine->config()['mode'] ?? 'disabled') === 'disabled') { return ''; }
            $s = $engine->licence()->summary();
            if ($s['tone'] === 'ok') { return ''; }
            $link = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '#wd-licence';
            $html = '<div class="alert alert-' . ($s['live'] ? 'warning' : 'danger') . '" id="wd29-licence-alert" style="margin:16px 0"><strong>Inklura Sync : ' . $this->escape($s['label']) . '.</strong> ' . $this->escape($s['text']) . ' <a href="' . $this->escape($link) . '">Licence</a></div>';
            return '<script>document.addEventListener("DOMContentLoaded",function(){if(document.getElementById("wd29-licence-alert"))return;var t=document.querySelector("#main-div .content-div")||document.querySelector("#content");if(!t)return;var d=document.createElement("div");d.innerHTML=' . json_encode($html) . ';t.insertBefore(d.firstChild,t.firstChild);});</script>';
        } catch (Throwable $e) { return ''; }
    }
    public function uninstall() { return parent::uninstall(); } // Keep mappings, replay protection and order history.
    private function captureLater(string $kind, int $id): void
    {
        $engine = $this->bridge();
        if ($id < 1 || !$engine->enabled() || ($kind === 'product' && ($engine->catalogApplying || $engine->stockApplying)) || ($kind === 'order' && $engine->orderApplying)) { return; }
        $this->pending[$kind][$id] = $id;
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function () {
                foreach (['product','order'] as $kind) { foreach ($this->pending[$kind] ?? [] as $id) { $this->bridge()->capture($kind, $id); } }
            });
        }
    }
    public function hookActionProductAdd($p) { $this->captureLater('product', (int) ($p['id_product'] ?? ($p['product']->id ?? 0))); }
    public function hookActionProductUpdate($p) { $this->hookActionProductAdd($p); }
    public function hookActionObjectProductUpdateAfter($p) { $this->captureLater('product', (int) $p['object']->id); }
    public function hookActionObjectCombinationAddAfter($p) { $this->captureLater('product', (int) $p['object']->id_product); }
    public function hookActionObjectCombinationUpdateAfter($p) { $this->hookActionObjectCombinationAddAfter($p); }
    public function hookActionUpdateQuantity($p) { $this->captureLater('product', (int) $p['id_product']); }
    public function hookActionObjectOrderAddAfter($p) { $this->captureLater('order', (int) $p['object']->id); }
    public function hookActionObjectOrderUpdateAfter($p) { $this->hookActionObjectOrderAddAfter($p); }
    public function hookActionOrderStatusPostUpdate($p) { $this->captureLater('order', (int) $p['id_order']); }
    public function hookActionOrderStatusUpdate($p)
    {
        if (!$this->bridge()->enabled()) { return; }
        $rows = $this->bridge()->sql("SELECT record_key FROM {b}map WHERE kind='order' AND local_id=?", [(int)$p['id_order']]);
        if ($rows && strpos($rows[0]['record_key'], 'woo:') === 0) {
            $allowed = array_values($this->bridge()->config()['mirror_states'] ?? []);
            if (!in_array((int)$p['newOrderStatus']->id, $allowed, true)) { throw new PrestaShopException('Choisissez un statut miroir WooCommerce : paiements, factures et remboursements se font sur la boutique d\'origine.'); }
        }
    }
    private function escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    public function getContent()
    {
        $engine = $this->bridge(); $message = '';
        if (Tools::isSubmit('bridge_action')) {
            if (!hash_equals(Tools::getAdminTokenLite('AdminModules'), (string) Tools::getValue('wd29_token'))) { return $this->displayError('Formulaire expiré : rechargez la page.'); }
            try {
                $action = (string) Tools::getValue('bridge_action');
                $licenceMessage = \WD29\Bridge\LicenceAdmin::handle($engine, $action, (string) Tools::getValue('licence_key'));
                if ($licenceMessage !== null) { $message = $licenceMessage; }
                elseif ($action === 'licence_update') {
                    $version = \WD29\Bridge\ModuleUpdater::run($engine, __DIR__, $this->version);
                    Module::upgradeModuleVersion($this->name, $version);
                    if (method_exists('Tools', 'clearSf2Cache')) { Tools::clearSf2Cache(); }
                    $message = 'Module mis à jour en version ' . $version . '. Réglages, correspondances et historique sont conservés.';
                }
                elseif ($action === 'save') {
                    $config = $engine->config(); $mode = (string) Tools::getValue('mode');
                    if (!in_array($mode, ['disabled','audit','live'], true)) { throw new RuntimeException('Mode invalide.'); }
                    $peer = trim((string) Tools::getValue('peer')); if ($peer !== '') { \WD29\Bridge\Protocol::publicEndpoint($peer); }
                    $secret = trim((string) Tools::getValue('secret'));
                    if ($secret !== '' && strlen($secret) < 32) { throw new RuntimeException('Le secret partagé doit compter au moins 32 caractères.'); }
                    $engine->validateSettings($mode, $peer, $secret !== '' ? $secret : ($config['secret'] ?? ''));
                    $rate = (string) Tools::getValue('display_tax_rate');
                    if ($rate !== '' && (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100)) { throw new RuntimeException('Taux de TVA invalide.'); }
                    $config['mode'] = $mode; $config['peer'] = $peer; if ($secret !== '') { $config['secret'] = $secret; }
                    $policy = (string) Tools::getValue('conflict_policy');
                    if (!in_array($policy, ['review','woo','ps'], true)) { throw new RuntimeException('Règle de conflit invalide.'); } $config['conflict_policy'] = $policy;
                    $config['display_tax_rate'] = $rate; $config['display_basis'] = Tools::getValue('display_basis') === 'net' ? 'net' : 'gross';
                    $rules = json_decode((string)Tools::getValue('tax_rules', '{}'), true);
                    if (!is_array($rules)) { throw new RuntimeException('La correspondance des taxes doit être un objet JSON, par exemple {"20":1}.'); }
                    foreach ($rules as $taxRate=>$id) { if (!is_numeric($taxRate) || !is_numeric($id) || (int)$id<1) { throw new RuntimeException('Correspondance de taxe invalide.'); } }
                    $config['tax_rules']=$rules; $config['native_customers']=(bool)Tools::getValue('native_customers',false); $config['sync_gallery_removals']=(bool)Tools::getValue('sync_gallery_removals',false);
                    Configuration::updateValue('WD29_BRIDGE_CONFIG', json_encode($config)); $message = 'Réglages enregistrés.';
                } elseif ($action === 'restore_gallery') {
                    $engine->adapter->restoreGallery(trim((string)Tools::getValue('gallery_record',''))); $message='Images de galerie rattachées de nouveau ; produit capturé.';
                } elseif ($action === 'save_mirror_fields') {
                    \WD29\Bridge\FieldMirrorAdmin::save($engine,(string)Tools::getValue('field_record'),(array)Tools::getValue('field_values',[]),(string)Tools::getValue('field_base'));
                    $message='Champs personnalisés enregistrés et capturés.';
                } elseif ($action === 'health') { $message = json_encode($engine->peer(['op' => 'health'])); }
                elseif ($action === 'tick') { $engine->tick(false); $message = 'File traitée : consultez le résultat ci-dessous.'; }
                elseif ($action === 'retry') { $engine->retry(); $message = 'Événements en échec remis en file.'; }
                elseif ($action === 'resolve_order_upgrades') { $message = 'Mises à jour de commandes équivalentes remises en file : ' . $engine->retryEquivalentOrderConflicts(); }
                elseif ($action === 'resolve_catalog') { $engine->retryCatalogConflicts(); $message = 'Conflits de catalogue remis en file avec la priorité choisie.'; }
                elseif ($action === 'seed_customers') { $message = 'Contacts capturés : ' . $engine->seed('customer', max(0, (int) Tools::getValue('offset'))); }
                elseif ($action === 'seed') { $message = 'Produits capturés : ' . $engine->seed('product', max(0, (int) Tools::getValue('offset'))); }
            } catch (Throwable $error) { $message = $error->getMessage(); }
        }
        $config = $engine->config();
        $html = '<div class="panel"><h2>WooCommerce Bridge</h2><p>En mode audit, les modifications reçues sont mises en file sans toucher au catalogue, aux stocks ni aux commandes.</p>';
        if ($message) { $html .= '<p class="alert alert-info">' . $this->escape($message) . '</p>'; }
        $html .= '<p>Webhook de cette boutique : <code>' . $this->escape($this->context->link->getModuleLink($this->name, 'webhook', [], true)) . '</code></p>';
        $html .= '<form method="post"><input type="hidden" name="wd29_token" value="' . $this->escape(Tools::getAdminTokenLite('AdminModules')) . '"><label>Mode</label><select name="mode">';
        foreach (['disabled' => 'Arrêtée', 'audit' => 'Audit : réception sans écriture', 'live' => 'Synchronisation live'] as $mode => $label) { $html .= '<option value="' . $mode . '"' . (($config['mode'] ?? '') === $mode ? ' selected' : '') . '>' . $label . '</option>'; }
        $html .= '</select><label>Webhook WooCommerce</label><input type="url" name="peer" value="' . $this->escape($config['peer'] ?? '') . '">';
        $html .= '<label>Modifications simultanées du catalogue (même règle sur les deux boutiques)</label><select name="conflict_policy">';
        foreach (['review'=>'Mettre en pause pour examen','woo'=>'Priorité à WooCommerce','ps'=>'Priorité à PrestaShop'] as $value=>$label) { $html .= '<option value="'.$value.'"'.(($config['conflict_policy'] ?? 'review')===$value?' selected':'').'>'.$label.'</option>'; }
        $html .= '</select>';
        $html .= '<label>Secret partagé (laisser vide pour conserver l\'actuel)</label><input type="password" name="secret" autocomplete="new-password" value="">';
        $html .= '<label>Taux de TVA des prix reçus sans information fiscale (%) : laissez vide tant qu\'il n\'est pas confirmé</label><input name="display_tax_rate" value="' . $this->escape($config['display_tax_rate'] ?? '') . '">';
        $html .= '<label>Ces prix sont saisis</label><select name="display_basis"><option value="gross"' . (($config['display_basis'] ?? 'gross') === 'gross' ? ' selected' : '') . '>TTC</option><option value="net"' . (($config['display_basis'] ?? '') === 'net' ? ' selected' : '') . '>HT</option></select>';
        $html .= '<label>Taux de TVA → identifiant du groupe de règles de taxes, en JSON (exemple : {&quot;20&quot;:1})</label><input name="tax_rules" value="'.$this->escape(json_encode($config['tax_rules'] ?? new stdClass())).'">';
        $html .= '<label><input type="checkbox" name="native_customers" value="1"'.(!empty($config['native_customers'])?' checked':'').'> Créer des comptes clients natifs pour les clients inscrits de l\'autre boutique (mots de passe indépendants, aucune fusion par e-mail)</label>';
        $html .= '<label><input type="checkbox" name="sync_gallery_removals" value="1"'.(!empty($config['sync_gallery_removals'])?' checked':'').'> Détacher les images importées retirées chez le partenaire (réversible ; fichiers et images ajoutées à la main conservés)</label>';
        $html .= '<button class="btn btn-primary" name="bridge_action" value="save">Enregistrer les réglages</button></form><hr>';
        $html .= '<form method="post"><input type="hidden" name="wd29_token" value="' . $this->escape(Tools::getAdminTokenLite('AdminModules')) . '"><label>Offset du lot (10 fiches par lot)</label><input name="offset" type="number" min="0" value="0">';
        $html .= '<label>Identité du produit ou de la déclinaison</label><input name="gallery_record" placeholder="woo:product:123"><button class="btn btn-default" name="bridge_action" value="restore_gallery">Rattacher les images détachées</button><p>Rattache les images conservées du produit (et, pour une déclinaison, ses associations d\'images), puis capture le produit.</p>';
        foreach (['health' => 'Tester la connexion','seed' => 'Capturer le catalogue', 'seed_customers'=>'Capturer les contacts clients','tick' => 'Traiter la file','retry' => 'Relancer les échecs','resolve_order_upgrades'=>'Relancer les mises à jour de commandes équivalentes', 'resolve_catalog'=>'Relancer les conflits de catalogue'] as $action => $label) { $html .= '<button class="btn btn-default" name="bridge_action" value="' . $action . '">' . $label . '</button> '; }
        $html .= '</form>'.\WD29\Bridge\DiagnosticsAdmin::render($engine).'<p>Dernier message enregistré (l\'état actuel est dans les diagnostics) : ' . $this->escape(Configuration::get('WD29_BRIDGE_NOTICE')) . '</p><h3>Journal des événements</h3><table class="table"><thead><tr>';
        foreach (['N°','Sens','Type','Identité','État','Essais','Erreur','Créé le'] as $heading) { $html .= '<th>' . $heading . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->report() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>' . $this->escape($value) . '</td>'; } $html .= '</tr>'; }
        $html .= '</tbody></table><h3>Catalogue</h3><p>Derniers instantanés transmis ; un stock inconnu n\'est pas un stock nul. Jusqu\'à 200 produits.</p><table class="table"><thead><tr>';
        foreach (['Origine','ID local','Nom','Marques','Étiquettes','Type','Prix','Promo','Taxe','Base','Stock initial','Identifiants','Dimensions (cm)','Caractéristiques','Images des déclinaisons','Archivé','Achat HT','Fournisseur','SEO'] as $heading) { $html .= '<th>' . $heading . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->catalogAudit() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>' . $this->escape($value) . '</td>'; } $html .= '</tr>'; }
        $html .= '</tbody></table><h3>Commandes</h3><p>Les lignes historiques sans lien gardent leurs détails d\'origine ; le lien au catalogue se fait dès que le produit existe.</p><table class="table"><thead><tr>';
        foreach (['Origine','ID local','Total','Devise','Statut','Lignes','Lignes sans lien'] as $heading) { $html .= '<th>'.$heading.'</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->orderReport() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>'.$this->escape($value).'</td>'; } $html .= '</tr>'; }
        $html .= '</tbody></table><h3>Contacts clients</h3><p>Copies en lecture seule, à modifier sur leur boutique d\'origine. Les comptes natifs sont facultatifs ; mots de passe et consentements marketing ne sont jamais copiés, et l\'e-mail ne sert jamais à fusionner deux fiches. Jusqu\'à 200 contacts.</p><table class="table"><thead><tr>';
        foreach (['Origine','Nom','E-mail','Téléphone','Société','Facturation','Adresses','Livraison','Type'] as $heading) { $html .= '<th>'.$heading.'</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->customerReport() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>'.$this->escape($value).'</td>'; } $html .= '</tr>'; }
        $html.='</tbody></table><h3>Modifier les champs personnalisés</h3><p>Chargez l\'identité d\'un produit, d\'une déclinaison ou d\'une commande. Seuls les champs déjà reçus de WooCommerce se modifient ; les valeurs sont en JSON pour garder leur type.</p><form method="post"><input type="hidden" name="wd29_token" value="'.$this->escape(Tools::getAdminTokenLite('AdminModules')).'"><label>Identité de la fiche</label><input name="field_record" value="'.$this->escape(Tools::getValue('field_record','')).'"><button name="bridge_action" value="load_mirror_fields" class="btn btn-default">Charger les champs</button>';
        if (in_array((string)Tools::getValue('bridge_action'),['load_mirror_fields','save_mirror_fields'],true)) {
            try {
                $fields=\WD29\Bridge\FieldMirrorAdmin::read($engine,(string)Tools::getValue('field_record'));
                $html.='<input type="hidden" name="field_base" value="'.$this->escape(\WD29\Bridge\Protocol::fingerprint($fields)).'">';
                foreach ($fields as $id=>$entry) { $html.='<label>'.$this->escape($id).'</label><label><input type="checkbox" name="field_values['.$this->escape($id).'][present]" value="1"'.(!empty($entry['present'])?' checked':'').'> Présent</label><textarea name="field_values['.$this->escape($id).'][json]">'.$this->escape(json_encode($entry['value'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</textarea>'; }
                if ($fields) { $html.='<button class="btn btn-primary" name="bridge_action" value="save_mirror_fields">Enregistrer les champs</button>'; } else { $html.='<p>Aucun champ personnalisé synchronisé pour cette fiche.</p>'; }
            } catch (Throwable $error) { $html.='<p>'.$this->escape($error->getMessage()).'</p>'; }
        }
        $token = '<input type="hidden" name="wd29_token" value="' . $this->escape(Tools::getAdminTokenLite('AdminModules')) . '">';
        $update = $engine->licence()->updateAvailable();
        $updateHtml = $update && $engine->licence()->allowsLive() && ($engine->licence()->state()['status'] ?? '') !== ''
            ? '<form method="post">' . $token . '<p>Version ' . $this->escape($update) . ' disponible (installée : ' . $this->escape($this->version) . '). Archive vérifiée par SHA-256 avant remplacement ; réglages et historique conservés.</p><button class="btn btn-primary" name="bridge_action" value="licence_update">Mettre à jour le module</button></form>'
            : ($update ? '<p>Version ' . $this->escape($update) . ' disponible : téléchargez-la depuis plugins.inklura.fr/compte.</p>' : '');
        return \WD29\Bridge\AdminDesign::render($html.'</form>'.\WD29\Bridge\LicenceAdmin::render($engine, $token, $updateHtml).'</div>', $engine, 'ps');
    }
}
