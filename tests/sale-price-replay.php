<?php
/** Real adapter import against a unique-index database and bounded native API fixtures. */
namespace WD29\Bridge {
 class Gallery {public static function apply($p,$ids,&$meta,$remove){}}
 class FixtureEngine {
  public $maps=[];
  public function mapping($key){return $this->maps[$key]??null;}
  public function bind($key,$kind,$id){$this->maps[$key]=($this->maps[$key]??[])+['local_id'=>$id,'kind'=>$kind,'snapshot'=>null];}
  public function sql($sql,$args){if(strpos($sql,'SET snapshot=')!==false){$this->maps[$args[1]]['snapshot']=$args[0];return true;}throw new \Exception('Unexpected engine SQL');}
 }
}
namespace {
 define('_DB_PREFIX_','ps_');
 function pSQL($v,$html=false){return str_replace("'","''",$v);}
 class Db {public static $pdo;public static function getInstance(){return new self;}public function executeS($sql){return self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}public function execute($sql){return self::$pdo->exec($sql)!==false;}}
 class Configuration {public static function get($name){return ['PS_CURRENCY_DEFAULT'=>1,'PS_HOME_CATEGORY'=>2,'PS_LANG_DEFAULT'=>1,'WD29_BRIDGE_CONFIG'=>'{}'][$name]??0;}}
 class Context {public static $context;public static function getContext(){return self::$context??(self::$context=(object)['shop'=>(object)['id'=>1],'employee'=>null]);}}
 class Validate {public static function isLoadedObject($o){return !empty($o->id);}}
 class Tools {public static function str2url($v){return 'fixture';}public static function purifyHTML($v){return $v;}}
 class Language {public static function getLanguages($active){return [['id_lang'=>1]];}}
 class Currency {public $iso_code='EUR';public function __construct($id){}}
 #[AllowDynamicProperties] class Product {
  public $id;public $id_supplier=0;
  public function __construct($id=null){$this->id=$id;}
  public function hasAttributes(){return false;}public function save(){if(!$this->id)$this->id=10;return true;}
  public function updateCategories($ids){}public function setWsProductFeatures($f){return true;}
  public static function updateDefaultAttribute($id){}public static function flushPriceCache(){}
 }
 #[AllowDynamicProperties] class SpecificPrice {
  public $id;public $id_specific_price_rule=0;
  public function __construct($id=0){if($id){$r=Db::$pdo->query('SELECT * FROM ps_specific_price WHERE id_specific_price='.(int)$id)->fetch(PDO::FETCH_ASSOC);if($r){foreach($r as $k=>$v){if($k==='id_specific_price')$this->id=(int)$v;else $this->$k=$v;}}}}
  public function save(){
   $fields=get_object_vars($this);$id=$fields['id'];unset($fields['id']);$cols=array_keys($fields);
   if($id){$q=Db::$pdo->prepare('UPDATE ps_specific_price SET '.implode(',',array_map(static function($c){return '`'.$c.'`=?';},$cols)).' WHERE id_specific_price=?');$q->execute(array_merge(array_values($fields),[$id]));}
   else{$q=Db::$pdo->prepare('INSERT INTO ps_specific_price (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');$q->execute(array_values($fields));$this->id=(int)Db::$pdo->lastInsertId();}
   return true;
  }
 }
 require dirname(__DIR__).'/includes/Protocol.php';require dirname(__DIR__).'/includes/PrestaAdapter.php';
 Db::$pdo=new PDO('sqlite::memory:');Db::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 Db::$pdo->exec('CREATE TABLE ps_specific_price(id_specific_price INTEGER PRIMARY KEY AUTOINCREMENT,id_product INTEGER,id_product_attribute INTEGER,id_shop INTEGER,id_shop_group INTEGER,id_currency INTEGER,id_country INTEGER,id_group INTEGER,id_customer INTEGER,id_cart INTEGER,price REAL,from_quantity INTEGER,reduction REAL,reduction_type TEXT,reduction_tax INTEGER,`from` TEXT,`to` TEXT,id_specific_price_rule INTEGER,UNIQUE(id_product,id_product_attribute,id_shop,id_shop_group,id_currency,id_country,id_group,id_customer,id_cart,from_quantity,`from`,`to`,id_specific_price_rule))');
 $a=new WD29\Bridge\PrestaAdapter();$a->engine=new WD29\Bridge\FixtureEngine();
 $data=['key'=>'woo:product:29','type'=>'simple','name'=>'Fixture','sku'=>'','description'=>'','short_description'=>'','status'=>'publish','virtual'=>false,'currency'=>'EUR','prices'=>['regular'=>'20','sale'=>'15','tax_rate'=>0,'basis'=>'net'],'weight_kg'=>'0','categories'=>[],'attributes'=>[],'variants'=>[],'inventory'=>[],'images'=>[]];
 function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
 function nativeRule($overrides=[]){$p=new SpecificPrice();foreach(array_replace(['id_product'=>10,'id_product_attribute'=>0,'id_shop'=>1,'id_shop_group'=>0,'id_currency'=>0,'id_country'=>0,'id_group'=>0,'id_customer'=>0,'id_cart'=>0,'price'=>-1,'from_quantity'=>1,'reduction'=>2,'reduction_type'=>'amount','reduction_tax'=>0,'from'=>'0000-00-00 00:00:00','to'=>'0000-00-00 00:00:00'],$overrides) as $k=>$v)$p->$k=$v;$p->save();return $p->id;}
 function runImport(){global $a,$data;return $a->applyProduct($data,$a->engine->mapping($data['key']));}
 $native=nativeRule();$other=nativeRule(['id_customer'=>7,'reduction'=>9]);$rule=nativeRule(['id_specific_price_rule'=>3,'reduction'=>7]);
 $a->engine->bind($data['key'],'product',10);
 // Reproduces KerAwen's native broad sale with no retained connector price ID.
 runImport();$meta=json_decode($a->engine->mapping($data['key'])['snapshot'],true);
 check($meta['specific_prices'][$data['key']]===$native,'Did not adopt existing exact-scope rule');
 check((float)(new SpecificPrice($native))->reduction===5.0,'Wrong adopted reduction');
 check((float)(new SpecificPrice($other))->reduction===9.0&&(float)(new SpecificPrice($rule))->reduction===7.0,'Scoped/native rule changed');
 runImport();check((int)Db::$pdo->query('SELECT COUNT(*) FROM ps_specific_price')->fetchColumn()===3,'Replay duplicated prices');
 $data['prices']['sale']='12';runImport();check((float)(new SpecificPrice($native))->reduction===8.0,'Update not applied');
 $data['prices']['sale']=null;runImport();check((float)(new SpecificPrice($native))->reduction===0.0,'Sale removal not applied');
 // Lost/deleted metadata target can recover; a changed tracked scope cannot be moved.
 $a->engine->maps[$data['key']]['snapshot']=json_encode(['specific_prices'=>[$data['key']=>9999]]);$data['prices']['sale']='11';runImport();check((float)(new SpecificPrice($native))->reduction===9.0,'Missing mapped ID did not recover');
 $a->engine->maps[$data['key']]['snapshot']=json_encode(['specific_prices'=>[$data['key']=>$other]]);$blocked=false;try{runImport();}catch(RuntimeException $e){$blocked=true;}check($blocked,'Changed tracked scope not blocked');check((float)(new SpecificPrice($other))->reduction===9.0,'Foreign scope overwritten');
 echo "PASS real PrestaAdapter sale import: missing ID, exact-scope adoption, update, removal, replay, stale ID, changed scope, unrelated customer/rule preservation\n";
}
