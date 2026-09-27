<?php
namespace WD29\Bridge {
    class Engine {
        public $adapter;
        public function __construct(string $side) { $this->adapter=new class($side) { private $side;public function __construct($s){$this->side=$s;}public function site(){return $this->side;}public function siteUrl(){return 'https://'.$this->side.'.example.test/';} }; }
        public function config(){return ['mode'=>'audit','peer'=>'https://hidden-user:hidden-password@peer.example.test/webhook?secret=do-not-display'];}
        public function mode(){return 'audit';}
        public function diagnostics(){return ['ok'=>true,'queue'=>[],'issues'=>[]];}
        public function licence(){return new class {public function summary(){return ['tone'=>'ok','label'=>'Active','hint'=>'','expires'=>''];}};}
        public function manualOrderDelta($direction,$offset){$source=$direction==='out'?$this->adapter->site():($this->adapter->site()==='woo'?'ps':'woo');return ['mode'=>'disabled','destination_mode'=>'disabled','next'=>null,'rows'=>[['key'=>$source.':order:42','hash'=>str_repeat('a',64),'number'=>'SOURCE-42','status'=>'normalized-status','total'=>'12.00','currency'=>'EUR','summary'=>['number'=>'SOURCE-42','status_label'=>'État natif original','total'=>'12.00','currency'=>'EUR'],'destination_summary'=>['number'=>'COPY-87','status_label'=>'État natif copie','total'=>'11.00','currency'=>'EUR'],'local_id'=>87,'destination'=>str_repeat('b',64),'state'=>'changed','changes'=>['montants']]]];}
    }
}
namespace {
require __DIR__.'/../includes/AdminDesign.php';require __DIR__.'/../includes/ManualOrdersAdmin.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function document($html){$doc=new DOMDocument();@$doc->loadHTML('<meta charset="UTF-8">'.$html);return new DOMXPath($doc);}
foreach(['woo','ps'] as $side){
 $engine=new \WD29\Bridge\Engine($side);$other=$side==='woo'?'ps':'woo';
 $input='<div><h1>Sync</h1><form><input name="wd29_token" value="nonce-preserved"></form><h2>Catalogue</h2><table><tr><th>Origine</th><th>ID local</th><th>Nom</th></tr><tr><td>'.$side.':product:10</td><td>10</td><td>Original</td></tr><tr><td>'.$other.':product:20</td><td>30</td><td>Copy</td></tr></table><h2>Journal des événements</h2><table><tr><th>Sens</th><th>Identité</th></tr><tr><td>in</td><td>'.$other.':order:1</td></tr><tr><td>out</td><td>'.$side.':order:2</td></tr></table></div>';
 $_GET=['wd_view'=>'settings','page'=>'wd29-bridge','token'=>'native-csrf-token'];$_POST=[];
 $html=\WD29\Bridge\AdminDesign::render($input,$engine,$side);$xp=document($html);
 check($xp->query('//*[@data-store-local="true" and @data-store-platform="'.$side.'"]')->length===1,'Wrong local platform');
 check(strpos($html,'peer.example.test')!==false&&!preg_match('/hidden-user|hidden-password|do-not-display/',$html),'Header leaks endpoint credentials');
 check($xp->query('//input[@name="wd29_token" and @value="nonce-preserved"]')->length===1,'Form token lost');
 check($xp->query('//*[@data-wd-origin]')->length===0,'Settings page includes unrelated reports');
 check(strpos($html,'token=native-csrf-token')!==false,'Native navigation token lost');
 check($xp->query('//input[@name="wd_view" and @value="settings"]')->length===1,'Form page not preserved');
 $_GET['wd_view']='reports';$reports=\WD29\Bridge\AdminDesign::render($input,$engine,$side);check(strpos($reports,'name="wd29_token"')===false,'Reports page contains settings form');
 $_GET['wd_view']='activity';$html=$reports.\WD29\Bridge\AdminDesign::render($input,$engine,$side);$xp=document($html);
 check($xp->query('//*[@data-wd-origin="original"]')->length===2&&$xp->query('//*[@data-wd-origin="imported"]')->length===2,'Ownership misclassified');
 check(strpos($html,'Copie importée · #30')!==false&&strpos($html,'Original · #10')!==false,'Local IDs ambiguous');
 check($xp->query('//*[@data-wd-origin-filter]')->length===1,'Report origin filter missing');
 foreach(['in','out'] as $direction){
  $source=$direction==='out'?$side:$other;$target=$source==='woo'?'ps':'woo';
  $panel=\WD29\Bridge\ManualOrdersAdmin::render($engine,'<input name="nonce" value="preserved">',['bridge_action'=>'manual_orders_scan','manual_direction'=>$direction]);$xp=document($panel);$row=$xp->query('//tr[@data-manual-order="'.$source.':order:42"]')->item(0);$cells=$xp->query('./td',$row);
  check(strpos($cells->item(0)->getAttribute('class'),'wd-side-woo')!==false&&strpos($cells->item(1)->getAttribute('class'),'wd-side-ps')!==false,'Platform columns changed order');
  check(strpos($cells->item($source==='woo'?0:1)->textContent,'SOURCE-42')!==false&&strpos($cells->item($target==='woo'?0:1)->textContent,'COPY-87')!==false,'Native order references placed on wrong side');
  check(strpos($panel,'État natif original')!==false&&strpos($panel,'État natif copie')!==false&&strpos($panel,'normalized-status')===false,'Native statuses not displayed');
  $button=$xp->query('//button[@value="manual_order_sync"]')->item(0);check(strpos($button->textContent,\WD29\Bridge\AdminContext::name($target))!==false,'Apply button names wrong destination');
  check($xp->query('//input[@name="manual_direction" and @value="'.$direction.'"]')->length>=2,'Direction not preserved');
 }
}
echo "PASS: local/peer identity, endpoint privacy, original/imported ownership, preserved tokens, fixed platform columns and native order labels in both directions\n";
}
