<?php
namespace WD29\Bridge;

/** Bounded, explicitly requested original-to-copy transfers. Automatic modes are untouched. */
trait ManualRecords
{
    private function manualKind(string $kind): void
    {
        if(!in_array($kind,['product','order','customer'],true)){throw new \RuntimeException('Famille de fiches invalide.');}
    }
    private function recordSnapshot(string $kind,string $key): array
    {
        $this->manualKind($kind);$parts=explode(':',$key);
        if(count($parts)!==3||$parts[0]!==$this->adapter->site()||!ctype_digit($parts[2])||(int)$parts[2]<1||!in_array($parts[1],$kind==='customer'?['customer','guest']:[$kind],true)){throw new \RuntimeException('Choisissez une fiche originale de la boutique source.');}
        $previous=$this->manualPreview;$this->manualPreview=true;
        try{
            if($kind==='order')return $this->manualSnapshot((int)$parts[2]);
            if($kind==='product'){$data=$this->adapter->product((int)$parts[2]);if($data['key']!==$key||$this->productDeleted($key)){throw new \RuntimeException('Produit original indisponible.');}return $data;}
            if(!$this->adapter->manualContactOriginal($key)){throw new \RuntimeException('Contact original indisponible.');}
            $data=$this->adapter->contactProfile($parts[1],(int)$parts[2]);$data['key']=$key;
            if(!empty($data['deleted']))throw new \RuntimeException('Contact supprimé ou indisponible.');
            return $this->contactData($data);
        }finally{$this->manualPreview=$previous;}
    }
    private function recordHash(string $kind,array $data): string {return $kind==='product'?self::catalogHash($data):Protocol::fingerprint($data);}
    private function recordFields(string $kind,array $data): array
    {
        if($kind==='order')return $this->manualOrderFields($data);
        $groups=$kind==='product'?['identité'=>['name','sku','type','status','archived'],'prix'=>['prices','currency'],'description'=>['description','short_description'],'variantes'=>['attributes','variants'],'images'=>['images'],'classement'=>['categories','tags','brands'],'métadonnées'=>['custom_fields','identifiers','dimensions_cm','suppliers','seo','purchase_price_net','supplier']]:['identité'=>['first_name','last_name','company','guest','deleted'],'coordonnées'=>['email','phone','billing','shipping','addresses'],'métadonnées'=>['custom_fields']];$out=[];
        foreach($groups as $label=>$keys){$values=[];foreach($keys as $k)$values[$k]=$data[$k]??null;$out[$label]=Protocol::fingerprint($values);}return $out;
    }
    private function recordStock(array $data): string
    {
        $stock=[];foreach($data['inventory']??[] as $row)$stock[$row['key']]=$row['quantity'];ksort($stock);return Protocol::fingerprint($stock);
    }
    private function recordSummary(string $kind,array $data): array
    {
        if($kind==='product')return ['label'=>(string)$data['name'],'detail'=>(string)($data['sku']??'').' · '.count($data['variants']??[]).' variante(s) · '.(string)($data['prices']['regular']??'—').' '.(string)($data['currency']??''),'status'=>(string)($data['status']??'')];
        return ['label'=>trim(($data['first_name']??'').' '.($data['last_name']??'')),'detail'=>!empty($data['guest'])?'Contact invité':'Contact inscrit','status'=>'Répertoire du plugin'];
    }
    public function manualRecordScan(string $kind,int $offset=0,int $limit=20): array
    {
        $this->manualKind($kind);if($limit<1||$limit>20)throw new \RuntimeException('Taille du lot invalide.');if($kind==='order')return $this->manualOrderScan($offset,$limit);
        if($offset<0||$offset>10000000)throw new \RuntimeException('Page invalide.');
        $ids=$kind==='customer'?$this->adapter->manualContactKeys($offset,$limit):$this->adapter->ids($kind,$offset,$limit);$rows=[];
        foreach($ids as $id){
            $key=$kind==='customer'?(string)$id:Protocol::key($this->adapter->site(),$kind,(int)$id);
            if($kind==='product'){$map=$this->sql('SELECT record_key FROM {b}map WHERE kind=? AND local_id=?',[$kind,(int)$id])[0]??null;if($map&&$map['record_key']!==$key)continue;}
            try{$data=$this->recordSnapshot($kind,$key);$rows[]=['key'=>$key,'hash'=>$this->recordHash($kind,$data),'fields'=>$this->recordFields($kind,$data),'stock'=>$kind==='product'?$this->recordStock($data):null,'summary'=>$this->recordSummary($kind,$data)];}
            catch(\Throwable $e){$rows[]=['key'=>$key,'state'=>'unavailable','error'=>$e->getMessage(),'summary'=>['label'=>$key,'detail'=>'Source à examiner']];}
        }
        return ['ok'=>true,'rows'=>$rows,'offset'=>$offset,'next'=>count($ids)===$limit?$offset+$limit:null,'mode'=>$this->config()['mode']??'disabled'];
    }
    public function manualRecordCompare(string $kind,array $rows): array
    {
        $this->manualKind($kind);if($kind==='order')return $this->manualOrderCompare($rows);
        if(count($rows)>20)throw new \RuntimeException('Lot trop grand.');$foreign=$this->adapter->site()==='woo'?'ps':'woo';$result=[];
        foreach($rows as $row){
            $key=(string)($row['key']??'');$hash=(string)($row['hash']??'');$pattern=$kind==='customer'?'(?:customer|guest)':'product';
            if(!preg_match('/^'.$foreign.':'.$pattern.':[1-9][0-9]{0,14}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new \RuntimeException('Comparaison invalide.');
            $map=$this->mapping($key);$state=!$map||$map['fingerprint']===''?'missing':($map['fingerprint']===$hash?'same':'changed');$native=null;$summary=null;
            if($map&&$map['fingerprint']!==''){
                try{$previous=$this->manualPreview;$this->manualPreview=true;
                    try{$native=$kind==='product'?$this->adapter->product((int)$map['local_id']):json_decode($this->sql('SELECT data FROM {b}contacts WHERE id=?',[(int)$map['local_id']])[0]['data']??'',true);if(!is_array($native))throw new \RuntimeException('Copie manquante.');}finally{$this->manualPreview=$previous;}
                    if($this->recordHash($kind,$native)!==$map['local_hash'])$state='conflict';$summary=$this->recordSummary($kind,$native);
                }catch(\Throwable $e){$state='conflict';}
            }
            if($kind==='product'&&$this->productDeleted($key))$state='conflict';
            if($this->sql("SELECT seq FROM {b}queue WHERE record_key=? AND state IN ('failed','conflict') LIMIT 1",[$key]))$state='conflict';
            $changes=[];if($native)foreach($this->recordFields($kind,$native) as $field=>$value){if(isset($row['fields'][$field])&&$row['fields'][$field]!==$value)$changes[]=$field;}
            $result[]=['key'=>$key,'state'=>$state,'changes'=>$changes,'destination'=>$map['fingerprint']??'','local_id'=>$map?(int)$map['local_id']:null,'destination_summary'=>$summary,'stock_difference'=>$kind==='product'&&$native&&isset($row['stock'])&&$row['stock']!==$this->recordStock($native)];
        }
        return ['ok'=>true,'rows'=>$result,'mode'=>$this->config()['mode']??'disabled'];
    }
    public function manualRecordDelta(string $kind,string $direction,int $offset=0,int $limit=20): array
    {
        $this->manualKind($kind);if(!in_array($direction,['in','out'],true))throw new \RuntimeException('Sens invalide.');
        if($kind==='order')return $this->manualOrderDelta($direction,$offset,$limit);
        $scan=$direction==='in'?$this->peer(['op'=>'manual_records_scan','kind'=>$kind,'offset'=>$offset,'limit'=>$limit]):$this->manualRecordScan($kind,$offset,$limit);
        $valid=array_values(array_filter($scan['rows'],static function($r){return ($r['state']??'')!=='unavailable';}));
        $comp=$direction==='in'?$this->manualRecordCompare($kind,$valid):$this->peer(['op'=>'manual_records_compare','kind'=>$kind,'rows'=>$valid]);$states=array_column($comp['rows'],null,'key');
        foreach($scan['rows'] as &$row){if(($row['state']??'')==='unavailable')continue;if(!isset($states[$row['key']]))throw new \RuntimeException('Comparaison incomplète.');$row=array_merge($row,$states[$row['key']]);}unset($row);
        $scan['destination_mode']=$comp['mode'];return $scan;
    }
    public function manualRecordExport(string $kind,string $key,string $expected): array
    {
        $this->manualKind($kind);if($kind==='order')return $this->manualOrderExport($key,$expected);
        $data=$this->recordSnapshot($kind,$key);$hash=$this->recordHash($kind,$data);if(!hash_equals($hash,$expected))throw new \RuntimeException('La source a changé. Actualisez la comparaison.');
        if($kind==='product'){
            $id=(int)substr($key,strrpos($key,':')+1);$this->adapter->product($id);
            foreach($data['inventory']??[] as $stock)$this->sql('UPDATE {b}map SET quantity=?,stock_initialized=1 WHERE record_key=? AND stock_initialized=0',[Protocol::quantity($stock['quantity']),$stock['key']]);
        }
        $through=(int)($this->sql("SELECT COALESCE(MAX(seq),0) n FROM {b}queue WHERE direction='out' AND record_key=? AND kind=?",[$key,$kind])[0]['n']??0);
        return ['ok'=>true,'data'=>$data,'hash'=>$hash,'through'=>$through];
    }
    public function manualRecordApply(string $kind,array $export,string $destination): array
    {
        $this->manualKind($kind);if($kind==='order')return $this->manualOrderApply($export,$destination);
        if(!$this->licence()->allowsLive())throw new \RuntimeException('Une licence autorisant les écritures est nécessaire.');
        $data=$export['data']??[];$hash=(string)($export['hash']??'');if(!is_array($data)||!hash_equals($this->recordHash($kind,$data),$hash))throw new \RuntimeException('Instantané invalide.');$key=(string)($data['key']??'');
        $lock=substr($this->table.'worker',0,64);if((int)($this->sql('SELECT GET_LOCK(?,0) acquired',[$lock])[0]['acquired']??0)!==1)throw new \RuntimeException('Un traitement est en cours. Réessayez.');
        try{
            $comparison=$this->manualRecordCompare($kind,[['key'=>$key,'hash'=>$hash]])['rows'][0];
            if($comparison['state']==='conflict')throw new \RuntimeException('Modification locale ou conflit : consultez les rapports.');
            if($comparison['state']==='same')return ['ok'=>true,'state'=>'same','local_id'=>$comparison['local_id']];
            if(!hash_equals($comparison['destination'],$destination))throw new \RuntimeException('La destination a changé. Actualisez la comparaison.');
            $id=bin2hex(random_bytes(16));$this->enqueue('in',$kind,$key,['base'=>$destination,'hash'=>$hash,'data'=>$data],$id);$event=$this->sql("SELECT * FROM {b}queue WHERE event_id=? AND direction='in'",[$id])[0];$this->apply($event);$done=$this->sql("SELECT state,error FROM {b}queue WHERE event_id=? AND direction='in'",[$id])[0];
            if($done['state']!=='applied')throw new \RuntimeException('Fiche non appliquée : '.$done['error']);
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by manual record synchronization.' WHERE direction='in' AND record_key=? AND kind=? AND seq<? AND state='pending'",[$key,$kind,(int)$event['seq']]);
            return ['ok'=>true,'state'=>'applied','local_id'=>(int)$this->mapping($key)['local_id']];
        }finally{$this->sql('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function manualRecordAck(string $kind,string $key,string $hash,int $through): array
    {
        $this->manualKind($kind);if($kind==='order')return $this->manualOrderAck($key,$hash,$through);
        $this->recordSnapshot($kind,$key);$parts=explode(':',$key);$id=$kind==='customer'?$this->contactId($key):(int)end($parts);$lock='wd29_capture_'.sha1($this->table.$kind.':'.$id);
        if((int)($this->sql('SELECT GET_LOCK(?,5) acquired',[$lock])[0]['acquired']??0)!==1)throw new \RuntimeException('Fiche occupée.');
        try{
            $data=$this->recordSnapshot($kind,$key);if($this->recordHash($kind,$data)!==$hash)return ['ok'=>true,'state'=>'source_changed'];
            if($kind==='product'){
                $previous=$this->manualPreview;$this->manualPreview=false;try{$this->adapter->product($id);}finally{$this->manualPreview=$previous;}
                // Existing stock checkpoints and queued deltas are deliberately preserved.
            }else{$this->sql('UPDATE {b}contacts SET data=? WHERE id=?',[Protocol::encode($data),$id]);}
            $this->bind($key,$kind,$id);$this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?',[$hash,$hash,$key]);
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by manual record synchronization.' WHERE direction='out' AND record_key=? AND kind=? AND seq<=? AND state IN ('pending','failed')",[$key,$kind,max(0,$through)]);
            return ['ok'=>true,'state'=>'acknowledged'];
        }finally{$this->sql('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function manualRecordSync(string $kind,string $direction,string $key,string $hash,string $destination): array
    {
        $this->manualKind($kind);if(!in_array($direction,['in','out'],true))throw new \RuntimeException('Sens invalide.');if($kind==='order')return $this->manualOrderSync($direction,$key,$hash,$destination);
        $export=$direction==='in'?$this->peer(['op'=>'manual_record_export','kind'=>$kind,'key'=>$key,'hash'=>$hash]):$this->manualRecordExport($kind,$key,$hash);
        $result=$direction==='in'?$this->manualRecordApply($kind,$export,$destination):$this->peer(['op'=>'manual_record_apply','kind'=>$kind,'export'=>$export,'destination'=>$destination]);if(empty($result['ok']))throw new \RuntimeException('Synchronisation non confirmée.');
        try{$ack=$direction==='in'?$this->peer(['op'=>'manual_record_ack','kind'=>$kind,'key'=>$key,'hash'=>$hash,'through'=>$export['through']]):$this->manualRecordAck($kind,$key,$hash,(int)$export['through']);$result['ack']=$ack['state'];}catch(\Throwable $e){$result['ack']='pending';}return $result;
    }
    public function manualRecordBatch(string $scope,string $direction,int $family,int $offset,int $index): array
    {
        $kinds=$scope==='all'?['product','customer','order']:[$scope];if($family<0||$family>=count($kinds)||$index<0||$index>19)throw new \RuntimeException('Position du traitement invalide.');$kind=$kinds[$family];// One fresh native record per step; older peers may still return their default page.
        $delta=$this->manualRecordDelta($kind,$direction,$offset,1);$row=$delta['rows'][$index]??null;$result=['state'=>'empty','key'=>''];
        if($row){$result=['state'=>$row['state'],'key'=>$row['key']];if(in_array($row['state'],['missing','changed'],true)){try{$result=$this->manualRecordSync($kind,$direction,$row['key'],$row['hash'],$row['destination'])+['key'=>$row['key']];}catch(\Throwable $e){$result=['key'=>$row['key'],'state'=>'error','error'=>$e->getMessage()];}}elseif($row['state']==='unavailable')$result['error']=$row['error'];}
        $index++;if($index>=count($delta['rows'])){$index=0;if($delta['next']!==null)$offset=$delta['next'];else{$family++;$offset=0;}}
        return ['ok'=>true,'result'=>$result,'done'=>$family>=count($kinds),'cursor'=>['family'=>$family,'offset'=>$offset,'index'=>$index]];
    }
    private function manualRecordReceive(array $m): array
    {
        $kind=(string)($m['kind']??'');
        switch($m['op']){
            case 'manual_records_scan':return $this->manualRecordScan($kind,(int)($m['offset']??0),(int)($m['limit']??20));
            case 'manual_records_compare':return $this->manualRecordCompare($kind,(array)($m['rows']??[]));
            case 'manual_record_export':return $this->manualRecordExport($kind,(string)($m['key']??''),(string)($m['hash']??''));
            case 'manual_record_apply':return $this->manualRecordApply($kind,(array)($m['export']??[]),(string)($m['destination']??''));
            case 'manual_record_ack':return $this->manualRecordAck($kind,(string)($m['key']??''),(string)($m['hash']??''),(int)($m['through']??0));
        }throw new \RuntimeException('Opération inconnue.');
    }
}
