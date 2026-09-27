<?php
namespace WD29\Bridge;
final class ManualOrdersAdmin
{
    private static function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function handle(Engine $engine,array $input): ?string
    {
        $action=$input['bridge_action']??'';
        if(!in_array($action,['manual_orders_scan','manual_order_sync'],true)){return null;}
        if($action==='manual_orders_scan'){return 'Comparaison des commandes actualisée.';}
        $r=$engine->manualOrderSync((string)($input['manual_direction']??'in'),(string)($input['manual_key']??''),(string)($input['manual_hash']??''),(string)($input['manual_destination']??''));
        return 'Commande synchronisée · ID sur la destination : '.$r['local_id'].'. Le mode automatique est inchangé.'.(($r['ack']??'')==='pending'?' Confirmation côté source en attente ; actualisez avant de réessayer.':'');
    }
    public static function render(Engine $engine,string $token,array $input): string
    {
        $direction=($input['manual_direction']??'in')==='out'?'out':'in';$offset=max(0,min(10000000,(int)($input['manual_offset']??0)));
        $local=$engine->adapter->site()==='woo'?'WordPress':'PrestaShop';$remote=$local==='WordPress'?'PrestaShop':'WordPress';
        $hidden=static function(array $values)use($token){$s=$token;foreach($values as $k=>$v){$s.='<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';}return $s;};
        $html='<h2>Commandes à synchroniser</h2><p>Comparez les commandes originales avec leur copie sur l’autre boutique, y compris celles passées pendant un arrêt. La comparaison ne modifie aucune commande. Les brouillons de checkout sont exclus.</p><p><strong>Synchronisation manuelle :</strong> applique uniquement la commande choisie et ses coordonnées, même en mode arrêté ou audit, sans réactiver le suivi automatique. Le catalogue et les autres commandes restent inchangés. Une connexion configurée et une licence autorisant les écritures sur la destination sont nécessaires.</p><div class="wd-manual-directions">';
        foreach(['in'=>$remote.' → '.$local,'out'=>$local.' → '.$remote] as $d=>$label){$html.='<form method="post">'.$hidden(['manual_direction'=>$d,'manual_offset'=>0]).'<button class="button btn btn-default" name="bridge_action" value="manual_orders_scan">Comparer '.self::e($label).'</button></form>';}$html.='</div>';
        if(!in_array($input['bridge_action']??'',['manual_orders_scan','manual_order_sync'],true)){return $html;}
        try{$delta=$engine->manualOrderDelta($direction,$offset);}catch(\Throwable $e){return $html.'<p role="alert">Comparaison indisponible : '.self::e($e->getMessage()).' Vérifiez la connexion des deux plugins et leur mise à jour.</p>';}
        $modes=['disabled'=>'arrêté','audit'=>'audit','live'=>'live'];$source=$direction==='in'?$remote:$local;$target=$direction==='in'?$local:$remote;
        $html.='<p><strong>'.self::e($source.' → '.$target).'</strong> · source : '.self::e($modes[$delta['mode']]??$delta['mode']).' · destination : '.self::e($modes[$delta['destination_mode']]??$delta['destination_mode']).' · lot '.(int)($offset/20+1).'</p>';
        $counts=['missing'=>0,'changed'=>0,'same'=>0,'conflict'=>0];foreach($delta['rows'] as $r){$counts[$r['state']]++;}
        $html.='<p>'.$counts['missing'].' absente(s) · '.$counts['changed'].' modifiée(s) · '.$counts['same'].' identique(s) · '.$counts['conflict'].' conflit(s). Résultat limité à ce lot ; utilisez Suivant pour continuer.</p><table class="table"><thead><tr><th>Commande source</th><th>Statut source</th><th>Total</th><th>Différence</th><th>ID destination</th><th>Action</th></tr></thead><tbody>';
        $labels=['missing'=>'Absente de la destination','changed'=>'Mise à jour disponible','same'=>'Déjà synchronisée','conflict'=>'Modification locale / conflit à examiner'];
        foreach($delta['rows'] as $r){
            $html.='<tr data-manual-order="'.self::e($r['key']).'"><td>'.self::e($r['number']).'<br><small>'.self::e($r['key']).'</small></td><td>'.self::e($r['status']).'</td><td>'.self::e($r['total'].' '.$r['currency']).'</td><td>'.self::e($labels[$r['state']]).($r['changes']?'<br><small>'.self::e(implode(', ',$r['changes'])).'</small>':'').'</td><td>'.self::e($r['local_id']??'—').(!empty($r['destination_summary'])?'<br><small>'.self::e($r['destination_summary']['status'].' · '.$r['destination_summary']['total'].' '.$r['destination_summary']['currency']).'</small>':'').'</td><td>';
            if(in_array($r['state'],['missing','changed'],true)){$html.='<form method="post">'.$hidden(['manual_direction'=>$direction,'manual_offset'=>$offset,'manual_key'=>$r['key'],'manual_hash'=>$r['hash'],'manual_destination'=>$r['destination']]).'<button class="button button-primary btn btn-primary" name="bridge_action" value="manual_order_sync">'.($direction==='in'?'Importer cette commande':'Envoyer cette commande').'</button></form>';}
            $html.='</td></tr>';
        }
        $html.='</tbody></table>';
        if(!$delta['rows']){$html.='<p>Aucune commande originale dans ce lot.</p>';}
        foreach(['Précédent'=>$offset>0?max(0,$offset-20):null,'Suivant'=>$delta['next']] as $label=>$page){if($page!==null){$html.='<form method="post">'.$hidden(['manual_direction'=>$direction,'manual_offset'=>$page]).'<button class="button btn btn-default" name="bridge_action" value="manual_orders_scan">'.self::e($label).'</button></form>';}}
        return $html;
    }
}
