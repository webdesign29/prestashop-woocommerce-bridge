<?php
require_once __DIR__ . '/../includes/Protocol.php';
use WD29\Bridge\Protocol;
function expect($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function rejects(callable $fn, string $message): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$secret = str_repeat('test-only-', 5);
$body = Protocol::encode(['version' => 1, 'op' => 'health']);
$headers = ['timestamp' => '1700000000', 'nonce' => str_repeat('a', 32)];
$headers['signature'] = Protocol::sign($body, $secret, $headers['timestamp'], $headers['nonce']);
expect(Protocol::verify($body, $secret, $headers, 1700000000)['op'] === 'health', 'Valid signature rejected');
rejects(function () use ($body,$secret,$headers) { Protocol::verify($body . ' ', $secret, $headers, 1700000000); }, 'Tampering accepted');
rejects(function () use ($body,$secret,$headers) { Protocol::verify($body, $secret, $headers, 1700000400); }, 'Expired signature accepted');
rejects(function () use ($body,$headers) { Protocol::verify($body, str_repeat('x', 32), $headers, 1700000000); }, 'Wrong peer accepted');
expect(Protocol::quantity(null) === null, 'Unknown quantity became zero');
expect(Protocol::quantity(-1) === -1, 'Negative source stock changed');
expect(Protocol::quantity(0) === 0, 'Zero quantity changed');
rejects(function () { Protocol::quantity('1.2'); }, 'Fractional stock silently rounded');
expect(Protocol::fingerprint(['b' => 2, 'a' => 1]) === Protocol::fingerprint(['a' => 1, 'b' => 2]), 'Object key order changes fingerprints');
expect(Protocol::key('woo','product',1) !== Protocol::key('ps','product',1), 'Native IDs collide');
rejects(function () { Protocol::validateKey('woo:product:0'); }, 'Invalid identity accepted');
rejects(function () { Protocol::publicEndpoint('http://example.com'); }, 'Plain HTTP accepted');
rejects(function () { Protocol::publicEndpoint('https://user:password@example.com'); }, 'Credentials accepted in URL');
rejects(function () { Protocol::publicEndpoint('https://example.com:8080'); }, 'Nonstandard port accepted');
echo "PASS: protocol signatures, expiry, tampering, identities, quantities, URL constraints\n";

foreach (['3.000000'=>3, '-2.000000'=>-2, '0.000000'=>0, ' 9.000 '=>9] as $quantity=>$expected) { expect(Protocol::quantity($quantity)===$expected,'Native whole stock rejected'); }
foreach (['1.2', '-2.0001', '3e0', 'NaN', '1,000', '9999999999999999999999.000'] as $quantity) { rejects(function () use ($quantity) { Protocol::quantity($quantity); }, 'Invalid stock accepted: '.$quantity); }
echo "PASS: native decimal whole stock; fractional, overflow and ambiguous counts rejected\n";
