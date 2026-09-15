<?php
namespace WD29\Bridge;
final class DiagnosticsAdmin
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
    public static function render(Engine $engine): string
    {
        $d=$engine->diagnostics();
        $html='<h2>Operational diagnostics</h2><p>'.($d['ok']?'No actionable issue detected.':'Action is required; see the issues below.').'</p><table class="widefat table"><tr><th>Issue</th><th>Record / direction</th><th>Action</th></tr>';
        foreach ($d['issues'] as $row) { $html.='<tr><td>'.self::e($row['code']).'</td><td>'.self::e($row['record']??$row['direction']??'worker').'</td><td>'.self::e($row['action']).'</td></tr>'; }
        $html.='</table><p>Use the bundled server worker every minute. Health exit status is suitable for your hosting monitor. Stock synchronization is asynchronous: simultaneous last-unit sales need a shared reservation service.</p>';
        $html.='<h2>Refund / credit-note records</h2><p>Source-owned audit records only. No second payment, stock restoration or fiscal document is created.</p><table class="widefat table"><tr><th>Order</th><th>Refund identity</th><th>Amount</th><th>Currency</th></tr>';
        foreach ($engine->sql("SELECT record_key,local_id FROM {b}map WHERE kind='order' ORDER BY record_key LIMIT 200") as $row) {
            try { $order=$engine->adapter->order((int)$row['local_id']); }
            catch (\Throwable $e) { continue; }
            foreach ($order['refunds']??[] as $refund) { $html.='<tr><td>'.self::e($row['record_key']).'</td><td>'.self::e($refund['source_id']??'').'</td><td>'.self::e($refund['amount']??'Unknown legacy tax basis').'</td><td>'.self::e($order['currency']).'</td></tr>'; }
        }
        $html.='</table><h2>Native customer account links</h2><p>Optional source-owned accounts use independent passwords. Existing email collisions require manual review. Guests, passwords and marketing consent are never migrated.</p><table class="widefat table"><tr><th>Source customer</th><th>Local account ID</th></tr>';
        foreach ($engine->sql('SELECT record_key,native_id FROM {b}account_links ORDER BY record_key LIMIT 200') as $row) { $html.='<tr><td>'.self::e($row['record_key']).'</td><td>'.self::e($row['native_id']).'</td></tr>'; }
        return $html.'</table>';
    }
}
