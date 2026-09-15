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
            'deleted_products' => 'record_key varchar(96) NOT NULL, deleted_at bigint NOT NULL, PRIMARY KEY(record_key)',
            'account_links' => 'record_key varchar(96) NOT NULL, native_id bigint unsigned NOT NULL, PRIMARY KEY(record_key), UNIQUE KEY native_account(native_id)',
            'issues' => 'issue_key varchar(96) NOT NULL, code varchar(32) NOT NULL, first_seen bigint NOT NULL, last_seen bigint NOT NULL, occurrences int NOT NULL DEFAULT 1, PRIMARY KEY(issue_key)',
            'order_lines' => 'order_key varchar(96) NOT NULL, line_key varchar(96) NOT NULL, native_id bigint unsigned NOT NULL, PRIMARY KEY(order_key,line_key), UNIQUE KEY native_line(native_id)',
            'contacts' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, record_key varchar(96) NOT NULL, data longtext NOT NULL, PRIMARY KEY(id), UNIQUE KEY origin(record_key)',
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
        if (!in_array($kind, ['product', 'variant', 'order', 'customer'], true) || $id < 1) { throw new \RuntimeException('Invalid mapping.'); }
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
        $data['inventory']=$data['inventory']??[];
        foreach ($data['inventory'] as &$inventory) { $inventory['managed']=$inventory['quantity']!==null; unset($inventory['quantity']); }
        unset($inventory);
        return Protocol::fingerprint($data);
    }

    public function capture(string $kind, int $id): void
    {
        if (!$this->enabled() || ($kind === 'product' && $this->catalogApplying) || ($kind === 'order' && $this->orderApplying)) { return; }
        if ($kind==='product' && method_exists($this->adapter,'productExists')) {
            $known=$this->sql("SELECT * FROM {b}map WHERE kind='product' AND local_id=?",[$id])[0]??null;
            if ($known && $this->productDeleted($known['record_key'])) { return; }
            if ($known && strpos($known['record_key'],$this->adapter->site().':product:')===0 && !$this->adapter->productExists($id)) { $this->captureProductDeletion($known); return; }
        }
        $lock = 'wd29_capture_' . sha1($this->table . $kind . ':' . $id);
        $locked = false;
        try {
            $locked = (int) ($this->sql('SELECT GET_LOCK(?,5) AS acquired', [$lock])[0]['acquired'] ?? 0) === 1;
            if (!$locked) { throw new \RuntimeException('Record capture is busy.'); }
            $this->sql('START TRANSACTION');
            $data = $kind === 'customer' ? $this->customer($id) : ($kind === 'product' ? $this->adapter->product($id) : $this->adapter->order($id));
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
            $this->resolveIssue('capture:' . $kind . ':' . $id);
        } catch (\Throwable $error) {
            $this->sql('ROLLBACK');
            $this->recordIssue('capture:' . $kind . ':' . $id, 'capture_failed');
            $this->adapter->notice('Could not capture ' . $kind . ' #' . $id . ': ' . $error->getMessage());
        } finally {
            if ($locked) { $this->sql('SELECT RELEASE_LOCK(?)', [$lock]); }
        }
    }

    private function productDeleted(string $key): bool
    {
        return (bool)$this->sql('SELECT record_key FROM {b}deleted_products WHERE record_key=?',[$key]);
    }

    /** Missing originals are archived remotely from their last captured catalog, never reconstructed. */
    public function scanDeletedProducts(): void
    {
        if (!$this->enabled() || !method_exists($this->adapter,'productExists')) { return; }
        $offset=$this->adapter->scanOffset('known_products');
        $rows=$this->sql("SELECT * FROM {b}map WHERE kind='product' AND record_key LIKE ? ORDER BY record_key LIMIT ".max(0,(int)$offset).",10",[$this->adapter->site().':product:%']);
        foreach ($rows as $row) {
            if (!$this->productDeleted($row['record_key']) && !$this->adapter->productExists((int)$row['local_id'])) { $this->captureProductDeletion($row); }
        }
        $this->adapter->saveScanOffset('known_products',count($rows)<10?0:$offset+10);
    }

    private function captureProductDeletion(array $map): void
    {
        $key=$map['record_key'];
        if (strpos($key,$this->adapter->site().':product:')!==0 || $this->productDeleted($key)) { return; }
        $lock='wd29_capture_'.sha1($this->table.'product:'.$map['local_id']);
        if ((int)($this->sql('SELECT GET_LOCK(?,5) AS acquired',[$lock])[0]['acquired']??0)!==1) { $this->recordIssue('deletion:'.$map['local_id'],'deletion_busy'); return; }
        try {
            if ($this->productDeleted($key) || $this->adapter->productExists((int)$map['local_id'])) { return; }
            $snapshot=$this->sql("SELECT payload FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=? ORDER BY seq DESC LIMIT 1",[$key])[0]??null;
            $payload=$snapshot?json_decode($snapshot['payload'],true):null; $data=$payload['data']??null;
            if (!is_array($data) || ($data['key']??'')!==$key || ($payload['hash']??'')!==self::catalogHash($data)) {
                $this->recordIssue('deletion:'.$map['local_id'],'deletion_snapshot_missing'); return;
            }
            $data['archived']=true; $data['source_deleted']=true; $hash=self::catalogHash($data);
            $this->sql('START TRANSACTION');
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by original product deletion.' WHERE direction='out' AND kind='product' AND record_key=? AND state IN ('pending','failed','conflict')",[$key]);
            $this->enqueue('out','product',$key,['base'=>$map['fingerprint'],'hash'=>$hash,'data'=>$data]);
            $this->sql('INSERT IGNORE INTO {b}deleted_products (record_key,deleted_at) VALUES (?,?)',[$key,time()]);
            $this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?',[$hash,$hash,$key]);
            $this->sql('COMMIT');
            $this->resolveIssue('deletion:'.$map['local_id']); $this->resolveIssue('capture:product:'.$map['local_id']);
        } catch (\Throwable $error) {
            $this->sql('ROLLBACK'); $this->recordIssue('deletion:'.$map['local_id'],'deletion_failed');
        } finally { $this->sql('SELECT RELEASE_LOCK(?)',[$lock]); }
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
        if (!in_array($direction, ['in','out'], true) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !in_array($kind, ['product', 'stock', 'order', 'customer'], true)) {
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
            return ['ok' => true, 'protocol' => Protocol::VERSION, 'platform' => $this->adapter->site(), 'mode' => $this->config()['mode'], 'worker'=>$this->adapter->workerStatus(), 'diagnostics'=>$this->diagnostics()];
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
            $kind = in_array($message['kind']??'', ['order','customer'],true) ? $message['kind'] : 'product';
            return ['ok' => true, 'count' => $this->seed($kind, $offset)];
        }
        throw new \RuntimeException('Unknown operation.');
    }

    public function seed(string $kind, int $offset = 0): int
    {
        $ids = $this->adapter->ids($kind, $offset, 10);
        foreach ($ids as $id) {
            if ($kind==='order' && ($this->config()['mode']??'')==='live') { $this->adapter->reconcileOrderLinks((int)$id); }
            $this->capture($kind, (int) $id);
            if ($kind==='order') { $key=$this->adapter->orderContact((int)$id); if ($key) { $this->capture('customer',$this->contactId($key)); } }
        }
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
        $workerOutcome='failed'; $this->adapter->workerStatus('running');
        try {
            $this->scanDeletedProducts();
            // Reconcile bounded pages as well as hooks: recovers missed callbacks and scheduled prices.
            foreach (['product','order','customer'] as $kind) {
                $offset=$this->adapter->scanOffset($kind);
                $count=$this->seed($kind,$offset);
                $this->adapter->saveScanOffset($kind,$count<10?0:$offset+10);
            }
            $offset=$this->adapter->scanOffset('known_contacts');
            $contacts=$this->sql("SELECT id FROM {b}contacts WHERE record_key LIKE ? ORDER BY id LIMIT ".(int)$offset.",10",[$this->adapter->site().':%']);
            foreach ($contacts as $contact) { $this->capture('customer',(int)$contact['id']); }
            $this->adapter->saveScanOffset('known_contacts',count($contacts)<10?0:$offset+10);
            if (($this->config()['mode'] ?? '') === 'live') {
                $this->supersedeDeletedCatalog();
                $this->supersedeUnmappedCatalog();
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
                } catch (\Throwable $error) { $this->fail($event, $error); $workerOutcome='delivery_error'; break; }
            }
            if ($workerOutcome==='failed') { $workerOutcome='completed'; }
        } finally {
            $this->adapter->workerStatus($workerOutcome);
            $this->sql('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        if ($wakePeer) {
            try { $this->peer(['op' => 'tick']); $this->resolveIssue('peer'); }
            catch (\Throwable $error) { $this->recordIssue('peer', 'peer_unreachable'); $this->adapter->notice($error->getMessage()); }
        }
    }

    private function ready(string $direction): array
    {
        return $this->sql("SELECT q.* FROM {b}queue q WHERE q.direction=? AND q.state='pending' AND q.next_try<=? AND NOT EXISTS (SELECT 1 FROM {b}queue older WHERE older.direction=q.direction AND older.record_key=q.record_key AND older.seq<q.seq AND older.state IN ('pending','failed','conflict')) ORDER BY q.seq LIMIT 10", [$direction, time()]);
    }

    private function apply(array $event): void
    {
        $key = $event['record_key'];
        $kind = $event['kind'];
        $stockLock = null;
        $this->sql('START TRANSACTION');
        try {
            $payload = json_decode($event['payload'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) { throw new \RuntimeException('Invalid event payload.'); }
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
                    if ($stored !== $previous && $stored !== Protocol::quantity($payload['quantity'] ?? null)) { throw new \RuntimeException('Inventory mode conflict; review the current count.'); }
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
                if ($kind==='product') {
                    $sourceDeleted=$data['source_deleted']??false;
                    if (!is_bool($sourceDeleted)) { throw new \RuntimeException('Invalid source deletion marker.'); }
                    if ($sourceDeleted) {
                        if (strpos($key,($this->adapter->site()==='woo'?'ps':'woo').':product:')!==0 || empty($data['archived'])) { throw new \RuntimeException('Only the original product source can declare permanent deletion.'); }
                        if (!$this->productDeleted($key)) {
                            if ($map) {
                                if (!method_exists($this->adapter,'productExists') || !method_exists($this->adapter,'archiveProduct')) { throw new \RuntimeException('Product archiving adapter is unavailable.'); }
                                if ($this->adapter->productExists((int)$map['local_id'])) {
                                    $this->catalogApplying=true; $this->adapter->archiveProduct((int)$map['local_id']);
                                }
                            }
                            $this->sql('INSERT IGNORE INTO {b}deleted_products (record_key,deleted_at) VALUES (?,?)',[$key,time()]);
                            if ($map) { $this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?',[$hash,$hash,$key]); }
                        }
                        $this->sql("UPDATE {b}queue SET state='applied',error='' WHERE seq=?",[$event['seq']]);
                        $this->sql('COMMIT'); return;
                    }
                    if ($this->productDeleted($key) || ($map && strpos($key,$this->adapter->site().':product:')===0 && method_exists($this->adapter,'productExists') && !$this->adapter->productExists((int)$map['local_id']))) {
                        $this->sql("UPDATE {b}queue SET state='ignored',error='Original product was permanently deleted; stale catalog update ignored.' WHERE seq=?",[$event['seq']]);
                        $this->sql('COMMIT');
                        if ($map && strpos($key,$this->adapter->site().':product:')===0 && !$this->productDeleted($key)) { $this->captureProductDeletion($map); }
                        return;
                    }
                }
                if ($map && $map['fingerprint'] !== '' && $map['fingerprint'] !== ($payload['base'] ?? '') && $map['fingerprint'] !== $hash && !($kind==='order' && $this->equivalentOrderAugmentation($data,$map))) {
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
                    } elseif ($kind === 'customer') {
                        $id = $this->applyCustomer($data);
                    } else {
                        $this->orderApplying = true;
                        $id = $this->adapter->applyOrder($data, $map);
                    }
                    $this->bind($key, $kind, $id);
                    $native = $kind === 'customer' ? $this->customer($id) : ($kind === 'product' ? $this->adapter->product($id) : $this->adapter->order($id));
                    $nativeHash = $kind === 'product' ? self::catalogHash($native) : Protocol::fingerprint($native);
                    $this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?', [$hash, $nativeHash, $key]);
                }
            }
            $this->sql("UPDATE {b}queue SET state='applied',error='' WHERE seq=?", [$event['seq']]);
            $this->sql('COMMIT');
        } catch (\Throwable $error) {
            $this->sql('ROLLBACK');
            if (method_exists($this->adapter,'afterRollback')) { $this->adapter->afterRollback($kind,(int)($map['local_id']??0)); }
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

    private function supersedeDeletedCatalog(): void
    {
        // An authoritative deletion must not wait forever behind an invalid/conflicting
        // older catalog snapshot. Stock deltas retain their own ordering and are untouched.
        $rows=$this->sql("SELECT * FROM {b}queue WHERE direction='in' AND kind='product' AND state='pending' AND payload LIKE ? ORDER BY seq DESC LIMIT 100",['%"source_deleted":true%']);
        foreach ($rows as $row) {
            $payload=json_decode($row['payload'],true); $data=$payload['data']??[];
            if (($data['source_deleted']??false)!==true || empty($data['archived']) || ($data['key']??'')!==$row['record_key'] || strpos($row['record_key'],($this->adapter->site()==='woo'?'ps':'woo').':product:')!==0 || ($payload['hash']??'')!==self::catalogHash($data)) { continue; }
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by original product deletion.' WHERE direction='in' AND kind='product' AND record_key=? AND seq<? AND state IN ('pending','failed','conflict')",[$row['record_key'],(int)$row['seq']]);
        }
    }

    private function supersedeUnmappedCatalog(): void
    {
        // Initial imports use the newest received full snapshot. Mapped stock is never rebased.
        $rows=$this->sql("SELECT q.* FROM {b}queue q JOIN (SELECT record_key,MAX(seq) seq FROM {b}queue WHERE direction='in' AND kind='product' AND state IN ('pending','failed') GROUP BY record_key) newest ON newest.seq=q.seq LEFT JOIN {b}map m ON m.record_key=q.record_key WHERE m.record_key IS NULL LIMIT 200");
        foreach ($rows as $row) {
            $payload=json_decode($row['payload'],true); $data=$payload['data']??[];
            if (($data['key']??'')!==$row['record_key'] || ($payload['hash']??'')!==self::catalogHash($data)) { continue; }
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by corrected initial catalog snapshot.' WHERE direction='in' AND kind='product' AND record_key=? AND seq<? AND state IN ('pending','failed','conflict')",[$row['record_key'],(int)$row['seq']]);
            foreach ($data['inventory']??[] as $item) {
                if ($this->mapping($item['key'])) { continue; }
                $this->sql("UPDATE {b}queue SET state='ignored',error='Included in initial stock snapshot.' WHERE direction='in' AND kind='stock' AND record_key=? AND seq<? AND state IN ('pending','failed')",[$item['key'],(int)$row['seq']]);
            }
        }
    }

    public function retry(): void
    {
        $this->supersedeUnmappedCatalog();
        // Conflicts require reviewing and editing the source; they are never automatically overwritten.
        $this->sql("UPDATE {b}queue SET state='pending',attempts=0,next_try=0 WHERE state IN ('failed','pending')");
    }

    /** Only empty new fields / source line identity additions can bypass an upgrade conflict. */
    private function equivalentOrderAugmentation(array $data, array $map): bool
    {
        if (!class_exists(OrderConflicts::class) || !method_exists($this->adapter,'orderConflictSnapshot')) { return false; }
        try {
            return OrderConflicts::equivalentAugmentation($this->adapter->orderConflictSnapshot((int)$map['local_id']),$data);
        } catch (\Throwable $error) { return false; }
    }

    /** Operational comparison without customer details or custom-field values. */
    public function orderConflictReport(): array
    {
        $rows=[];
        foreach ($this->sql("SELECT seq,record_key,payload FROM {b}queue WHERE direction='in' AND kind='order' AND state='conflict' ORDER BY seq LIMIT 100") as $event) {
            $payload=json_decode($event['payload'],true); $data=$payload['data']??null; $map=$this->mapping($event['record_key']);
            $row=['event'=>(int)$event['seq'],'order'=>$event['record_key'],'local'=>'Unavailable','incoming'=>'Unavailable','resolution'=>'Manual review required'];
            if (is_array($data)) {
                $summary=function(array $d): string { return (string)($d['total']??'?').' '.(string)($d['currency']??'?').' / '.(string)($d['status']??'?').' / '.count($d['items']??[]).' lines'; };
                $row['incoming']=$summary($data);
                try {
                    if (!$map) { throw new \RuntimeException('Missing mapping.'); }
                    $native=$this->adapter->orderConflictSnapshot((int)$map['local_id']); $row['local']=$summary($native);
                    if (($data['key']??'')===$event['record_key'] && ($payload['hash']??'')===Protocol::fingerprint($data) && $this->equivalentOrderAugmentation($data,$map)) { $row['resolution']='Equivalent technical upgrade; safe retry available'; }
                    else {
                        $different=[];
                        foreach (['status','source_status','total','currency','items','shipping_net','shipping_tax','discount','tax','refunds','custom_fields'] as $field) {
                            if (json_encode($native[$field]??null)!==json_encode($data[$field]??null)) { $different[]=$field; }
                        }
                        $row['resolution']='Review differences: '.($different?implode(', ',$different):'other order details');
                    }
                } catch (\Throwable $error) { $row['resolution']='Native order differs from mirror snapshot or is unavailable; review on originating store'; }
            }
            $rows[]=$row;
        }
        return $rows;
    }

    public function retryEquivalentOrderConflicts(): int
    {
        $count=0;
        foreach ($this->sql("SELECT seq,record_key,payload FROM {b}queue WHERE direction='in' AND kind='order' AND state='conflict' ORDER BY seq LIMIT 100") as $event) {
            $payload=json_decode($event['payload'],true); $data=$payload['data']??null; $map=$this->mapping($event['record_key']);
            if (!is_array($data) || !$map || ($data['key']??'')!==$event['record_key'] || ($payload['hash']??'')!==Protocol::fingerprint($data)) { continue; }
            if (!$this->equivalentOrderAugmentation($data,$map)) { continue; }
            // Application repeats the native guard within its transaction before accepting it.
            $this->sql("UPDATE {b}queue SET state='pending',attempts=0,next_try=0,error='' WHERE seq=? AND state='conflict'",[$event['seq']]); $count++;
        }
        return $count;
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
                'basis' => $prices['basis'] ?? '', 'initial_stock_snapshot' => implode('; ', $stock),
                'identifiers'=>json_encode($data['identifiers']??[],JSON_UNESCAPED_UNICODE),
                'dimensions_cm'=>json_encode($data['dimensions_cm']??[],JSON_UNESCAPED_UNICODE),
                'features'=>json_encode(array_values(array_filter($data['attributes']??[],function($a){return empty($a['variation']);})),JSON_UNESCAPED_UNICODE),
                'variant_images'=>array_sum(array_map(function($v){return count($v['images']??[]);},$data['variants']??[])),
                'archived'=>!empty($data['archived'])?'yes':'no','purchase_price_net'=>$data['purchase_price_net']??'unknown',
                'supplier'=>json_encode($data['supplier']??[],JSON_UNESCAPED_UNICODE),'seo'=>json_encode($data['seo']??[],JSON_UNESCAPED_UNICODE)];
        }
        return $result;
    }

    public function contactId(string $key): int
    {
        Protocol::validateKey($key);
        if (!preg_match('/^(woo|ps):(customer|guest):[1-9][0-9]*$/D',$key)) { throw new \RuntimeException('Invalid contact identity.'); }
        $this->sql('INSERT IGNORE INTO {b}contacts (record_key,data) VALUES (?,?)',[$key,'{}']);
        $id=(int)$this->sql('SELECT id FROM {b}contacts WHERE record_key=?',[$key])[0]['id'];
        $this->bind($key,'customer',$id); return $id;
    }

    public function customer(int $id): array
    {
        $row=$this->sql('SELECT * FROM {b}contacts WHERE id=?',[$id])[0]??null;
        if (!$row) { throw new \RuntimeException('Contact is missing.'); }
        $key=$row['record_key']; $parts=explode(':',$key);
        $data=$parts[0]===$this->adapter->site() ? $this->adapter->contactProfile($parts[1],(int)$parts[2]) : json_decode($row['data'],true);
        $data['key']=$key; $data=$this->contactData($data);
        $this->sql('UPDATE {b}contacts SET data=? WHERE id=?',[Protocol::encode($data),$id]);
        return $data;
    }

    private function contactData(array $data): array
    {
        foreach (['first_name','last_name','email','phone','company'] as $field) {
            if (!is_string($data[$field]??null) || strlen($data[$field])>1000) { throw new \RuntimeException('Invalid contact field.'); }
        }
        if (!is_array($data['billing']??null) || !is_array($data['shipping']??null)) { throw new \RuntimeException('Invalid contact address.'); }
        // Contact records contain no credentials, roles, payment tokens or marketing consents.
        $clean=array_intersect_key($data,array_flip(['key','first_name','last_name','email','phone','company','billing','shipping','guest','deleted']));
        $allowed=array_flip(['first_name','last_name','company','address_1','address_2','city','postcode','country','state','phone','email']);
        foreach (['billing','shipping'] as $field) {
            $clean[$field]=array_intersect_key($data[$field],$allowed);
            foreach ($clean[$field] as $value) { if (!is_string($value) || strlen($value)>1000) { throw new \RuntimeException('Invalid contact address value.'); } }
        }
        if (isset($data['addresses'])) {
            if (!is_array($data['addresses']) || count($data['addresses'])>100) { throw new \RuntimeException('Invalid address book.'); }
            $clean['addresses']=[];
            foreach ($data['addresses'] as $address) {
                if (!is_array($address)) { throw new \RuntimeException('Invalid address book entry.'); }
                $entry=array_intersect_key($address,$allowed+['id'=>true,'label'=>true]);
                foreach ($entry as $value) { if (!is_string($value)||strlen($value)>1000) { throw new \RuntimeException('Invalid address book value.'); } }
                $clean['addresses'][]=$entry;
            }
        }
        $clean['guest']=(bool)($data['guest']??false); $clean['deleted']=(bool)($data['deleted']??false);
        if (array_key_exists('custom_fields',$data)) {
            $fields=$data['custom_fields'];
            if (!is_array($fields) || count($fields)>50) { throw new \RuntimeException('Invalid contact custom fields.'); }
            foreach ($fields as $id=>$entry) {
                if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$id) || !is_array($entry) || !is_bool($entry['present']??null) || !array_key_exists('value',$entry) || count($entry)!==2) { throw new \RuntimeException('Invalid contact custom field envelope.'); }
                self::customValue($entry['value']);
            }
            $clean['custom_fields']=$clean['deleted']?[]:$fields;
        }
        return $clean;
    }

    private static function customValue($value,int $depth=0): void
    {
        if ($depth>8 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) { throw new \RuntimeException('Custom fields require bounded JSON values.'); }
        if (is_array($value)) {
            if (count($value)>500) { throw new \RuntimeException('Custom field array too large.'); }
            foreach ($value as $item) { self::customValue($item,$depth+1); }
        }
        if ($depth===0 && strlen(json_encode($value,JSON_THROW_ON_ERROR))>65536) { throw new \RuntimeException('Custom field exceeds 64 KiB.'); }
    }

    private function applyCustomer(array $data): int
    {
        if (strpos($data['key'],$this->adapter->site().':')===0) { throw new \RuntimeException('Contact details are edited on their originating store.'); }
        $clean=$this->contactData($data); $id=$this->contactId($data['key']);
        $this->sql('UPDATE {b}contacts SET data=? WHERE id=?',[Protocol::encode($clean),$id]);
        if (!empty($this->config()['native_customers']) && !$clean['guest'] && !$clean['deleted'] && method_exists($this->adapter,'applyCustomerAccount')) { $this->adapter->applyCustomerAccount($clean); }
        return $id;
    }

    public function customerReport(): array
    {
        $rows=$this->sql('SELECT record_key,data FROM {b}contacts ORDER BY record_key LIMIT 200'); $out=[];
        foreach ($rows as $row) {
            $d=json_decode($row['data'],true); if (empty($d['key'])) { continue; }
            $billing=$d['billing']??[];
            $out[]=['source'=>$row['record_key'],'name'=>trim(($d['first_name']??'').' '.($d['last_name']??'')),
                'email'=>$d['email']??'','phone'=>$d['phone']??'','company'=>$d['company']??'',
                'billing'=>implode(', ',array_filter(array_map(function($k)use($billing){return $billing[$k]??'';},['address_1','address_2','postcode','city','country']))),
                'addresses'=>count($d['addresses']??[]),
                'shipping'=>implode(', ',array_filter(array_map(function($k)use($d){return $d['shipping'][$k]??'';},['address_1','address_2','postcode','city','country']))),
                'type'=>!empty($d['deleted'])?'deleted':(!empty($d['guest'])?'guest contact':'customer contact')];
        }
        return $out;
    }

    public function orderReport(): array
    {
        $out=[];
        foreach ($this->sql("SELECT record_key,local_id FROM {b}map WHERE kind='order' ORDER BY record_key LIMIT 200") as $row) {
            try { $out[]=['source'=>$row['record_key']]+$this->adapter->orderSummary((int)$row['local_id']); }
            catch (\Throwable $e) { $out[]=['source'=>$row['record_key'],'local_id'=>$row['local_id'],'total'=>'unavailable','currency'=>'','status'=>'review','lines'=>'','unlinked_lines'=>'']; }
        }
        return $out;
    }

    /** Operational issues contain only internal identities and codes, never payloads or credentials. */
    private function recordIssue(string $key, string $code): void
    {
        $this->sql('INSERT INTO {b}issues (issue_key,code,first_seen,last_seen) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code),last_seen=VALUES(last_seen),occurrences=occurrences+1', [$key,$code,time(),time()]);
    }

    private function resolveIssue(string $key): void { $this->sql('DELETE FROM {b}issues WHERE issue_key=?',[$key]); }

    /** Machine-readable, payload-free report suitable for local monitoring and signed health checks. */
    public function diagnostics(?int $now=null): array
    {
        $now=$now??time(); $worker=$this->adapter->workerStatus(); $at=strtotime($worker['at']??'');
        $workerAge=$at===false?null:max(0,$now-$at); $mode=$this->config()['mode']??'disabled'; $issues=[];
        if ($mode!=='disabled' && ($workerAge===null || $workerAge>300)) {
            $issues[]=['code'=>'worker_stale','severity'=>'error','action'=>'Run the server worker every minute; check its exit status and PHP/database availability.'];
        } elseif (($worker['state']??'')==='failed' || ($worker['state']??'')==='delivery_error') {
            $issues[]=['code'=>'worker_failed','severity'=>'error','action'=>'Inspect failed/retrying events and server logs, then run the worker again.'];
        }
        $queue=$this->sql("SELECT direction,state,COUNT(*) total,MIN(created_at) oldest_at,MIN(next_try) next_try,SUM(attempts>0) retried FROM {b}queue WHERE state IN ('pending','failed','conflict') GROUP BY direction,state");
        foreach ($queue as &$group) {
            $group['total']=(int)$group['total']; $group['retried']=(int)$group['retried'];
            $group['oldest_age_seconds']=max(0,$now-(strtotime($group['oldest_at'].' UTC')?:$now));
            $group['next_retry_in_seconds']=max(0,(int)$group['next_try']-$now);
            unset($group['oldest_at'],$group['next_try']);
            if ($group['state']==='conflict') { $code='record_conflict'; $action='Review both versions; select catalog priority only for products, then retry the reviewed conflict.'; }
            elseif ($group['state']==='failed') { $code='retry_exhausted'; $action='Correct the event error in the journal, then retry. Later events for the same record remain blocked.'; }
            elseif ($group['retried']>0) { $code='event_retrying'; $action='Check the journal and peer health. Automatic exponential retries stop after eight attempts.'; }
            elseif ($group['oldest_age_seconds']>300 && !($mode==='audit' && $group['direction']==='in')) { $code='queue_delayed'; $action='Check both workers and earlier failed/conflicting events for this record; allow further bounded batches to run.'; }
            else { continue; }
            $issues[]=['code'=>$code,'severity'=>$group['state']==='pending'?'warning':'error','direction'=>$group['direction'],'count'=>$group['total'],'action'=>$action];
        }
        unset($group);
        $active=$this->sql('SELECT issue_key,code,first_seen,last_seen,occurrences FROM {b}issues ORDER BY first_seen LIMIT 100');
        foreach ($active as $issue) {
            $issues[]=['code'=>$issue['code'],'severity'=>'error','record'=>$issue['issue_key'],'age_seconds'=>max(0,$now-(int)$issue['first_seen']),'occurrences'=>(int)$issue['occurrences'],
                'action'=>strpos($issue['code'],'deletion_')===0?'A source product is missing. Review its last captured catalog snapshot and database availability; keep the original mapping. The bridge will retry archiving and will not recreate the source.':($issue['code']==='capture_failed'?'Inspect this record and the capture notice; correct its unsupported or invalid fields. A successful recapture clears this issue.':'Check peer HTTPS availability and shared configuration; a successful peer wake clears this issue.')];
        }
        return ['ok'=>!$issues,'mode'=>$mode,'worker_age_seconds'=>$workerAge,'queue'=>$queue,'issues'=>$issues];
    }

    public function report(): array
    {
        return $this->sql('SELECT seq,direction,kind,record_key,state,attempts,error,created_at FROM {b}queue ORDER BY seq DESC LIMIT 100');
    }
}
