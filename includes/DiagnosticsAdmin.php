<?php
namespace WD29\Bridge;
final class DiagnosticsAdmin
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
    public static function render(Engine $engine): string
    {
        $d=$engine->diagnostics();
        $html='<h2>Diagnostics</h2><p>'.($d['ok']?'Aucun point à traiter.':'Une action est nécessaire : voir les points ci-dessous.').'</p><table class="widefat table"><tr><th>Code</th><th>Fiche / sens</th><th>Que faire</th></tr>';
        foreach ($d['issues'] as $row) { $html.='<tr><td>'.self::e($row['code']).'</td><td>'.self::e($row['record']??$row['direction']??'worker').'</td><td>'.self::e($row['action']).'</td></tr>'; }
        $html.='</table><p>Lancez le worker fourni chaque minute. Le code de sortie de la commande health se branche sur la supervision de l\'hébergement. Les stocks se synchronisent de façon asynchrone : deux ventes simultanées du dernier article demandent un service de réservation commun.</p>';
        $html.='<h2>Conflits de commandes</h2><p>« Retry equivalent order updates » n\'accepte que de nouveaux champs vides ou des identités de lignes quand les valeurs natives concordent encore. Les autres écarts restent en pause.</p><table class="widefat table"><tr><th>Événement</th><th>Commande</th><th>Total / statut / lignes ici</th><th>Total / statut / lignes reçus</th><th>Résolution</th></tr>';
        foreach ($engine->orderConflictReport() as $row) { $html.='<tr>'; foreach ($row as $value) { $html.='<td>'.self::e($value).'</td>'; } $html.='</tr>'; }
        $html.='</table>';
        $html.='<h2>Remboursements & avoirs</h2><p>Copies pour consultation, issues de la boutique d\'origine. Aucun second paiement, remise en stock ni document fiscal n\'est créé.</p><table class="widefat table"><tr><th>Commande</th><th>Remboursement</th><th>Montant</th><th>Devise</th></tr>';
        foreach ($engine->sql("SELECT record_key,local_id FROM {b}map WHERE kind='order' ORDER BY record_key LIMIT 200") as $row) {
            try { $order=$engine->adapter->order((int)$row['local_id']); }
            catch (\Throwable $e) { continue; }
            foreach ($order['refunds']??[] as $refund) { $html.='<tr><td>'.self::e($row['record_key']).'</td><td>'.self::e($refund['source_id']??'').'</td><td>'.self::e($refund['amount']??'Base fiscale ancienne inconnue').'</td><td>'.self::e($order['currency']).'</td></tr>'; }
        }
        $html.='</table><h2>Comptes clients liés</h2><p>Les comptes natifs facultatifs ont leur propre mot de passe. Une adresse e-mail déjà utilisée demande un examen manuel. Invités, mots de passe et consentements marketing ne sont jamais copiés.</p><table class="widefat table"><tr><th>Client source</th><th>Compte local (ID)</th></tr>';
        foreach ($engine->sql('SELECT record_key,native_id FROM {b}account_links ORDER BY record_key LIMIT 200') as $row) { $html.='<tr><td>'.self::e($row['record_key']).'</td><td>'.self::e($row['native_id']).'</td></tr>'; }
        return $html.'</table>';
    }
}
