<?php
/** GPL-2.0-or-later. */
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Engine.php';
require_once __DIR__ . '/includes/PrestaAdapter.php';

class Wd29woobridge extends Module
{
    private $bridgeEngine;
    private $pending = [];
    private $shutdownRegistered = false;

    public function __construct()
    {
        $this->name = 'wd29woobridge'; $this->tab = 'administration'; $this->version = '0.1.4';
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
        if (Configuration::get('WD29_BRIDGE_SCHEMA') !== '4') { $this->bridgeEngine->install(); Configuration::updateValue('WD29_BRIDGE_SCHEMA','4'); }
        return $this->bridgeEngine;
    }
    public function install()
    {
        if (Shop::isFeatureActive() || !extension_loaded('curl')) { $this->_errors[] = 'Single-shop mode and PHP cURL are required.'; return false; }
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
        return true;
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
            if (!in_array((int)$p['newOrderStatus']->id, $allowed, true)) { throw new PrestaShopException('Use a WooCommerce mirror status. Payments, invoices and financial refunds belong on the originating store.'); }
        }
    }
    private function escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    public function getContent()
    {
        $engine = $this->bridge(); $message = '';
        if (Tools::isSubmit('bridge_action')) {
            if (!hash_equals(Tools::getAdminTokenLite('AdminModules'), (string) Tools::getValue('wd29_token'))) { return $this->displayError('Invalid form token.'); }
            try {
                $action = (string) Tools::getValue('bridge_action');
                if ($action === 'save') {
                    $config = $engine->config(); $mode = (string) Tools::getValue('mode');
                    if (!in_array($mode, ['disabled','audit','live'], true)) { throw new RuntimeException('Invalid mode.'); }
                    $peer = trim((string) Tools::getValue('peer')); if ($peer !== '') { \WD29\Bridge\Protocol::publicEndpoint($peer); }
                    $secret = trim((string) Tools::getValue('secret'));
                    if ($secret !== '' && strlen($secret) < 32) { throw new RuntimeException('Secret must contain at least 32 characters.'); }
                    $engine->validateSettings($mode, $peer, $secret !== '' ? $secret : ($config['secret'] ?? ''));
                    $rate = (string) Tools::getValue('display_tax_rate');
                    if ($rate !== '' && (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100)) { throw new RuntimeException('Invalid tax rate.'); }
                    $config['mode'] = $mode; $config['peer'] = $peer; if ($secret !== '') { $config['secret'] = $secret; }
                    $policy = (string) Tools::getValue('conflict_policy');
                    if (!in_array($policy, ['review','woo','ps'], true)) { throw new RuntimeException('Invalid conflict policy.'); } $config['conflict_policy'] = $policy;
                    $config['display_tax_rate'] = $rate; $config['display_basis'] = Tools::getValue('display_basis') === 'net' ? 'net' : 'gross';
                    $rules = json_decode((string)Tools::getValue('tax_rules', '{}'), true);
                    if (!is_array($rules)) { throw new RuntimeException('Tax mappings must be a JSON object.'); }
                    foreach ($rules as $taxRate=>$id) { if (!is_numeric($taxRate) || !is_numeric($id) || (int)$id<1) { throw new RuntimeException('Invalid tax-rule mapping.'); } }
                    $config['tax_rules']=$rules;
                    Configuration::updateValue('WD29_BRIDGE_CONFIG', json_encode($config)); $message = 'Settings saved.';
                } elseif ($action === 'health') { $message = json_encode($engine->peer(['op' => 'health'])); }
                elseif ($action === 'tick') { $engine->tick(false); $message = 'Queue processed; inspect results below.'; }
                elseif ($action === 'retry') { $engine->retry(); $message = 'Failed events queued again.'; }
                elseif ($action === 'resolve_catalog') { $engine->retryCatalogConflicts(); $message = 'Catalog conflicts queued with the selected priority.'; }
                elseif ($action === 'seed_customers') { $message = 'Contacts captured: ' . $engine->seed('customer', max(0, (int) Tools::getValue('offset'))); }
                elseif ($action === 'seed') { $message = 'Products captured: ' . $engine->seed('product', max(0, (int) Tools::getValue('offset'))); }
            } catch (Throwable $error) { $message = $error->getMessage(); }
        }
        $config = $engine->config();
        $html = '<div class="panel"><h2>WooCommerce Bridge</h2><p>Audit mode queues incoming changes without writing catalog, stock or orders.</p>';
        if ($message) { $html .= '<p class="alert alert-info">' . $this->escape($message) . '</p>'; }
        $html .= '<p>Local webhook: <code>' . $this->escape($this->context->link->getModuleLink($this->name, 'webhook', [], true)) . '</code></p>';
        $html .= '<form method="post"><input type="hidden" name="wd29_token" value="' . $this->escape(Tools::getAdminTokenLite('AdminModules')) . '"><label>Mode</label><select name="mode">';
        foreach (['disabled','audit','live'] as $mode) { $html .= '<option value="' . $mode . '"' . (($config['mode'] ?? '') === $mode ? ' selected' : '') . '>' . $mode . '</option>'; }
        $html .= '</select><label>WooCommerce webhook</label><input type="url" name="peer" value="' . $this->escape($config['peer'] ?? '') . '">';
        $html .= '<label>Simultaneous catalog edits — use the same policy on both stores</label><select name="conflict_policy">';
        foreach (['review'=>'Pause for review','woo'=>'Prefer WooCommerce','ps'=>'Prefer PrestaShop'] as $value=>$label) { $html .= '<option value="'.$value.'"'.(($config['conflict_policy'] ?? 'review')===$value?' selected':'').'>'.$label.'</option>'; }
        $html .= '</select>';
        $html .= '<label>Shared secret (blank keeps the current secret)</label><input type="password" name="secret" autocomplete="new-password" value="">';
        $html .= '<label>Tax rate for source prices without tax information (%) — leave blank until confirmed</label><input name="display_tax_rate" value="' . $this->escape($config['display_tax_rate'] ?? '') . '">';
        $html .= '<label>Those source prices are</label><select name="display_basis"><option value="gross"' . (($config['display_basis'] ?? 'gross') === 'gross' ? ' selected' : '') . '>Tax inclusive</option><option value="net"' . (($config['display_basis'] ?? '') === 'net' ? ' selected' : '') . '>Tax exclusive</option></select>';
        $html .= '<label>Tax rate → tax rules group ID, JSON (example: {&quot;20&quot;:1})</label><input name="tax_rules" value="'.$this->escape(json_encode($config['tax_rules'] ?? new stdClass())).'">';
        $html .= '<button class="btn btn-primary" name="bridge_action" value="save">Save settings</button></form><hr>';
        $html .= '<form method="post"><input type="hidden" name="wd29_token" value="' . $this->escape(Tools::getAdminTokenLite('AdminModules')) . '"><label>Batch offset</label><input name="offset" type="number" min="0" value="0">';
        foreach (['health' => 'Test connection','seed' => 'Capture catalog', 'seed_customers'=>'Capture customer contacts','tick' => 'Process queue','retry' => 'Retry failures','resolve_catalog'=>'Retry catalog conflicts'] as $action => $label) { $html .= '<button class="btn btn-default" name="bridge_action" value="' . $action . '">' . $label . '</button> '; }
        $html .= '</form><p>' . $this->escape(Configuration::get('WD29_BRIDGE_NOTICE')) . '</p><h3>Latest events</h3><table class="table"><thead><tr>';
        foreach (['seq','direction','kind','record_key','state','attempts','error','created_at'] as $heading) { $html .= '<th>' . $heading . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->report() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>' . $this->escape($value) . '</td>'; } $html .= '</tr>'; }
        $html .= '</tbody></table><h3>Catalog audit</h3><p>Latest transmitted snapshots; unknown stock is not zero. Up to 200 products.</p><table class="table"><thead><tr>';
        foreach (['source','local_id','name','brands','tags','type','regular','sale','tax','basis','initial_stock_snapshot'] as $heading) { $html .= '<th>' . $heading . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->catalogAudit() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>' . $this->escape($value) . '</td>'; } $html .= '</tr>'; }
        $html .= '</tbody></table><h3>Order reconciliation</h3><p>Unlinked historical lines retain source details and receive catalog links once products are available.</p><table class="table"><thead><tr>';
        foreach (['source','local_id','total','currency','status','lines','unlinked_lines'] as $heading) { $html .= '<th>'.$heading.'</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->orderReport() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>'.$this->escape($value).'</td>'; } $html .= '</tr>'; }
        $html .= '</tbody></table><h3>Customer contact directory</h3><p>Read-only contact copies edited on their source store. No login accounts, passwords or marketing consents copied. No automatic identity merge by email. Up to 200 contacts.</p><table class="table"><thead><tr>';
        foreach (['source','name','email','phone','company','billing','type'] as $heading) { $html .= '<th>'.$heading.'</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($engine->customerReport() as $row) { $html .= '<tr>'; foreach ($row as $value) { $html .= '<td>'.$this->escape($value).'</td>'; } $html .= '</tr>'; }
        return $html . '</tbody></table></div>';
    }
}
