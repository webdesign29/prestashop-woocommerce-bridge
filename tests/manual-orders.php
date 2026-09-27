<?php
namespace WD29\Bridge { class Licence {public static $allowed=true;public function __construct($a){}public function allowsLive(){return self::$allowed;}} }
namespace {
require __DIR__.'/../includes/Protocol.php';require __DIR__.'/../includes/Engine.php';
use WD29\Bridge\Engine;use WD29\Bridge\Protocol;use WD29\Bridge\Licence;
class ManualAdapter {
 public $db,$engine,$orders=[],$writes=0,$site;
 function __construct($site){$this->site=$site;$this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $this->db->exec("CREATE TABLE t_wd29_bridge_map(record_key TEXT PRIMARY KEY,kind TEXT,local_id INTEGER,fingerprint TEXT DEFAULT '',local_hash TEXT DEFAULT '',snapshot TEXT,UNIQUE(kind,local_id));CREATE TABLE t_wd29_bridge_queue(seq INTEGER PRIMARY KEY AUTOINCREMENT,event_id TEXT,direction TEXT,kind TEXT,record_key TEXT,payload TEXT,state TEXT DEFAULT 'pending',attempts INTEGER DEFAULT 0,next_try INTEGER DEFAULT 0,error TEXT DEFAULT '',created_at TEXT,UNIQUE(event_id,direction));");}
 function prefix(){return 't_';}function site(){return $this->site;}function config(){return ['mode'=>'disabled'];}
 function sql($query,$args=[]){if(strpos($query,'GET_LOCK')!==false)return [['acquired'=>1]];if(strpos($query,'RELEASE_LOCK')!==false)return [];if($query==='START TRANSACTION')$query='BEGIN';$query=str_replace('INSERT IGNORE','INSERT OR IGNORE',$query);$s=$this->db->prepare($query);$s->execute($args);return preg_match('/^SELECT/',$query)?$s->fetchAll(PDO::FETCH_ASSOC):$s->rowCount();}
 function ids($kind,$offset,$limit){return array_slice(array_keys($this->orders),$offset,$limit);}
 function orderSyncable($id){return isset($this->orders[$id])&&$this->orders[$id]['status']!=='checkout-draft';}
 function order($id){if(!isset($this->orders[$id]))throw new RuntimeException('Missing order');return ['key'=>$this->orders[$id]['key']??$this->engine->identity('order',$id)]+$this->orders[$id];}
 function manualOrderSummary($id){return ['number'=>'NATIVE-'.$id,'status'=>'native-display','status_label'=>'État natif','total'=>$this->orders[$id]['total'],'currency'=>'EUR'];}
 function orderConflictSnapshot($id){return $this->order($id);}
 function applyOrder($data,$map){$id=$map?(int)$map['local_id']:100+count($this->orders);$this->orders[$id]=$data;$this->writes++;return $id;}
}
function check($value,$label){if(!$value)throw new RuntimeException($label);}
function refuses($f,$label){try{$f();}catch(Throwable $e){return;}throw new RuntimeException($label);}
$a=new ManualAdapter('woo');$source=new Engine($a);$a->engine=$source;$b=new ManualAdapter('ps');$target=new Engine($b);$b->engine=$target;
$a->orders[1]=['number'=>'DEMO-1','status'=>'on-hold','total'=>'22.80','currency'=>'EUR','items'=>[['name'=>'Palets','quantity'=>1]],'billing'=>['email'=>'private@example.invalid']];$a->orders[2]=array_merge($a->orders[1],['status'=>'checkout-draft']);
$scan=$source->manualOrderScan();check(count($scan['rows'])===1,'Draft excluded');check(!str_contains(json_encode($scan),'private@example.invalid'),'No contact details in preview');check(!$source->sql('SELECT * FROM {b}map'),'Preview wrote mappings');$row=$scan['rows'][0];check($row['summary']['status_label']==='État natif','Native display summary missing');check($row['status']==='on-hold','Display status changed synchronization status');
$comp=$target->manualOrderCompare([$row])['rows'][0];check($comp['state']==='missing','Missing order not detected');$export=$source->manualOrderExport($row['key'],$row['hash']);
refuses(fn()=>$source->manualOrderExport($row['key'],str_repeat('0',64)),'Stale source accepted');refuses(fn()=>$source->manualOrderExport('ps:order:1',$row['hash']),'Wrong source accepted');
Licence::$allowed=false;refuses(fn()=>$target->manualOrderApply($export,''),'Unlicensed write accepted');Licence::$allowed=true;
refuses(fn()=>$target->manualOrderApply(array_merge($export,['hash'=>str_repeat('0',64)]),''),'Invalid payload hash accepted');
$applied=$target->manualOrderApply($export,'');check($applied['state']==='applied'&&$b->writes===1,'Manual apply while disabled failed');check($source->mode()==='disabled'&&$target->mode()==='disabled','Mode changed');
check($target->manualOrderApply($export,'')['state']==='same'&&$b->writes===1,'Duplicate apply created another order');$compared=$target->manualOrderCompare([$row])['rows'][0];check($compared['state']==='same'&&$compared['destination_summary']['status']==='native-display','Native presentation must not affect comparison fingerprints');$source->manualOrderAck($row['key'],$row['hash'],$export['through']);check($source->mapping($row['key'])['fingerprint']===$row['hash'],'Source acknowledgement not persisted');
$a->orders[1]['status']='processing';$new=$source->manualOrderScan()['rows'][0];check($target->manualOrderCompare([$new])['rows'][0]['state']==='changed','Delta missed source change');$export=$source->manualOrderExport($new['key'],$new['hash']);refuses(fn()=>$target->manualOrderApply($export,''),'Stale destination accepted');
$b->orders[$applied['local_id']]['total']='999.00';check($target->manualOrderCompare([$new])['rows'][0]['state']==='conflict','Local edit not detected');refuses(fn()=>$target->manualOrderApply($export,$row['hash']),'Local edit overwritten');
$b->orders[$applied['local_id']]['total']='22.80';$target->manualOrderApply($export,$row['hash']);check($b->writes===2,'Update not applied');
$a->orders[1]['status']='completed';check($source->manualOrderAck($new['key'],$new['hash'],0)['state']==='source_changed','Changed source falsely acknowledged');
refuses(fn()=>$target->receive(['source'=>'ps','op'=>'manual_orders_scan']),'Wrong peer platform accepted');refuses(fn()=>$target->manualOrderCompare(array_fill(0,21,$row)),'Unbounded batch accepted');
check($target->manualOrderScan()['rows']===[],'Mirrors were listed as originals');
echo "PASS: read-only paused delta, draft/privacy filtering, guarded manual apply, idempotency, stale snapshots, licence, local conflicts, acknowledgement and bounds\n";
}
