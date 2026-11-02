<?php
require __DIR__.'/../includes/Protocol.php';require __DIR__.'/../includes/OrderConflicts.php';require __DIR__.'/../includes/Engine.php';
class ReviewAdapter {public $last;public function prefix(){return 'fixture_';}public function sql($q,$args){$this->last=[$q,$args];return [];}}
function check($ok,$m){if(!$ok)throw new RuntimeException($m);}
$a=new ReviewAdapter();$e=new WD29\Bridge\Engine($a);$fail=new ReflectionMethod($e,'fail');$fail->setAccessible(true);
try{WD29\Bridge\OrderConflicts::assertMoney('18','17',2);throw new LogicException('Mismatch accepted');}catch(WD29\Bridge\NativeOrderReview $error){}
$event=['kind'=>'order','seq'=>7,'attempts'=>3];$fail->invoke($e,$event,$error);check(strpos($a->last[0],"state='conflict'")!==false,'Native order review was retried');check($a->last[1][1]===7,'Unrelated event changed');
$fail->invoke($e,$event,new RuntimeException('Temporary native failure'));check($a->last[1][2]==='pending','Temporary failure blocked permanently');check($a->last[1][0]===4,'Temporary retry counter wrong');
$event['kind']='product';$fail->invoke($e,$event,$error);check($a->last[1][2]==='pending','Unrelated kind classified as native order review');
$event['kind']='customer';$fail->invoke($e,$event,new WD29\Bridge\NativeCustomerReview('Native customer changed'));check(strpos($a->last[0],"state='conflict'")!==false,'Native customer copy was overwritten/retried');
echo "PASS native order review stops retries; temporary failures and other records keep retrying\n";
