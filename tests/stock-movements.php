<?php
/** Execute real adapter stock writes with a native ledger API fixture. */
class Context {public static $context;public static function getContext(){return self::$context??(self::$context=(object)['shop'=>(object)['id'=>1],'employee'=>null]);}}
class Validate {public static function isLoadedObject($o){return !empty($o->id);}}
class Configuration {public static function get($k){return $k==='PS_STOCK_MVT_INC_EMPLOYEE_EDITION'?1:2;}}
class StockMvtReason {public $id;public $sign;public function __construct($id){$this->id=$id;$this->sign=$id===1?1:-1;}}
#[AllowDynamicProperties] class StockMvt {public function add(){if(preg_match('/[0-9]/',(string)$this->employee_firstname))throw new RuntimeException('Invalid native name');StockAvailable::$movements[]=['product'=>10,'variant'=>StockAvailable::$variant,'delta'=>$this->sign*$this->physical_quantity,'actor'=>$this->id_employee,'reason'=>$this->id_stock_mvt_reason];return true;}}
class Employee {public $id;public $firstname;public $lastname;}
class Combination {public $id_product=10;public function __construct($id){}}
class StockAvailable {
 public static $variant=0;public static $quantity=10;public static $movements=[];public static $fail=false;
 private static function change($pid,$aid,$delta,$shop,$movement){self::$variant=$aid;if(self::$fail)throw new RuntimeException('Native failure');if($movement&&$delta!==0){$actor=Context::getContext()->employee;if(!$actor||$actor->id===null)throw new RuntimeException('Stock ledger needs an actor');self::$movements[]=['product'=>$pid,'variant'=>$aid,'delta'=>$delta,'shop'=>$shop,'actor'=>$actor->id];}self::$quantity+=$delta;return true;}
 public static function getQuantityAvailableByProduct($pid,$aid,$shop){return self::$quantity;}
 public static function getStockAvailableIdByProductId($pid,$aid,$shop){self::$variant=$aid;return 5;}
 public static function setQuantity($pid,$aid,$q,$shop,$movement=true){return self::change($pid,$aid,$q-self::$quantity,$shop,$movement);}
 public static function updateQuantity($pid,$aid,$delta,$shop,$movement=false){return self::change($pid,$aid,$delta,$shop,$movement);}
 public static function setProductOutOfStock($pid,$allow,$shop){}
}
require dirname(__DIR__).'/includes/Protocol.php';require dirname(__DIR__).'/includes/PrestaAdapter.php';
function check($ok,$m){if(!$ok)throw new RuntimeException($m);}
$a=new WD29\Bridge\PrestaAdapter();$map=['kind'=>'product','local_id'=>10];
$a->stockSet($map,15);check(StockAvailable::$quantity===15&&count(StockAvailable::$movements)===1&&StockAvailable::$movements[0]['delta']===5,'Absolute stock change omitted its native movement');check(Context::getContext()->employee===null,'System actor leaked');
$a->stockSet($map,15);check(count(StockAvailable::$movements)===1,'No-op stock set added movement');
$a->stockDelta($map,-2);check(StockAvailable::$quantity===13&&StockAvailable::$movements[1]['delta']===-2,'Delta lost native movement');
$employee=new Employee();$employee->id=17;$employee->firstname='Test';$employee->lastname='Staff';Context::getContext()->employee=$employee;$a->stockSet(['kind'=>'variant','local_id'=>4],20);check(StockAvailable::$movements[2]['variant']===4&&StockAvailable::$movements[2]['actor']===17&&Context::getContext()->employee===$employee,'Variant/staff attribution wrong');
Context::getContext()->employee=null;StockAvailable::$fail=true;$failed=false;try{$a->stockSet($map,30);}catch(RuntimeException $e){$failed=true;}check($failed&&Context::getContext()->employee===null,'Native failure swallowed or actor leaked');StockAvailable::$fail=false;
$a->stockSet($map,null);check(StockAvailable::$quantity===20&&count(StockAvailable::$movements)===3,'Unknown quantity fabricated a movement');
$e=new class {public function sql($q,$args){return true;}};$a->engine=$e;$initial=new ReflectionMethod($a,'initializeStock');$initial->setAccessible(true);$initial->invoke($a,10,0,'woo:product:29',[['key'=>'woo:product:29','quantity'=>25,'backorders'=>false]]);check(StockAvailable::$quantity===25&&StockAvailable::$movements[3]['delta']===5,'Initial stock omitted native movement');
echo "PASS real PrestaAdapter stock ledger: absolute/delta/initial changes, no-op, variants, unknown stock, staff/system attribution and exception cleanup\n";
