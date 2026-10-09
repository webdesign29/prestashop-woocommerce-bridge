<?php
namespace WD29\Bridge {
    // Deliberately narrow storage/transport harness: all writes and scans fail the test.
    class Engine {
        public $adapter, $maps = [], $deleted = [], $queries = [], $calls = [], $response, $failure = false;
        public function __construct(string $side) { $this->adapter = new ProductLinkAdapter($side); }
        public function config(): array { return $this->adapter->settings; }
        public function sql(string $query, array $args = []) {
            $this->queries[] = $query;
            if (strpos($query, 'SELECT ') !== 0 || strpos($query, 'LIMIT 1') === false) { throw new \RuntimeException('Unbounded read or write'); }
            if (strpos($query, 'deleted_products') !== false) { return isset($this->deleted[$args[0]]) ? [['record_key' => $args[0]]] : []; }
            if (strpos($query, 'WHERE kind=? AND local_id=?') !== false) {
                foreach ($this->maps as $key => $map) if ($map['kind'] === $args[0] && $map['local_id'] === $args[1]) return [['record_key' => $key] + $map];
                return [];
            }
            $map = $this->maps[$args[0]] ?? null;
            return $map && $map['kind'] === $args[1] ? [['record_key' => $args[0]] + $map] : [];
        }
        public function peer(array $payload, int $timeout = 25): array {
            $this->calls[] = [$payload, $timeout];
            if ($this->failure) throw new \RuntimeException('SECRET MUST NOT LEAK');
            return $this->response;
        }
    }
    class ProductLinkAdapter {
        public $side, $settings, $products = [], $public = [], $urls = [], $reads = 0;
        public function __construct(string $side) { $this->side=$side; $this->settings=['peer'=>'https://'.($side==='woo'?'ps':'woo').'.example/shop/webhook','secret'=>str_repeat('s',32),'mode'=>'disabled']; }
        public function site(): string {return $this->side;}
        public function siteUrl(): string {return 'https://'.$this->side.'.example/shop/';}
        public function productExists(int $id): bool {$this->reads++; return isset($this->products[$id]);}
        public function productPublicUrl(int $id): string {return !empty($this->public[$id])?($this->urls[$id]??$this->siteUrl().'product/'.$id):'';}
        public function product(int $id) {throw new \RuntimeException('Full catalog export not allowed');}
    }
}
namespace {
    require __DIR__.'/../includes/Protocol.php';
    require __DIR__.'/../includes/ProductLinks.php';
    use WD29\Bridge\ProductLinks;
    use WD29\Bridge\Engine;
    $checks=0;
    function check($condition,$message) {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
    function refuses($fn,$message) {$failed=false;try{$fn();}catch(Throwable $e){$failed=true;}check($failed,$message);}
    foreach(['woo','ps'] as $side) {
        $foreign=$side==='woo'?'ps':'woo';$engine=new Engine($side);$adapter=$engine->adapter;
        $adapter->products=[10=>true,20=>true];$adapter->public=[10=>true,20=>true];
        check(ProductLinks::local($engine,10)['state']==='unmapped','Unknown product guessed by ID');
        check(count($engine->calls)===0,'Local rendering contacted peer');
        $engine->maps[$side.':product:10']=['kind'=>'product','local_id'=>10];
        $engine->maps[$foreign.':product:99']=['kind'=>'product','local_id'=>20];
        check(ProductLinks::local($engine,10)['origin']===true,'Original identity wrong');
        check(ProductLinks::local($engine,20)['key']===$foreign.':product:99','Imported identity guessed by local ID');
        check(ProductLinks::local($engine,20)['origin']===false,'Copy origin wrong');
        $row=ProductLinks::inspect($engine,$foreign.':product:99');
        check($row['state']==='linked'&&$row['local_id']===20&&$row['url']===$adapter->siteUrl().'product/20','Copy permalink mismatch');
        check(ProductLinks::inspect($engine,$side.':product:10')['local_id']===10,'Original permalink mismatch');
        check(ProductLinks::inspect($engine,$foreign.':product:10')['state']==='missing','Guessed foreign mapping');
        check(ProductLinks::inspect($engine,$side.':product:20')['state']==='missing','Imported record treated as unrelated original');
        $engine->deleted[$foreign.':product:99']=true;
        check(ProductLinks::inspect($engine,$foreign.':product:99')['state']==='missing','Deleted identity linked');
        check(ProductLinks::local($engine,20)['state']==='missing','Deleted local identity linked');
        unset($engine->deleted[$foreign.':product:99']);unset($adapter->public[20]);
        check(ProductLinks::inspect($engine,$foreign.':product:99')['state']==='unpublished','Draft permalink exposed');
        unset($adapter->products[20]);check(ProductLinks::inspect($engine,$foreign.':product:99')['state']==='missing','Removed product linked');
        $response=['ok'=>true,'key'=>$side.':product:10','local_id'=>77,'state'=>'linked','url'=>'https://'.$foreign.'.example/shop/product/77'];
        $engine->response=$response;$result=ProductLinks::remote($engine,10);
        check($result['state']==='linked'&&$result['remote_id']===77,'Remote original-to-copy resolution failed while paused');
        check($engine->calls[0][0]===['op'=>'product_link','key'=>$side.':product:10']&&$engine->calls[0][1]===6,'Lookup exported payload or exceeded timeout budget');
        check(count($engine->queries)<40,'Unexpected scans');
        foreach(['https://evil.example/x','javascript:alert(1)','https://user:pass@'.$foreign.'.example/x','http://'.$foreign.'.example/x','https://'.$foreign.'.example:444/x','https://'.$foreign.'.example/x#token','https://'.$foreign.'.example/with space'] as $url){$engine->response=$response;$engine->response['url']=$url;check(ProductLinks::remote($engine,10)['state']==='unavailable','Unsafe product URL accepted');}
        $engine->response=$response;$engine->response['key']=$foreign.':product:77';check(ProductLinks::remote($engine,10)['state']==='unavailable','Wrong identity accepted');
        $engine->response=$response;$engine->response['url']=['invalid'];check(ProductLinks::remote($engine,10)['state']==='unavailable','Malformed URL accepted');
        $engine->response=$response;$engine->response['local_id']=0;check(ProductLinks::remote($engine,10)['state']==='unavailable','Invalid native ID accepted');
        $engine->response=$response;$engine->response['state']='unpublished';$engine->response['url']='';check(ProductLinks::remote($engine,10)['state']==='unpublished','Unpublished state lost');
        $engine->response=['ok'=>true,'key'=>$side.':product:10','state'=>'missing'];check(ProductLinks::remote($engine,10)['state']==='missing','Missing copy not handled');
        $engine->failure=true;$result=ProductLinks::remote($engine,10);check($result['state']==='unavailable'&&strpos(json_encode($result),'SECRET')===false,'Transport failure leaked');
        $before=count($engine->calls);$adapter->settings['peer']='';check(ProductLinks::remote($engine,10)['state']==='disconnected'&&count($engine->calls)===$before,'Disconnected store attempted network');
        $adapter->settings['peer']='https://'.$foreign.'.example/webhook';$adapter->settings['secret']='';check(ProductLinks::local($engine,10)['state']==='disconnected','Missing secret not detected');
        foreach(['woo:variant:10','ps:customer:1','woo:product:0','woo:product:-2','woo:product:12x',"ps:product:99\n",'ps:product:9999999999999999'] as $key)refuses(fn()=>ProductLinks::inspect($engine,$key),'Invalid identity accepted');
    }
    echo 'PASS '.$checks." product-link mapping, read-only, paused mode, URL safety, missing/unpublished and bounded transport checks\n";
}
