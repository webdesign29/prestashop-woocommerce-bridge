<?php
namespace WD29\Bridge {
    // Isolated native field adapter: no WordPress, PrestaShop, network or customer writes.
    class CustomFields {
        public static function exportCustomer(int $id): array { return $GLOBALS['custom']; }
        public static function rules($rules): array { return $rules; }
    }
}
namespace {
require __DIR__.'/../includes/Protocol.php';
require __DIR__.'/../includes/Engine.php';
require_once __DIR__.'/../includes/CustomerRecordGuard.php';
use WD29\Bridge\CustomerRecordGuard;
use WD29\Bridge\Engine;
class GuardAdapter {
    public $db,$enabled=true,$queries=0,$throw=false;
    public function __construct() {
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE fixture_wd29_bridge_account_links(record_key TEXT,native_id INTEGER);CREATE TABLE fixture_wd29_bridge_contacts(record_key TEXT,data TEXT);CREATE TABLE ps_address(id_address INTEGER,id_customer INTEGER,alias TEXT,deleted INTEGER);CREATE TABLE ps_state(id_state INTEGER,id_country INTEGER,iso_code TEXT);');
    }
    public function prefix(){return 'fixture_';}
    public function site(){return $GLOBALS['guardSite'];}
    public function config(){return ['native_customers'=>$this->enabled];}
    public function sql($sql,$params=[]) {
        if (!preg_match('/^SELECT /',$sql))throw new RuntimeException('Unexpected guard write');
        $this->queries++;if($this->throw)throw new RuntimeException('private@example.test SQL secret');
        $q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
$count=0;
function check($ok,$message){global $count;$count++;if(!$ok)throw new RuntimeException($message);}
function inspect($expected,$reason=null,?array $incoming=null){global $engine,$key;$r=CustomerRecordGuard::inspect($engine,$key,$incoming);check($r['state']===$expected,'Unexpected state: '.json_encode($r));if($reason!==null)check($r['reason']===$reason,'Unexpected reason');check(array_keys($r)===['state','native_id','reason'],'Unexpected personal fields');check(strpos(json_encode($r),'private@')===false,'PII leaked');return $r;}
function resetFixture(){global $adapter,$engine,$key,$baseline;$adapter=new GuardAdapter();$engine=new Engine($adapter);$q=$adapter->db->prepare('INSERT INTO fixture_wd29_bridge_account_links VALUES(?,7)');$q->execute([$key]);$q=$adapter->db->prepare('INSERT INTO fixture_wd29_bridge_contacts VALUES(?,?)');$q->execute([$key,json_encode($baseline)]);nativeFixture();}

define('_DB_PREFIX_','ps_');
class Validate {public static function isLoadedObject($o){return !empty($o->id);}}
class Context {public static function getContext(){return (object)['shop'=>(object)['id'=>1,'id_shop_group'=>2]];}}
class Country {public static function getByIso($iso){return $iso==='FR'?8:0;}}
class Customer {public $id=0;public function __construct($id){foreach($GLOBALS['native'] as $k=>$v)$this->$k=$v;}public function save(){throw new RuntimeException('Forbidden write');}}
class Address {public $id=0;public function __construct($id){foreach($GLOBALS['addresses'][$id]??[] as $k=>$v)$this->$k=$v;}public function save(){throw new RuntimeException('Forbidden write');}}
require __DIR__.'/../includes/PrestaAdapter.php';
$guardSite='ps';
$key='woo:customer:5';
$baseline=['key'=>$key,'email'=>'private@example.test','first_name'=>'Anne','last_name'=>'Le Roux','company'=>'Breizh','guest'=>false,'deleted'=>false,'billing'=>['address_1'=>'1 Rue Test','city'=>'Pont-l&#039;Abbé','country'=>'FR','state'=>'BRE','phone'=>'0200000000'],'shipping'=>['address_1'=>'2 Rue Test','city'=>'Quimper','country'=>'FR']];
function nativeFixture(){global $adapter,$baseline;$GLOBALS['native']=['id'=>7,'id_shop'=>1,'id_shop_group'=>2,'deleted'=>false,'is_guest'=>false,'email'=>$baseline['email'],'firstname'=>'Anne','lastname'=>'Le Roux','company'=>'Breizh'];$GLOBALS['addresses']=[];foreach(['billing'=>11,'shipping'=>12] as $kind=>$id){$source=WD29\Bridge\PrestaAdapter::nativeAddress($baseline[$kind]);$row=['id'=>$id,'id_customer'=>7,'alias'=>'WD29 '.$kind,'deleted'=>false,'id_country'=>8,'id_state'=>$kind==='billing'?3:0,'firstname'=>'Anne','lastname'=>'Le Roux'];foreach(['address_1'=>'address1','address_2'=>'address2','city'=>'city','postcode'=>'postcode','company'=>'company','phone'=>'phone'] as $from=>$to)$row[$to]=$source[$from]??'';$GLOBALS['addresses'][$id]=$row;$q=$adapter->db->prepare('INSERT INTO ps_address VALUES(?,7,?,0)');$q->execute([$id,'WD29 '.$kind]);}$adapter->db->exec("INSERT INTO ps_state VALUES(3,8,'BRE')");}
resetFixture();inspect('linked');check($adapter->queries===6,'Unbounded base reads');
foreach(['email','firstname','lastname','company'] as $field){resetFixture();$GLOBALS['native'][$field]='local edit';inspect('conflict','native_profile_changed');}
foreach(['firstname','lastname','address1','address2','city','postcode','phone','company','id_country','id_state'] as $field){resetFixture();$GLOBALS['addresses'][11][$field]='local edit';inspect('conflict','native_address_changed');}
resetFixture();unset($GLOBALS['addresses'][11]);inspect('conflict','native_address_origin_changed');
resetFixture();$GLOBALS['addresses'][11]['id_customer']=22;inspect('conflict','native_address_origin_changed');
resetFixture();$GLOBALS['addresses'][11]['alias']='local';inspect('conflict','native_address_origin_changed');
resetFixture();$adapter->db->exec('DELETE FROM ps_address WHERE id_address=11');inspect('conflict','native_address_missing');
resetFixture();$adapter->db->exec("INSERT INTO ps_address VALUES(13,7,'WD29 billing',0)");inspect('conflict','native_address_ambiguous');
resetFixture();$adapter->db->exec('DELETE FROM ps_state');inspect('conflict','native_address_state_unavailable');
resetFixture();$GLOBALS['native']['id_shop']=9;inspect('conflict','native_account_shop_changed');
resetFixture();$GLOBALS['native']['id_shop_group']=9;inspect('conflict','native_account_shop_changed');
resetFixture();$GLOBALS['native']['is_guest']=true;inspect('conflict','native_account_origin_changed');
resetFixture();$GLOBALS['native']['deleted']=true;inspect('conflict','native_account_missing');

resetFixture();$adapter->enabled=false;check(inspect('directory_only')['native_id']===7,'Disabled guard lost linked ID');check($adapter->queries===1,'Disabled native guard queried more than link');
resetFixture();$adapter->db->exec('DELETE FROM fixture_wd29_bridge_account_links');inspect('not_linked','native_account_not_created');
resetFixture();$adapter->db->exec("INSERT INTO fixture_wd29_bridge_account_links VALUES('other:customer:5',7)");inspect('conflict','account_link_ambiguous');
resetFixture();$adapter->db->exec('DELETE FROM fixture_wd29_bridge_contacts');inspect('conflict','contact_baseline_missing');
resetFixture();$adapter->db->exec("UPDATE fixture_wd29_bridge_contacts SET data='{}'");inspect('conflict','contact_baseline_missing');
resetFixture();$adapter->throw=true;inspect('conflict','native_account_unavailable');
resetFixture();$originalKey=$key;$key='invalid';inspect('conflict','identity_invalid');check($adapter->queries===0,'Invalid key queried DB');
$key=str_replace(':customer:',':guest:',$originalKey);inspect('directory_only','guest_contact_only');$key=$originalKey;

// A newly complete source address cannot overwrite a pre-existing WD29 address.
foreach(['billing','shipping'] as $kind){
 resetFixture();$old=$baseline;$old[$kind]=[];$adapter->db->prepare('UPDATE fixture_wd29_bridge_contacts SET data=?')->execute([json_encode($old)]);$incoming=$baseline;$id=$kind==='billing'?11:12;$GLOBALS['addresses'][$id]['city']='Local addition';inspect('linked');inspect('conflict','native_address_changed',$incoming);
 resetFixture();$old=$baseline;$old[$kind]=[];$adapter->db->prepare('UPDATE fixture_wd29_bridge_contacts SET data=?')->execute([json_encode($old)]);inspect('linked',null,$baseline);
 resetFixture();$old=$baseline;$old[$kind]=[];$adapter->db->prepare('UPDATE fixture_wd29_bridge_contacts SET data=?')->execute([json_encode($old)]);$adapter->db->prepare('DELETE FROM ps_address WHERE alias=?')->execute(['WD29 '.$kind]);inspect('linked',null,$baseline);
 resetFixture();$old=$baseline;$old[$kind]=[];$adapter->db->prepare('UPDATE fixture_wd29_bridge_contacts SET data=?')->execute([json_encode($old)]);$adapter->db->prepare('INSERT INTO ps_address VALUES(22,7,?,0)')->execute(['WD29 '.$kind]);inspect('conflict','native_address_ambiguous',$baseline);
}
resetFixture();$old=$baseline;$old['billing']=['city'=>'Incomplete'];$adapter->db->prepare('UPDATE fixture_wd29_bridge_contacts SET data=?')->execute([json_encode($old)]);$GLOBALS['addresses'][11]['firstname']='Local name';$incoming=$baseline;$incoming['first_name']='Changed source name';inspect('conflict','native_address_changed',$incoming);
resetFixture();$incoming=$baseline;$incoming['key']='woo:customer:999';inspect('conflict','incoming_contact_invalid',$incoming);

echo 'PASS: '.$count." native customer guard assertions; read-only, bounded, no personal data or real emails\n";
}
