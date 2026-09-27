<?php
namespace WD29\Bridge;
require_once __DIR__ . '/AdminContext.php';
final class ManualOrdersAdmin
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
    public static function handle(Engine $engine,array $input): ?string
    {
        $action=$input['bridge_action']??'';
        if(!in_array($action,['manual_orders_scan','manual_order_sync'],true)){return null;}
        if($action==='manual_orders_scan'){return 'Comparaison des commandes actualisée.';}
        $direction=(string)($input['manual_direction']??'in');
        $r=$engine->manualOrderSync($direction,(string)($input['manual_key']??''),(string)($input['manual_hash']??''),(string)($input['manual_destination']??''));
        $local=$engine->adapter->site();$target=$direction==='in'?$local:($local==='woo'?'ps':'woo');
        return 'Commande synchronisée sur '.AdminContext::name($target).' · ID #'.$r['local_id'].'. Le mode automatique est inchangé.'.(($r['ack']??'')==='pending'?' Confirmation côté source en attente ; actualisez avant de réessayer.':'');
    }
    private static function amount($total, $currency): string
    {
        $value=(string)$total;
        if(preg_match('/^(-?[0-9]+)(?:\.([0-9]+))?$/D',$value,$parts)){
            $fraction=rtrim($parts[2]??'','0');$value=$parts[1].','.str_pad($fraction,2,'0');
        }
        return self::e($value.' '.$currency);
    }
    private static function orderCell(string $side,bool $original,array $row): string
    {
        $name=AdminContext::name($side);$summary=$original?($row['summary']??null):($row['destination_summary']??null);
        if(!$original && !$row['local_id']){return '<td class="wd-order-side wd-side-'.$side.'"><strong>Aucune copie sur '.self::e($name).'</strong><small>Cette commande reste uniquement sur sa boutique d’origine.</small></td>';}
        $id=$original?substr($row['key'],strrpos($row['key'],':')+1):(string)$row['local_id'];
        $number=$summary['number']??($original?$row['number']:'');
        $html='<td class="wd-order-side wd-side-'.$side.'"><span class="wd-origin wd-side-'.$side.'">'.($original?'Original':'Copie importée').'</span><strong>'.self::e($name).' · #'.self::e($id).'</strong>';
        if($number!=='' && (string)$number!==$id){$html.='<small>Référence : '.self::e($number).'</small>';}
        if($summary){$html.='<span>'.self::e($summary['status_label']??$summary['status']).'</span><span>'.self::amount($summary['total'],$summary['currency']).'</span>';}
        elseif($original){$html.='<span>Statut synchronisé : '.self::e($row['status']).'</span><span>'.self::amount($row['total'],$row['currency']).'</span>';}
        else{$html.='<small>Lecture de la copie indisponible : à examiner sur '.self::e($name).'.</small>';}
        return $html.'</td>';
    }
    public static function render(Engine $engine,string $token,array $input): string
    {
        if(class_exists(AdminDesign::class)&&AdminDesign::currentView()!=='orders'&&!in_array($input['bridge_action']??'',['manual_orders_scan','manual_order_sync'],true))return '';
        $direction=($input['manual_direction']??'in')==='out'?'out':'in';$offset=max(0,min(10000000,(int)($input['manual_offset']??0)));
        $local=$engine->adapter->site();$remote=$local==='woo'?'ps':'woo';
        $source=$direction==='in'?$remote:$local;$target=$source==='woo'?'ps':'woo';
        $sourceName=AdminContext::name($source);$targetName=AdminContext::name($target);
        $active=in_array($input['bridge_action']??'',['manual_orders_scan','manual_order_sync'],true);
        $hidden=static function(array $values)use($token){$s=$token;foreach($values as $k=>$v){$s.='<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';}return $s;};
        $html='<h2>Commandes à synchroniser</h2><p>Choisissez la boutique où les commandes ont été passées. WordPress reste à gauche et PrestaShop à droite : original et copie sont identifiés dans chaque colonne.</p><div class="wd-manual-directions">';
        foreach(['woo','ps'] as $origin){$dest=$origin==='woo'?'ps':'woo';$d=$origin===$local?'out':'in';$selected=$active&&$source===$origin;
            $html.='<form method="post" class="wd-direction-choice wd-side-'.$origin.'" data-selected="'.($selected?'true':'false').'">'.$hidden(['manual_direction'=>$d,'manual_offset'=>0]).'<button class="button btn btn-default" name="bridge_action" value="manual_orders_scan" aria-pressed="'.($selected?'true':'false').'">Comparer les commandes '.self::e($origin==='woo'?'WordPress':'PrestaShop').'</button><small>Originaux '.self::e(AdminContext::name($origin)).' → copies '.self::e(AdminContext::name($dest)).'</small></form>';
        }
        $html.='</div><p class="wd-manual-note">Comparer ne modifie rien. Une synchronisation manuelle applique seulement la commande choisie et ses coordonnées sur la boutique indiquée, même à l’arrêt ou en audit, sans réactiver le suivi automatique. Connexion configurée et licence autorisant les écritures sur la destination requises. Les brouillons de checkout sont exclus.</p>';
        if(!$active){return $html;}
        try{$delta=$engine->manualOrderDelta($direction,$offset);}catch(\Throwable $e){return $html.'<p role="alert">Comparaison indisponible : '.self::e($e->getMessage()).' Vérifiez la connexion des deux plugins et leur mise à jour.</p>';}
        $modes=['disabled'=>'arrêté','audit'=>'audit','live'=>'live'];
        $html.='<div class="wd-comparison-direction"><strong>Commandes créées sur '.self::e($sourceName).' → copie sur '.self::e($targetName).'</strong><small>'.self::e($sourceName).' : '.self::e($modes[$delta['mode']]??$delta['mode']).' · '.self::e($targetName).' : '.self::e($modes[$delta['destination_mode']]??$delta['destination_mode']).' · lot '.(int)($offset/20+1).'</small></div>';
        $counts=['missing'=>0,'changed'=>0,'same'=>0,'conflict'=>0];foreach($delta['rows'] as $r){$counts[$r['state']]++;}
        $html.='<p>'.$counts['missing'].' à créer sur '.self::e($targetName).' · '.$counts['changed'].' à mettre à jour · '.$counts['same'].' déjà synchronisée(s) · '.$counts['conflict'].' conflit(s). Ce lot uniquement ; Suivant affiche les autres commandes.</p><table class="table wd-order-comparison"><thead><tr><th class="wd-side-woo">WordPress / WooCommerce<br><small>'.($local==='woo'?'Cette administration':'Boutique partenaire').'</small></th><th class="wd-side-ps">PrestaShop<br><small>'.($local==='ps'?'Cette administration':'Boutique partenaire').'</small></th><th>Écart entre les boutiques</th><th>Action sur '.self::e($targetName).'</th></tr></thead><tbody>';
        $labels=['missing'=>'Copie absente sur '.$targetName,'changed'=>'Mise à jour disponible sur '.$targetName,'same'=>'Déjà synchronisée','conflict'=>'Copie sur '.$targetName.' : modification locale ou conflit à examiner'];
        foreach($delta['rows'] as $r){
            $html.='<tr data-manual-order="'.self::e($r['key']).'">'.self::orderCell('woo',$source==='woo',$r).self::orderCell('ps',$source==='ps',$r).'<td>'.self::e($labels[$r['state']]).($r['changes']?'<br><small>'.self::e(implode(', ',$r['changes'])).'</small>':'').'<small class="wd-record-key">'.self::e($r['key']).'</small></td><td>';
            if(in_array($r['state'],['missing','changed'],true)){$html.='<form method="post">'.$hidden(['manual_direction'=>$direction,'manual_offset'=>$offset,'manual_key'=>$r['key'],'manual_hash'=>$r['hash'],'manual_destination'=>$r['destination']]).'<button class="button button-primary btn btn-primary" name="bridge_action" value="manual_order_sync">'.self::e(($r['state']==='missing'?'Créer sur ':'Mettre à jour sur ').$targetName).'</button></form>';}
            $html.='</td></tr>';
        }
        $html.='</tbody></table>';
        if(!$delta['rows']){$html.='<p>Aucune commande originale de '.self::e($sourceName).' dans ce lot.</p>';}
        foreach(['Précédent'=>$offset>0?max(0,$offset-20):null,'Suivant'=>$delta['next']] as $label=>$page){if($page!==null){$html.='<form method="post">'.$hidden(['manual_direction'=>$direction,'manual_offset'=>$page]).'<button class="button btn btn-default" name="bridge_action" value="manual_orders_scan">'.self::e($label).'</button></form>';}}
        return $html;
    }
}
