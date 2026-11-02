<?php
$platform=$argv[1]??'woo';
function get_userdata($id){return $GLOBALS['users'][$id]??false;}
function get_user_meta($id,$field,$single){return $GLOBALS['origins'][$id]??'';}
class Customer {public $id;public $is_guest=false;public $deleted=false;public $active=true;public function __construct($id){$d=$GLOBALS['users'][$id]??null;if($d){$this->id=$id;foreach($d as $k=>$v)$this->$k=$v;}}}
class Validate {public static function isLoadedObject($o){return !empty($o->id);}}
require dirname(__DIR__).'/includes/Protocol.php';require dirname(__DIR__).'/includes/Engine.php';require dirname(__DIR__).'/includes/OrderCustomers.php';
class IdentityAdapter {
 public $site;public $links=[];public $enabled=true;
 public function site(){return $this->site;}public function prefix(){return 'fixture_';}public function config(){return ['native_customers'=>$this->enabled];}
 public function sql($q,$args){$out=[];foreach($this->links as $key=>$id){if(strpos($q,'WHERE native_id=')!==false&&$id===$args[0])$out[]=['record_key'=>$key];if(strpos($q,'WHERE record_key=')!==false&&$key===$args[0])$out[]=['native_id'=>$id];}return $out;}
}
$a=new IdentityAdapter();$a->site=$platform;$e=new WD29\Bridge\Engine($a);$foreign=$platform==='woo'?'ps':'woo';$key=$foreign.':customer:1';$local=$platform.':customer:9';
$GLOBALS['users']=[7=>(object)['roles'=>['customer']],9=>(object)['roles'=>['customer']]];$GLOBALS['origins']=[7=>$key];$a->links=[$key=>7];$count=0;
function check($ok,$m){global $count;$count++;if(!$ok)throw new RuntimeException($m);}
function reject($key){global $e;try{WD29\Bridge\OrderCustomers::resolve($e,$key);}catch(RuntimeException $x){return true;}return false;}
check(WD29\Bridge\OrderCustomers::source($e,7)===$key,'Imported account acquired a second identity');
check(WD29\Bridge\OrderCustomers::source($e,9)===$local,'Original identity wrong');
check(WD29\Bridge\OrderCustomers::resolve($e,$key)===7,'Foreign registered order not linked');
check(WD29\Bridge\OrderCustomers::resolve($e,$local)===9,'Local original order not linked');
check(WD29\Bridge\OrderCustomers::resolve($e,null)===0,'Guest linked');
foreach(['ps:guest:1','woo:customer:0','woo:customer:1x',123,[],str_repeat('9',100)] as $bad)check(reject($bad),'Malformed identity accepted');
$a->enabled=false;check(WD29\Bridge\OrderCustomers::resolve($e,$key)===0,'Directory-only setting ignored');$a->enabled=true;
check(reject($foreign.':customer:2'),'Unknown source silently matched');
$a->links[$foreign.':customer:2']=7;check(reject($key),'Ambiguous link accepted');unset($a->links[$foreign.':customer:2']);
if($platform==='woo'){$GLOBALS['users'][7]->roles=['customer','administrator'];check(reject($key),'Privileged native account linked');$GLOBALS['users'][7]->roles=['customer'];$GLOBALS['origins'][7]=$foreign.':customer:99';check(reject($key),'Changed origin accepted');}
else{$GLOBALS['users'][7]->is_guest=true;check(reject($key),'Native guest reused as registered');$GLOBALS['users'][7]->is_guest=false;$GLOBALS['users'][7]->deleted=true;check(reject($key),'Deleted native customer reused');$GLOBALS['users'][7]->deleted=false;$GLOBALS['users'][7]->active=false;check(WD29\Bridge\OrderCustomers::resolve($e,$key)===7,'Inactive historical customer lost identity');}
echo 'PASS '.$platform.': '.$count." registered order identity assertions; canonical origins, account links, guests, roles, missing/deleted accounts and ambiguity\n";
