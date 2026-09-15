<?php
/** Shared wire protocol. GPL-2.0-or-later. */
namespace WD29\Bridge;

final class Protocol
{
    const VERSION = 1;
    const MAX_BYTES = 4194304;

    public static function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw new \RuntimeException('Message exceeds 4 MiB; split the batch.');
        }
        return $json;
    }

    public static function sign(string $body, string $secret, string $timestamp, string $nonce): string
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('A shared secret of at least 32 characters is required.');
        }
        return hash_hmac('sha256', self::VERSION . "\n" . $timestamp . "\n" . $nonce . "\n" . $body, $secret);
    }

    public static function verify(string $body, string $secret, array $headers, ?int $now = null): array
    {
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        if (strlen($body) > self::MAX_BYTES || !ctype_digit($timestamp)
            || abs(($now ?? time()) - (int) $timestamp) > 300
            || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || !hash_equals(self::sign($body, $secret, $timestamp, $nonce), $signature)) {
            throw new \RuntimeException('Invalid or expired signature.');
        }
        $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['version'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('Unsupported protocol.');
        }
        return $value;
    }

    public static function key(string $site, string $kind, int $id): string
    {
        if (!in_array($site, ['woo', 'ps'], true) || !in_array($kind, ['product', 'variant', 'order', 'customer', 'guest'], true) || $id < 1) {
            throw new \InvalidArgumentException('Invalid source identity.');
        }
        return "$site:$kind:$id";
    }

    public static function validateKey(string $key): void
    {
        if (!preg_match('/^(woo|ps):(product|variant|order|customer|guest):[1-9][0-9]*$/D', $key)) {
            throw new \InvalidArgumentException('Invalid record identity.');
        }
    }

    public static function fingerprint(array $value): string
    {
        $sort = function ($node) use (&$sort) {
            if (!is_array($node)) { return $node; }
            if (array_keys($node) !== range(0, count($node) - 1)) { ksort($node); }
            return array_map($sort, $node);
        };
        return hash('sha256', self::encode($sort($value)));
    }

    /** Unknown quantity remains null. Availability is not an inventory count. */
    public static function quantity($value): ?int
    {
        if ($value === null || $value === '') { return null; }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Fractional or invalid inventory requires an explicit unit mapping.');
        }
        return (int) $value;
    }

    public static function publicEndpoint(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Use an HTTPS endpoint without credentials or a nonstandard port.');
        }
        return $url;
    }

    public static function imageBytes(string $url, string $peer): string
    {
        self::publicEndpoint($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (strtolower($host) !== strtolower((string) parse_url($peer, PHP_URL_HOST))) {
            throw new \RuntimeException('Image host differs from the paired store; explicit media mapping required.');
        }
        $addresses = gethostbynamel($host) ?: [];
        if (!$addresses) { throw new \RuntimeException('Image DNS lookup failed.'); }
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { throw new \RuntimeException('Image must have a public address.'); }
        }
        $bytes = ''; $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$bytes) {
                if (strlen($bytes) + strlen($chunk) > 10485760) { return 0; } $bytes .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($ok === false || $status !== 200) { throw new \RuntimeException('Image download failed.'); }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true) || $info[0] * $info[1] > 40000000) {
            throw new \RuntimeException('Unsupported or oversized image.');
        }
        return $bytes;
    }

    /** Pin the resolved public address to prevent DNS rebinding and internal HTTP requests. */
    public static function request(string $url, string $secret, array $payload): array
    {
        self::publicEndpoint($url);
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = gethostbynamel($host) ?: [];
        if (!$addresses) { throw new \RuntimeException('Peer DNS lookup failed.'); }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Peer must resolve only to public IPv4 addresses.');
            }
        }
        $body = self::encode(['version' => self::VERSION] + $payload);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $response = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WD29-Timestamp: ' . $timestamp,
                'X-WD29-Nonce: ' . $nonce, 'X-WD29-Signature: ' . self::sign($body, $secret, $timestamp, $nonce)],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > self::MAX_BYTES) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($ok === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Peer request failed (HTTP ' . $status . ').');
        }
        $result = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result) || empty($result['ok'])) { throw new \RuntimeException('Peer did not acknowledge the request.'); }
        return $result;
    }
}
