<?php
/** Local server worker. Intentionally unavailable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$usage="Usage: php worker-cli.php --root=/absolute/store/path --platform=woo|ps --command=tick|health\n";
$options=getopt('', ['root:', 'platform:', 'command:']);
$root=realpath($options['root']??''); $platform=$options['platform']??''; $command=$options['command']??'tick';
if (!$root || !isset($options['root']) || substr($options['root'],0,1)!=='/' || !in_array($platform,['woo','ps'],true) || !in_array($command,['tick','health'],true)) { fwrite(STDERR,$usage); exit(64); }
// Suppress browser-oriented output from third-party plugins during boot.
ob_start();
try {
    if ($platform==='woo') {
        if (!is_file($root.'/wp-load.php')) { throw new RuntimeException('WordPress root is invalid.'); }
        if (!defined('DOING_CRON')) { define('DOING_CRON',true); }
        require $root.'/wp-load.php';
        if (!function_exists('wd29_bridge') || !function_exists('wc_get_product')) { throw new RuntimeException('WooCommerce bridge must be active.'); }
        $engine=wd29_bridge();
    } else {
        if (!is_file($root.'/config/config.inc.php')) { throw new RuntimeException('PrestaShop root is invalid.'); }
        $_SERVER['REQUEST_URI']=$_SERVER['REQUEST_URI']??'/';
        require $root.'/config/config.inc.php';
        if (Shop::isFeatureActive()) { throw new RuntimeException('Single-shop mode is required.'); }
        $context=Context::getContext();
        $context->shop=new Shop((int)Configuration::get('PS_SHOP_DEFAULT'));
        $context->language=new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $context->currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
        $_SERVER['HTTP_HOST']=$context->shop->domain;
        if (is_file($root.'/app/AppKernel.php')) { require_once $root.'/app/AppKernel.php'; $kernel=new AppKernel('prod',false); $kernel->boot(); }
        if (!Module::isEnabled('wd29woobridge')) { throw new RuntimeException('PrestaShop bridge must be enabled.'); }
        $module=Module::getInstanceByName('wd29woobridge'); $engine=$module->bridge();
    }
    if ($command==='tick') { $engine->tick(); }
    $status=$engine->diagnostics();
    while (ob_get_level()>0) { ob_end_clean(); }
    fwrite(STDOUT,json_encode($status,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    exit($status['ok'] && $status['mode']!=='disabled'?0:2);
} catch (Throwable $error) {
    while (ob_get_level()>0) { ob_end_clean(); }
    // Native exception text may contain credentials, SQL or personal information.
    fwrite(STDERR,"Bridge worker failed; inspect private server logs and bridge diagnostics.\n"); exit(1);
}
