<?php
namespace WD29\Bridge {
    class Protocol {public static function key($site,$kind,$id){return "$site:$kind:$id";}}
    class Engine {public $maps=[];public $links=[];public function sql($sql,$args){if(strpos($sql,'account_links')!==false)return $this->links;return $this->maps;}}
}
namespace {
    define('_DB_PREFIX_','ps_');
    function pSQL($s,$html=false){return addslashes($s);}
    class Context {public static function getContext(){return (object)['shop'=>(object)['id'=>1]];}}
    class Db {public static $exists=true;public static $mirror=false;public static $original=true;public static $queries=[];public static function getInstance(){return new self;}public function executeS($sql){self::$queries[]=$sql;if(strpos($sql,'NOT EXISTS')!==false)return self::$original?[['id_customer'=>7]]:[];if(strpos($sql,"module='wd29woobridge'")!==false)return self::$mirror?[['id_order'=>7]]:[];return self::$exists?[['id_product'=>7,'id_customer'=>7,'id_order'=>7]]:[];}}
    require dirname(__DIR__).'/includes/PrestaAdapter.php';
    $adapter=new WD29\Bridge\PrestaAdapter;$adapter->engine=new WD29\Bridge\Engine;$count=0;
    function check($yes,$message){global $count;$count++;if(!$yes)throw new Exception($message);}
    foreach(['product','order','customer'] as $kind){check($adapter->recordPanelIdentity($kind,7)==="ps:$kind:7",'Original identity missing');check(strpos(Db::$queries[count(Db::$queries)-($kind==='customer'?2:1)],'id_shop=')!==false||$kind==='order','Native scope not read');}
    $adapter->engine->maps=[['record_key'=>'woo:product:90']];check($adapter->recordPanelIdentity('product',7)==='woo:product:90','Copy not resolved from map');
    $adapter->engine->maps=[['record_key'=>'woo:order:90']];check($adapter->recordPanelIdentity('order',7)==='woo:order:90','Order copy not resolved');
    $adapter->engine->links=[['record_key'=>'woo:guest:90']];check($adapter->recordPanelIdentity('customer',7)==='woo:guest:90','Guest copy not resolved from account link');
    $adapter->engine->maps=[['record_key'=>'ps:product:99']];check($adapter->recordPanelIdentity('product',7)==='','Cross-original mapping accepted');
    $adapter->engine->maps=[['record_key'=>'woo:order:99']];check($adapter->recordPanelIdentity('product',7)==='','Cross-kind mapping accepted');
    $adapter->engine->links=[['record_key'=>'ps:customer:99']];check($adapter->recordPanelIdentity('customer',7)==='','Local account-link identity accepted');
    $adapter->engine->maps=[];$adapter->engine->links=[];Db::$original=false;Db::$mirror=true;
    check($adapter->recordPanelIdentity('customer',7)==='','Unlinked mirror guest became original');check($adapter->recordPanelIdentity('order',7)==='','Unlinked mirror order became original');
    Db::$exists=false;foreach(['product','order','customer'] as $kind)check($adapter->recordPanelIdentity($kind,7)==='','Missing/wrong-shop record accepted');
    check($adapter->recordPanelIdentity('variant',7)==='','Variant standalone accepted');check($adapter->recordPanelIdentity('product',0)==='','Invalid ID accepted');
    check(stripos(implode("\n",Db::$queries),'email')===false,'Email identity guessing');
    echo "PASS $count canonical native record identity checks\n";
}
