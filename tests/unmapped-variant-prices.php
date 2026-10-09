<?php
/** Execute the real adapter export with unmapped combinations and distinct native sale prices. */
namespace WD29\Bridge {
    class Suppliers {public static function export($product){return [];}}
    class PreviewEngine {
        public $mappings=0;
        public function identity($kind,$id){return 'ps:'.$kind.':'.$id;}
        public function mapping($key){$this->mappings++;if(strpos($key,':variant:')!==false)throw new \RuntimeException('Unmapped preview must not resolve combination IDs through mappings');return null;}
    }
}
namespace {
    define('_DB_PREFIX_','ps_');
    function pSQL($value,$html=false){return addslashes($value);}
    class Db {public static function getInstance(){return new self;}public function executeS($sql){return [];}public function execute($sql){throw new Exception('Preview wrote to database');}}
    class Configuration {public static function get($name){return ['PS_LANG_DEFAULT'=>1,'PS_COUNTRY_DEFAULT'=>1,'PS_CURRENCY_DEFAULT'=>1,'PS_WEIGHT_UNIT'=>'kg','PS_DIMENSION_UNIT'=>'cm'][$name]??0;}}
    class Context {public static function getContext(){return (object)['shop'=>(object)['id'=>1],'link'=>(object)[]];}}
    class Validate {public static function isLoadedObject($object){return (bool)$object->id;}}
    class Pack {public static function isPack($id){return false;}}
    class Address {public $id_country;}
    class Country {public function __construct($id){}}
    class Customer {}
    class Cart {}
    class Currency {public $iso_code='EUR';public function __construct($id){}}
    class Tag {public static function getProductTags($id){return [];}}
    class StockAvailable {public static function getQuantityAvailableByProduct($pid,$aid,$shop){return 5;}public static function outOfStock($pid,$shop){return 0;}}
    class Combination {
        public $id;public $ean13='';public $upc='';public $isbn='';public $mpn='';public $wholesale_price='0';
        public function __construct($id){$this->id=$id;}
    }
    class Product extends Combination {
        public $name='Produit breton';public $reference='BRETAGNE';public $description='';public $description_short='';public $active=0;public $is_virtual=false;
        public $price=100;public $weight=1;public $ecotax=0;public $id_supplier=0;public $id_manufacturer=0;public $depth=1;public $width=1;public $height=1;public $meta_title='';public $meta_description='';public $link_rewrite='produit-breton';
        public static $requested=[];
        public function hasAttributes(){return true;}
        public function getTaxesRate($address){return 0;}
        public function getCategories(){return [];}
        public function getImages($lang){return [];}
        public function getAttributeCombinations($lang){return [
            ['id_product_attribute'=>71,'group_name'=>'Taille','attribute_name'=>'Petit','reference'=>'BRETAGNE-P','price'=>7,'weight'=>0],
            ['id_product_attribute'=>72,'group_name'=>'Taille','attribute_name'=>'Grand','reference'=>'BRETAGNE-G','price'=>17,'weight'=>0],
        ];}
        public static function getPriceStatic($pid,$tax,$aid,...$args){self::$requested[]=$aid;if(!in_array($aid,[71,72],true))throw new Exception('Wrong native combination price requested');return $aid===71?101:105;}
        public static function isAvailableWhenOutOfStock($mode){return false;}
    }
    require dirname(__DIR__).'/includes/PrestaAdapter.php';
    set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});
    $adapter=new WD29\Bridge\PrestaAdapter;$adapter->engine=new WD29\Bridge\PreviewEngine;
    $data=$adapter->product(7);
    if(Product::$requested!==[71,72])throw new Exception('Did not use original combination IDs');
    if(array_column($data['variants'],'key')!==['ps:variant:71','ps:variant:72'])throw new Exception('Variant identity/order changed');
    if(array_column(array_column($data['variants'],'prices'),'regular')!==['107.000000','117.000000'])throw new Exception('Regular prices incorrect');
    if(array_column(array_column($data['variants'],'prices'),'sale')!==['101.000000','105.000000'])throw new Exception('Specific sale prices incorrect');
    if($adapter->engine->mappings!==1)throw new Exception('Unexpected per-variant mapping queries');
    if(array_keys($data['variants'])!==[0,1])throw new Exception('Wire variants no longer a sequential list');
    restore_error_handler();echo "PASS real adapter unmapped variant export: distinct regular/sale prices, zero writes/warnings, canonical IDs and no per-variant mapping queries\n";
}
