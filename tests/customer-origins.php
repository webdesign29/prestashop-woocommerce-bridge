<?php
/** Actual adapter SQL: a mirrored order does not change a registered customer's origin. */
define('_DB_PREFIX_','ps_');
function pSQL($v,$html=false){return str_replace("'","''",$v);}
class Db {public static $pdo;public static function getInstance(){return new self;}public function executeS($sql){return self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}}
require __DIR__.'/../includes/Protocol.php';require __DIR__.'/../includes/PrestaAdapter.php';
class ContactIdentity {public function contactId($key){return (int)explode(':',$key)[2];}}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
Db::$pdo=new PDO('sqlite::memory:');Db::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);Db::$pdo->sqliteCreateFunction('CONCAT',static function(...$parts){return implode('',$parts);});
Db::$pdo->exec("CREATE TABLE ps_customer(id_customer INTEGER PRIMARY KEY,deleted INTEGER,is_guest INTEGER,active INTEGER);CREATE TABLE ps_orders(id_order INTEGER PRIMARY KEY,id_customer INTEGER,module TEXT);CREATE TABLE ps_wd29_bridge_account_links(native_id INTEGER,record_key TEXT);
INSERT INTO ps_customer VALUES(1,0,0,1),(2,0,0,1),(3,0,1,1),(4,0,0,1),(5,0,1,1),(6,1,0,0),(7,0,0,0),(8,0,1,1),(9,0,0,1);
INSERT INTO ps_orders VALUES(1,2,'wd29woobridge'),(2,3,'wd29woobridge'),(3,4,'kerawen'),(4,5,'kerawen'),(5,7,'wd29woobridge'),(6,8,'wd29woobridge'),(7,9,'kerawen'),(8,9,'wd29woobridge');
INSERT INTO ps_wd29_bridge_account_links VALUES(4,'woo:customer:4');");
$a=new WD29\Bridge\PrestaAdapter();$a->engine=new ContactIdentity();$expected=[1,2,5,7,9];
check($a->ids('customer',0,20)===$expected,'Registered source customers disappeared after Woo purchases, or imported guests/accounts leaked into exports');
check($a->manualContactKeys(0,20)===array_map(static function($id){return 'ps:customer:'.$id;},$expected),'Manual source list disagrees with automatic capture');
foreach([1,2,5,7,9] as $id)check($a->manualContactOriginal('ps:customer:'.$id),'Original identity refused after mirrored purchase');
foreach([3,4,6,8,999] as $id)check(!$a->manualContactOriginal('ps:customer:'.$id),'Copied/deleted/nonexistent customer became an original');
check(!$a->manualContactOriginal('woo:customer:2'),'Foreign origin accepted');check($a->ids('customer',1,2)===[2,5],'Customer pagination changed');
echo "PASS actual PrestaShop customer SQL: registered origins survive Woo purchases; imported accounts, mirror guests and deletions stay excluded\n";
