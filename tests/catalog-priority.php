<?php
require_once __DIR__.'/concurrent-products.php';
use WD29\Bridge\Engine;

class CatalogPriorityAdapter extends ProductCreationAdapter {
 public $policy='ps',$broken=false;
 function config(){return ['mode'=>'audit','conflict_policy'=>$this->policy];}
 function applyProduct($data,$map){if($this->broken)throw new RuntimeException('Injected native import failure');return parent::applyProduct($data,$map);}
}
$apply=new ReflectionMethod(Engine::class,'apply');$apply->setAccessible(true);
foreach(['woo','ps'] as $site)foreach(['incoming','local','review','failure','duplicate'] as $scenario){
 $a=new CatalogPriorityAdapter($site);$e=new Engine($a);$a->engine=$e;$foreign=$site==='woo'?'ps':'woo';$key=$site.':product:1';
 $a->products[1]=['name'=>'Existing product','sku'=>'','prices'=>['regular'=>'10.000000','sale'=>null,'basis'=>'net','tax_rate'=>20],'inventory'=>[]];
 $e->capture('product',1);$baseline=$e->mapping($key)['fingerprint'];$e->sql("UPDATE {b}queue SET state='delivered'");
 $a->products[1]['prices']['regular']='8.333333';$e->capture('product',1);
 $old=$e->sql("SELECT * FROM {b}queue WHERE direction='out' AND state='pending'")[0];
 foreach(['failed','conflict'] as $state){$id=bin2hex(random_bytes(16));$e->enqueue('out','product',$key,json_decode($old['payload'],true),$id);$e->sql('UPDATE {b}queue SET state=? WHERE event_id=?',[$state,$id]);}
 $e->enqueue('out','stock',$key,['delta'=>-2]);$e->enqueue('out','order',$site.':order:1',[]);
 $a->products[2]=['name'=>'Independent product','sku'=>'','inventory'=>[]];$e->capture('product',2);
 $incoming=$e->adapter->product(1);$incoming['prices']['regular']='5.500000';
 $hash=Engine::catalogHash($incoming);$a->policy=$scenario==='local'?$site:($scenario==='review'?'review':$foreign);$a->broken=$scenario==='failure';
 if($scenario==='duplicate'){$hash=$e->mapping($key)['fingerprint'];$incoming=$e->adapter->product(1);}
 $id=bin2hex(random_bytes(16));$e->enqueue('in','product',$key,['base'=>$baseline,'hash'=>$hash,'data'=>$incoming],$id);
 $event=$e->sql("SELECT * FROM {b}queue WHERE direction='in' AND event_id=?",[$id])[0];$apply->invoke($e,$event);
 $states=array_column($e->sql("SELECT state FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=? AND state!='delivered'",[$key]),'state');
 $accepted=$scenario==='incoming';
 check($states===($accepted?['ignored','ignored','ignored']:['pending','failed','conflict']),$site.' '.$scenario.': stale outgoing state incorrect');
 check($a->products[1]['prices']['regular']===($accepted?'5.500000':'8.333333'),$site.' '.$scenario.': wrong winning price');
 check(count($e->sql("SELECT seq FROM {b}queue WHERE direction='out' AND kind IN ('stock','order') AND state='pending'"))===2,'Stock/order events changed');
 check(count($e->sql("SELECT seq FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=? AND state='pending'",[$site.':product:2']))===1,'Independent product changed');
 if($accepted){$apply->invoke($e,$event);check($a->products[1]['prices']['regular']==='5.500000','Replay restored stale price');}
}
echo "PASS: winning inbound catalogue supersedes stale outgoing snapshots on both platforms; local/review priority, duplicate, failed imports, replay, stock, orders and independent products preserved\n";
