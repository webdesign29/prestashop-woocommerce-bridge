<?php
namespace WD29\Bridge;

/** Shared presentation layer: retains existing forms, names, nonces and handlers. */
final class AdminDesign
{
    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    public static function render(string $html, Engine $engine, string $platform): string
    {
        $doc=new \DOMDocument('1.0','UTF-8'); $previous=libxml_use_internal_errors(true);
        $loaded=$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) { return $html; }
        $body=$doc->getElementsByTagName('body')->item(0); $root=null;
        foreach($body->childNodes as $node){if($node instanceof \DOMElement){$root=$node;break;}}
        if(!$root){return $html;}
        $nodes=iterator_to_array($root->childNodes); $sections=[]; $key='settings';
        $titles=['settings'=>['Réglages','Connexion et règles de synchronisation'],'tools'=>['Actions & maintenance','Traitement des files, captures et reprises'],'Custom field mappings'=>['Champs personnalisés','Correspondances des métadonnées WooCommerce et ACF'],'Custom fields'=>['Champs personnalisés','Correspondances des métadonnées WooCommerce et ACF'],'PrestaShop order status mappings'=>['Statuts des commandes','Correspondances entre PrestaShop et WooCommerce'],'Operational diagnostics'=>['Diagnostics','État du traitement et points à examiner'],'Order conflicts'=>['Conflits de commandes','Comparer les versions avant une reprise'],'Refund / credit-note records'=>['Remboursements & avoirs','Suivi des enregistrements de la boutique source'],'Native customer account links'=>['Comptes clients liés','Correspondances des comptes natifs'],'Latest events'=>['Journal des événements','Les 100 derniers échanges et leur résultat'],'Catalog audit'=>['Catalogue','Derniers instantanés transmis · jusqu’à 200 produits'],'Order reconciliation'=>['Commandes','Montants, statuts et correspondances des lignes'],'Customer contact directory'=>['Contacts clients','Copies à modifier sur leur boutique d’origine'],'Edit synchronized custom fields'=>['Modifier les champs personnalisés','Valeurs reçues de WooCommerce']];
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
        $d=$engine->diagnostics();$pending=0;$blocked=0;
        foreach($d['queue'] as $group){if($group['state']==='pending'){$pending+=(int)$group['total'];}else{$blocked+=(int)$group['total'];}}
        $modes=['live'=>'Synchronisation active','audit'=>'Mode audit','disabled'=>'Synchronisation arrêtée'];$mode=$modes[$engine->config()['mode']??'disabled']??'État inconnu';
        $out='<style>'.file_get_contents(__DIR__.'/admin-design.css').'</style><div id="wd29-admin" class="wd-platform-'.self::e($platform).'"><header class="wd-hero"><div><span class="wd-eyebrow">WEBDESIGN29 · CONNECTEUR E-COMMERCE</span><h1>PrestaShop <span aria-hidden="true">↔</span> WooCommerce</h1><p>Vos boutiques, réunies dans un seul suivi.</p></div><div class="wd-hero-meta"><span class="wd-badge">'.self::e($mode).'</span><small>Configuration '.($platform==='ps'?'PrestaShop':'WordPress').' · WD29 Bridge</small></div></header>'.$top;
        $out.='<nav class="wd-nav" aria-label="Sections du connecteur"><a href="#wd-settings">Réglages</a><a href="#wd-tools">Actions &amp; maintenance</a><a href="#wd-reports">Rapports &amp; diagnostics</a></nav><div class="wd-stats">';
        foreach([['État du suivi',$d['ok']?'À jour':'À vérifier',$d['ok']?'Aucune anomalie détectée':count($d['issues']).' point(s) à examiner'],['En attente',$pending,'Événements à traiter'],['À résoudre',$blocked,'Échecs et conflits'],['Boutique distante',$platform==='ps'?'WooCommerce':'PrestaShop','Connexion directe et signée']] as $stat){$out.='<article class="wd-card wd-stat"><span>'.$stat[0].'</span><strong>'.self::e($stat[1]).'</strong><small>'.self::e($stat[2]).'</small></article>';}
        $out.='</div>';$index=0;
        foreach($sections as $name=>$content){
            $index++;$title=$titles[$name]??[$name,'Configuration avancée du connecteur'];$id=$name==='settings'?'wd-settings':($name==='tools'?'wd-tools':'wd-report-'.$index);
            $open=in_array($name,['settings','Operational diagnostics'],true)||($name==='Edit synchronized custom fields'&&in_array((string)($_POST['bridge_action']??''),['load_mirror_fields','save_mirror_fields'],true))||($name==='Custom field mappings'&&($_POST['bridge_action']??'')==='save_field_rows');
            if($index===3){$out.='<div id="wd-reports" class="wd-section-title"><h2>Rapports &amp; diagnostics</h2><p>Ouvrez une rubrique pour consulter ses détails.</p></div>';}
            $out.='<details id="'.$id.'" class="wd-card wd-section wd-section-'.($name==='settings'?'settings':($name==='tools'?'tools':'report')).'"'.($open?' open':'').'><summary><span><strong>'.self::e($title[0]).'</strong><small>'.self::e($title[1]).'</small></span><span class="wd-chevron" aria-hidden="true">⌄</span></summary><div class="wd-section-body">'.$content.'</div></details>';
        }
        $out.='<footer class="wd-footnote">WD29 · Les rapports affichent les données suivies par le connecteur.</footer></div><script>'.file_get_contents(__DIR__.'/admin-design.js').'</script>';
        return $out;
    }
}
