<?php
namespace WD29\Bridge;

/** Native record pages: local rendering, explicit asynchronous comparison and sync. */
final class RecordPanelAdmin
{
    const CONTROLLER = 'AdminWd29RecordPanel';
    private const TABS = ['product' => 'AdminProducts', 'order' => 'AdminOrders', 'customer' => 'AdminCustomers'];

    public static function install($module): bool
    {
        foreach (['displayAdminProductsExtra', 'displayAdminOrderMainBottom', 'displayAdminCustomers'] as $hook) {
            if (!$module->registerHook($hook)) { return false; }
        }
        $id = (int)\Tab::getIdFromClassName(self::CONTROLLER);
        if ($id) { return (string)(new \Tab($id))->module === (string)$module->name; }
        $tab = new \Tab(); $tab->active = 1; $tab->class_name = self::CONTROLLER;
        $tab->id_parent = -1; $tab->module = $module->name;
        foreach (\Language::getLanguages(false) as $language) { $tab->name[(int)$language['id_lang']] = 'Inklura Sync · fiches'; }
        return (bool)$tab->add();
    }

    public static function canRead($context, string $kind): bool
    {
        return isset(self::TABS[$kind]) && self::employeeCan($context, self::TABS[$kind]);
    }

    /** Connector pages are module configuration: native module rights are required. */
    public static function canConfigure($context): bool { return self::employeeCan($context, 'AdminModules'); }

    /** Token-free link for e-mails and notices: resolved after login into the current employee's tokenized module page. */
    public static function moduleUrl($module, array $input): string
    {
        $view = $input['view'] ?? 'overview';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !is_string($view) || !in_array($view, ['overview','sync','orders','activity','reports','settings','licence'], true)
            || !$module || !$module->active || !self::canConfigure(\Context::getContext())) { throw new \RuntimeException('Navigation refusée.'); }
        return (string)\Context::getContext()->link->getAdminLink('AdminModules', true, [], ['configure' => $module->name, 'wd_view' => $view]);
    }

    private static function employeeCan($context, string $tabClass): bool
    {
        $employee = $context->employee ?? null; $shop = $context->shop ?? null;
        if (!$employee || !\Validate::isLoadedObject($employee) || !$employee->active || !$employee->isLoggedBack()
            || !$shop || !\Validate::isLoadedObject($shop) || (int)$shop->id < 1
            || \Shop::isFeatureActive() || \Shop::getContext() !== \Shop::CONTEXT_SHOP
            || !$employee->hasAuthOnShop((int)$shop->id)) { return false; }
        $tab = (int)\Tab::getIdFromClassName($tabClass);
        if (!$tab) { return false; }
        $access = \Profile::getProfileAccess((int)$employee->id_profile, $tab);
        return !empty($access['view']) && !empty($access['edit']);
    }

    public static function guard($context, string $kind, int $id, int $shop): void
    {
        if (!self::canRead($context, $kind) || $shop !== (int)$context->shop->id || $id < 1) { throw new \RuntimeException('Accès refusé.'); }
        $table = $kind === 'product' ? 'product_shop' : ($kind === 'order' ? 'orders' : 'customer');
        $field = 'id_'.$kind;
        if (!\Db::getInstance()->getValue('SELECT '.$field.' FROM `'._DB_PREFIX_.$table.'` WHERE '.$field.'='.(int)$id.' AND id_shop='.(int)$shop.($kind === 'customer' ? ' AND deleted=0' : ''))) {
            throw new \RuntimeException('Fiche inaccessible dans cette boutique.');
        }
    }

    public static function token($context, string $kind, int $id, int $shop, ?string $submitted = null)
    {
        $name = 'wd29-record-panel:'.(int)$context->employee->id.':'.$shop.':'.$kind.':'.$id;
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            $manager = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
            return $submitted === null ? $manager->getToken($name)->getValue() : $manager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken($name, $submitted));
        }
        $token = (string)\Tools::getAdminToken($name);
        return $submitted === null ? $token : ($submitted !== '' && hash_equals($token, $submitted));
    }

    /** Save only the native back-office base URL, never an employee token. */
    public static function rememberAdminBase($context): void
    {
        if (!defined('_PS_ADMIN_DIR_')) { return; }
        $allowed = false;
        foreach (array_keys(self::TABS) as $kind) { if (self::canRead($context, $kind)) { $allowed = true; break; } }
        if (!$allowed) { return; }
        $url = (string)$context->link->getAdminLink(self::CONTROLLER, true);
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) { return; }
        $base = 'https://'.$parts['host'].($parts['path'] ?? '');
        if ((string)\Configuration::get('WD29_BRIDGE_ADMIN_BASE') !== $base) { \Configuration::updateValue('WD29_BRIDGE_ADMIN_BASE', $base); }
    }

    /** Token-free GET is navigation only; native target token belongs to this employee. */
    public static function openUrl($module, array $input): string
    {
        $kind = $input['kind'] ?? null; $key = $input['key'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !is_string($kind) || !is_string($key) || !isset(self::TABS[$kind]) || strlen($key) > 96
            || !$module || !$module->active || !self::canRead(\Context::getContext(), $kind)) { throw new \RuntimeException('Navigation refusée.'); }
        $id = RecordPanel::nativeId($module->bridge(), $kind, $key);
        $context = \Context::getContext(); self::guard($context, $kind, $id, (int)$context->shop->id);
        $action = ['product' => 'updateproduct', 'order' => 'vieworder', 'customer' => 'viewcustomer'][$kind];
        return (string)$context->link->getAdminLink(self::TABS[$kind], true, [], ['id_'.$kind => $id, $action => 1]);
    }

    public static function dispatch($module, array $input): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($input['kind'] ?? null)
            || !is_string($input['record_token'] ?? null) || !is_scalar($input['id'] ?? null) || !is_scalar($input['shop'] ?? null)
            || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$input['id']) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$input['shop'])
            || !in_array($input['operation'] ?? null, ['compare', 'sync'], true)) { throw new \RuntimeException('Requête invalide.'); }
        $context = \Context::getContext(); $kind = $input['kind']; $id = (int)$input['id']; $shop = (int)$input['shop'];
        self::guard($context, $kind, $id, $shop);
        if (!self::token($context, $kind, $id, $shop, $input['record_token']) || !$module || !$module->active) { throw new \RuntimeException('Formulaire expiré ou module indisponible.'); }
        if ($input['operation'] === 'compare') { return RecordPanel::compare($module->bridge(), $kind, $id); }
        foreach (['key', 'direction', 'hash', 'destination'] as $name) {
            if (!isset($input[$name]) || !is_string($input[$name]) || strlen($input[$name]) > 256) { throw new \RuntimeException('Comparaison requise.'); }
        }
        if (!in_array($input['confirm'] ?? null, ['1', 1, true], true)) { throw new \RuntimeException('Confirmation requise.'); }
        return RecordPanel::sync($module->bridge(), $kind, $id, array_intersect_key($input, array_flip(['key', 'direction', 'hash', 'destination', 'confirm'])));
    }

    private static function escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

    public static function render($module, string $kind, int $id): string
    {
        $context = \Context::getContext(); $shop = (int)($context->shop->id ?? 0);
        try {
            self::guard($context, $kind, $id, $shop);
            $local = RecordPanel::local($module->bridge(), $kind, $id);
            $url = $context->link->getAdminLink(self::CONTROLLER, true, [], ['ajax' => 1]);
            $token = self::token($context, $kind, $id, $shop);
        } catch (\Throwable $error) { return ''; }
        $noun = ['product' => 'produit et déclinaisons', 'order' => 'commande', 'customer' => 'client'][$kind];
        $html = '<section class="card" data-wd-record-panel data-endpoint="'.self::escape($url).'" data-kind="'.$kind.'" data-id="'.$id.'" data-shop="'.$shop.'" data-token="'.self::escape($token).'">';
        $html .= '<div class="card-header"><h3 class="card-header-title">Inklura Sync · '.self::escape($noun).'</h3></div><div class="card-body">';
        $html .= '<p>Cette fiche : <strong>PrestaShop</strong> · boutique partenaire : <strong>WooCommerce</strong></p>';
        if (!empty($local['key'])) { $html .= '<p>'.(!empty($local['origin']) ? 'Original PrestaShop' : 'Copie importée de WooCommerce').' · <code>'.self::escape($local['key']).'</code></p>'; }
        $html .= '<p class="text-muted">Enregistrez vos modifications avant de comparer. La synchronisation utilise les données enregistrées';
        $html .= $kind === 'product' ? ' du produit et de toutes ses déclinaisons. Elle ne force pas les quantités de stock.</p>' : ' de cette fiche.</p>';
        $html .= '<p data-wd-record-status role="status">Comparez cette fiche avec la boutique partenaire pour vérifier les différences.</p>';
        $html .= '<div data-wd-record-details hidden></div><button class="btn btn-outline-primary" data-wd-record-compare type="button">Comparer avec WooCommerce</button> ';
        $html .= '<button class="btn btn-primary" data-wd-record-sync type="button" hidden>Synchroniser</button>';
        $html .= '<noscript><p>Activez JavaScript pour comparer et synchroniser cette fiche.</p></noscript></div></section>';
        $html .= '<script src="'.self::escape($module->getPathUri().'includes/record-panel-admin.js?v='.substr(hash_file('sha256', __DIR__.'/record-panel-admin.js'), 0, 12)).'" defer></script>';
        return $html;
    }
}
