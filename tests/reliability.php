<?php
/** Disposable real-MySQL queue/transaction tests, independent from both native platform fixtures. */
require __DIR__.'/../includes/Protocol.php';
require __DIR__.'/../includes/Engine.php';
use WD29\Bridge\Engine;
final class ReliabilityAdapter {
    public $db; public $state=[]; public $broken=false; public $capturesBroken=false; public $engine;
    public function __construct() { $this->db=new PDO('mysql:unix_socket=/tmp/wd29-bridge-tests/mysql.sock;dbname=wd29unit','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    public function prefix(){return 'reliability_';} public function site(){return 'woo';}
    public function config(){return ['mode'=>'live','conflict_policy'=>'woo'];}
    public function sql($query,$args=[]){$stmt=$this->db->prepare($query);$stmt->execute($args);return preg_match('/^\s*SELECT/i',$query)?$stmt->fetchAll(PDO::FETCH_ASSOC):$stmt->rowCount();}
    public function ids($kind,$offset,$limit){return [];} public function scanOffset($kind){return 0;} public function saveScanOffset($kind,$offset){}
    public function workerStatus($state=null){if($state!==null){$this->state=['state'=>$state,'at'=>gmdate('c')];}return $this->state;}
    public function notice($text){} public function stockQuantity($map){return (int)$this->sql('SELECT quantity FROM reliability_stock WHERE id=?',[$map['local_id']])[0]['quantity'];}
    public function stockDelta($map,$delta){$this->sql('UPDATE reliability_stock SET quantity=quantity+? WHERE id=?',[$delta,$map['local_id']]);if($this->broken){throw new RuntimeException('Injected after native write');}}
    public function product($id){if($this->capturesBroken){throw new RuntimeException('Invalid fixture product');}$key=$this->engine->identity('product',$id);return ['key'=>$key,'inventory'=>[]];}
}
function check($value,$message){if(!$value){throw new RuntimeException($message);}}
$a=new ReliabilityAdapter();$e=new Engine($a);$a->engine=$e;
foreach(['queue','map','contacts','issues','account_links'] as $table){$a->sql('DROP TABLE IF EXISTS reliability_wd29_bridge_'.$table);}
$a->sql('DROP TABLE IF EXISTS reliability_stock');$a->sql('CREATE TABLE reliability_stock(id int PRIMARY KEY,quantity int) ENGINE=InnoDB');
$e->install();$e->bind('ps:product:1','product',1);$e->bind('ps:product:2','product',2);
$a->sql('INSERT INTO reliability_stock VALUES(1,10),(2,20)');$e->sql('UPDATE {b}map SET quantity=10,stock_initialized=1 WHERE local_id=1');$e->sql('UPDATE {b}map SET quantity=20,stock_initialized=1 WHERE local_id=2');
$one=['source'=>'ps','op'=>'events','events'=>[['id'=>str_repeat('a',32),'kind'=>'stock','key'=>'ps:product:1','payload'=>['delta'=>-2]]]];
$e->receive($one);$e->receive($one);check(count($e->report())===1,'Duplicate receipt created duplicate event');
$a->broken=true;$e->tick(false);check($a->stockQuantity(['local_id'=>1])===10,'Native write not rolled back');check($e->report()[0]['attempts']==1,'Failure not retried');
$e->enqueue('in','stock','ps:product:1',['delta'=>-3]);$a->broken=false;$e->enqueue('in','stock','ps:product:2',['delta'=>-4]);$e->tick(false);
check($a->stockQuantity(['local_id'=>1])===10,'Later same-record event bypassed backoff');check($a->stockQuantity(['local_id'=>2])===16,'Unrelated event blocked');
$e->sql('UPDATE {b}queue SET next_try=0');$e->tick(false);$e->tick(false);$e->receive($one);$e->tick(false);check($a->stockQuantity(['local_id'=>1])===5,'Replay double-applied or lost stock delta');
// Malformed storage must fail its event rather than abort the entire worker.
$e->enqueue('in','stock','ps:product:1',['delta'=>1]);$e->sql("UPDATE {b}queue SET payload='{' WHERE state='pending'");$e->enqueue('in','stock','ps:product:2',['delta'=>1]);$e->tick(false);check($a->stockQuantity(['local_id'=>2])===17,'Malformed event stopped unrelated work');
$e->sql("UPDATE {b}queue SET attempts=7,next_try=0 WHERE state='pending'");$e->tick(false);check($e->sql("SELECT state FROM {b}queue WHERE record_key='ps:product:1' ORDER BY seq DESC LIMIT 1")[0]['state']==='failed','Retry limit missing');
$codes=array_column($e->diagnostics()['issues'],'code');check(in_array('retry_exhausted',$codes,true),'No exhausted-retry diagnostic');
$a->capturesBroken=true;$e->capture('product',3);$e->capture('product',4);$a->capturesBroken=false;$e->capture('product',3);$issues=$e->sql('SELECT issue_key FROM {b}issues');check(count($issues)===1&&$issues[0]['issue_key']==='capture:product:4','Unrelated success cleared capture failure');
$a->state=['state'=>'completed','at'=>gmdate('c',time()-600)];check(in_array('worker_stale',array_column($e->diagnostics()['issues'],'code'),true),'Stale worker not detected');
// A second connection owns worker lock; the first must leave its state untouched.
$other=new ReliabilityAdapter();$other->sql('SELECT GET_LOCK(?,0)',['reliability_wd29_bridge_worker']);$before=$a->state;$e->tick(false);check($a->state===$before,'Concurrent worker ran');$other->sql('SELECT RELEASE_LOCK(?)',['reliability_wd29_bridge_worker']);
$reflection=new ReflectionMethod(Engine::class,'contactData');$reflection->setAccessible(true);
$contact=['key'=>'ps:customer:1','first_name'=>'','last_name'=>'','email'=>'','phone'=>'','company'=>'','billing'=>[],'shipping'=>[],'custom_fields'=>['loyalty_note'=>['present'=>true,'value'=>['a'=>1]]]];
check($reflection->invoke($e,$contact)['custom_fields']===$contact['custom_fields'],'Contact metadata lost');$contact['deleted']=true;check($reflection->invoke($e,$contact)['custom_fields']===[],'Deleted contact retained custom values');
echo "Reliability MySQL tests passed\n";
