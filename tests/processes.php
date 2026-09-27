<?php
require __DIR__.'/manual-records.php';
class CountedRecordAdapter extends RecordAdapter {
 public $reads=0;
 public function product($id){$this->reads++;return parent::product($id);}
}
$a=new CountedRecordAdapter('woo');$e=new WD29\Bridge\Engine($a);$a->engine=$e;
for($i=1;$i<=25;$i++)$a->products[$i]=['name'=>'Test '.$i,'sku'=>'T'.$i,'inventory'=>[]];
$a->reads=0;$page=$e->manualRecordScan('product',0,1);check($a->reads===1&&count($page['rows'])===1&&$page['next']===1,'Single-record scan loads an entire page');
check(count($e->manualRecordScan('product')['rows'])===20,'Comparison pages no longer default to 20');
refuses(fn()=>$e->manualRecordScan('product',0,0),'Zero limit accepted');refuses(fn()=>$e->manualRecordScan('product',0,21),'Unbounded limit accepted');
refuses(fn()=>$e->manualOrderScan(0,21),'Unbounded order limit accepted');
// Exercise cursor traversal with current peers (one raw record) and older peers (20).
class BatchProcessHarness {
 use WD29\Bridge\ManualRecords;
 public $legacy=false,$seen=[],$calls=0;
 public function manualRecordDelta(string $kind,string $direction,int $offset=0,int $limit=20):array {
  check($limit===1,'Batch requested a full comparison page');$this->calls++;
  $raw=array_slice(range(1,25),$offset,$this->legacy?20:1);$rows=[];
  foreach($raw as $id)if($id!==2)$rows[]=['key'=>'woo:product:'.$id,'hash'=>'hash','destination'=>'','state'=>$id===4?'conflict':'missing'];
  return ['rows'=>$rows,'next'=>count($raw)===($this->legacy?20:1)?$offset+count($raw):null];
 }
 public function manualRecordSync(string $kind,string $direction,string $key,string $hash,string $destination):array {$this->seen[]=$key;return ['state'=>'applied'];}
}
foreach([false,true] as $legacy){
 $h=new BatchProcessHarness();$h->legacy=$legacy;$cursor=['family'=>0,'offset'=>0,'index'=>0];$steps=0;
 do{$r=$h->manualRecordBatch('product','out',$cursor['family'],$cursor['offset'],$cursor['index']);$cursor=$r['cursor'];check(++$steps<60,'Batch cursor stuck');}while(!$r['done']);
 check(count($h->seen)===23&&count(array_unique($h->seen))===23,'Filtered or legacy pages skip/replay originals');
 check(!in_array('woo:product:4',$h->seen,true),'Conflict was applied');
}
echo "PASS: single-record scan budget, 20-row comparison compatibility, bounded cursors, filtered originals and old-peer fallback\n";
