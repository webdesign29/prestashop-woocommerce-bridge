<?php
namespace WD29\Bridge;
require_once __DIR__.'/AdminContext.php';
final class ManualRecordsAdmin
{
    private static function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function handle(Engine $engine,array $input): ?string
    {
        $action=$input['bridge_action']??'';if($action==='manual_records_scan')return 'Comparaison actualisée.';
        if($action!=='manual_record_sync')return null;
        $r=$engine->manualRecordSync((string)($input['manual_kind']??''),(string)($input['manual_direction']??''),(string)($input['manual_key']??''),(string)($input['manual_hash']??''),(string)($input['manual_destination']??''));
        $target=$input['manual_direction']==='in'?$engine->adapter->site():($engine->adapter->site()==='woo'?'ps':'woo');
        return 'Fiche synchronisée sur '.AdminContext::name($target).' · #'.$r['local_id'].'. Mode automatique inchangé.'.(($r['ack']??'')==='pending'?' Confirmation source en attente ; actualisez avant de réessayer.':'');
    }
    public static function batch(Engine $engine,array $input): array
    {
        return $engine->manualRecordBatch((string)($input['manual_scope']??''),(string)($input['manual_direction']??''),(int)($input['manual_family']??0),(int)($input['manual_offset']??0),(int)($input['manual_index']??0));
    }
    public static function render(Engine $engine,string $token,array $input,string $batchUrl=''): string
    {
        $kind=in_array($input['manual_kind']??'',['product','order','customer'],true)?$input['manual_kind']:'product';$direction=($input['manual_direction']??'out')==='in'?'in':'out';$offset=max(0,min(10000000,(int)($input['manual_offset']??0)));
        $local=$engine->adapter->site();$source=$direction==='out'?$local:($local==='woo'?'ps':'woo');$target=$source==='woo'?'ps':'woo';$targetName=AdminContext::name($target);
        $hidden=static function(array $fields)use($token){$html=$token;foreach($fields as $key=>$value)$html.='<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';return $html;};
        $html='<h2>Comparer et synchroniser</h2><form method="post" class="wd-compare-controls">'.$token.'<label>Fiches <select name="manual_kind">';
        foreach(['product'=>'Produits, variantes et métadonnées','order'=>'Commandes','customer'=>'Contacts clients'] as $value=>$label)$html.='<option value="'.$value.'"'.($value===$kind?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><label>Source → destination <select name="manual_direction">';
        foreach(['woo','ps'] as $origin){$d=$origin===$local?'out':'in';$html.='<option value="'.$d.'"'.($origin===$source?' selected':'').'>'.self::e(AdminContext::name($origin).' → '.AdminContext::name($origin==='woo'?'ps':'woo')).'</option>';}
        $html.='</select></label><button name="bridge_action" value="manual_records_scan" class="button button-primary btn btn-primary">Comparer / actualiser</button></form>';
        $html.='<div class="wd-bulk"><form method="post" data-full-sync data-sync-endpoint="'.self::e($batchUrl).'">'.$hidden(['manual_direction'=>$direction,'manual_scope'=>$kind]).'<button type="button" data-sync-start disabled class="button btn btn-default">Synchroniser toute cette famille → '.self::e($targetName).'</button></form><form method="post" data-full-sync data-sync-endpoint="'.self::e($batchUrl).'">'.$hidden(['manual_direction'=>$direction,'manual_scope'=>'all']).'<button type="button" data-sync-start disabled class="button button-primary btn btn-primary">Synchronisation complète → '.self::e($targetName).'</button></form></div><details class="wd-sync-scope"><summary>Périmètre de la synchronisation et stocks</summary><p class="wd-manual-note">La synchronisation complète parcourt les produits, contacts puis commandes, uniquement dans le sens sélectionné. Les copies déjà identiques sont ignorées ; les conflits sont signalés sans écrasement. Les variantes, images et champs autorisés suivent leurs produits. Les comptes de connexion restent facultatifs. Les quantités des produits existants conservent leur suivi par événements de stock ; cette action ne force pas un inventaire.</p></details><noscript>JavaScript est nécessaire pour exécuter les lots complets. Les actions individuelles restent disponibles.</noscript><section id="wd-sync-progress" hidden aria-label="Progression de la synchronisation"><strong data-sync-status role="status"></strong><progress></progress><button type="button" data-sync-stop class="button btn btn-default">Arrêter après la fiche en cours</button><button type="button" data-sync-copy class="button btn btn-default">Copier le journal</button><pre data-sync-log></pre><p>Gardez cette page ouverte. Arrêter conserve les fiches déjà traitées ; relancer ignore celles déjà synchronisées.</p></section>';
        $active=in_array($input['bridge_action']??'',['manual_records_scan','manual_record_sync'],true)||($_GET['wd_view']??'')==='sync';if(!$active)return $html;
        try{$delta=$engine->manualRecordDelta($kind,$direction,$offset);}catch(\Throwable $e){return $html.'<p role="alert">Comparaison indisponible : '.self::e($e->getMessage()).' Vérifiez la connexion et la version des deux plugins.</p>';}
        $modes=['disabled'=>'arrêté','audit'=>'audit','live'=>'live'];$html.='<div class="wd-comparison-direction"><strong>'.self::e(AdminContext::name($source).' → '.$targetName).'</strong><small>Source : '.self::e($modes[$delta['mode']]??$delta['mode']).' · destination : '.self::e($modes[$delta['destination_mode']]??$delta['destination_mode']).' · lot '.((int)($offset/20)+1).'</small></div>';
        $counts=['missing'=>0,'changed'=>0,'same'=>0,'conflict'=>0,'unavailable'=>0];foreach($delta['rows'] as $row)$counts[$row['state']]++;
        $html.='<p>'.$counts['missing'].' à créer · '.$counts['changed'].' à mettre à jour · '.$counts['same'].' identique(s) · '.($counts['conflict']+$counts['unavailable']).' à examiner (ce lot).</p><label class="wd-difference-filter"><input type="checkbox" data-differences-only> Afficher uniquement les écarts</label><table class="table wd-order-comparison"><thead><tr><th class="wd-side-woo">WordPress / WooCommerce</th><th class="wd-side-ps">PrestaShop</th><th>Différences</th><th>Action</th></tr></thead><tbody>';
        $labels=['missing'=>'Copie absente','changed'=>'Modifications disponibles','same'=>'Déjà synchronisé','conflict'=>'Modification locale ou conflit à examiner','unavailable'=>'Source indisponible'];
        foreach($delta['rows'] as $row){$html.='<tr data-manual-record="'.self::e($row['key']).'" data-delta-state="'.self::e(!empty($row['stock_difference'])?'stock':$row['state']).'">';foreach(['woo','ps'] as $side){$original=$side===$source;$summary=$original?($row['summary']??null):($row['destination_summary']??null);$id=$original?substr($row['key'],strrpos($row['key'],':')+1):($row['local_id']??null);$html.='<td class="wd-order-side wd-side-'.$side.'"><span class="wd-origin wd-side-'.$side.'">'.($original?'Original':'Copie').'</span><strong>'.self::e(AdminContext::name($side)).($id?' · #'.self::e($id):' · absente').'</strong>';if($summary){$html.='<span>'.self::e($summary['label']??$summary['number']??'').'</span><small>'.self::e($summary['detail']??$summary['status_label']??$summary['status']??'').'</small>';if(isset($summary['total']))$html.='<span>'.self::e($summary['total'].' '.$summary['currency']).'</span>';}$html.='</td>';}
            $html.='<td>'.self::e($labels[$row['state']]).(!empty($row['stock_difference'])?'<small>Quantités différentes : consulter les événements de stock dans Activité.</small>':'').'<small>'.self::e(implode(', ',$row['state']==='same'?[]:($row['changes']??[]))).'</small>'.(isset($row['error'])?'<small>'.self::e($row['error']).'</small>':'').'<code class="wd-record-key">'.self::e($row['key']).'</code></td><td>';
            if(in_array($row['state'],['missing','changed'],true))$html.='<form method="post">'.$hidden(['manual_kind'=>$kind,'manual_direction'=>$direction,'manual_offset'=>$offset,'manual_key'=>$row['key'],'manual_hash'=>$row['hash'],'manual_destination'=>$row['destination']]).'<button name="bridge_action" value="manual_record_sync" class="button btn btn-primary">'.self::e(($row['state']==='missing'?'Créer sur ':'Mettre à jour sur ').$targetName).'</button></form>';
            $html.='</td></tr>';
        }
        $html.='</tbody></table>';if(!$delta['rows'])$html.='<p>Aucune fiche originale dans ce lot.</p>';
        foreach(['Précédent'=>$offset>0?max(0,$offset-20):null,'Suivant'=>$delta['next']] as $label=>$next)if($next!==null)$html.='<form method="post">'.$hidden(['manual_kind'=>$kind,'manual_direction'=>$direction,'manual_offset'=>$next]).'<button name="bridge_action" value="manual_records_scan" class="button btn btn-default">'.$label.'</button></form>';
        return $html;
    }
}
