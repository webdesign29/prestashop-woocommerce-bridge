<?php
/** Engine retry integration on an isolated prefix in the disposable MySQL unit database. */
require __DIR__.'/../includes/Protocol.php';require __DIR__.'/../includes/OrderConflicts.php';require __DIR__.'/../includes/Engine.php';
use WD29\Bridge\Engine;use WD29\Bridge\Protocol;
final class UpgradeOrderAdapter {
    public $db;public $orders=[];public $changed=[];public $writes=0;
    public function __construct(){$this->db=new PDO('mysql:unix_socket=/tmp/wd29-bridge-tests/mysql.sock;dbname=wd29unit','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    public function prefix(){return 'order_upgrade_';}public function site(){return 'woo';}public function config(){return ['mode'=>'live'];}
    public function sql($query,$args=[]){$q=$this->db->prepare($query);$q->execute($args);return preg_match('/^\s*SELECT/i',$query)?$q->fetchAll(PDO::FETCH_ASSOC):$q->rowCount();}
    public function orderConflictSnapshot($id){if(!empty($this->changed[$id])){throw new RuntimeException('Native mirror changed independently');}return $this->orders[$id];}
    public function order($id){return $this->orders[$id];}
    public function applyOrder($data,$map){$this->writes++;$this->orders[(int)$map['local_id']]=$data;return (int)$map['local_id'];}
}
function upgradeCheck($value,$message){if(!$value){throw new RuntimeException($message);}}
$a=new UpgradeOrderAdapter();$e=new Engine($a);$e->install();foreach(['queue','map'] as $table){$e->sql('TRUNCATE TABLE {b}'.$table);}
$legacy=['key'=>'ps:order:1','number'=>'FIXTURE','status'=>'processing','currency'=>'EUR','total'=>'24.00','tax'=>'4.00','shipping_net'=>'0.00','shipping_tax'=>'0.00','discount'=>'0.00','billing'=>['first_name'=>'Synthetic'],'shipping'=>[],'items'=>[['product'=>'ps:product:1','name'=>'Fixture','quantity'=>2,'net'=>'20.00','tax'=>'4.00']],'created'=>'2026-01-01T00:00:00+00:00'];
$make=function($id,$change=null)use($a,$e,$legacy){$native=$legacy;$native['key']='ps:order:'.$id;$a->orders[$id]=$native;$e->bind($native['key'],'order',$id);$e->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?',[str_repeat('a',64),str_repeat('a',64),$native['key']]);$incoming=$native;$incoming['custom_fields']=[];$incoming['refunds']=[];$incoming['items'][0]['line_id']=(string)(100+$id);if($change){$change($incoming);}$event=bin2hex(random_bytes(16));$e->enqueue('in','order',$incoming['key'],['base'=>str_repeat('b',64),'hash'=>Protocol::fingerprint($incoming),'data'=>$incoming],$event);$e->sql("UPDATE {b}queue SET state='conflict' WHERE event_id=?",[$event]);return $event;};
$equivalent=$make(1);
$status=$make(2,function(&$d){$d['status']='cancelled';});
$money=$make(3,function(&$d){$d['total']='25.00';});
$meta=$make(4,function(&$d){$d['custom_fields']=['note'=>['present'=>true,'value'=>'Synthetic change']];});
$guarded=$make(5);$a->changed[5]=true;
$report=$e->orderConflictReport();upgradeCheck(strpos($report[0]['resolution'],'safe retry')!==false,'Equivalent upgrade not reported as retryable');
$serialized=json_encode($report);upgradeCheck(strpos($serialized,'Synthetic')===false,'Conflict review disclosed address/custom value');
upgradeCheck($e->retryEquivalentOrderConflicts()===1,'Retry released non-equivalent/unguarded conflicts');
foreach([$status,$money,$meta,$guarded] as $id){upgradeCheck($e->sql('SELECT state FROM {b}queue WHERE event_id=?',[$id])[0]['state']==='conflict','Real business change escaped review');}
$apply=new ReflectionMethod(Engine::class,'apply');$apply->setAccessible(true);
$row=$e->sql('SELECT * FROM {b}queue WHERE event_id=?',[$equivalent])[0];$apply->invoke($e,$row);upgradeCheck($e->sql('SELECT state FROM {b}queue WHERE event_id=?',[$equivalent])[0]['state']==='applied'&&$a->writes===1,'Equivalent augmentation did not apply');
// Retry approval is not cached: a subsequent native edit must close the gate again.
$race=$make(6);upgradeCheck($e->retryEquivalentOrderConflicts()===1,'Equivalent race fixture not released');$a->changed[6]=true;$apply->invoke($e,$e->sql('SELECT * FROM {b}queue WHERE event_id=?',[$race])[0]);upgradeCheck($e->sql('SELECT state FROM {b}queue WHERE event_id=?',[$race])[0]['state']==='conflict'&&$a->writes===1,'Native guard was not repeated at application');
// Identity and hash must remain valid even when the business shape otherwise matches.
$badHash=$make(7);$p=json_decode($e->sql('SELECT payload FROM {b}queue WHERE event_id=?',[$badHash])[0]['payload'],true);$p['hash']=str_repeat('f',64);$e->sql('UPDATE {b}queue SET payload=? WHERE event_id=?',[Protocol::encode($p),$badHash]);upgradeCheck($e->retryEquivalentOrderConflicts()===0,'Invalid hash admitted to safe retry');
echo "PASS Engine order upgrade retry: equivalent additions apply; status/money/custom/native changes stay conflicted; apply rechecks guard; invalid hash blocked\n";
