<?php
namespace WD29\Bridge;

/** Explicit, bounded order transfers; never changes the configured automatic mode. */
trait ManualOrders
{
    private $manualPreview = false;

    private function manualSnapshot(int $id): array
    {
        if ($id<1 || (method_exists($this->adapter,'orderSyncable') && !$this->adapter->orderSyncable($id))) { throw new \RuntimeException('Commande non validée ou indisponible.'); }
        $previous=$this->manualPreview;$this->manualPreview=true;
        try { return $this->adapter->order($id); } finally { $this->manualPreview=$previous; }
    }

    private function manualOriginal(string $key): int
    {
        if (!preg_match('/^'.preg_quote($this->adapter->site(),'/').':order:([1-9][0-9]{0,14})$/D',$key,$m)) { throw new \RuntimeException('Seules les commandes originales de cette boutique peuvent être envoyées.'); }
        return (int)$m[1];
    }

    private function manualOrderFields(array $data): array
    {
        $groups=['statut'=>['status'],'montants'=>['total','tax','shipping_net','shipping_tax','discount','currency'],'articles'=>['items'],'coordonnées'=>['billing','shipping'],'métadonnées'=>['custom_fields'],'remboursements'=>['refunds']];$result=[];
        foreach($groups as $label=>$keys){$values=[];foreach($keys as $key){$values[$key]=$data[$key]??null;}$result[$label]=Protocol::fingerprint($values);}return $result;
    }

    public function manualOrderScan(int $offset=0): array
    {
        if ($offset<0 || $offset>10000000) { throw new \RuntimeException('Page invalide.'); }
        $ids=$this->adapter->ids('order',$offset,20);$rows=[];
        foreach($ids as $id){
            if(method_exists($this->adapter,'orderSyncable')&&!$this->adapter->orderSyncable((int)$id)){continue;}
            $data=$this->manualSnapshot((int)$id);
            if(strpos($data['key'],$this->adapter->site().':order:')!==0){continue;}
            $rows[]=['key'=>$data['key'],'hash'=>Protocol::fingerprint($data),'number'=>(string)$data['number'],'status'=>(string)$data['status'],'total'=>(string)$data['total'],'currency'=>(string)$data['currency'],'lines'=>count($data['items']??[]),'fields'=>$this->manualOrderFields($data)];
        }
        return ['ok'=>true,'rows'=>$rows,'offset'=>$offset,'next'=>count($ids)===20?$offset+20:null,'mode'=>$this->config()['mode']??'disabled'];
    }

    public function manualOrderCompare(array $rows): array
    {
        if(count($rows)>20){throw new \RuntimeException('Lot trop grand.');}$result=[];
        $foreign=$this->adapter->site()==='woo'?'ps':'woo';
        foreach($rows as $row){
            $key=(string)($row['key']??'');$hash=(string)($row['hash']??'');
            if(!preg_match('/^'.$foreign.':order:[1-9][0-9]{0,14}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){throw new \RuntimeException('Comparaison invalide.');}
            $native=null;$map=$this->mapping($key);$status=!$map?'missing':($map['fingerprint']===$hash?'same':'changed');
            if($map){
                try{
                    $previous=$this->manualPreview;$this->manualPreview=true;
                    try{$native=method_exists($this->adapter,'orderConflictSnapshot')?$this->adapter->orderConflictSnapshot((int)$map['local_id']):$this->adapter->order((int)$map['local_id']);}finally{$this->manualPreview=$previous;}
                    if(Protocol::fingerprint($native)!==$map['local_hash']){$status='conflict';}
                }catch(\Throwable $e){$status='conflict';}
            }
            if($this->sql("SELECT seq FROM {b}queue WHERE direction='in' AND record_key=? AND state IN ('failed','conflict') LIMIT 1",[$key])){$status='conflict';}
            $changes=[];if($native&&isset($row['fields'])&&is_array($row['fields'])){foreach($this->manualOrderFields($native) as $label=>$value){if(($row['fields'][$label]??$value)!==$value){$changes[]=$label;}}}
            $result[]=['key'=>$key,'state'=>$status,'changes'=>$changes,'destination_summary'=>$native?['status'=>$native['status'],'total'=>$native['total'],'currency'=>$native['currency']]:null,'destination'=>$map['fingerprint']??'','local_id'=>$map?(int)$map['local_id']:null];
        }
        return ['ok'=>true,'rows'=>$result,'mode'=>$this->config()['mode']??'disabled'];
    }

    public function manualOrderDelta(string $direction,int $offset=0): array
    {
        if(!in_array($direction,['in','out'],true)){throw new \RuntimeException('Sens invalide.');}
        $scan=$direction==='in'?$this->peer(['op'=>'manual_orders_scan','offset'=>$offset]):$this->manualOrderScan($offset);
        $comp=$direction==='in'?$this->manualOrderCompare($scan['rows']):$this->peer(['op'=>'manual_orders_compare','rows'=>$scan['rows']]);
        $states=array_column($comp['rows'],null,'key');
        foreach($scan['rows'] as &$row){if(!isset($states[$row['key']])){throw new \RuntimeException('Comparaison incomplète.');}$row=array_merge($row,$states[$row['key']]);}unset($row);
        $scan['destination_mode']=$comp['mode'];return $scan;
    }

    public function manualOrderExport(string $key,string $expected): array
    {
        $id=$this->manualOriginal($key);$data=$this->manualSnapshot($id);$hash=Protocol::fingerprint($data);
        if($data['key']!==$key || !hash_equals($hash,$expected)){throw new \RuntimeException('La commande source a changé. Actualisez la comparaison.');}
        $through=(int)($this->sql("SELECT COALESCE(MAX(seq),0) n FROM {b}queue WHERE direction='out' AND record_key=?",[$key])[0]['n']??0);
        return ['ok'=>true,'data'=>$data,'hash'=>$hash,'through'=>$through];
    }

    public function manualOrderApply(array $export,string $destination): array
    {
        if(!$this->licence()->allowsLive()){throw new \RuntimeException('Une licence autorisant les écritures est nécessaire pour cette synchronisation manuelle.');}
        $data=$export['data']??[];$key=(string)($data['key']??'');$hash=(string)($export['hash']??'');
        if(!is_array($data)||!hash_equals(Protocol::fingerprint($data),$hash)){throw new \RuntimeException('Instantané invalide.');}
        $lock=substr($this->table.'worker',0,64);
        if((int)($this->sql('SELECT GET_LOCK(?,0) acquired',[$lock])[0]['acquired']??0)!==1){throw new \RuntimeException('Un traitement est en cours. Réessayez dans un instant.');}
        try{
            $comparison=$this->manualOrderCompare([['key'=>$key,'hash'=>$hash]])['rows'][0];
            if($comparison['state']==='conflict'){throw new \RuntimeException('La copie locale ou sa file a un conflit. Consultez les rapports avant de synchroniser.');}
            if($comparison['state']==='same'){return ['ok'=>true,'state'=>'same','local_id'=>$comparison['local_id']];}
            if(!hash_equals($comparison['destination'],$destination)){throw new \RuntimeException('La destination a changé. Actualisez la comparaison.');}
            $id=bin2hex(random_bytes(16));$this->enqueue('in','order',$key,['base'=>$destination,'hash'=>$hash,'data'=>$data],$id);
            $event=$this->sql("SELECT * FROM {b}queue WHERE event_id=? AND direction='in'",[$id])[0];
            $this->apply($event);
            $done=$this->sql("SELECT state,error FROM {b}queue WHERE event_id=? AND direction='in'",[$id])[0];
            if($done['state']!=='applied'){throw new \RuntimeException('Commande non appliquée : '.$done['error']);}
            // The explicitly reviewed latest snapshot supersedes older pending snapshots of this order only.
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by manual order synchronization.' WHERE direction='in' AND record_key=? AND seq<? AND state='pending'",[$key,(int)$event['seq']]);
            return ['ok'=>true,'state'=>'applied','local_id'=>(int)$this->mapping($key)['local_id']];
        }finally{$this->sql('SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    public function manualOrderAck(string $key,string $hash,int $through): array
    {
        $id=$this->manualOriginal($key);$lock='wd29_capture_'.sha1($this->table.'order:'.$id);
        if((int)($this->sql('SELECT GET_LOCK(?,5) acquired',[$lock])[0]['acquired']??0)!==1){throw new \RuntimeException('Commande occupée.');}
        try{
            if(Protocol::fingerprint($this->manualSnapshot($id))!==$hash){return ['ok'=>true,'state'=>'source_changed'];}
            $this->bind($key,'order',$id);
            $this->sql('UPDATE {b}map SET fingerprint=?,local_hash=? WHERE record_key=?',[$hash,$hash,$key]);
            $this->sql("UPDATE {b}queue SET state='ignored',error='Superseded by manual order synchronization.' WHERE direction='out' AND record_key=? AND seq<=? AND state IN ('pending','failed')",[$key,max(0,$through)]);
            return ['ok'=>true,'state'=>'acknowledged'];
        }finally{$this->sql('SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    public function manualOrderSync(string $direction,string $key,string $hash,string $destination): array
    {
        if(!in_array($direction,['in','out'],true)){throw new \RuntimeException('Sens invalide.');}
        $export=$direction==='in'?$this->peer(['op'=>'manual_order_export','key'=>$key,'hash'=>$hash]):$this->manualOrderExport($key,$hash);
        $result=$direction==='in'?$this->manualOrderApply($export,$destination):$this->peer(['op'=>'manual_order_apply','export'=>$export,'destination'=>$destination]);
        if(empty($result['ok'])){throw new \RuntimeException('Synchronisation non confirmée.');}
        try{
            $ack=$direction==='in'?$this->peer(['op'=>'manual_order_ack','key'=>$key,'hash'=>$hash,'through'=>$export['through']]):$this->manualOrderAck($key,$hash,(int)$export['through']);
            $result['ack']=$ack['state'];
        }catch(\Throwable $e){$result['ack']='pending';}
        return $result;
    }

    private function manualOrderReceive(array $m): array
    {
        switch($m['op']){
            case 'manual_orders_scan':return $this->manualOrderScan((int)($m['offset']??0));
            case 'manual_orders_compare':return $this->manualOrderCompare((array)($m['rows']??[]));
            case 'manual_order_export':return $this->manualOrderExport((string)($m['key']??''),(string)($m['hash']??''));
            case 'manual_order_apply':return $this->manualOrderApply((array)($m['export']??[]),(string)($m['destination']??''));
            case 'manual_order_ack':return $this->manualOrderAck((string)($m['key']??''),(string)($m['hash']??''),(int)($m['through']??0));
        }
        throw new \RuntimeException('Opération manuelle inconnue.');
    }
}
