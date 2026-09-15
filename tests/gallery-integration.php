<?php
$root=getenv('WD29_PS_ROOT'); if (!$root) { throw new RuntimeException('Disposable PS root required.'); }
$_SERVER['HTTP_HOST']='ps.example.test'; $_SERVER['REQUEST_URI']='/'; require $root.'/config/config.inc.php';
if (_DB_NAME_!=='wd29ps' || Configuration::get('PS_SHOP_DOMAIN')!=='ps.example.test') { throw new RuntimeException('Disposable PS database required.'); }
Context::getContext()->shop=new Shop(1); Context::getContext()->language=new Language((int)Configuration::get('PS_LANG_DEFAULT')); Context::getContext()->currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT')); Context::getContext()->employee=new Employee(1);
require_once $root.'/app/AppKernel.php'; $kernel=new AppKernel('prod',false); $kernel->boot(); require_once __DIR__.'/../includes/Gallery.php';
use WD29\Bridge\Gallery;
function galleryCheck($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$p=new Product(); foreach (Language::getLanguages(false) as $lang) { $p->name[(int)$lang['id_lang']]='Gallery fixture'; $p->link_rewrite[(int)$lang['id_lang']]='gallery-fixture'; } $p->price=12; $p->active=false; $p->id_category_default=(int)Configuration::get('PS_HOME_CATEGORY'); galleryCheck($p->add(),'Product fixture failed');
$images=[]; for ($i=0;$i<3;$i++) { $image=new Image(); $image->id_product=$p->id; $image->position=$i+1; $image->cover=$i===0?1:null; galleryCheck($image->add(),'Image fixture failed'); $images[]=$image; }
$ids=array_map(function($image){return (int)$image->id;},$images); $meta=['images'=>[hash('sha256','old')=>$ids[0],hash('sha256','new')=>$ids[1]]];
$c=new Combination(); $c->id_product=$p->id; galleryCheck($c->add(),'Combination fixture failed');
try {
 Gallery::apply($p,[$ids[1]],$meta,false); galleryCheck(count($p->getImages(Context::getContext()->language->id))===3,'Default mode detached image');
 Gallery::apply($p,[$ids[1]],$meta,true); galleryCheck(count($p->getImages(Context::getContext()->language->id))===2,'Shop image detach failed');
 galleryCheck(Validate::isLoadedObject(new Image($ids[0])) && isset($meta['gallery_detached'][$ids[0]]),'Native Image deleted or recovery absent');
 Gallery::apply($p,[$ids[1]],$meta,true); galleryCheck(count($meta['gallery_detached'])===1,'Replay duplicated recovery');
 Gallery::restore($p,$meta); galleryCheck(count($p->getImages(Context::getContext()->language->id))===3,'Gallery restore failed');
 $c->setImages([$ids[0],$ids[2]]); Gallery::applyCombination($c,[$ids[1]],$meta,true);
 $rows=Db::getInstance()->executeS('SELECT id_image FROM `'._DB_PREFIX_.'product_attribute_image` WHERE id_product_attribute='.(int)$c->id); $actual=array_map('intval',array_column($rows,'id_image')); sort($actual); $expected=[$ids[1],$ids[2]]; sort($expected);
 galleryCheck($actual===$expected,'Combination detach lost manual image');
 Gallery::restoreCombination($c,$meta); galleryCheck((int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'product_attribute_image` WHERE id_product_attribute='.(int)$c->id)===3,'Combination recovery failed');
 Gallery::apply($p,[],$meta,true); galleryCheck(count($p->getImages(Context::getContext()->language->id))===1,'Empty source did not preserve only manual image');
 Gallery::applyCombination($c,[$ids[1]],$meta,true);
 $adapter=new \WD29\Bridge\PrestaAdapter();
 $adapter->engine=new class($p->id,$c->id,$meta) {
  public $pid; public $aid; public $meta; public $captured=[];
  public function __construct($pid,$aid,$meta) { $this->pid=$pid; $this->aid=$aid; $this->meta=$meta; }
  public function parentMap() { return ['kind'=>'product','local_id'=>$this->pid,'record_key'=>'woo:product:999','snapshot'=>json_encode($this->meta)]; }
  public function mapping($key) { return $key==='woo:variant:999'?['kind'=>'variant','local_id'=>$this->aid]:null; }
  public function sql($query,$args=[]) { if (strpos($query,'SELECT')===0) { return [$this->parentMap()]; } $this->meta=json_decode($args[0],true); return true; }
  public function capture($kind,$id) { $this->captured[]=[$kind,$id]; }
 };
 $adapter->restoreGallery('woo:variant:999');
 galleryCheck(count($p->getImages(Context::getContext()->language->id))===3 && count($adapter->engine->captured)===1,'Wrapper did not restore parent/capture');
 galleryCheck((int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'product_attribute_image` WHERE id_product_attribute='.(int)$c->id)===3,'Wrapper did not restore combination');
 galleryCheck(empty($adapter->engine->meta['gallery_detached']),'Wrapper did not persist recovery metadata');
 echo "PASS: PS reversible image-shop detach, retained native records, restore, replay and manual/combination preservation\n";
} finally { $c->delete(); foreach ($images as $image) { $image->delete(); } $p->delete(); }
