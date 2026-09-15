<?php
$root=getenv('WD29_PS_ROOT'); if (!$root) { throw new RuntimeException('Disposable PS root required.'); }
$_SERVER['HTTP_HOST']='ps.example.test'; $_SERVER['REQUEST_URI']='/';
require $root.'/config/config.inc.php';
if (_DB_NAME_!=='wd29ps' || Configuration::get('PS_SHOP_DOMAIN')!=='ps.example.test') { throw new RuntimeException('Disposable PS database required.'); }
Context::getContext()->shop=new Shop(1); Context::getContext()->language=new Language((int)Configuration::get('PS_LANG_DEFAULT'));
Context::getContext()->currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT')); Context::getContext()->employee=new Employee(1);
require_once $root.'/app/AppKernel.php'; $kernel=new AppKernel('prod',false); $kernel->boot();
require_once __DIR__.'/../includes/Suppliers.php';
use WD29\Bridge\Suppliers;
function supplierCheck($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$suffix=bin2hex(random_bytes(5)); $p=new Product();
foreach (Language::getLanguages(false) as $lang) { $p->name[(int)$lang['id_lang']]='Supplier fixture '.$suffix; $p->link_rewrite[(int)$lang['id_lang']]='supplier-fixture-'.$suffix; }
$p->price=12; $p->id_category_default=(int)Configuration::get('PS_HOME_CATEGORY'); $p->active=false;
supplierCheck($p->add(),'Native product fixture failed'); $c=new Combination(); $c->id_product=$p->id; supplierCheck($c->add(),'Native combination fixture failed');
$currency=Context::getContext()->currency->iso_code;
$a=['name'=>'Fixture A '.$suffix,'reference'=>'A-1','purchase_price_net'=>'3.25','currency'=>$currency];
$b=['name'=>'Fixture B '.$suffix,'reference'=>'B-1','purchase_price_net'=>'0','currency'=>$currency];
try {
 Suppliers::apply($p,[$a,$b]); $rows=Suppliers::export($p);
 supplierCheck(count($rows)===2 && (float)$rows[0]['purchase_price_net']===3.25,'Native supplier export differs');
 $primary=(int)Supplier::getIdByName($a['name']); $p->id_supplier=$primary; $p->save();
 $a['reference']='A-2'; Suppliers::apply($p,[$a]); Suppliers::apply($p,[$a]);
 supplierCheck(count(Suppliers::export($p))===2 && Suppliers::export($p)[0]['reference']==='A-2','Native replay or additive upsert failed');
 supplierCheck((int)(new Product($p->id))->id_supplier===$primary,'Primary supplier changed');
 $variant=$a; $variant['reference']='VAR-1'; $variant['purchase_price_net']='4.5'; Suppliers::apply($c,[$variant]);
 supplierCheck(count(Suppliers::export($c))===1 && Suppliers::export($c)[0]['reference']==='VAR-1' && Suppliers::export($p)[0]['reference']==='A-2','Combination suppliers leaked into parent');
 $bad=$a; $bad['currency']='ZZZ'; $failed=false; try { Suppliers::apply($p,[$bad]); } catch (RuntimeException $e) { $failed=true; }
 supplierCheck($failed && Suppliers::export($p)[0]['reference']==='A-2','Unknown currency was accepted or wrote before validation');
 echo "PASS: native multi-supplier upsert/replay, variant scope, primary/unrelated preservation and unknown currency refusal\n";
} finally {
 $c->delete(); $p->delete();
 foreach ([$a['name'],$b['name']] as $name) { $sid=(int)Supplier::getIdByName($name); if ($sid) { (new Supplier($sid))->delete(); } }
}
