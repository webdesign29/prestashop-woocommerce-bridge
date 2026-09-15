<?php
namespace WD29\Bridge;

/** Durable transport and reconciliation. Native platform operations live in adapters. */
final class Engine
{
    public $adapter;
    public $catalogApplying = false;
    public $orderApplying = false;
    public $stockApplying = false;
    private $table;

    public function __construct($adapter)
    {
        $this->adapter = $adapter;
        $this->table = $adapter->prefix() . 'wd29_bridge_';
    }

    public function sql(string $sql, array $args = [])
    {
        return $this->adapter->sql(str_replace('{b}', $this->table, $sql), $args);
    }

    public function install(): void
    {
        foreach ([
            'map' => 'record_key varchar(96) NOT NULL, kind varchar(16) NOT NULL, local_id bigint unsigned NOT NULL, fingerprint varchar(64) NOT NULL DEFAULT \'\', local_hash varchar(64) NOT NULL DEFAULT \'\', quantity bigint NULL, stock_initialized tinyint NOT NULL DEFAULT 0, snapshot longtext NULL, PRIMARY KEY(record_key), UNIQUE KEY native_record(kind,local_id)',
            'queue' => 'seq bigint unsigned NOT NULL AUTO_INCREMENT, event_id char(32) NOT NULL, direction varchar(8) NOT NULL, kind varchar(16) NOT NULL, record_key varchar(96) NOT NULL, payload longtext NOT NULL, state varchar(16) NOT NULL DEFAULT \'pending\', attempts int NOT NULL DEFAULT 0, next_try bigint NOT NULL DEFAULT 0, error varchar(255) NOT NULL DEFAULT \'\', created_at datetime NOT NULL, PRIMARY KEY(seq), UNIQUE KEY event_direction(event_id,direction), KEY pending_queue(direction,state,next_try)',
        ] as $name => $fields) {
            $this->sql('CREATE TABLE IF NOT EXISTS {b}' . $name . ' (' . $fields . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
    }

    public function config(): array { return $this->adapter->config(); }
    public function enabled(): bool { return in_array($this->config()['mode'] ?? 'disabled', ['audit', 'live'], true); }

    public function validateSettings(string $mode, string $peer, string $secret): void
    {
        if ($mode !== 'disabled' && ($peer === '' || strlen($secret) < 32)) {
            throw new \RuntimeException('A peer HTTPS URL and shared secret are required before enabling the bridge.');
        }
        $previous = $this->config()['peer'] ?? '';
        if ($peer !== $previous && $previous !== '' && $this->sql('SELECT record_key FROM {b}map LIMIT 1')) {
            throw new \RuntimeException('This bridge already has record mappings. Re-pairing requires an explicit migration of its private tables.');
        }
    }

    public function mapping(string $key): ?array
    {
        Protocol::validateKey($key);
        return $this->sql('SELECT * FROM {b}map WHERE record_key=?', [$key])[0] ?? null;
    }

    public function identity(string $kind, int $id): string
    {
        $row = $this->sql('SELECT * FROM {b}map WHERE kind=? AND local_id=?', [$kind, $id])[0] ?? null;
        if ($row) { return $row['record_key']; }
        $key = Protocol::key($this->adapter->site(), $kind, $id);
        $this->bind($key, $kind, $id);
        return $key;
    }

    public function bind(string $key, string $kind, int $id): void
    {
        Protocol::validateKey($key);
        if (!in_array($kind, ['product', 'variant', 'order'], true) || $id < 1) { throw new \RuntimeException('Invalid mapping.'); }
        $existing = $this->mapping($key);
        if ($existing && ((int) $existing['local_id'] !== $id || $existing['kind'] !== $kind)) {
            throw new \RuntimeException('A source record is already mapped to another native record.');
        }
        $this->sql('INSERT IGNORE INTO {b}map (record_key,kind,local_id) VALUES (?,?,?)', [$key, $kind, $id]);
        $check = $this->mapping($key);
        if (!$check || (int) $check['local_id'] !== $id) { throw new \RuntimeException('Native record already has another origin.'); }
    }

    public static function catalogHash(array $data): string
    {
        unset($data['inventory']);
        return Protocol::fingerprint($data);
    }

    public function capture(string $kind, int $id): void
    {
        if (!$this->enabled() || ($kind === 'product' && $this->catalogApplying) || ($kind === 'order' && $this->orderApplying)) { return; }
        $lock = 'wd29_capture_' . sha1($this->table . $kind . ':' . $id);
        $locked = false;
        try {
            $locked = (int) ($this->sql('SELECT GET_LOCK(?,5) AS acquired', [$lock])[0]['acquired'] ?? 0) === 1;
            if (!$locked) { throw new \RuntimeException('Record capture is busy.'); }
            $this->sql('START TRANSACTION');
            $data = $kind === 'product' ? $this->adapter->product($id) : $this->adapter->order($id);
            $key = $data['key'];
            $row = $this->mapping($key);
            $hash = $kind === 'product' ? self::catalogHash($data) : Protocol::fingerprint($data);
            if ($row['local_hash'] !== $hash) {
                $this->enqueue('out', $kind, $key, ['base' => $row['fingerprint'], 'hash' => $hash, 'data' => $data]);
                $this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?', [$hash, $hash, $key]);
            }
            if ($kind === 'product') {
                foreach ($data['inventory'] as $inventory) {
                    $this->sql('UPDATE {b}map SET quantity=?,stock_initialized=1 WHERE record_key=? AND stock_initialized=0', [Protocol::quantity($inventory['quantity']), $inventory['key']]);
                }
            }
            $this->sql('COMMIT');
            if ($kind === 'product') {
                foreach ($data['inventory'] as $inventory) { $this->captureStock($inventory['key'], $inventory['quantity']); }
            }
        } catch (\Throwable $error) {
            $this->sql('ROLLBACK');
            $this->adapter->notice('Could not capture ' . $kind . ' #' . $id . ': ' . $error->getMessage());
        } finally {
            if ($locked) { $this->sql('SELECT RELEASE_LOCK(?)', [$lock]); }
        }
    }

    public function captureStock(string $key, $quantity): void
    {
        if (!$this->enabled() || $this->stockApplying || $this->catalogApplying) { return; }
        $snapshotQuantity = Protocol::quantity($quantity);
        $lock = 'wd29_stock_' . sha1($this->table . $key);
        if ((int) ($this->sql('SELECT GET_LOCK(?,5) AS acquired', [$lock])[0]['acquired'] ?? 0) !== 1) { throw new \RuntimeException('Inventory is busy; retry capture.'); }
        try {
            $this->sql('START TRANSACTION');
            $row = $this->mapping($key);
            if (!$row) { throw new \RuntimeException('Stock has no product mapping.'); }
            // Re-read under the lock, rather than trusting a possibly stale hook argument.
            $quantity = Protocol::quantity($this->adapter->stockQuantity($row));
            $previous = $row['stock_initialized'] ? ($row['quantity'] === null ? null : (int) $row['quantity']) : $snapshotQuantity;
            if ($quantity !== $previous) {
                $payload = $quantity === null || $previous === null ? ['set_mode' => true, 'previous' => $previous, 'quantity' => $quantity] : ['delta' => $quantity - $previous];
                $this->enqueue('out', 'stock', $key, $payload);
            }
            $this->sql('UPDATE {b}map SET quantity=?,stock_initialized=1 WHERE record_key=?', [$quantity, $key]);
            $this->sql('COMMIT');
        } catch (\Throwable $e) { $this->sql('ROLLBACK'); throw $e; }
        finally { $this->sql('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    public function enqueue(string $direction, string $kind, string $key, array $payload, ?string $id = null): void
    {
        Protocol::validateKey($key);
        $id = $id ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !in_array($kind, ['product', 'stock', 'order'], true)) {
            throw new \RuntimeException('Invalid event envelope.');
        }
        $this->sql('INSERT IGNORE INTO {b}queue (event_id,direction,kind,record_key,payload,created_at) VALUES (?,?,?,?,?,?)',
            [$id, $direction, $kind, $key, Protocol::encode($payload), gmdate('Y-m-d H:i:s')]);
    }

    public function receive(array $message): array
    {
        if (($message['source'] ?? '') !== ($this->adapter->site() === 'woo' ? 'ps' : 'woo')) { throw new \RuntimeException('Peer platform mismatch.'); }
        $op = $message['op'] ?? '';
        if ($op === 'health') {
            return ['ok' => true, 'protocol' => Protocol::VERSION, 'platform' => $this->adapter->site(), 'mode' => $this->config()['mode']];
        }
        if (!$this->enabled()) { throw new \RuntimeException('Bridge is disabled.'); }
        if ($op === 'events') {
            $events = $message['events'] ?? [];
            if (!is_array($events) || count($events) > 20) { throw new \RuntimeException('Invalid event batch.'); }
            foreach ($events as $event) {
                if (!is_array($event) || !is_array($event['payload'] ?? null)) { throw new \RuntimeException('Invalid event.'); }
                $this->enqueue('in', (string) ($event['kind'] ?? ''), (string) ($event['key'] ?? ''), $event['payload'], (string) ($event['id'] ?? ''));
            }
            return ['ok' => true, 'accepted' => count($events)];
        }
        if ($op === 'tick') { $this->tick(false); return ['ok' => true]; }
        if ($op === 'seed') {
            $offset = max(0, (int) ($message['offset'] ?? 0));
            $kind = ($message['kind'] ?? '') === 'order' ? 'order' : 'product';
            return ['ok' => true, 'count' => $this->seed($kind, $offset)];
        }
        throw new \RuntimeException('Unknown operation.');
    }

    public function seed(string $kind, int $offset = 0): int
    {
        $ids = $this->adapter->ids($kind, $offset, 10);
        foreach ($ids as $id) { $this->capture($kind, (int) $id); }
        return count($ids);
    }

    public function peer(array $payload): array
    {
        $config = $this->config();
        return Protocol::request($config['peer'] ?? '', $config['secret'] ?? '', ['source' => $this->adapter->site()] + $payload);
    }

    public function tick(bool $wakePeer = true): void
    {
        if (!$this->enabled()) { return; }
        $lock = substr($this->table . 'worker', 0, 64);
        $row = $this->sql('SELECT GET_LOCK(?,0) AS acquired', [$lock])[0] ?? [];
        if ((int) ($row['acquired'] ?? 0) !== 1) { return; }
        try {
            // Reconcile bounded pages as well as hooks: recovers missed callbacks and scheduled prices.
            foreach (['product','order'] as $kind) {
                $offset=$this->adapter->scanOffset($kind);
                $count=$this->seed($kind,$offset);
                $this->adapter->saveScanOffset($kind,$count<10?0:$offset+10);
            }
            if (($this->config()['mode'] ?? '') === 'live') {
                $events = $this->ready('in');
                foreach ($events as $event) { $this->apply($event); }
            }
            $events = $this->ready('out');
            // Deliver individually: one malformed record cannot prevent unrelated records reaching the peer.
            foreach ($events as $event) {
                try {
                    $this->peer(['op' => 'events', 'events' => [['id' => $event['event_id'], 'kind' => $event['kind'],
                        'key' => $event['record_key'], 'payload' => json_decode($event['payload'], true, 64, JSON_THROW_ON_ERROR)]]]);
                    $this->sql("UPDATE {b}queue SET state='delivered',error='' WHERE seq=?", [$event['seq']]);
                } catch (\Throwable $error) { $this->fail($event, $error); break; }
            }
        } finally {
            $this->sql('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        if ($wakePeer) {
            try { $this->peer(['op' => 'tick']); }
            catch (\Throwable $error) { $this->adapter->notice($error->getMessage()); }
        }
    }

    private function ready(string $direction): array
    {
        return $this->sql("SELECT q.* FROM {b}queue q WHERE q.direction=? AND q.state='pending' AND q.next_try<=? AND NOT EXISTS (SELECT 1 FROM {b}queue older WHERE older.direction=q.direction AND older.record_key=q.record_key AND older.seq<q.seq AND older.state IN ('pending','failed','conflict')) ORDER BY q.seq LIMIT 10", [$direction, time()]);
    }

    private function apply(array $event): void
    {
        $payload = json_decode($event['payload'], true, 64, JSON_THROW_ON_ERROR);
        $key = $event['record_key'];
        $kind = $event['kind'];
        $stockLock = null;
        $this->sql('START TRANSACTION');
        try {
            $map = $this->mapping($key);
            if ($kind === 'stock') {
                $stockLock = 'wd29_stock_' . sha1($this->table . $key);
                if ((int) ($this->sql('SELECT GET_LOCK(?,5) AS acquired', [$stockLock])[0]['acquired'] ?? 0) !== 1) { throw new \RuntimeException('Inventory is busy.'); }
                $map = $this->sql('SELECT * FROM {b}map WHERE record_key=? FOR UPDATE', [$key])[0] ?? null;
                if (!$map) { throw new \RuntimeException('Stock product is not yet mapped.'); }
                $this->stockApplying = true;
                if (!empty($payload['set_mode'])) {
                    $previous = Protocol::quantity($payload['previous'] ?? null);
                    $stored = $map['quantity'] === null ? null : (int) $map['quantity'];
                    if ($stored !== $previous) { throw new \RuntimeException('Inventory mode conflict; review the current count.'); }
                    $quantity = Protocol::quantity($payload['quantity'] ?? null);
                    $this->adapter->stockSet($map, $quantity);
                    $this->sql('UPDATE {b}map SET quantity=?,stock_initialized=1 WHERE record_key=?', [$quantity, $key]);
                } else {
                    if ($map['quantity'] === null) { throw new \RuntimeException('Stock quantity is unknown.'); }
                    $delta = Protocol::quantity($payload['delta'] ?? null);
                    if ($delta === null) { throw new \RuntimeException('Stock delta is missing.'); }
                    $this->adapter->stockDelta($map, $delta);
                    $this->sql('UPDATE {b}map SET quantity=quantity+? WHERE record_key=?', [$delta, $key]);
                }
            } else {
                $data = $payload['data'] ?? [];
                if (($data['key'] ?? '') !== $key) { throw new \RuntimeException('Payload identity mismatch.'); }
                $hash = $kind === 'product' ? self::catalogHash($data) : Protocol::fingerprint($data);
                if (($payload['hash'] ?? '') !== $hash) { throw new \RuntimeException('Payload hash mismatch.'); }
                if ($map && $map['fingerprint'] !== '' && $map['fingerprint'] !== ($payload['base'] ?? '') && $map['fingerprint'] !== $hash) {
                    $policy = $kind === 'product' ? ($this->config()['conflict_policy'] ?? 'review') : 'review';
                    if ($policy === $this->adapter->site()) {
                        $this->sql("UPDATE {b}queue SET state='ignored',error='Local catalog priority applied.' WHERE seq=?", [$event['seq']]);
                        $this->sql('COMMIT'); return;
                    }
                    if (!in_array($policy, ['woo','ps'], true)) {
                        $this->sql('ROLLBACK');
                        $this->sql("UPDATE {b}queue SET state='conflict',error='Both sites changed this record; review required.' WHERE seq=?", [$event['seq']]);
                        return;
                    }
                }
                if (!$map || $map['fingerprint'] !== $hash) {
                    if ($kind === 'product') {
                        $this->catalogApplying = true;
                        $id = $this->adapter->applyProduct($data, $map);
                    } else {
                        $this->orderApplying = true;
                        $id = $this->adapter->applyOrder($data, $map);
                    }
                    $this->bind($key, $kind, $id);
                    $native = $kind === 'product' ? $this->adapter->product($id) : $this->adapter->order($id);
                    $nativeHash = $kind === 'product' ? self::catalogHash($native) : Protocol::fingerprint($native);
                    $this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?', [$hash, $nativeHash, $key]);
                }
            }
            $this->sql("UPDATE {b}queue SET state='applied',error='' WHERE seq=?", [$event['seq']]);
            $this->sql('COMMIT');
        } catch (\Throwable $error) {
            $this->sql('ROLLBACK');
            $this->fail($event, $error);
        } finally {
            $this->catalogApplying = $this->orderApplying = $this->stockApplying = false;
            if ($stockLock !== null) { $this->sql('SELECT RELEASE_LOCK(?)', [$stockLock]); }
        }
    }

    private function fail(array $event, \Throwable $error): void
    {
        $attempts = (int) $event['attempts'] + 1;
        // Error text contains no payload, request headers, secrets or customer data.
        $message = substr(preg_replace('/[\r\n]+/', ' ', $error->getMessage()), 0, 255);
        $this->sql('UPDATE {b}queue SET attempts=?,next_try=?,state=?,error=? WHERE seq=?',
            [$attempts, time() + min(3600, 30 * (2 ** min(7, $attempts))), $attempts >= 8 ? 'failed' : 'pending', $message, $event['seq']]);
    }

    public function retry(): void
    {
        // Conflicts require reviewing and editing the source; they are never automatically overwritten.
        $this->sql("UPDATE {b}queue SET state='pending',attempts=0,next_try=0 WHERE state IN ('failed','pending')");
    }

    public function retryCatalogConflicts(): void
    {
        if (!in_array($this->config()['conflict_policy'] ?? '', ['woo','ps'], true)) { throw new \RuntimeException('Choose the catalog priority before retrying conflicts.'); }
        $this->sql("UPDATE {b}queue SET state='pending',attempts=0,next_try=0 WHERE state='conflict' AND kind='product'");
    }

    /** Latest transmitted snapshots, not a replacement for a live stock count. No customer data. */
    public function catalogAudit(): array
    {
        $events = $this->sql("SELECT q.record_key,q.payload FROM {b}queue q WHERE q.kind='product' AND NOT EXISTS (SELECT 1 FROM {b}queue n WHERE n.kind='product' AND n.record_key=q.record_key AND n.seq>q.seq) ORDER BY q.record_key LIMIT 200");
        $result = [];
        foreach ($events as $event) {
            $data = json_decode($event['payload'], true)['data'] ?? [];
            $stock = [];
            foreach ($data['inventory'] ?? [] as $item) {
                if (($data['type'] ?? '') === 'variable' && $item['key'] === $event['record_key'] && $item['quantity'] === null) { continue; }
                $stock[] = $item['key'] . ': ' . ($item['quantity'] === null ? 'unknown (' . ($item['status'] ?? '') . ')' : (string)$item['quantity']);
            }
            $prices = $data['prices'] ?? [];
            $map = $this->mapping($event['record_key']);
            $result[] = ['source' => $event['record_key'], 'local_id' => $map ? $map['local_id'] : 'not applied',
                'name' => $data['name'] ?? '', 'brands' => implode(', ', $data['brands'] ?? []), 'tags' => implode(', ', $data['tags'] ?? []), 'type' => $data['type'] ?? '',
                'regular' => $prices['regular'] ?? 'missing', 'sale' => $prices['sale'] ?? '',
                'tax' => $prices['tax_rate'] === null ? 'unknown' : (string)$prices['tax_rate'] . '%',
                'basis' => $prices['basis'] ?? '', 'initial_stock_snapshot' => implode('; ', $stock)];
        }
        return $result;
    }

    public function report(): array
    {
        return $this->sql('SELECT seq,direction,kind,record_key,state,attempts,error,created_at FROM {b}queue ORDER BY seq DESC LIMIT 100');
    }
}
