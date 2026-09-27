<?php
require __DIR__.'/manual-orders.php';
class RecordAdapter extends ManualAdapter {
 public $products=[],$profiles=[];
 function __construct($site){parent::__construct($site);$this->db->exec("ALTER TABLE t_wd29_bridge_map ADD COLUMN quantity INTEGER;ALTER TABLE t_wd29_bridge_map ADD COLUMN stock_initialized INTEGER DEFAULT 0;CREATE TABLE t_wd29_bridge_deleted_products(record_key TEXT PRIMARY KEY);CREATE TABLE t_wd29_bridge_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,record_key TEXT UNIQUE,data TEXT);");}
 function ids($kind,$offset,$limit){return $kind==='product'?array_slice(array_keys($this->products),$offset,$limit):parent::ids($kind,$offset,$limit);}
 function product($id){if(!isset($this->products[$id]))throw new RuntimeException('Missing product');return ['key'=>$this->products[$id]['key']??$this->engine->identity('product',$id)]+$this->products[$id];}
 function productExists($id){return isset($this->products[$id]);}
 function applyProduct($data,$map){$id=$map?(int)$map['local_id']:200+count($this->products);$this->products[$id]=$data;$this->writes++;return $id;}
 function manualContactKeys($offset,$limit){return array_slice(array_keys($this->profiles),$offset,$limit);}
 function manualContactOriginal($key){return isset($this->profiles[$key]);}
 function contactProfile($kind,$id){return $this->profiles[$this->site.':'.$kind.':'.$id];}
}
foreach(['woo','ps'] as $side){
 $a=new RecordAdapter($side);$s=new WD29\Bridge\Engine($a);$a->engine=$s;$b=new RecordAdapter($side==='woo'?'ps':'woo');$t=new WD29\Bridge\Engine($b);$b->engine=$t;
 $a->products[1]=['name'=>'Produit breton','sku'=>'TEST','status'=>'publish','inventory'=>[],'prices'=>['regular'=>'10'],'variants'=>[]];
 $a->profiles[$side.':customer:5']=['key'=>$side.':customer:5','first_name'=>'Client','last_name'=>'Exemple','email'=>'private@example.invalid','phone'=>'','company'=>'','billing'=>[],'shipping'=>[],'guest'=>false,'deleted'=>false];
 foreach(['product','customer'] as $kind){
  $scan=$s->manualRecordScan($kind);check(count($scan['rows'])===1,'Scan missing original');$row=$scan['rows'][0];check(!str_contains(json_encode($scan),'private@example.invalid'),'Comparison exposed email');
  check(!$s->sql('SELECT * FROM {b}map WHERE kind=?',[$kind]),'Read-only scan created map');check(!$s->sql('SELECT * FROM {b}contacts'),'Read-only scan created contact');
  $comp=$t->manualRecordCompare($kind,[$row])['rows'][0];check($comp['state']==='missing','Missing copy not identified');$export=$s->manualRecordExport($kind,$row['key'],$row['hash']);
  refuses(fn()=>$s->manualRecordExport($kind,$row['key'],str_repeat('0',64)),'Stale source accepted');
  WD29\Bridge\Licence::$allowed=false;refuses(fn()=>$t->manualRecordApply($kind,$export,''),'Unlicensed write accepted');WD29\Bridge\Licence::$allowed=true;
  $done=$t->manualRecordApply($kind,$export,'');check($done['state']==='applied','Manual record not applied');$before=$b->writes;check($t->manualRecordApply($kind,$export,'')['state']==='same'&&$b->writes===$before,'Duplicate record write');
  check($s->manualRecordAck($kind,$row['key'],$row['hash'],$export['through'])['state']==='acknowledged','Source not acknowledged');check($s->mode()==='disabled'&&$t->mode()==='disabled','Automatic mode changed');
  if($kind==='product')$a->products[1]['name']='Nouveau nom';else $a->profiles[$row['key']]['first_name']='Nouveau';
  $changed=$s->manualRecordScan($kind)['rows'][0];check($t->manualRecordCompare($kind,[$changed])['rows'][0]['state']==='changed','Source delta missed');$new=$s->manualRecordExport($kind,$row['key'],$changed['hash']);refuses(fn()=>$t->manualRecordApply($kind,$new,''),'Stale destination accepted');
  if($kind==='product')$b->products[$done['local_id']]['name']='Local edit';else{$native=json_decode($t->sql('SELECT data FROM {b}contacts WHERE id=?',[$done['local_id']])[0]['data'],true);$native['first_name']='Local edit';$t->sql('UPDATE {b}contacts SET data=? WHERE id=?',[json_encode($native),$done['local_id']]);}
  check($t->manualRecordCompare($kind,[$changed])['rows'][0]['state']==='conflict','Local edit missed');refuses(fn()=>$t->manualRecordApply($kind,$new,$row['hash']),'Local edit overwritten');
  refuses(fn()=>$t->manualRecordCompare($kind,array_fill(0,21,$row)),'Unbounded compare accepted');
 }
 refuses(fn()=>$s->manualRecordScan('sql'),'Invalid kind accepted');refuses(fn()=>$s->manualRecordBatch('all','out',4,0,0),'Invalid family cursor accepted');refuses(fn()=>$s->manualRecordBatch('all','out',0,0,20),'Invalid row cursor accepted');
 check($t->manualRecordScan('product')['rows']===[],'Imported copies exported as originals');
 $key=$side.':customer:5';refuses(fn()=>$s->manualRecordAck('customer',($side==='woo'?'ps':'woo').':customer:9',str_repeat('a',64),0),'Foreign contact acknowledgement accepted');
}
echo "PASS: products and contacts, both platforms, read-only scan, stale comparisons, licences, idempotency, local conflict guards, origins, cursor limits and unchanged modes\n";
