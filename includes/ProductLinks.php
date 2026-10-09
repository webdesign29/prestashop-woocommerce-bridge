<?php
namespace WD29\Bridge;

/** Read-only, indexed product navigation. Never creates a mapping or exports a catalogue. */
final class ProductLinks
{
    private static function validKey(string $key): bool
    {
        return (bool) preg_match('/^(woo|ps):product:[1-9][0-9]{0,14}$/D', $key);
    }

    public static function local(Engine $engine, int $id): array
    {
        $config = $engine->config();
        $peer = (string) ($config['peer'] ?? '');
        $result = ['state' => 'missing', 'key' => '', 'local_id' => $id, 'peer_host' => '', 'origin' => true];
        if ($id < 1 || !$engine->adapter->productExists($id)) { return $result; }
        try { Protocol::publicEndpoint($peer); }
        catch (\Throwable $error) { $result['state'] = 'disconnected'; return $result; }
        $result['peer_host'] = (string) parse_url($peer, PHP_URL_HOST);
        if (strlen((string) ($config['secret'] ?? '')) < 32) { $result['state'] = 'disconnected'; return $result; }
        $map = $engine->sql('SELECT record_key FROM {b}map WHERE kind=? AND local_id=? LIMIT 1', ['product', $id])[0] ?? null;
        if (!$map) { $result['state'] = 'unmapped'; return $result; }
        $key = (string) $map['record_key'];
        if (!self::validKey($key)) { return $result; }
        $result['key'] = $key;
        $result['origin'] = strpos($key, $engine->adapter->site() . ':') === 0;
        // A local original cannot resolve to a different native product with a reused ID.
        if ($result['origin'] && $key !== Protocol::key($engine->adapter->site(), 'product', $id)) { return $result; }
        if ($engine->sql('SELECT record_key FROM {b}deleted_products WHERE record_key=? LIMIT 1', [$key])) { return $result; }
        $result['state'] = 'ready';
        return $result;
    }

    /** Authenticated peer operation; available even while automatic synchronization is paused. */
    public static function inspect(Engine $engine, string $key): array
    {
        if (!self::validKey($key)) { throw new \InvalidArgumentException('Invalid product identity.'); }
        $result = ['ok' => true, 'state' => 'missing', 'key' => $key, 'local_id' => 0, 'url' => ''];
        if ($engine->sql('SELECT record_key FROM {b}deleted_products WHERE record_key=? LIMIT 1', [$key])) { return $result; }
        $map = $engine->sql('SELECT record_key,local_id FROM {b}map WHERE record_key=? AND kind=? LIMIT 1', [$key, 'product'])[0] ?? null;
        $parts = explode(':', $key);
        if ($parts[0] === $engine->adapter->site()) {
            $id = (int) $parts[2];
            // Check ownership as well as existence; an unrelated copy must never be linked.
            $owner = $engine->sql('SELECT record_key FROM {b}map WHERE kind=? AND local_id=? LIMIT 1', ['product', $id])[0] ?? null;
            if ($owner && $owner['record_key'] !== $key) { return $result; }
            if ($map && (int) $map['local_id'] !== $id) { return $result; }
        } else {
            if (!$map) { return $result; }
            $id = (int) $map['local_id'];
        }
        if ($id < 1 || !$engine->adapter->productExists($id)) { return $result; }
        $result['local_id'] = $id;
        $url = $engine->adapter->productPublicUrl($id);
        if ($url === '') { $result['state'] = 'unpublished'; return $result; }
        $result['url'] = self::safeUrl($url, $engine->adapter->siteUrl());
        $result['state'] = 'linked';
        return $result;
    }

    public static function safeUrl(string $url, string $peer): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new \InvalidArgumentException('Invalid product URL.'); }
        Protocol::publicEndpoint($url);
        Protocol::publicEndpoint($peer);
        if (strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url($peer, PHP_URL_HOST)) !== 0) {
            throw new \InvalidArgumentException('Product URL differs from the paired shop.');
        }
        return $url;
    }

    public static function remote(Engine $engine, int $id): array
    {
        $result = self::local($engine, $id) + ['url' => '', 'remote_id' => 0];
        if ($result['state'] !== 'ready') { return $result; }
        try {
            $response = $engine->peer(['op' => 'product_link', 'key' => $result['key']], 6);
            if (($response['key'] ?? '') !== $result['key']) { throw new \RuntimeException('Product identity mismatch.'); }
            if (($response['state'] ?? '') === 'missing') { $result['state'] = 'missing'; return $result; }
            $remoteId = filter_var($response['local_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$remoteId || $remoteId < 1) { throw new \RuntimeException('Invalid remote product.'); }
            if (($response['state'] ?? '') === 'unpublished') { $result['state'] = 'unpublished'; return $result; }
            if (($response['state'] ?? '') !== 'linked') { throw new \RuntimeException('Product link unavailable.'); }
            if (!isset($response['url']) || !is_string($response['url'])) { throw new \RuntimeException('Invalid product URL.'); }
            $result['url'] = self::safeUrl($response['url'], (string) $engine->config()['peer']);
            $result['remote_id'] = $remoteId;
            $result['state'] = 'linked';
        } catch (\Throwable $error) {
            $result['state'] = 'unavailable';
            // No transport internals, shared secrets or remote response bodies reach the editor.
        }
        return $result;
    }
}
