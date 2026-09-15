<?php
$root=getenv('WD29_PS_ROOT'); if (!$root) { throw new RuntimeException('Disposable PS root required.'); }
$_SERVER['HTTP_HOST']='ps.example.test'; $_SERVER['REQUEST_URI']='/';
require $root.'/config/config.inc.php';
if (_DB_NAME_!=='wd29ps' || Configuration::get('PS_SHOP_DOMAIN')!=='ps.example.test') { throw new RuntimeException('Disposable PS database required.'); }
require_once __DIR__.'/../includes/Refunds.php';
use WD29\Bridge\Refunds;
function checkRefund($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
// Isolated native source document fixture. No real order, gateway, stock or cashier action is invoked.
$id=random_int(100000000,999999999); $order=(object)['id'=>$id,'id_customer'=>$id];
$slip=new OrderSlip(); $slip->id_order=$id; $slip->id_customer=$id; $slip->conversion_rate=1;
$slip->total_products_tax_excl=10; $slip->total_products_tax_incl=12; $slip->total_shipping_tax_excl=0; $slip->total_shipping_tax_incl=0;
checkRefund($slip->add(),'Source slip fixture creation failed');
try {
 $rows=Refunds::export($order);
 checkRefund(count($rows)===1 && (float)$rows[0]['amount']===12.0 && (float)$rows[0]['tax']===2.0 && $rows[0]['payment_refunded']===null,'Credit slip export differs');
 $rows[0]['source_id']='woo:refund:123'; $data=['key'=>'woo:order:123','total'=>'12','refunds'=>$rows];
 $before=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'order_slip`');
 checkRefund(Refunds::apply($order,$data)===Refunds::apply($order,$data),'Replay differs');
 checkRefund((int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'order_slip`')===$before,'Mirror created a native fiscal document');
 $bad=$data; $bad['refunds'][0]['amount']='13'; $rejected=false;
 try { Refunds::apply($order,$bad); } catch (RuntimeException $e) { $rejected=true; }
 checkRefund($rejected,'Excess refund accepted');
 $slip->total_products_tax_excl=0; $slip->total_products_tax_incl=0; $slip->amount=5; $slip->save();
 $legacy=Refunds::export($order); checkRefund($legacy[0]['amount']===null && $legacy[0]['amount_basis']==='legacy_unspecified','Legacy tax basis was invented');
 echo "PASS: native credit-slip export, legacy amount preservation, no native fiscal mirror, replay and amount limit\n";
} finally { $slip->delete(); }
