<?php
/** Exercise the real license/engine classes in an extracted Marketplace artifact. */
$root = $argv[1] ?? '';
if (!is_file($root . '/includes/Distribution.php')) { throw new RuntimeException('Pass an extracted Marketplace package root.'); }
require $root . '/includes/Protocol.php';
require $root . '/includes/Licence.php';
require $root . '/includes/Engine.php';
require $root . '/includes/LicenceAdmin.php';
use WD29\Bridge\Distribution;
use WD29\Bridge\Licence;
use WD29\Bridge\Engine;
use WD29\Bridge\LicenceAdmin;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
class MarketplaceAdapter {
    public $saved = 0;
    public $state = [];
    public $mode = 'live';
    public $side;
    public function __construct($side) { $this->side = $side; }
    public function site() { return $this->side; }
    public function prefix() { return 'fixture_'; }
    public function siteUrl() { return 'https://' . $this->side . '.example.test/'; }
    public function pluginVersion() { return '0.7.0'; }
    public function config() { return ['mode' => $this->mode, 'peer' => 'https://peer.example.test/webhook']; }
    public function licenceState(?array $state = null) { if ($state !== null) { $this->saved++; $this->state = $state; } return $this->state; }
}
check(Distribution::isMarketplace(), 'Wrong distribution in the Marketplace artifact');
$calls = 0;
foreach (['woo', 'ps'] as $side) {
    $adapter = new MarketplaceAdapter($side);
    $licence = new Licence($adapter, null, function () use (&$calls) { $calls++; throw new RuntimeException('Unexpected network request'); });
    $engine = new Engine($adapter);
    $issues = new ReflectionMethod(Engine::class, 'licenceIssues'); $issues->setAccessible(true);
    foreach (['missing', 'active', 'expired', 'revoked', 'invalid', 'seats_exhausted', 'unverified'] as $status) {
        $adapter->state = $status === 'missing' ? [] : [
            'key' => 'WD29-ABCD-EFGH-JKMN-PQRS', 'status' => $status,
            'unlicensed_since' => 1, 'pause_after' => '2000-01-01T00:00:00Z',
            'expires_at' => '2000-01-01T00:00:00Z', 'checked_at' => 1,
            'failures' => 10, 'last_error' => 'Old license request failed',
            'latest_version' => ['woocommerce' => '99.0.0', 'prestashop' => '99.0.0']];
        $before = $adapter->state;
        check($licence->gate(PHP_INT_MAX) === [true, 'woo-marketplace'], "$side $status paused installed sync");
        $adapter->mode = 'live'; check($engine->mode() === 'live', "$side $status forced audit");
        $adapter->mode = 'audit'; check($engine->mode() === 'audit', "$side audit was ignored");
        $adapter->mode = 'disabled'; check($engine->mode() === 'disabled', "$side disabled was ignored");
        check($issues->invoke($engine, 'live', time()) === [], "$side stale license generated warnings");
        $licence->maybeCheck(); $licence->check('activate'); $licence->removeKey();
        check($adapter->state === $before && $adapter->saved === 0, "$side direct-sale state was altered");
        check($licence->downloadHeaders() === [], "$side old key leaked to update headers");
        check($licence->updateAvailable() === null, "$side self-hosted update appeared");
        $summary = $licence->summary();
        foreach (['hint', 'expires', 'checked', 'error', 'pairs'] as $field) {
            check($summary[$field] === '', "$side stale $field was displayed");
        }
        check($summary['label'] !== 'Licence active' && $summary['reason'] === 'woo-marketplace', "$side asserted an active subscription");
        $html = LicenceAdmin::render($engine, '<input name="nonce" value="fixture">');
        check(strpos($html, 'licence_key') === false && strpos($html, 'plugins.inklura.fr') === false, "$side extra purchase/key form rendered");
        check(strpos($html, 'WooCommerce.com account') !== false, "$side subscription destination missing");
        foreach (['licence_save', 'licence_check', 'licence_remove'] as $action) {
            LicenceAdmin::handle($engine, $action, 'WD29-ABCD-EFGH-JKMN-PQRS');
            check($adapter->state === $before, "$side stale POST altered a direct-sale key");
        }
    }
    try { $licence->setKey('WD29-ABCD-EFGH-JKMN-PQRS'); throw new RuntimeException('Marketplace accepted direct-sale key'); }
    catch (LogicException $expected) {}
    try { $licence->call('deactivate', 'WD29-ABCD-EFGH-JKMN-PQRS'); throw new RuntimeException('Marketplace sent a license request'); }
    catch (LogicException $expected) {}
}
check($calls === 0, 'Marketplace made a license-service request');
echo "PASS: both stores keep installed sync across every stale/missing key state, preserve direct-sale state, respect audit/disabled, and make zero license requests\n";
