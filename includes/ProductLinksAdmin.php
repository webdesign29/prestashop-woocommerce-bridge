<?php
namespace WD29\Bridge;

/** Product-editor presentation and authorization. Remote I/O is AJAX-only. */
final class ProductLinksAdmin
{
    const CONTROLLER = 'AdminWd29ProductLinks';

    public static function install($module): bool
    {
        if (!$module->registerHook('displayAdminProductsExtra')) { return false; }
        $id = (int)\Tab::getIdFromClassName(self::CONTROLLER);
        if ($id) {
            $tab = new \Tab($id);
            return (string)$tab->module === (string)$module->name;
        }
        $tab = new \Tab();
        $tab->active = 1; $tab->class_name = self::CONTROLLER;
        $tab->id_parent = -1; $tab->module = $module->name;
        foreach (\Language::getLanguages(false) as $language) {
            $tab->name[(int)$language['id_lang']] = 'Inklura Sync · lien produit';
        }
        return (bool)$tab->add();
    }

    public static function canRead($context): bool
    {
        $employee = $context->employee ?? null;
        $shop = $context->shop ?? null;
        if (!$employee || !\Validate::isLoadedObject($employee) || !$employee->active || !$employee->isLoggedBack()
            || !$shop || !\Validate::isLoadedObject($shop) || (int)$shop->id < 1
            || \Shop::isFeatureActive() || \Shop::getContext() !== \Shop::CONTEXT_SHOP
            || !$employee->hasAuthOnShop((int)$shop->id)) { return false; }
        $tab = (int)\Tab::getIdFromClassName('AdminProducts');
        if (!$tab) { return false; }
        $access = \Profile::getProfileAccess((int)$employee->id_profile, $tab);
        return !empty($access['view']) && !empty($access['edit']);
    }

    public static function guard($context, int $id, int $shop): void
    {
        if (!self::canRead($context) || $shop !== (int)$context->shop->id || $id < 1) {
            throw new \RuntimeException('Accès au produit refusé.');
        }
        $found = \Db::getInstance()->getValue('SELECT id_product FROM `'._DB_PREFIX_.'product_shop` WHERE id_product='.(int)$id.' AND id_shop='.(int)$shop);
        if (!$found) { throw new \RuntimeException('Produit inaccessible dans cette boutique.'); }
    }

    /** Bound to the employee, product and shop, including when URL tokens are disabled. */
    public static function token($context, int $id, int $shop, ?string $submitted = null)
    {
        $name = 'wd29-product-link:'.(int)$context->employee->id.':'.$shop.':'.$id;
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            $manager = $container->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
            return $submitted === null ? $manager->getToken($name)->getValue()
                : $manager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken($name, $submitted));
        }
        $token = (string)\Tools::getAdminToken($name);
        return $submitted === null ? $token : ($submitted !== '' && hash_equals($token, $submitted));
    }

    public static function lookup($module, array $input): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($input['id_product'], $input['shop'], $input['wd29_product_token'])
            || !is_scalar($input['id_product']) || !is_scalar($input['shop']) || !is_string($input['wd29_product_token'])
            || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$input['id_product'])
            || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$input['shop'])) {
            throw new \RuntimeException('Requête invalide.');
        }
        $context = \Context::getContext(); $id = (int)$input['id_product']; $shop = (int)$input['shop'];
        self::guard($context, $id, $shop);
        if (!self::token($context, $id, $shop, $input['wd29_product_token'])) {
            throw new \RuntimeException('Formulaire expiré : rechargez la fiche produit.');
        }
        if (!$module || !$module->active) { throw new \RuntimeException('Connecteur indisponible.'); }
        return ProductLinks::remote($module->bridge(), $id);
    }

    private static function escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

    public static function render($module, int $id): string
    {
        $context = \Context::getContext(); $shop = (int)($context->shop->id ?? 0);
        try { self::guard($context, $id, $shop); } catch (\Throwable $error) { return ''; }
        try {
            $local = ProductLinks::local($module->bridge(), $id);
            $url = $context->link->getAdminLink(self::CONTROLLER, true, [], ['ajax' => 1, 'action' => 'lookup']);
            $token = self::token($context, $id, $shop);
        } catch (\Throwable $error) { return '<div class="alert alert-info">Inklura Sync : le lien partenaire est momentanément indisponible.</div>'; }
        $messages = [
            'ready' => 'Vérification du lien vers WooCommerce…',
            'unmapped' => 'Ce produit n’a pas encore de correspondance Inklura Sync.',
            'disconnected' => 'Aucune boutique WooCommerce connectée.',
            'missing' => 'Ce produit n’est plus disponible.',
            'unpublished' => 'Produit distant non publié : aucun lien public disponible.',
        ];
        $state = (string)($local['state'] ?? 'unmapped');
        $html = '<section class="card" data-wd-product-link data-endpoint="'.self::escape($url).'" data-product="'.$id.'" data-shop="'.$shop.'" data-token="'.self::escape($token).'" data-state="'.self::escape($state).'">';
        $html .= '<div class="card-header"><h3 class="card-header-title">Inklura Sync · produit WooCommerce</h3></div><div class="card-body">';
        if (!empty($local['peer_host'])) { $html .= '<p class="text-muted">Boutique partenaire : <span data-wd-peer>'.self::escape($local['peer_host']).'</span></p>'; }
        if (!empty($local['key'])) { $html .= '<p>'.(!empty($local['origin']) ? 'Original PrestaShop' : 'Copie importée de WooCommerce').' · correspondance : <code>'.self::escape($local['key']).'</code></p>'; }
        $html .= '<p data-wd-link-status role="status">'.self::escape($messages[$state] ?? 'Lien partenaire indisponible.').'</p>';
        $html .= '<a data-wd-remote-link class="btn btn-primary" target="_blank" rel="noopener noreferrer" hidden>Voir sur WooCommerce ↗</a> ';
        $html .= '<button data-wd-link-retry type="button" class="btn btn-outline-secondary" hidden>Réessayer</button>';
        $html .= '<noscript><p>Activez JavaScript pour vérifier le lien vers le produit WooCommerce.</p></noscript></div></section>';
        $html .= '<script src="'.self::escape($module->getPathUri().'includes/product-links-admin.js?v='.substr(hash_file('sha256', __DIR__.'/product-links-admin.js'), 0, 12)).'" defer></script>';
        return $html;
    }
}
