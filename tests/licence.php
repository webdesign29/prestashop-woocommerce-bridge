<?php
/** Licence client tests: signatures, binding, gate states, backoff, check frequency. CLI only, no store. */
require __DIR__ . '/../includes/Protocol.php';
require __DIR__ . '/../includes/Licence.php';
require __DIR__ . '/../includes/Engine.php';

use WD29\Bridge\Licence;
use WD29\Bridge\Engine;

final class FakeAdapter
{
    public $state = [];
    public $config = ['mode' => 'live', 'peer' => 'https://ps.example.test/module/wd29woobridge/webhook'];
    public function site(): string { return 'woo'; }
    public function prefix(): string { return 'wp_'; }
    public function config(): array { return $this->config; }
    public function licenceState(?array $s = null): array { if ($s !== null) { $this->state = $s; } return $this->state; }
    public function siteUrl(): string { return 'https://Woo.Example.test/'; }
    public function pluginVersion(): string { return '0.3.0'; }
}

$failures = 0;
function ok(bool $cond, string $label): void { global $failures; if (!$cond) { $failures++; echo "FAIL $label\n"; } }

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = base64_encode(sodium_crypto_sign_publickey($pair));
const KEY = 'WD29-ABCD-EFGH-JKMN-PQRS';

function body(array $over, string $secret, int $now): string
{
    $p = $over + ['v' => 1, 'product' => 'wd29-bridge', 'key_hint' => '…PQRS', 'site_host' => 'woo.example.test', 'status' => 'active',
        'plan' => 'essentiel', 'pairs_used' => 1, 'pairs_max' => 1, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 300 * 86400),
        'pause_after' => gmdate('Y-m-d\TH:i:s\Z', $now + 314 * 86400), 'latest_version' => ['woocommerce' => '0.3.1', 'prestashop' => '0.3.0'],
        'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'message' => 'ok'];
    $json = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return json_encode(['payload_json' => $json, 'payload' => $p, 'sig' => base64_encode(sodium_crypto_sign_detached($json, $secret))]);
}

// Key normalization mirrors the server (Crockford: O→0, I/L→1, case and separators ignored).
ok(Licence::normalizeKey(' wd29-abcd efgh_jkmn-pqrs ') === KEY, 'normalize spacing');
ok(Licence::normalizeKey('WD29-ABCD-EFGH-JKMN-PQR5') === 'WD29-ABCD-EFGH-JKMN-PQR5', 'normalize digits');
ok(Licence::normalizeKey('WD29-oooo-iiii-llll-0000') === 'WD29-0000-1111-1111-0000', 'normalize crockford');
ok(Licence::normalizeKey('WD29-ABCD-EFGH-JKMN') === null, 'reject short');
ok(Licence::normalizeKey('WD29-ABCD-EFGH-JKMN-PQRU') === null, 'reject U');

$now = 1790000000;
$calls = 0; $next = null;
$adapter = new FakeAdapter();
$transport = function ($url, $fields) use (&$calls, &$next) { $calls++; return $next ?? ['status' => 500, 'body' => '', 'retry_after' => 0]; };
$licence = new Licence($adapter, $public, $transport);

// Verification binds the answer to this host, this key and a recent time.
$v = function (string $b, ?string $last = null) use ($licence, $now) { try { $licence->verify($b, 'woo.example.test', '…PQRS', $now, $last); return true; } catch (\Throwable $e) { return false; } };
ok($v(body([], $secret, $now)), 'valid signature');
$tampered = json_decode(body([], $secret, $now), true); $tampered['payload_json'] = str_replace('"active"', '"revoked"', $tampered['payload_json']);
ok(!$v(json_encode($tampered)), 'tampered payload rejected');
$other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
ok(!$v(body([], $other, $now)), 'foreign key rejected');
ok(!$v(body(['site_host' => 'other.example.test'], $secret, $now)), 'other host rejected');
ok(!$v(body(['key_hint' => '…ZZZZ'], $secret, $now)), 'other key rejected');
ok(!$v(body(['issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3 * 86400)], $secret, $now)), 'stale answer rejected');
ok(!$v(body([], $secret, $now - 60), gmdate('Y-m-d\TH:i:s\Z', $now)), 'replayed older answer rejected');
ok(!$v('not json'), 'garbage rejected');

// No key: live allowed during the grace period only.
$licence->maybeCheck($now);
ok($calls === 0, 'no call without key');
ok($licence->gate($now + 86400) === [true, 'missing'], 'missing within grace');
ok($licence->gate($now + 15 * 86400) === [false, 'missing'], 'missing after grace');

// Saving a key activates at once; a verified answer is stored.
$next = ['status' => 200, 'body' => body([], $secret, time()), 'retry_after' => 0];
$licence->setKey('wd29 abcd efgh jkmn pqrs');
ok($adapter->state['key'] === KEY && $adapter->state['status'] === 'active', 'activation stored');
ok($licence->gate() === [true, 'active'], 'active allows live');
ok(empty($adapter->state['unlicensed_since']), 'grace cleared once licensed');
ok($licence->updateAvailable() === '0.3.1', 'update announced');
ok($licence->downloadHeaders() === ['X-Licence-Key' => KEY, 'X-Site-Url' => 'https://Woo.Example.test/'], 'download headers');

// At most one call per day.
$calls = 0; $t = time();
$licence->maybeCheck($t + 60); $licence->maybeCheck($t + 3600);
ok($calls === 0, 'no call before next_check');
$licence->maybeCheck($t + 90000);
ok($calls === 1, 'one call after a day');

// Server unavailable: status unchanged, backoff grows, Retry-After honoured.
$adapter->state['next_check'] = 0; $calls = 0;
$next = ['status' => 503, 'body' => '', 'retry_after' => 7200];
$s = $licence->check('check', $t);
ok($s['status'] === 'active' && $s['failures'] === 1 && $s['next_check'] === $t + 7200, 'retry-after honoured, status kept');
$s = $licence->check('check', $t); $s = $licence->check('check', $t);
ok($s['next_check'] - $t === 14400 && $s['status'] === 'active', 'exponential backoff');
$next = ['status' => 200, 'body' => body([], $other, $t), 'retry_after' => 0];
$s = $licence->check('check', $t);
ok($s['status'] === 'active' && $s['failures'] === 4, 'unverifiable answer changes nothing');

// Status transitions from the server.
$set = function (array $over) use ($licence, &$next, $secret) { static $i = 0; $i++; $next = ['status' => 200, 'body' => body($over, $secret, time() + $i), 'retry_after' => 0]; $licence->check('check'); };
$set(['status' => 'past_due']); ok($licence->gate()[0], 'past_due keeps live');
$set(['status' => 'seats_exhausted']); ok($licence->gate()[0], 'seats_exhausted keeps an activated store live');
$set(['status' => 'expired', 'pause_after' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)]); ok($licence->gate() === [true, 'expired'], 'expired within grace');
ok($licence->gate(time() + 2 * 86400) === [false, 'expired'], 'expired after pause_after');
$set(['status' => 'revoked', 'pause_after' => null]); ok($licence->gate() === [false, 'revoked'], 'revoked pauses at once');
$set(['status' => 'active']); ok($licence->gate() === [true, 'active'], 'renewal resumes live');
$set(['status' => 'invalid']); ok($licence->gate() === [false, 'invalid'], 'invalid key pauses');

// A new store that never held a seat stays in audit when all pairs are used.
$fresh = new FakeAdapter(); $freshLicence = new Licence($fresh, $public, function () use ($secret) { return ['status' => 200, 'body' => body(['status' => 'seats_exhausted'], $secret, time()), 'retry_after' => 0]; });
$freshLicence->setKey(KEY);
ok($freshLicence->gate() === [false, 'seats_exhausted'], 'new store without seat stays in audit');

// The engine applies the gate to live mode only.
$engineAdapter = new FakeAdapter();
$engine = new Engine($engineAdapter);
$engineAdapter->state = ['key' => KEY, 'status' => 'revoked'];
ok($engine->mode() === 'audit', 'engine: live falls back to audit');
$engineAdapter->state['status'] = 'active';
ok($engine->mode() === 'live', 'engine: active licence keeps live');
$engineAdapter->config['mode'] = 'disabled';
ok($engine->mode() === 'disabled', 'engine: disabled untouched');

// Removing the key frees the seat (best effort) and forgets it.
$next = ['status' => 200, 'body' => body(['status' => 'active', 'message' => 'Ce site a été désactivé pour cette licence.'], $secret, time() + 99), 'retry_after' => 0];
$s = $licence->removeKey();
ok(!isset($s['key']) && strpos($s['message'], 'désactivé') !== false, 'remove key');

echo $failures ? "$failures failure(s)\n" : "all passed\n";
exit($failures ? 1 : 0);
