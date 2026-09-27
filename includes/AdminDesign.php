<?php
namespace WD29\Bridge;
require_once __DIR__ . '/AdminContext.php';

/** Shared presentation layer: retains existing forms, names, nonces and handlers. */
final class AdminDesign
{
    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    /** Use the host admin's components without changing form actions or security fields. */
    private static function nativeControls(\DOMDocument $doc, string $platform): void
    {
        $xp=new \DOMXPath($doc);
        foreach($xp->query('//button | //a[contains(concat(" ",normalize-space(@class)," ")," button ")] | //table | //input | //select | //textarea') as $node){
            $classes=preg_split('/\s+/',trim($node->getAttribute('class')),-1,PREG_SPLIT_NO_EMPTY);
            $primary=in_array('button-primary',$classes,true)||in_array('btn-primary',$classes,true);
            $classes=array_values(array_diff($classes,['button','button-primary','button-secondary','btn','btn-primary','btn-default','btn-secondary','widefat','striped','table','table-striped','form-control']));
            if(in_array($node->tagName,['button','a'],true)){
                $classes=array_merge($classes,$platform==='ps'?['btn',$primary?'btn-primary':'btn-default']:['button',$primary?'button-primary':'button-secondary']);
            }elseif($node->tagName==='table'){
                $classes=array_merge($classes,$platform==='ps'?['table']:['widefat','striped']);
            }elseif($platform==='ps'&&!in_array($node->getAttribute('type'),['hidden','checkbox','radio'],true)){
                $classes[]='form-control';
            }
            $node->setAttribute('class',implode(' ',array_unique($classes)));
        }
    }
    public static function viewUrl(string $view): string
    {
        $query=$_GET;unset($query['inklura_demo_section']);$query['wd_view']=$view;
        foreach(array_keys($query) as $key)if(strpos($key,'manual_')===0||$key==='bridge_action')unset($query[$key]);
        return '?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }
    public static function currentView(): string
    {
        $view=$_POST['wd_view']??$_GET['wd_view']??'overview';$action=(string)($_POST['bridge_action']??'');
        if(strpos($action,'manual_record')===0)$view='sync';elseif(strpos($action,'manual_order')===0)$view='orders';
        return in_array($view,['overview','sync','orders','activity','reports','settings','licence'],true)?$view:'overview';
    }
    public static function render(string $html, Engine $engine, string $platform): string
    {
        $doc=new \DOMDocument('1.0','UTF-8'); $previous=libxml_use_internal_errors(true);
        $loaded=$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) { return $html; }
        $body=$doc->getElementsByTagName('body')->item(0); $root=null;
        foreach($body->childNodes as $node){if($node instanceof \DOMElement){$root=$node;break;}}
        if(!$root){return $html;}
        self::nativeControls($doc,$platform);
        $view=self::currentView();
        $panelClass=$platform==='ps'?'panel':'postbox';
        $primaryButton=$platform==='ps'?'btn btn-primary':'button button-primary';
        $secondaryButton=$platform==='ps'?'btn btn-default':'button button-secondary';
        $forms=new \DOMXPath($doc);foreach($forms->query('//form') as $form){$hidden=$doc->createElement('input');$hidden->setAttribute('type','hidden');$hidden->setAttribute('name','wd_view');$hidden->setAttribute('value',$view);$form->appendChild($hidden);}
        $local=$platform==='ps'?'ps':'woo';$localName=AdminContext::name($local);$remoteName=AdminContext::name($local==='woo'?'ps':'woo');
        // Decorate ownership before serializing sections, preserving forms and tokens.
        $section='';foreach(iterator_to_array($root->childNodes) as $node){if($node instanceof \DOMElement){if(in_array($node->tagName,['h1','h2','h3'],true)){$section=trim($node->textContent);}elseif($node->tagName==='table'){AdminContext::table($node,$section,$local);}}}
        $nodes=iterator_to_array($root->childNodes); $sections=[]; $key='settings';
        $titles=['settings'=>['Réglages','Connexion et règles de synchronisation'],'tools'=>['Actions & maintenance','Traitement des files, captures et reprises'],'Champs personnalisés'=>['Champs personnalisés','Correspondances des métadonnées WooCommerce et ACF'],'Statuts des commandes'=>['Statuts des commandes','Correspondances entre PrestaShop et WooCommerce'],'Diagnostics'=>['Diagnostics','État du traitement et points à examiner'],'Conflits de commandes'=>['Conflits de commandes','Comparer les versions avant une reprise'],'Remboursements & avoirs'=>['Remboursements & avoirs','Suivi des enregistrements de la boutique source'],'Comptes clients liés'=>['Comptes clients liés','Correspondances des comptes natifs'],'Journal des événements'=>['Journal des événements','Les 100 derniers échanges et leur résultat'],'Catalogue'=>['Catalogue','Derniers instantanés transmis · jusqu’à 200 produits'],'Commandes'=>['Commandes','Montants, statuts et correspondances des lignes'],'Contacts clients'=>['Contacts clients','Copies à modifier sur leur boutique d’origine'],'Modifier les champs personnalisés'=>['Modifier les champs personnalisés','Valeurs reçues de WooCommerce']];
        $titles['Comparer et synchroniser']=['Comparer et synchroniser','Produits, commandes et contacts · dans les deux sens'];
        $titles['Commandes à synchroniser']=['Commandes à synchroniser','Comparer les écarts et rattraper une commande même à l’arrêt'];
        $titles['Catalogue']=['Catalogue suivi sur '.$localName,'Instantanés échangés · originaux et copies importées · jusqu’à 200 produits'];
        $titles['Commandes']=['Commandes suivies sur '.$localName,'Commandes de cette boutique · origine et copie identifiées'];
        $titles['Contacts clients']=['Contacts reçus sur '.$localName,'Données conservées ici · à modifier sur la boutique d’origine'];
        $titles['Journal des événements']=['Échanges de '.$localName,'Sens explicite : WordPress → PrestaShop ou PrestaShop → WordPress'];
        $top=''; $firstHeading=true; $forms=0;
        foreach($nodes as $node){
            if($node instanceof \DOMElement && in_array(strtolower($node->tagName),['h1','h2','h3'],true)){
                if($firstHeading){$firstHeading=false;continue;}
                $key=trim($node->textContent);continue;
            }
            if($node instanceof \DOMElement && $node->tagName==='hr'){continue;}
            if($node instanceof \DOMElement && $node->tagName==='form'){$forms++;if($forms===2&&$key==='settings'){$key='tools';}}
            if($node instanceof \DOMElement && (strpos($node->getAttribute('class'),'notice')!==false || strpos($node->getAttribute('class'),'alert')!==false)){$top.=$doc->saveHTML($node);continue;}
            $sections[$key]=($sections[$key]??'').$doc->saveHTML($node);
        }
        $licence=$sections['Licence']??'';unset($sections['Licence']);$ls=$engine->licence()->summary();
        $d=$engine->diagnostics();$pending=0;$blocked=0;
        foreach($d['queue'] as $group){if($group['state']==='pending'){$pending+=(int)$group['total'];}else{$blocked+=(int)$group['total'];}}
        $modes=['live'=>'Synchronisation active','audit'=>'Mode audit','disabled'=>'Synchronisation arrêtée'];$mode=$modes[$engine->mode()]??'État inconnu';
        if(($engine->config()['mode']??'')==='live'&&$engine->mode()!=='live'){$mode='Mode audit · licence';}
        $out='<style>'.file_get_contents(__DIR__.'/admin-design.css').'</style><div id="wd29-admin" data-wd-view="'.self::e($view).'" class="wd-platform-'.self::e($platform).($platform==='ps'?'':' wrap').'"><header class="wd-hero"><h1>Inklura Sync</h1><span class="wd-badge">'.self::e($mode).'</span></header>'.AdminContext::stores($engine,$local).$top;
        $out.='<nav class="wd-nav'.($platform==='ps'?'':' nav-tab-wrapper').'" aria-label="Pages du connecteur">'.($platform==='ps'?'<ul class="nav nav-tabs">':'');
        foreach(['overview'=>'Vue d’ensemble','sync'=>'Comparer & synchroniser','activity'=>'Activité','reports'=>'Rapports','settings'=>'Réglages','licence'=>'Licence & mises à jour'] as $page=>$label){
            $active=($view==='orders'?'sync':$view)===$page;
            if($platform==='ps')$out.='<li class="nav-item'.($active?' active':'').'">';
            $out.='<a class="'.($platform==='ps'?'nav-link'.($active?' active':''):'nav-tab'.($active?' nav-tab-active':'')).'" href="'.self::e(self::viewUrl($page)).'"'.($active?' aria-current="page"':'').'>'.self::e($label).'</a>';
            if($platform==='ps')$out.='</li>';
        }
        $out.=($platform==='ps'?'</ul>':'').'</nav>';
        if($view==='overview'){$out.='<div class="wd-stats">';
        foreach([['État du suivi',$d['ok']?'À jour':'À vérifier',$d['ok']?'Aucune anomalie détectée':count($d['issues']).' point(s) à examiner'],['En attente',$pending,'Événements à traiter'],['À résoudre',$blocked,'Échecs et conflits'],['Partenaire configuré',$remoteName,'Les rapports ci-dessous concernent '.$localName]] as $stat){$out.='<article class="wd-card wd-stat '.$panelClass.'"><span>'.$stat[0].'</span><strong>'.self::e($stat[1]).'</strong><small>'.self::e($stat[2]).'</small></article>';}
        $out.='</div><p><a class="'.$primaryButton.'" href="'.self::e(self::viewUrl('sync')).'">Comparer les boutiques</a> <a class="'.$secondaryButton.'" href="'.self::e(self::viewUrl('activity')).'">Consulter les échanges</a></p>';}$index=0;
        if(isset($sections['Commandes à synchroniser'])){$sections=['Commandes à synchroniser'=>$sections['Commandes à synchroniser']]+$sections;}
        if($view==='licence'&&$licence!==''){$out.='<details id="wd-licence" class="wd-card '.$panelClass.' wd-section wd-section-licence"'.' open'.'><summary><span><strong>Licence · '.self::e($ls['label']).'</strong><small>'.self::e($ls['hint']!==''?'Clé '.$ls['hint'].($ls['expires']!==''?' · échéance '.$ls['expires']:''):'Clé, état et mises à jour').'</small></span><span class="wd-chevron" aria-hidden="true">⌄</span></summary><div class="wd-section-body">'.$licence.'</div></details>';}
        foreach($sections as $name=>$content){
            $index++;$page=in_array($name,['settings','Champs personnalisés','Statuts des commandes'],true)?'settings':($name==='Comparer et synchroniser'?'sync':($name==='Commandes à synchroniser'?'orders':(in_array($name,['tools','Diagnostics','Conflits de commandes','Journal des événements'],true)?'activity':'reports')));
            if($page!==$view&&!($view==='overview'&&$name==='Diagnostics'))continue;
            $title=$titles[$name]??[$name,'Configuration avancée du connecteur'];$id=$name==='Comparer et synchroniser'?'wd-compare':($name==='Commandes à synchroniser'?'wd-orders-delta':($name==='settings'?'wd-settings':($name==='tools'?'wd-tools':'wd-report-'.$index)));
            $open=in_array($name,['settings','Diagnostics','Commandes à synchroniser','Comparer et synchroniser','tools','Journal des événements'],true)||($name==='Modifier les champs personnalisés'&&in_array((string)($_POST['bridge_action']??''),['load_mirror_fields','save_mirror_fields'],true))||($name==='Champs personnalisés'&&($_POST['bridge_action']??'')==='save_field_rows');
            if($view==='reports'&&strpos($out,'id="wd-reports"')===false)$out.='<div id="wd-reports" class="wd-section-title"><h2>Rapports</h2><p>Originaux et copies importées sur '.self::e($localName).'.</p></div>';
            $out.='<details id="'.$id.'" class="wd-card '.$panelClass.' wd-section wd-section-'.($name==='settings'?'settings':($name==='tools'?'tools':'report')).'"'.($open?' open':'').'><summary><span><strong>'.self::e($title[0]).'</strong><small>'.self::e($title[1]).'</small></span><span class="wd-chevron" aria-hidden="true">⌄</span></summary><div class="wd-section-body">'.$content.'</div></details>';
        }
        $out.='<footer class="wd-footnote">WD29 · Les rapports affichent les données suivies par le connecteur.</footer></div><script>'.file_get_contents(__DIR__.'/admin-design.js').'</script>';
        return $out;
    }
}
