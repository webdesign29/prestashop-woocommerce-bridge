<?php
/** Disposable database test: authoritative deletion, replay, stale updates and missing snapshots. */
require __DIR__.'/../includes/Protocol.php';require __DIR__.'/../includes/Engine.php';
use WD29\Bridge\Engine;use WD29\Bridge\Protocol;
final class DeletedProductAdapter {
    public $db;public $engine;public $siteName;public $products=[];public $archived=[];public $offsets=[];
    public function __construct($site){$this->siteName=$site;$this->db=new PDO('mysql:unix_socket=/tmp/wd29-bridge-tests/mysql.sock;dbname=wd29unit','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    public function site(){return $this->siteName;}public function prefix(){return 'deletion_'.$this->siteName.'_';}
    public function config(){return ['mode'=>'live'];}public function notice($text){}public function workerStatus($value=null){return ['state'=>'completed','at'=>gmdate('c')];}
    public function scanOffset($key){return $this->offsets[$key]??0;}public function saveScanOffset($key,$value){$this->offsets[$key]=$value;}
    public function sql($query,$args=[]){$stmt=$this->db->prepare($query);$stmt->execute($args);return preg_match('/^\s*SELECT/i',$query)?$stmt->fetchAll(PDO::FETCH_ASSOC):$stmt->rowCount();}
    public function productExists($id){return isset($this->products[$id]);}
    public function product($id){if(!$this->productExists($id)){throw new RuntimeException('Missing product');}$key=$this->engine->identity('product',$id);return ['key'=>$key,'name'=>'Fixture product','inventory'=>[['key'=>$key,'quantity'=>$this->products[$id]['quantity']]],'archived'=>false];}
    public function stockQuantity($map){return $this->products[$map['local_id']]['quantity'];}
    public function archiveProduct($id){$this->archived[$id]=($this->archived[$id]??0)+1;}
    public function applyProduct($data,$map){throw new RuntimeException('Full product import must never run for source deletion or stale replay.');}
}
function expectDeleted($value,$message){if(!$value){throw new RuntimeException($message);}}
function wireDeleted($engine,$source,$event){$engine->receive(['source'=>$source,'op'=>'events','events'=>[['id'=>$event['event_id'],'kind'=>'product','key'=>$event['record_key'],'payload'=>json_decode($event['payload'],true)]]]);$supersede=new ReflectionMethod(Engine::class,'supersedeDeletedCatalog');$supersede->setAccessible(true);$supersede->invoke($engine);$row=$engine->sql("SELECT * FROM {b}queue WHERE direction='in' AND event_id=?",[$event['event_id']])[0];if($row['state']==='pending'){$apply=new ReflectionMethod(Engine::class,'apply');$apply->setAccessible(true);$apply->invoke($engine,$row);}return $engine->sql("SELECT state FROM {b}queue WHERE direction='in' AND event_id=?",[$event['event_id']])[0]['state'];}
foreach(['woo','ps'] as $site){$adapter=new DeletedProductAdapter($site);$engine=new Engine($adapter);$adapter->engine=$engine;$engine->install();foreach(['queue','map','issues','deleted_products'] as $table){$engine->sql('TRUNCATE TABLE {b}'.$table);}if($site==='woo'){$woo=$engine;$wa=$adapter;}else{$ps=$engine;$pa=$adapter;}}
$wa->products[1]=['quantity'=>20];$woo->capture('product',1);$key='woo:product:1';$original=$woo->sql("SELECT * FROM {b}queue WHERE direction='out' ORDER BY seq DESC LIMIT 1")[0];
$pa->products[101]=['quantity'=>13];$ps->bind($key,'product',101);$ps->sql('UPDATE {b}map SET quantity=13,stock_initialized=1,fingerprint=? WHERE record_key=?',[json_decode($original['payload'],true)['hash'],$key]);
$ps->enqueue('in','product',$key,json_decode($original['payload'],true),$original['event_id']);$ps->sql("UPDATE {b}queue SET state='conflict' WHERE direction='in'");
unset($wa->products[1]);$woo->scanDeletedProducts();$deleted=$woo->sql("SELECT * FROM {b}queue WHERE direction='out' AND state='pending' ORDER BY seq DESC LIMIT 1")[0];
expectDeleted(json_decode($deleted['payload'],true)['data']['source_deleted']===true,'No explicit source deletion marker');expectDeleted(wireDeleted($ps,'woo',$deleted)==='applied','Mirror deletion not applied');
expectDeleted($ps->sql("SELECT state FROM {b}queue WHERE event_id=?",[$original['event_id']])[0]['state']==='ignored','Older catalog conflict blocked authoritative deletion');
expectDeleted($pa->archived[101]===1 && $pa->products[101]['quantity']===13,'Archiving modified known native stock or did not archive');expectDeleted((int)$ps->mapping($key)['quantity']===13,'Archiving rebased bridge stock');
wireDeleted($ps,'woo',$deleted);expectDeleted($pa->archived[101]===1,'Deletion replay archived twice');
$stale=$original;$stale['event_id']=bin2hex(random_bytes(16));expectDeleted(wireDeleted($ps,'woo',$stale)==='ignored','Stale update resurrected archived mirror');
$stale['event_id']=bin2hex(random_bytes(16));expectDeleted(wireDeleted($woo,'ps',$stale)==='ignored','Stale peer update resurrected deleted original');
$before=count($woo->report());$woo->scanDeletedProducts();$woo->capture('product',1);expectDeleted(count($woo->report())===$before,'Deleted source re-enqueued indefinitely');
// If a peer update arrives before the scan, deletion is still detected and emitted.
$wa->products[2]=['quantity'=>7];$woo->capture('product',2);$r=$woo->sql("SELECT * FROM {b}queue WHERE direction='out' AND record_key='woo:product:2' ORDER BY seq DESC LIMIT 1")[0];unset($wa->products[2]);$r['event_id']=bin2hex(random_bytes(16));expectDeleted(wireDeleted($woo,'ps',$r)==='ignored','Unscanned missing source was recreated');expectDeleted((bool)$woo->sql("SELECT * FROM {b}deleted_products WHERE record_key='woo:product:2'"),'Unscanned deletion not recorded');
// No catalog snapshot is invented when the original has already gone.
$woo->bind('woo:product:99','product',99);$woo->scanDeletedProducts();expectDeleted(in_array('deletion_snapshot_missing',array_column($woo->diagnostics()['issues'],'code'),true),'Missing snapshot was silent');
// A peer cannot claim authority to permanently delete our original product.
$wa->products[3]=['quantity'=>9];$woo->capture('product',3);$forged=$deleted;$forged['event_id']=bin2hex(random_bytes(16));$forged['record_key']='woo:product:3';$p=json_decode($forged['payload'],true);$p['data']['key']='woo:product:3';$p['hash']=Engine::catalogHash($p['data']);$forged['payload']=Protocol::encode($p);expectDeleted(wireDeleted($woo,'ps',$forged)==='pending','Peer deletion marker accepted for local origin');expectDeleted(!isset($wa->archived[3])&&$wa->productExists(3),'Peer archived local original');
// An as-yet unmapped tombstone must not create a product just to archive it.
$unmapped=$deleted;$unmapped['event_id']=bin2hex(random_bytes(16));$unmapped['record_key']='woo:product:555';$p=json_decode($unmapped['payload'],true);$p['data']['key']=$unmapped['record_key'];$p['hash']=Engine::catalogHash($p['data']);$unmapped['payload']=Protocol::encode($p);expectDeleted(wireDeleted($ps,'woo',$unmapped)==='applied'&&$ps->mapping($unmapped['record_key'])===null,'Unmapped deleted source recreated');
echo "PASS: authoritative product deletion, unchanged known stock, replay, stale source/mirror protection, missing snapshot and origin enforcement\n";
