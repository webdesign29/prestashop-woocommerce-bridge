<?php
require_once __DIR__.'/manual-orders.php';
use WD29\Bridge\Engine;
class ProductCreationAdapter extends ManualAdapter {
 public $products=[];
 function __construct($site){parent::__construct($site);$this->db->exec("ALTER TABLE t_wd29_bridge_map ADD quantity INTEGER;ALTER TABLE t_wd29_bridge_map ADD stock_initialized INTEGER DEFAULT 0;CREATE TABLE t_wd29_bridge_deleted_products(record_key TEXT PRIMARY KEY);CREATE TABLE t_wd29_bridge_issues(issue_key TEXT PRIMARY KEY,code TEXT,first_seen INTEGER,last_seen INTEGER,occurrences INTEGER);");}
 function config(){return ['mode'=>'audit','conflict_policy'=>'ps'];}
 function product($id){return ['key'=>$this->engine->identity('product',$id)]+$this->products[$id];}
 function applyProduct($data,$map){$id=$map?(int)$map['local_id']:max(array_keys($this->products))+1;$this->engine->bind($data['key'],'product',$id);unset($data['key']);$this->products[$id]=$data;return $id;}
 function notice($message){}
}
$a=new ProductCreationAdapter('woo');$b=new ProductCreationAdapter('ps');$woo=new Engine($a);$ps=new Engine($b);$a->engine=$woo;$b->engine=$ps;
$a->products[1]=['name'=>'Created independently in Woo','sku'=>'','inventory'=>[]];$b->products[1]=['name'=>'Created independently in PS','sku'=>'','inventory'=>[]];
$woo->capture('product',1);$ps->capture('product',1);
$exchange=function($source,$target){$messages=[];foreach($source->sql("SELECT * FROM {b}queue WHERE direction='out'") as $row){$messages[]=['id'=>$row['event_id'],'key'=>$row['record_key'],'kind'=>$row['kind'],'payload'=>json_decode($row['payload'],true)];}$target->receive(['source'=>$source->adapter->site(),'op'=>'events','events'=>$messages]);$method=new ReflectionMethod(Engine::class,'apply');$method->setAccessible(true);foreach($target->sql("SELECT * FROM {b}queue WHERE direction='in' AND state='pending'") as $row){$method->invoke($target,$row);}};
$exchange($woo,$ps);$exchange($ps,$woo);$exchange($woo,$ps);$exchange($ps,$woo);
foreach([$woo,$ps] as $e){check(count($e->adapter->products)===2,'Concurrent originals collapsed or replay duplicated one');check($e->mapping('woo:product:1')['local_id']!==$e->mapping('ps:product:1')['local_id'],'Different origins share one native product');$names=array_column($e->adapter->products,'name');sort($names);check($names===['Created independently in PS','Created independently in Woo'],'Priority rule overwrote an independent original');}
echo "PASS: two independent stores create same numeric ID and blank SKU before exchange; both originals survive both directions and replay with PrestaShop priority\n";
