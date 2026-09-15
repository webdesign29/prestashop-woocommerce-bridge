<?php
namespace WD29\Bridge;
final class FieldMirrorAdmin
{
    public static function read(Engine $engine,string $key): array
    {
        $map=$engine->mapping($key);
        if (!$map || !in_array($map['kind'],['product','order','variant'],true)) { throw new \RuntimeException('Choose a mapped product, variation or order key.'); }
        if ($map['kind']==='variant') {
            $combination=new \Combination((int)$map['local_id']);
            $parents=$engine->sql("SELECT * FROM {b}map WHERE kind='product' AND local_id=?",[(int)$combination->id_product]);
            if (!$parents) { throw new \RuntimeException('Variation parent is not mapped.'); } $map=$parents[0];
            $snapshot=json_decode($map['snapshot']??'{}',true)?:[];
            return $snapshot['variant_extras'][$key]['custom_fields']??[];
        }
        $snapshot=json_decode($map['snapshot']??'{}',true)?:[];
        return $snapshot['custom_fields']??[];
    }
    public static function save(Engine $engine,string $key,array $submitted,string $base): void
    {
        $lock=substr($engine->adapter->prefix().'wd29_bridge_worker',0,64);
        if ((int)($engine->sql('SELECT GET_LOCK(?,5) AS acquired',[$lock])[0]['acquired']??0)!==1) { throw new \RuntimeException('Custom fields are busy.'); }
        try {
            $engine->sql('START TRANSACTION');
            $map=$engine->mapping($key); $current=self::read($engine,$key);
            if (!hash_equals(Protocol::fingerprint($current),$base)) { throw new \RuntimeException('Fields changed since loading. Reload before saving.'); }
            foreach ($submitted as $id=>$row) {
                if (!array_key_exists($id,$current) || !is_array($row)) { throw new \RuntimeException('Only already synchronized fields can be edited.'); }
                $value=json_decode($row['json']??'null',true,16,JSON_THROW_ON_ERROR);
                if (strlen(json_encode($value,JSON_THROW_ON_ERROR))>65536) { throw new \RuntimeException('Custom field value too large.'); }
                $current[$id]=['present'=>!empty($row['present']),'value'=>!empty($row['present'])?$value:null];
            }
            if ($map['kind']==='variant') {
                $v=new \Combination((int)$map['local_id']);
                $map=$engine->sql("SELECT * FROM {b}map WHERE kind='product' AND local_id=?",[(int)$v->id_product])[0];
                $snapshot=json_decode($map['snapshot']??'{}',true)?:[];
                $snapshot['variant_extras'][$key]['custom_fields']=$current;
            } else { $snapshot=json_decode($map['snapshot']??'{}',true)?:[]; $snapshot['custom_fields']=$current; }
            $engine->sql('UPDATE {b}map SET snapshot=? WHERE record_key=?',[Protocol::encode($snapshot),$map['record_key']]);
            $engine->sql('COMMIT');
            $engine->capture($map['kind'],(int)$map['local_id']);
        } catch (\Throwable $error) { $engine->sql('ROLLBACK'); throw $error; }
        finally { $engine->sql('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
}
