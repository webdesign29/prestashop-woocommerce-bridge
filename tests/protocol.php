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
