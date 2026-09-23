<?php
/** Licence client for plugins.inklura.fr (protocol v1). Shared by both plugins. GPL-2.0-or-later. */
namespace WD29\Bridge;

/**
 * The licence decides one thing only: whether a store configured in live mode may apply
 * incoming events. Everything else (capture, delivery, audit, reports) keeps working.
 *
 * - The server signs every answer (Ed25519 over payload_json); answers are verified here.
 * - One check per day from the worker, never on storefront requests; short timeouts.
 * - Unreachable server or unverifiable answer: nothing changes, retry later.
 * - Expired: live until the server-provided pause_after (grace), then audit. Revoked or
 *   unknown key: audit. Payment failure or all pairs used on an already activated store:
 *   synchronization continues.
 * - Stores upgraded without a key keep syncing for GRACE_DAYS, with a warning.
 * Nothing is deleted or disabled; returning to a usable licence resumes live mode.
 */
final class Licence
{
    const PRODUCT = 'wd29-bridge';
    const SERVER = 'https://plugins.inklura.fr';
    const PUBLIC_KEY = 'esVdIXmGZigAGS8vz00iN//l3+WlIgFVh5iVATFI6Wk=';
    const CHECK_EVERY = 86400;
    const RETRY_MIN = 3600;
    const RETRY_MAX = 21600;
    const GRACE_DAYS = 14;
    const MAX_CLOCK_SKEW = 172800;
    const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private $adapter;
    private $publicKey;
    private $transport;

    /** @param callable|null $transport fn(string $url, array $fields): array{status:int,body:string,retry_after:int} (tests) */
    public function __construct($adapter, ?string $publicKey = null, ?callable $transport = null)
    {
        $this->adapter = $adapter;
        $this->publicKey = $publicKey ?? self::PUBLIC_KEY;
        $this->transport = $transport;
    }

    public function side(): string { return $this->adapter->site() === 'ps' ? 'prestashop' : 'woocommerce'; }
    public function state(): array { return $this->adapter->licenceState(); }
    private function save(array $state): array { $this->adapter->licenceState($state); return $state; }

    public static function normalizeKey(string $raw): ?string
    {
        $s = strtoupper(preg_replace('/[\s_]+/', '', trim($raw)));
        if (strpos($s, 'WD29') === 0) { $s = substr($s, 4); }
        $s = strtr(str_replace('-', '', $s), ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($s) !== 16 || strspn($s, self::CROCKFORD) !== 16) { return null; }
        return 'WD29-' . implode('-', str_split($s, 4));
    }

    public static function hint(string $key): string { return '…' . substr($key, -4); }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'https://' . $url, PHP_URL_HOST));
        return trim(rtrim($host, '.'), '[]');
    }

    /** Save a key entered by the administrator and activate this store now. */
    public function setKey(string $raw): array
    {
        $key = self::normalizeKey($raw);
        if ($key === null) { throw new \InvalidArgumentException('Clé de licence invalide : format attendu WD29-XXXX-XXXX-XXXX-XXXX.'); }
        $state = $this->state();
        if (($state['key'] ?? '') !== '' && $state['key'] !== $key) {
            try { $this->call('deactivate', $state['key']); } catch (\Throwable $e) { /* best effort: the old seat can be freed from the customer area */ }
        }
        $this->save(['key' => $key, 'unlicensed_since' => $state['unlicensed_since'] ?? time()]);
        return $this->check('activate');
    }

    /** Free this store's pair on the server, then forget the key. */
    public function removeKey(): array
    {
        $state = $this->state();
        $message = 'Clé retirée de cette boutique.';
        if (($state['key'] ?? '') !== '') {
            try { $payload = $this->call('deactivate', $state['key']); $message = (string) $payload['message']; }
            catch (\Throwable $e) { $message = 'Clé retirée localement ; le serveur de licence est injoignable. Libérez la paire depuis votre espace client.'; }
        }
        $this->save(['unlicensed_since' => $state['unlicensed_since'] ?? time(), 'message' => $message]);
        return $this->state();
    }

    /** Worker entry point: at most one network call per day, only when a key is set. */
    public function maybeCheck(?int $now = null): void
    {
        $now = $now ?? time();
        $state = $this->state();
        if (empty($state['status']) && empty($state['unlicensed_since'])) { $state = $this->save(['unlicensed_since' => $now] + $state); }
        if (($state['key'] ?? '') === '') { return; }
        if ($now < (int) ($state['next_check'] ?? 0)) { return; }
        $this->check('check', $now);
    }

    /** Contact the server now. Failures are recorded and never change the licence status. */
    public function check(string $op = 'check', ?int $now = null): array
    {
        $now = $now ?? time();
        $state = $this->state();
        $key = (string) ($state['key'] ?? '');
        if ($key === '') { return $state; }
        try {
            $payload = $this->call($op, $key, $now, $state['issued_at'] ?? null);
            $state = $this->apply($state, $payload, $now);
        } catch (\Throwable $e) {
            $failures = (int) ($state['failures'] ?? 0) + 1;
            $delay = min(self::RETRY_MAX, self::RETRY_MIN * (2 ** min(4, $failures - 1)));
            if ($e instanceof LicenceRetryLater) { $delay = max($delay, $e->retryAfter); }
            $state['failures'] = $failures;
            $state['last_error'] = substr($e->getMessage(), 0, 200);
            $state['last_attempt'] = $now;
            $state['next_check'] = $now + $delay;
        }
        return $this->save($state);
    }

    private function apply(array $state, array $p, int $now): array
    {
        $status = (string) $p['status'];
        $usable = in_array($status, ['active', 'past_due'], true);
        return [
            'key' => $state['key'],
            'status' => $status,
            'plan' => $p['plan'] ?? null,
            'pairs_used' => (int) ($p['pairs_used'] ?? 0),
            'pairs_max' => (int) ($p['pairs_max'] ?? 0),
            'expires_at' => $p['expires_at'] ?? null,
            'pause_after' => $p['pause_after'] ?? null,
            'latest_version' => is_array($p['latest_version'] ?? null) ? $p['latest_version'] : [],
            'message' => (string) ($p['message'] ?? ''),
            'issued_at' => (string) $p['issued_at'],
            'checked_at' => $now,
            // A store keeps its seat once it held one; the server re-checks seats itself.
            'activated' => $usable || (!empty($state['activated']) && in_array($status, ['seats_exhausted', 'expired'], true)),
            // The upgrade grace restarts only if the store later loses its key.
            'unlicensed_since' => $usable ? null : ($state['unlicensed_since'] ?? $now),
            'failures' => 0,
            'next_check' => $now + self::CHECK_EVERY + random_int(0, 3600),
        ];
    }

    /** @return array{0:bool,1:string} [live allowed, reason] */
    public function gate(?int $now = null): array
    {
        $now = $now ?? time();
        $s = $this->state();
        $status = ($s['key'] ?? '') === '' ? 'missing' : (string) ($s['status'] ?? 'unverified');
        switch ($status) {
            case 'active': case 'past_due': return [true, $status];
            case 'seats_exhausted': return [!empty($s['activated']), $status];
            case 'expired':
                $until = strtotime((string) ($s['pause_after'] ?? '')) ?: 0;
                return [$now < $until, $status];
            case 'missing': case 'unverified':
                $since = (int) ($s['unlicensed_since'] ?? $now);
                return [$now < $since + self::GRACE_DAYS * 86400, $status];
            default: return [false, $status]; // revoked, invalid
        }
    }

    public function allowsLive(?int $now = null): bool { return $this->gate($now)[0]; }

    /** Grace deadline for a store without a verified key, as a timestamp. */
    public function graceEnds(): int { return (int) ($this->state()['unlicensed_since'] ?? time()) + self::GRACE_DAYS * 86400; }

    /** @return array verified payload */
    public function call(string $op, string $key, ?int $now = null, ?string $lastIssued = null): array
    {
        if (!in_array($op, ['activate', 'check', 'deactivate'], true)) { throw new \InvalidArgumentException('Unknown licence operation.'); }
        $fields = [
            'key' => $key, 'product' => self::PRODUCT, 'side' => $this->side(),
            'site_url' => $this->adapter->siteUrl(), 'peer_url' => (string) ($this->adapter->config()['peer'] ?? ''),
            'plugin_version' => $this->adapter->pluginVersion(),
        ];
        $url = self::server() . '/api/licences/v1/' . $op;
        $response = $this->transport ? ($this->transport)($url, $fields) : self::post($url, $fields);
        if ($response['status'] === 429 || $response['status'] >= 500) {
            throw new LicenceRetryLater('Serveur de licence indisponible (HTTP ' . $response['status'] . ').', (int) ($response['retry_after'] ?? 0));
        }
        if ($response['status'] !== 200) { throw new \RuntimeException('Réponse inattendue du serveur de licence (HTTP ' . $response['status'] . ').'); }
        return $this->verify($response['body'], self::host($fields['site_url']), self::hint($key), $now ?? time(), $lastIssued);
    }

    /** Verify a signed answer and bind it to this store, this key and a recent time. */
    public function verify(string $body, string $host, string $hint, int $now, ?string $lastIssued = null): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) { throw new \RuntimeException('Extension PHP sodium absente : réponse de licence non vérifiable.'); }
        $data = json_decode($body, true, 16);
        $sig = is_array($data) ? base64_decode((string) ($data['sig'] ?? ''), true) : false;
        $json = is_array($data) ? (string) ($data['payload_json'] ?? '') : '';
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $json === ''
            || !sodium_crypto_sign_verify_detached($sig, $json, base64_decode($this->publicKey))) {
            throw new \RuntimeException('Signature de licence invalide.');
        }
        $p = json_decode($json, true, 16);
        $issued = is_array($p) ? strtotime((string) ($p['issued_at'] ?? '')) : false;
        if (!is_array($p) || ($p['v'] ?? null) !== 1 || ($p['product'] ?? '') !== self::PRODUCT
            || !in_array($p['status'] ?? '', ['active', 'past_due', 'expired', 'revoked', 'invalid', 'seats_exhausted'], true)) {
            throw new \RuntimeException('Réponse de licence non reconnue.');
        }
        if (($p['site_host'] ?? '') !== $host || ($p['key_hint'] ?? '') !== $hint) { throw new \RuntimeException('Réponse de licence destinée à une autre boutique ou une autre clé.'); }
        if ($issued === false || abs($now - $issued) > self::MAX_CLOCK_SKEW) { throw new \RuntimeException('Réponse de licence périmée : vérifiez l\'horloge du serveur.'); }
        if ($lastIssued !== null && ($last = strtotime($lastIssued)) !== false && $issued < $last) { throw new \RuntimeException('Réponse de licence plus ancienne que la dernière reçue.'); }
        return $p;
    }

    public static function server(): string
    {
        $url = defined('WD29_BRIDGE_LICENCE_SERVER') ? (string) constant('WD29_BRIDGE_LICENCE_SERVER') : self::SERVER;
        if (strpos($url, 'https://') !== 0) { throw new \RuntimeException('Licence server must use HTTPS.'); }
        return rtrim($url, '/');
    }

    /** Small bounded HTTPS POST: 4 s to connect, 8 s total, 64 KiB answer, no redirects. */
    public static function post(string $url, array $fields, int $timeout = 8): array
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('Extension PHP curl absente.'); }
        $body = ''; $retryAfter = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($fields, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'WD29-Bridge-Licence/1', CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$retryAfter) {
                if (stripos($line, 'retry-after:') === 0) { $retryAfter = (int) trim(substr($line, 12)); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($h, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($ok === false) { throw new LicenceRetryLater('Serveur de licence injoignable.', 0); }
        return ['status' => $status, 'body' => $body, 'retry_after' => $retryAfter];
    }

    /** Headers for authenticated downloads and update manifests. */
    public function downloadHeaders(): array
    {
        $key = (string) ($this->state()['key'] ?? '');
        return $key === '' ? [] : ['X-Licence-Key' => $key, 'X-Site-Url' => $this->adapter->siteUrl()];
    }

    /** Newer release published for this side, from the last verified answer. */
    public function updateAvailable(): ?string
    {
        $latest = (string) ($this->state()['latest_version'][$this->side()] ?? '');
        return $latest !== '' && version_compare($latest, $this->adapter->pluginVersion(), '>') ? $latest : null;
    }

    /** French summary for the admin panel. */
    public function summary(?int $now = null): array
    {
        $now = $now ?? time();
        $s = $this->state();
        [$live, $reason] = $this->gate($now);
        $labels = ['active' => 'Licence active', 'past_due' => 'Paiement en attente', 'expired' => 'Licence expirée', 'revoked' => 'Licence révoquée',
            'invalid' => 'Clé inconnue', 'seats_exhausted' => 'Toutes les paires utilisées', 'missing' => 'Aucune clé', 'unverified' => 'Clé non vérifiée'];
        $tone = in_array($reason, ['active', 'past_due'], true) ? 'ok' : ($live ? 'warn' : 'bad');
        $text = (string) ($s['message'] ?? '');
        if ($reason === 'missing' || $reason === 'unverified') {
            $text = ($reason === 'missing' ? 'Saisissez votre clé de licence (espace client plugins.inklura.fr/compte). ' : 'La clé n\'a pas encore pu être vérifiée. ')
                . ($live ? 'Sans clé vérifiée, le mode live reste possible jusqu\'au ' . gmdate('d/m/Y', $this->graceEnds()) . '.' : 'Le mode live est suspendu : la synchronisation fonctionne en mode audit.');
        } elseif (!$live) {
            $text .= ' Le mode live est suspendu : les événements sont reçus et conservés, rien n\'est perdu.';
        }
        $label = $labels[$reason] ?? $reason;
        // Server messages often open with the same words as the label ("Licence active : …").
        if (stripos($text, $label) === 0) {
            $text = ltrim(substr($text, strlen($label)), " .:");
            $text = function_exists('mb_strtoupper') && $text !== '' ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) : ucfirst($text);
        }
        return ['label' => $label, 'tone' => $tone, 'live' => $live, 'reason' => $reason, 'text' => trim($text),
            'hint' => ($s['key'] ?? '') !== '' ? self::hint($s['key']) : '', 'plan' => $s['plan'] ?? null,
            'pairs' => isset($s['pairs_max']) ? ((int) ($s['pairs_used'] ?? 0)) . '/' . ((int) $s['pairs_max']) : '',
            'expires' => !empty($s['expires_at']) ? gmdate('d/m/Y', strtotime($s['expires_at'])) : '',
            'checked' => !empty($s['checked_at']) ? gmdate('d/m/Y H:i', (int) $s['checked_at']) . ' UTC' : '',
            'error' => ($s['failures'] ?? 0) > 0 ? (string) ($s['last_error'] ?? '') : '',
            'update' => $this->updateAvailable()];
    }
}

final class LicenceRetryLater extends \RuntimeException
{
    public $retryAfter;
    public function __construct(string $message, int $retryAfter) { parent::__construct($message); $this->retryAfter = max(0, min(86400, $retryAfter)); }
}
