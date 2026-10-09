<?php
namespace Symfony\Component\Security\Csrf {
    interface CsrfTokenManagerInterface {}
    class CsrfToken { public $id; public $value; public function __construct($id,$value){$this->id=$id;$this->value=$value;} public function getValue(){return $this->value;} }
}
namespace PrestaShop\PrestaShop\Adapter {
    class SymfonyContainer { public static function getInstance(){return new self;}public function get($id){return new class {public function getToken($id){return new \Symfony\Component\Security\Csrf\CsrfToken($id,hash('sha256',$id));}public function isTokenValid($token){return hash_equals(hash('sha256',$token->id),$token->value);}};} }
}
namespace WD29\Bridge {
 class RecordPanel {public static function nativeId($e,$kind,$key){return $key==='ps:'.$kind.':7'?7:0;}public static $reads=0;public static $writes=0;public static function local($e,$k,$id){return ['key'=>'ps:'.$k.':'.$id,'origin'=>true];}public static function compare($e,$k,$id){self::$reads++;return ['state'=>'changed'];}public static function sync($e,$k,$id,$in){self::$writes++;return ['state'=>'applied'];}}
}
namespace {
 define('_PS_VERSION_',$argv[1]??'8.2.8');define('_DB_PREFIX_','ps_');
 class Validate {public static function isLoadedObject($o){return !empty($o->id);}}
 class Shop {const CONTEXT_SHOP=1;public static $multi=false;public static $context=1;public static function isFeatureActive(){return self::$multi;}public static function getContext(){return self::$context;}}
 class Tab {public static $requested;public static function getIdFromClassName($name){self::$requested=$name;if(!in_array($name,['AdminProducts','AdminOrders','AdminCustomers']))throw new \Exception('Unexpected permission scope');return 4;}}
 class Profile {public static $rights=['view'=>1,'edit'=>1];public static function getProfileAccess($profile,$tab){return self::$rights;}}
 class Db {public static $exists=true;public static $last;public static function getInstance(){return new self;}public function getValue($sql){self::$last=$sql;return self::$exists?7:false;}}
 class Tools {public static function getAdminToken($name){return hash('sha256',$name);}}
 class ModuleAdminController {public $context,$module;public function __construct(){$this->context=Context::getContext();$this->module=$GLOBALS['module']??null;}public function checkToken(){return false;}}
 class Context {public static $context;public static function getContext(){return self::$context;}}
 class Employee {public $id=2;public $active=true;public $id_profile=2;public $logged=true;public $assigned=true;public function isLoggedBack(){return $this->logged;}public function hasAuthOnShop($id){return $this->assigned;}}
 class Module {public static $configure=true;public static function getPermissionStatic($id,$variable,$employee){return $variable==='configure'&&$id===9&&$employee instanceof Employee&&self::$configure;}}
 class ModuleFixture {public $active=true;public $id=9;public $name='wd29woobridge';public function bridge(){return (object)[];}public function getPathUri(){return '/modules/wd29woobridge/';}}
 require dirname(__DIR__).'/includes/RecordPanelAdmin.php';
 use WD29\Bridge\RecordPanelAdmin as Admin;
 use WD29\Bridge\RecordPanel as Panel;
 $context=(object)['employee'=>new Employee,'shop'=>(object)['id'=>1],'link'=>new class {public function getAdminLink(){return 'https://ps.example.test/admin/index.php?controller=AdminWd29RecordPanel&token=csrf';}}];Context::$context=$context;
 $module=new ModuleFixture;$count=0;$_SERVER['REQUEST_METHOD']='POST';
 function check($yes,$message){global $count;$count++;if(!$yes)throw new Exception($message);}
 function denied($fn,$message){$before=[Panel::$reads,Panel::$writes];try{$fn();throw new LogicException('Accepted '.$message);}catch(RuntimeException $e){}check($before===[Panel::$reads,Panel::$writes],$message.' reached shared engine');}
 foreach(['product'=>'AdminProducts','order'=>'AdminOrders','customer'=>'AdminCustomers'] as $kind=>$tab){
  $input=['kind'=>$kind,'id'=>'7','shop'=>'1','record_token'=>Admin::token($context,$kind,7,1),'operation'=>'compare'];
  check(Admin::dispatch($module,$input)['state']==='changed','Comparison rejected');check(Tab::$requested===$tab,'Wrong native permission');check(strpos(Db::$last,'id_shop=1')!==false,'Missing shop scope');
  foreach(['active','logged','assigned'] as $prop){$context->employee->$prop=false;denied(function()use($module,$input){Admin::dispatch($module,$input);},'employee '.$prop);$context->employee->$prop=true;}
  foreach(['view','edit'] as $right){Profile::$rights[$right]=0;denied(function()use($module,$input){Admin::dispatch($module,$input);},'native '.$right);Profile::$rights[$right]=1;}
  foreach([['kind'=>'invalid'],['kind'=>[]],['shop'=>'2'],['id'=>'8'],['id'=>['7']],['id'=>'7abc'],['id'=>'0'],['record_token'=>'wrong'],['record_token'=>[]],['operation'=>'delete']] as $replace){denied(function()use($module,$input,$replace){Admin::dispatch($module,array_replace($input,$replace));},'invalid scope/token/input');}
  $other=$kind==='product'?'order':'product';denied(function()use($module,$input,$other){Admin::dispatch($module,array_replace($input,['kind'=>$other]));},'cross-kind token');
  $before=[Panel::$reads,Panel::$writes];$html=Admin::render($module,$kind,7);check($before===[Panel::$reads,Panel::$writes],'Rendering accessed peer');check(strpos($html,'data-wd-record-sync type="button" hidden')!==false,'Unreviewed sync shown');
  $mutation=$input+['key'=>'ps:'.$kind.':7','direction'=>'out','hash'=>str_repeat('a',64),'destination'=>'','confirm'=>'1'];$mutation['operation']='sync';
  check(Admin::dispatch($module,$mutation)['state']==='applied','Confirmed mutation rejected');
  foreach([['confirm'=>'0'],['hash'=>[]],['destination'=>str_repeat('a',257)]] as $replace){denied(function()use($module,$mutation,$replace){Admin::dispatch($module,array_replace($mutation,$replace));},'invalid mutation');}
 }
 Shop::$multi=true;denied(function()use($module,$input){Admin::dispatch($module,$input);},'multistore');Shop::$multi=false;
 Db::$exists=false;denied(function()use($module,$input){Admin::dispatch($module,$input);},'missing record');Db::$exists=true;
 $_SERVER['REQUEST_METHOD']='GET';denied(function()use($module,$input){Admin::dispatch($module,$input);},'GET');$_SERVER['REQUEST_METHOD']='POST';
 $module->active=false;denied(function()use($module,$input){Admin::dispatch($module,$input);},'inactive module');

 $module->active=true;require dirname(__DIR__).'/controllers/admin/AdminWd29RecordPanelController.php';$controller=new AdminWd29RecordPanelController;
 $_GET=['action'=>'open','kind'=>'order','key'=>'ps:order:7'];$_SERVER['REQUEST_METHOD']='GET';
 check($controller->checkToken()===true,'Native navigation requires other employee token');check($controller->isAnonymousAllowed()===true,'PS9 URL token navigation exemption missing');
 check(Admin::openUrl($module,$_GET)!=='','Authenticated native navigation denied');
 $_SERVER['REQUEST_METHOD']='POST';check($controller->checkToken()===false,'POST bypassed native CSRF');check($controller->isAnonymousAllowed()===false,'PS9 POST routing bypass');
 $_SERVER['REQUEST_METHOD']='GET';$_GET['action']='compare';check($controller->checkToken()===false,'Comparison GET bypassed native CSRF');
 $_GET['action']='open';$context->employee->logged=false;check($controller->checkToken()===false,'Anonymous navigation bypassed native auth');check($controller->isAnonymousAllowed()===false,'PS9 anonymous routing bypass');$context->employee->logged=true;
 denied(function()use($module){Admin::openUrl($module,['kind'=>'customer','key'=>'ps:customer:999']);},'Missing navigation record');
 // E-mail/notice links: token-free GET to the module page, only for logged-in employees with module rights.
 $_GET=['action'=>'module','view'=>'settings'];
 check($controller->checkToken()===true&&$controller->isAnonymousAllowed()===true&&$controller->viewAccess()===true,'Direct module link refused');check(Module::$configure===true,'Fixture');
 check(Admin::moduleUrl($module,$_GET)!=='','Direct module navigation denied');
 denied(function()use($module){Admin::moduleUrl($module,['view'=>'unknown']);},'Unknown module view');
 Module::$configure=false;check($controller->checkToken()===false&&$controller->isAnonymousAllowed()===false&&$controller->viewAccess()===false,'Module link without module configure right');denied(function()use($module){Admin::moduleUrl($module,['view'=>'settings']);},'Module navigation without rights');Module::$configure=true;
 $context->employee->logged=false;check($controller->checkToken()===false&&$controller->isAnonymousAllowed()===false,'Anonymous module link');$context->employee->logged=true;
 $_SERVER['REQUEST_METHOD']='POST';check($controller->checkToken()===false,'POST module link bypassed CSRF');$_SERVER['REQUEST_METHOD']='GET';
 if(($argv[2]??'')==='render'){echo $html;exit;}
 echo 'PASS '._PS_VERSION_.': '.$count." native record authorization and write-boundary checks\n";
}
