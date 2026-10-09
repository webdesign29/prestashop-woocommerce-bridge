<?php
namespace Symfony\Component\Security\Csrf {
    interface CsrfTokenManagerInterface {}
    class CsrfToken { public $id; public $value; public function __construct($id,$value){$this->id=$id;$this->value=$value;} public function getValue(){return $this->value;} }
}
namespace PrestaShop\PrestaShop\Adapter {
    class SymfonyContainer { public static function getInstance(){return new self;}public function get($id){return new class {public function getToken($id){return new \Symfony\Component\Security\Csrf\CsrfToken($id,hash('sha256',$id));}public function isTokenValid($token){return hash_equals(hash('sha256',$token->id),$token->value);}};} }
}
namespace WD29\Bridge {
    class ProductLinks { public static $remote=0;public static $local=0;public static $state='ready';
        public static function local($engine,$id){self::$local++;return ['state'=>self::$state,'peer_host'=>'woo.example.test','key'=>'woo:product:7','origin'=>false];}
        public static function remote($engine,$id){self::$remote++;return ['state'=>'linked','url'=>'https://woo.example.test/product/7','peer_host'=>'woo.example.test'];}
    }
}
namespace {
    define('_PS_VERSION_',$argv[1]??'8.2.8');define('_DB_PREFIX_','ps_');
    class Validate {public static function isLoadedObject($o){return !empty($o->id);}}
    class Shop {const CONTEXT_SHOP=1;public static $multi=false;public static $context=1;public static function isFeatureActive(){return self::$multi;}public static function getContext(){return self::$context;}}
    class Tab {public static function getIdFromClassName($name){if($name!=='AdminProducts')throw new Exception('Unexpected permission scope');return 4;}}
    class Profile {public static $rights=['view'=>1,'edit'=>1];public static function getProfileAccess($profile,$tab){return self::$rights;}}
    class Db {public static $exists=true;public static function getInstance(){return new self;}public function getValue($sql){if(strpos($sql,'product_shop')===false)throw new Exception('Wrong table');return self::$exists?7:false;}}
    class Tools {public static function getAdminToken($name){return hash('sha256',$name);}}
    class Context {public static $context;public static function getContext(){return self::$context;}}
    class Employee {public $id=2;public $active=true;public $id_profile=2;public $logged=true;public $assigned=true;public function isLoggedBack(){return $this->logged;}public function hasAuthOnShop($id){return $this->assigned;}}
    class ModuleFixture {public $active=true;public function bridge(){return (object)[];}public function getPathUri(){return '/modules/wd29woobridge/';}}
    require dirname(__DIR__).'/includes/ProductLinksAdmin.php';
    $context=(object)['employee'=>new Employee,'shop'=>(object)['id'=>1],'link'=>new class {public function getAdminLink(){return 'https://ps.example.test/admin/index.php?controller=AdminWd29ProductLinks&token=csrf';}}];Context::$context=$context;
    $module=new ModuleFixture;$count=0;
    function check($yes,$message){global $count;$count++;if(!$yes)throw new Exception($message);}
    function denied($fn,$message){$before=\WD29\Bridge\ProductLinks::$remote;try{$fn();throw new LogicException('Accepted '.$message);}catch(RuntimeException $e){}check($before===\WD29\Bridge\ProductLinks::$remote,$message.' reached remote');}
    use WD29\Bridge\ProductLinksAdmin as Admin;
    use WD29\Bridge\ProductLinks as Links;
    check(Admin::canRead($context),'Authorized editor rejected');
    $token=Admin::token($context,7,1);$input=['id_product'=>'7','shop'=>'1','wd29_product_token'=>$token];$_SERVER['REQUEST_METHOD']='POST';
    check(Admin::lookup($module,$input)['state']==='linked'&&Links::$remote===1,'Lookup missing');
    foreach(['active','logged','assigned'] as $prop){$context->employee->$prop=false;denied(function()use($module,$input){Admin::lookup($module,$input);},'employee '.$prop);$context->employee->$prop=true;}
    foreach(['view','edit'] as $right){Profile::$rights[$right]=0;denied(function()use($module,$input){Admin::lookup($module,$input);},'catalogue '.$right);Profile::$rights[$right]=1;}
    Shop::$multi=true;denied(function()use($module,$input){Admin::lookup($module,$input);},'multistore');Shop::$multi=false;
    Shop::$context=2;denied(function()use($module,$input){Admin::lookup($module,$input);},'group context');Shop::$context=1;
    Db::$exists=false;denied(function()use($module,$input){Admin::lookup($module,$input);},'missing product');Db::$exists=true;
    foreach([['shop'=>'2'],['id_product'=>'8'],['id_product'=>['7']],['id_product'=>'7abc'],['id_product'=>'0'],['wd29_product_token'=>'wrong'],['wd29_product_token'=>[]]] as $replace){denied(function()use($module,$input,$replace){Admin::lookup($module,array_replace($input,$replace));},'invalid scope/input/token');}
    $context->employee->id=3;denied(function()use($module,$input){Admin::lookup($module,$input);},'cross employee token');$context->employee->id=2;
    $_SERVER['REQUEST_METHOD']='GET';denied(function()use($module,$input){Admin::lookup($module,$input);},'GET');$_SERVER['REQUEST_METHOD']='POST';
    $module->active=false;denied(function()use($module,$input){Admin::lookup($module,$input);},'inactive module');$module->active=true;
    $before=Links::$remote;$html=Admin::render($module,7);check(Links::$remote===$before,'Editor render invoked remote');
    check(strpos($html,'rel="noopener noreferrer"')!==false&&strpos($html,'target="_blank"')!==false,'Unsafe external link');
    check(strpos($html,'data-wd-remote-link class="btn btn-primary" target="_blank" rel="noopener noreferrer" hidden')!==false,'Unverified link visible');
    check(strpos($html,'Copie importée de WooCommerce')!==false&&strpos($html,'woo:product:7')!==false,'Missing ownership identity');
    check(strpos($html,'product-links-admin.js?v=')!==false&&strpos($html,' defer')!==false,'Asset not deferred');
    foreach(['unmapped','disconnected','missing','unpublished'] as $state){Links::$state=$state;check(strpos(Admin::render($module,7),'data-state="'.$state.'"')!==false,'Missing state '.$state);}
    if (($argv[2]??'')==='render') { echo $html; exit; }
    echo 'PASS '._PS_VERSION_.': '.$count." product-editor authorization and deferred lookup checks\n";
}
