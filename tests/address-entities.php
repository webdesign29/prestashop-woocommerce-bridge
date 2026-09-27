<?php
require __DIR__.'/../includes/OrderConflicts.php';
require __DIR__.'/../includes/PrestaAdapter.php';
use WD29\Bridge\PrestaAdapter;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$base=['city'=>'29200 &#8211; BREST','address_1'=>'1 rue de Bretagne','email'=>'test@example.invalid'];
foreach (['29200 &#8211; BREST'=>'29200 – BREST','Pont&ndash;Aven'=>'Pont–Aven','Saint&#x2D;Malo'=>'Saint-Malo','Quimper'=>'Quimper'] as $source=>$native) {
 $wire=array_merge($base,['city'=>$source]);$stored=PrestaAdapter::nativeAddress($wire);
 check($stored['city']===$native,'City was not decoded');check($wire['city']===$source,'Wire snapshot changed');check($stored['address_1']===$wire['address_1'],'Unrelated address changed');
 PrestaAdapter::assertNativeAddress($stored,$wire);PrestaAdapter::assertNativeAddress($wire,$wire);
 foreach (['city','address_1','email'] as $field) {
  $edited=$stored;$edited[$field]='Changed locally';$rejected=false;
  try{PrestaAdapter::assertNativeAddress($edited,$wire);}catch(RuntimeException $e){$rejected=true;}
  check($rejected,'Local edit escaped review: '.$field);
 }
}
$invalid=PrestaAdapter::nativeAddress(['city'=>'&#60;script&#62;']);check($invalid['city']==='<script>','Invalid source text must remain subject to native validation');
echo "Address entity decoding, unchanged snapshots, legacy mirrors and local-edit guards passed\n";
