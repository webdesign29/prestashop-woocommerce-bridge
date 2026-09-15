<?php
$root=getenv('WD29_PS_ROOT'); if (!$root) { throw new RuntimeException('Disposable root required.'); }
$_SERVER['HTTP_HOST']='ps.example.test'; $_SERVER['REQUEST_URI']='/'; require $root.'/config/config.inc.php';
if (_DB_NAME_!=='wd29ps' || Configuration::get('PS_SHOP_DOMAIN')!=='ps.example.test') { throw new RuntimeException('Disposable database required.'); }
Context::getContext()->shop=new Shop(1); Context::getContext()->language=new Language((int)Configuration::get('PS_LANG_DEFAULT'));
require __DIR__.'/protocol.php'; $e=Module::getInstanceByName('wd29woobridge')->bridge(); $e->install(); $suffix=(string)random_int(100000,999999);
$d=['key'=>'woo:customer:'.$suffix,'first_name'=>'Fixture','last_name'=>'Account','email'=>'account'.$suffix.'@example.test','company'=>'','guest'=>false,'deleted'=>false,'billing'=>['address_1'=>'1 Test Street','city'=>'Paris','postcode'=>'75001','country'=>'FR'],'shipping'=>[]];
$id=WD29\Bridge\CustomerAccounts::apply($e,$d); $c=new Customer($id); $hash=$c->passwd;
expect(!$c->is_guest && !$c->newsletter && !$c->optin,'Native PS account or consents incorrect');
expect(count($c->getAddresses((int)Configuration::get('PS_LANG_DEFAULT')))===1,'Native PS address missing');
$d['first_name']='Updated'; expect(WD29\Bridge\CustomerAccounts::apply($e,$d)===$id,'Duplicate PS account on replay');
expect((new Customer($id))->passwd===$hash,'PS account update reset password');
$other=$d; $other['key']='woo:customer:'.($suffix+1); $blocked=false; try { WD29\Bridge\CustomerAccounts::apply($e,$other); } catch (Throwable $error) { $blocked=true; } expect($blocked,'PS email collision merged');
$ids=$e->adapter->ids('customer',0,10000); foreach ($ids as $contactId) { $data=$e->customer($contactId); expect($data['key']!=='ps:customer:'.$id,'PS native account echoed'); }
echo "PASS: native PS account/address, password retained, replay, collision and source exclusion\n";
