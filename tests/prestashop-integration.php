<?php
/** Disposable PrestaShop 8.2 test installation only. */
$root = getenv('WD29_PS_ROOT');
if (!$root || !is_file($root . '/config/config.inc.php')) { throw new RuntimeException('Set WD29_PS_ROOT.'); }
$_SERVER['HTTP_HOST'] = 'ps.example.test'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/config/config.inc.php';
if (_DB_NAME_ !== 'wd29ps' || Configuration::get('PS_SHOP_DOMAIN') !== 'ps.example.test') { throw new RuntimeException('Refusing to run outside the disposable test shop.'); }
Context::getContext()->shop = new Shop(1);
Context::getContext()->language = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
Context::getContext()->currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
Context::getContext()->employee = new Employee(1);
require_once $root . '/app/AppKernel.php';
$kernel = new AppKernel('prod', false); $kernel->boot();
require_once __DIR__ . '/protocol.php';
use WD29\Bridge\Protocol;
use WD29\Bridge\Engine;
$module = Module::getInstanceByName('wd29woobridge');
expect((bool)$module, 'Module could not be loaded');
if (Module::isInstalled('wd29woobridge') && !Configuration::get('WD29_BRIDGE_CONFIG')) { $module->uninstall(); }
if (!Module::isInstalled('wd29woobridge')) { expect($module->install(), 'Module installation failed'); }
$e=$module->bridge(); $e->install(); $e->sql('TRUNCATE TABLE {b}contacts'); $e->sql('TRUNCATE TABLE {b}queue'); $e->sql('TRUNCATE TABLE {b}map');
$config=$e->config(); $config['mode']='audit'; $config['display_tax_rate']='20'; $config['display_basis']='gross';
$config['tax_rules']=['20'=>1];
$config['peer']='https://woo.example.test/wp-json/wd29-bridge/v1/webhook'; $config['secret']=str_repeat('fixture-',8);
Configuration::updateValue('WD29_BRIDGE_CONFIG',json_encode($config));
$apply=new ReflectionMethod(Engine::class,'apply'); $apply->setAccessible(true);
$ready=new ReflectionMethod(Engine::class,'ready'); $ready->setAccessible(true);
$drain=function()use($e,$apply,$ready){for($round=0;$round<20;$round++){ $rows=$ready->invoke($e,'in'); if(!$rows){break;} foreach($rows as $row){$apply->invoke($e,$row);} }};
$send=function($id,$kind,$key,$payload)use($e,$drain){$e->receive(['source'=>'woo','op'=>'events','events'=>[['id'=>$id,'kind'=>$kind,'key'=>$key,'payload'=>$payload]]]);$drain();};
$data=['key'=>'woo:product:800','type'=>'simple','name'=>'Fixture from Woo','sku'=>'','description'=>'<p>Fixture description</p>','short_description'=>'Fixture',
    'status'=>'publish','virtual'=>false,'currency'=>'EUR','prices'=>['regular'=>'12.50','sale'=>null,'tax_rate'=>null,'basis'=>'display'],'weight_kg'=>'0',
    'brands'=>['Fixture Brand'], 'tags'=>['Summer','Outlet'], 'categories'=>[['Fixture clothing','Boots &amp; bottes']],'images'=>[],'attributes'=>[],'variants'=>[],
    'inventory'=>[['key'=>'woo:product:800','quantity'=>10,'status'=>'instock','backorders'=>false]]];
$send(str_repeat('a',32),'product',$data['key'],['base'=>'','hash'=>Engine::catalogHash($data),'data'=>$data]);
$map=$e->mapping($data['key']); expect($map!==null,'Product import failed: '.json_encode($e->report()));
$pid=(int)$map['local_id'];
expect((int)StockAvailable::getQuantityAvailableByProduct($pid,0,1)===10,'Initial stock changed');
$p=new Product($pid,false,(int)Configuration::get('PS_LANG_DEFAULT'));
expect(abs((float)$p->price*1.2-12.50)<0.00001,'Gross price was not preserved');
$send(str_repeat('b',32),'stock',$data['key'],['delta'=>-2]);
expect((int)StockAvailable::getQuantityAvailableByProduct($pid,0,1)===8,'Incoming stock update failed');
$send(str_repeat('b',32),'stock',$data['key'],['delta'=>-2]);
expect((int)StockAvailable::getQuantityAvailableByProduct($pid,0,1)===8,'Duplicate delivery changed inventory');
$e->capture('product',$pid);
StockAvailable::updateQuantity($pid,0,-1,1,true); $e->capture('product',$pid);
$delta=$e->sql("SELECT payload FROM {b}queue WHERE direction='out' AND kind='stock' ORDER BY seq DESC LIMIT 1");
expect($delta && json_decode($delta[0]['payload'],true)['delta']===-1,'Native PrestaShop stock movement not captured');
$v=$data; $v['key']='woo:product:801'; $v['type']='variable';
$v['attributes']=[['name'=>'Size','options'=>['M'],'variation'=>true]];
$v['variants']=[['key'=>'woo:variant:802','sku'=>'','attributes'=>['Size'=>'M'],'prices'=>$data['prices'],'weight_kg'=>'0','status'=>'publish']];
$v['prices']['regular']=null; // Native WooCommerce variable parent: price lives on its children.
$v['inventory']=[['key'=>$v['key'],'quantity'=>null,'status'=>'instock','backorders'=>false],['key'=>'woo:variant:802','quantity'=>3,'status'=>'instock','backorders'=>false]];
$send(str_repeat('c',32),'product',$v['key'],['base'=>'','hash'=>Engine::catalogHash($v),'data'=>$v]);
$vm=$e->mapping('woo:variant:802'); expect($vm!==null,'Combination import failed: '.json_encode($e->report()));
$combo=new Combination((int)$vm['local_id']);
expect((int)StockAvailable::getQuantityAvailableByProduct((int)$combo->id_product,(int)$combo->id,1)===3,'Combination stock changed');
$roundtrip=$e->adapter->product((int)$combo->id_product); expect(count($roundtrip['variants'])===1,'Combination export failed');
$unknown=$data; $unknown['key']='woo:product:803'; $unknown['inventory'][0]['key']=$unknown['key']; $unknown['inventory'][0]['quantity']=null;
$send(str_repeat('d',32),'product',$unknown['key'],['base'=>'','hash'=>Engine::catalogHash($unknown),'data'=>$unknown]);
$um=$e->mapping($unknown['key']); expect($um && $um['quantity']===null,'Unknown quantity became a fabricated count');
$order=['key'=>'woo:order:900','number'=>'FIXTURE','status'=>'processing','currency'=>'EUR','total'=>'12.50','tax'=>'0','shipping_net'=>'0','shipping_tax'=>'0','discount'=>'0',
    'billing'=>['first_name'=>'Test','last_name'=>'Customer','email'=>'customer@example.test','address_1'=>'1 Test Street','city'=>'Paris','postcode'=>'75001','country'=>'FR'],
    'shipping'=>[],'items'=>[['product'=>$data['key'],'name'=>'Fixture from Woo','quantity'=>1,'net'=>'12.50','tax'=>'0']],'created'=>'2026-01-01T12:00:00+00:00'];
$before=(int)StockAvailable::getQuantityAvailableByProduct($pid,0,1);
$send(str_repeat('e',32),'order',$order['key'],['base'=>'','hash'=>Protocol::fingerprint($order),'data'=>$order]);
$om=$e->mapping($order['key']); expect($om!==null,'Order import failed: '.json_encode($e->report()));
expect((int)StockAvailable::getQuantityAvailableByProduct($pid,0,1)===$before,'Mirrored order deducted stock again');
$o=new Order((int)$om['local_id']); expect(!$o->valid && (float)$o->total_paid_real===0.0,'Mirror created another paid sale');
expect(count($o->getOrderDetailList())===1,'Native order lines missing');
expect($e->adapter->order((int)$o->id)['number']==='FIXTURE','Source order number lost');
$beforeEvents=count($e->sql("SELECT seq FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=?",[$unknown['key']]));
$e->capture('product',(int)$um['local_id']);
expect(count($e->sql("SELECT seq FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=?",[$unknown['key']]))===$beforeEvents,'Native normalization created an echo');
$send(str_repeat('e',32),'order',$order['key'],['base'=>'','hash'=>Protocol::fingerprint($order),'data'=>$order]);
expect((int)$e->mapping($order['key'])['local_id']===(int)$o->id,'Duplicate native order created');
echo "PASS: native PrestaShop installation, simple product, gross price mapping, stock delta/replay, local stock event, combination, unknown quantity and native order mirror\n";
$historic=$order; $historic['key']='woo:order:902'; $historic['items'][0]['product']='woo:product:999999';
$send(str_repeat('1',32),'order',$historic['key'],['base'=>'','hash'=>Protocol::fingerprint($historic),'data'=>$historic]);
$hm=$e->mapping($historic['key']); expect($hm!==null,'Unmapped catalog product blocked historical order: '.json_encode($e->report()));
expect($e->adapter->orderSummary((int)$hm['local_id'])['unlinked_lines']===1,'Historical source line missing');
$late=$data; $late['key']='woo:product:999999'; $late['inventory'][0]['key']=$late['key'];
$send(str_repeat('2',32),'product',$late['key'],['base'=>'','hash'=>Engine::catalogHash($late),'data'=>$late]);
$e->adapter->reconcileOrderLinks((int)$hm['local_id']);
expect($e->adapter->orderSummary((int)$hm['local_id'])['unlinked_lines']===0,'Late product mapping not attached');
$ho=new Order((int)$hm['local_id']); expect((float)$ho->total_paid_tax_incl===12.5,'Late attachment changed order total');
expect((int)StockAvailable::getQuantityAvailableByProduct((int)$e->mapping($late['key'])['local_id'],0,1)===10,'Late attachment changed stock');
$contact=['key'=>'woo:customer:987','first_name'=>'Contact','last_name'=>'Fixture','email'=>'contact-only@example.test','phone'=>'1234','company'=>'Fixture','billing'=>[],'shipping'=>[],'guest'=>false,'deleted'=>false];
$beforeCustomers=count($e->sql('SELECT id_customer FROM `'._DB_PREFIX_.'customer`'));
$send(str_repeat('3',32),'customer',$contact['key'],['base'=>'','hash'=>Protocol::fingerprint($contact),'data'=>$contact]);
expect($e->mapping($contact['key'])!==null,'Contact mirror missing');
expect(count($e->sql('SELECT id_customer FROM `'._DB_PREFIX_.'customer`'))===$beforeCustomers,'Contact directory created native login accounts');
$send(str_repeat('3',32),'customer',$contact['key'],['base'=>'','hash'=>Protocol::fingerprint($contact),'data'=>$contact]);
expect(count($e->customerReport())===1,'Contact replay duplicated identity');
echo "PASS: historical order independent of catalog, late links preserve stock/totals, contact mirrors without accounts\n";
$sourceCustomer=new Customer(); $sourceCustomer->firstname='Profile'; $sourceCustomer->lastname='Fixture'; $sourceCustomer->email='contact_'.uniqid().'@example.test'; $sourceCustomer->passwd=Tools::hash('fixture-only-password'); $sourceCustomer->is_guest=true; $sourceCustomer->id_default_group=(int)Configuration::get('PS_GUEST_GROUP');
expect($sourceCustomer->add(),'Source customer fixture failed');
$cid=$e->contactId(Protocol::key('ps','customer',(int)$sourceCustomer->id)); $e->capture('customer',$cid);
$cp=$e->customer($cid); expect($cp['first_name']==='Profile' && !isset($cp['passwd']) && !isset($cp['newsletter']),'Native customer profile export was not safe');
expect($e->adapter->orderContact((int)$hm['local_id'])===null,'Mirrored customer was re-exported');
expect(in_array($cid,$e->adapter->ids('customer',0,1000),true),'Customer batch omitted native contact');
echo "PASS: native customer profile export and mirror exclusion\n";
$config['mode']='disabled';Configuration::updateValue('WD29_BRIDGE_CONFIG',json_encode($config));

$audited = $e->catalogAudit();
expect(count($audited)>0, "Catalog audit omitted captured products");
expect(strpos(json_encode($audited), "customer@example.test") === false, "Catalog audit leaked order data");
echo "PASS: catalog audit includes products without customer order data\n";

$mixed=$v; $mixed['inventory'][]=['key'=>'woo:variant:999','quantity'=>null,'status'=>'instock','backorders'=>false];
try { $e->adapter->applyProduct($mixed,null); throw new RuntimeException('Mixed stock modes were accepted'); }
catch (RuntimeException $error) { expect(strpos($error->getMessage(),'Mixed combination stock modes')!==false,'Unexpected mixed-stock failure'); }
expect(!Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int)$combo->id_product,1)), 'Tracked combinations inherited unlimited availability');
echo "PASS: variable parent price fallback and mixed-stock overselling guard\n";

$fields=$e->adapter->product($pid);
expect($fields['brands']===['Fixture Brand'] && $fields['tags']===['Outlet','Summer'],'Native manufacturer/tags roundtrip failed');
echo "PASS: encoded category labels and native manufacturer/tags\n";
