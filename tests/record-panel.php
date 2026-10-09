<?php
require __DIR__.'/manual-records.php';
use WD29\Bridge\RecordPanel;
use WD29\Bridge\Engine;
use WD29\Bridge\Protocol;
class PanelAdapter extends RecordAdapter {
 public $settings=['mode'=>'disabled','peer'=>'https://peer.example/webhook','secret'=>'abcdefghijklmnopqrstuvwxyz0123456789'];
 function config(){return $this->settings;}
 function recordPanelIdentity($kind,$id){
  if($kind==='customer'){
   $link=$this->engine->sql('SELECT record_key FROM {b}account_links WHERE native_id=?',[$id])[0]??null;
   if($link)return $link['record_key'];
   $key=$this->site.':customer:'.$id;if(!$this->manualContactOriginal($key))throw new RuntimeException('Ineligible');return $key;
  }
  if($kind==='product'&&!isset($this->products[$id]))throw new RuntimeException('Missing');
  if($kind==='order'&&!$this->orderSyncable($id))throw new RuntimeException('Missing');
  $map=$this->engine->sql('SELECT record_key FROM {b}map WHERE kind=? AND local_id=?',[$kind,$id])[0]??null;
  return $map['record_key']??($this->site.':'.$kind.':'.$id);
 }
 function recordPanelAdminUrl($kind,$key){return RecordPanel::nativeId($this->engine,$kind,$key)?'https://'.$this->site.'.example/admin?kind='.$kind.'&key='.rawurlencode($key):'';}
}
$assertions=0;
function panelCheck($condition,$message){global $assertions;$assertions++;check($condition,$message);}
foreach(['woo','ps'] as $side){
 $a=new PanelAdapter($side);$source=new Engine($a);$a->engine=$source;
 $b=new PanelAdapter($side==='woo'?'ps':'woo');$dest=new Engine($b);$b->engine=$dest;
 $a->products[11]=['name'=>'Palets bretons','sku'=>'PAL','type'=>'variable','status'=>'publish','prices'=>['regular'=>'10'],'currency'=>'EUR','variants'=>[['key'=>$side.':variant:12','sku'=>'PAL-6','attributes'=>['Boîte'=>'6 palets'],'prices'=>['regular'=>'10']]],'inventory'=>[['key'=>$side.':product:11','quantity'=>20],['key'=>$side.':variant:12','quantity'=>10]]];
 $a->orders[14]=['number'=>'BRETON-14','status'=>'on-hold','total'=>'10','currency'=>'EUR','items'=>[['name'=>'Palets','quantity'=>1]],'billing'=>['email'=>'NEVER_RETURN@example.invalid']];
 $a->profiles[$side.':customer:17']=['key'=>$side.':customer:17','first_name'=>'Anna','last_name'=>'Le Gall','email'=>'NEVER_RETURN@example.invalid','phone'=>'SECRET_PHONE','company'=>'','billing'=>[],'shipping'=>[],'guest'=>false,'deleted'=>false];
 foreach(['product'=>11,'order'=>14,'customer'=>17] as $kind=>$id){
  $context=RecordPanel::local($source,$kind,$id);
  panelCheck($context['state']==='ready'&&$context['origin']&&$context['direction']==='out','Original context wrong');
  panelCheck(!$source->sql('SELECT * FROM {b}map'),'Rendering created mappings');
  panelCheck(!$source->sql('SELECT * FROM {b}contacts'),'Rendering created contacts');
  $descriptor=RecordPanel::source($source,$kind,$context['key']);
  panelCheck($descriptor['row']['key']===$context['key'],'Source identity wrong');
  panelCheck(strpos(json_encode($descriptor),'NEVER_RETURN')===false&&strpos(json_encode($descriptor),'SECRET_PHONE')===false,'Preview leaked customer fields');
  $comp=RecordPanel::target($dest,$kind,[$descriptor['row']]);
  panelCheck($comp['rows'][0]['state']==='missing'&&$comp['writes_allowed'],'Missing destination not actionable');
  $export=$source->manualRecordExport($kind,$context['key'],$descriptor['row']['hash']);
  $applied=$dest->manualRecordApply($kind,$export,'');
  panelCheck($applied['state']==='applied','Apply failed');
  $again=RecordPanel::target($dest,$kind,[$descriptor['row']]);
  panelCheck($again['rows'][0]['state']==='same','Apply did not converge');
  if($kind!=='customer'){
   $copy=RecordPanel::local($dest,$kind,$applied['local_id']);
   panelCheck(!$copy['origin']&&$copy['direction']==='in'&&$copy['key']===$context['key'],'Copy direction/key wrong');
   panelCheck(RecordPanel::nativeId($dest,$kind,$context['key'])===$applied['local_id'],'Copy deep link wrong');
  }else{
   panelCheck(RecordPanel::nativeId($dest,$kind,$context['key'])===0,'Contact ID confused with native customer');
   $dest->sql('INSERT INTO {b}account_links(record_key,native_id) VALUES (?,?)',[$context['key'],799]);
   panelCheck(RecordPanel::nativeId($dest,$kind,$context['key'])===799,'Native account mapping lost');
  }
  refuses(fn()=>RecordPanel::sync($source,$kind,$id,['confirm'=>'1','key'=>'ps:product:99','direction'=>'out','hash'=>$descriptor['row']['hash'],'destination'=>'']),'Swapped record accepted');
  refuses(fn()=>RecordPanel::sync($source,$kind,$id,['confirm'=>'0','key'=>$context['key'],'direction'=>'out','hash'=>$descriptor['row']['hash'],'destination'=>'']),'Unconfirmed write accepted');
  refuses(fn()=>RecordPanel::target($dest,$kind,[]),'Empty comparison accepted');
  if($kind==='product'){
   panelCheck($again['rows'][0]['variation_changes']['count']===0,'Identical variations differ');
   $a->products[11]['variants'][0]['prices']['regular']='12';
   $a->products[11]['variants'][]=['key'=>$side.':variant:13','sku'=>'PAL-12','attributes'=>['Boîte'=>'12 palets'],'prices'=>['regular'=>'20']];
   $changed=RecordPanel::source($source,$kind,$context['key']);$delta=RecordPanel::target($dest,$kind,[$changed['row']])['rows'][0];
   panelCheck($delta['state']==='changed'&&$delta['variation_changes']['count']===2,'Saved variation edit/addition missed');
   panelCheck($delta['variation_changes']['basis']==='last_sync','Variation review not based on transmitted source');
   $a->products[11]['variants']=[];$removed=RecordPanel::source($source,$kind,$context['key']);$delta=RecordPanel::target($dest,$kind,[$removed['row']])['rows'][0];
   panelCheck($delta['variation_changes']['rows'][0]['state']==='removed','Removed variation missed');
   panelCheck($delta['state']==='conflict'&&$delta['blocker']==='variation_removal','Removed variation advertised as safe');
   $removedExport=$source->manualRecordExport($kind,$context['key'],$removed['row']['hash']);
   refuses(fn()=>$dest->manualRecordApply($kind,$removedExport,$descriptor['row']['hash']),'Removed variation reported as fully applied');
   $a->products[11]['variants']=$export['data']['variants'];$a->products[11]['inventory'][1]['quantity']=9;
   $stock=RecordPanel::source($source,$kind,$context['key']);$delta=RecordPanel::target($dest,$kind,[$stock['row']])['rows'][0];
   panelCheck($delta['state']==='same'&&$delta['stock_difference'],'Stock-only delta forced catalog transfer');
  }
  // Subsequent fixtures should prove that rendering itself did not create these mappings.
  $source->sql('DELETE FROM {b}map');$source->sql('DELETE FROM {b}contacts');
 }
 $a->settings['peer']='';panelCheck(RecordPanel::local($source,'product',11)['state']==='disconnected','Missing peer not explicit');
 panelCheck(RecordPanel::nativeId($source,'product','evil:product:11')===0,'Malformed deep link accepted');
 refuses(fn()=>RecordPanel::local($source,'variant',12),'Standalone variation accepted');
}
echo 'PASS '.$assertions." record-panel source/destination, identity, directory privacy, saved variations, stock isolation and confirmation checks\n";
