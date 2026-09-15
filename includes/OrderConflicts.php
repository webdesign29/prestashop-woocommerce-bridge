<?php
namespace WD29\Bridge;

/** Conservative wire-schema upgrade comparison. Never changes records or conflict policy. */
final class OrderConflicts
{
    /**
     * True only when the two complete exported orders have identical business values.
     * The caller must separately verify that a snapshot-backed export still matches
     * the actual native order before using this result to retry an incoming event.
     */
    public static function equivalentAugmentation(array $current,array $incoming): bool
    {
        $a=$current; $b=$incoming;
        foreach (['key','number','status','currency','total','tax','shipping_net','shipping_tax','discount','billing','shipping','items','created'] as $required) {
            if (!array_key_exists($required,$a) || !array_key_exists($required,$b)) { return false; }
        }
        if (!is_string($a['key']) || !preg_match('/^(woo|ps):order:[1-9][0-9]*$/D',$a['key']) || $a['key']!==$b['key']) { return false; }
        if (!is_array($a['items']) || !is_array($b['items']) || count($a['items'])!==count($b['items']) || array_values($a['items'])!==$a['items'] || array_values($b['items'])!==$b['items']) { return false; }
        foreach (['refunds','custom_fields'] as $added) {
            // An absent legacy field is equivalent only to the exact empty JSON list/object
            // represented by PHP []. Null, false, tombstones and populated values matter.
            if (!array_key_exists($added,$a) && ($b[$added]??null)===[]) { unset($b[$added]); }
            if (!array_key_exists($added,$b) && ($a[$added]??null)===[]) { unset($a[$added]); }
        }
        $seenA=[]; $seenB=[];
        foreach ($a['items'] as $index=>&$line) {
            $other=&$b['items'][$index];
            if (!is_array($line) || !is_array($other)) { return false; }
            foreach (['product','name','quantity','net','tax'] as $required) { if (!array_key_exists($required,$line) || !array_key_exists($required,$other)) { return false; } }
            foreach (['line','other'] as $side) {
                $entry=${$side};
                if (!array_key_exists('line_id',$entry)) { continue; }
                if (!is_string($entry['line_id']) || !preg_match('/^[1-9][0-9]*$/D',$entry['line_id'])) { return false; }
                if ($side==='line') { if (isset($seenA[$entry['line_id']])) { return false; } $seenA[$entry['line_id']]=true; }
                else { if (isset($seenB[$entry['line_id']])) { return false; } $seenB[$entry['line_id']]=true; }
            }
            if (!array_key_exists('line_id',$line) || !array_key_exists('line_id',$other)) { unset($line['line_id'],$other['line_id']); }
            unset($other);
        }
        unset($line);
        return Protocol::fingerprint($a)===Protocol::fingerprint($b);
    }

    public static function assertMoney($actual,$expected,int $decimals=2): void
    {
        if (!is_numeric($actual) || !is_numeric($expected) || !is_finite((float)$actual) || !is_finite((float)$expected) || abs((float)$actual)>1000000000000 || abs((float)$expected)>1000000000000 || number_format((float)$actual,max(0,min(6,$decimals)),'.','')!==number_format((float)$expected,max(0,min(6,$decimals)),'.','')) { throw new \RuntimeException('Native mirror financial values differ from the synchronized snapshot; explicit review required.'); }
    }
    public static function assertAddress(array $actual,array $expected): void
    {
        foreach (array_unique(array_merge(array_keys($actual),array_keys($expected))) as $key) {
            if ((string)($actual[$key]??'')!==(string)($expected[$key]??'')) { throw new \RuntimeException('Native mirror address differs from the synchronized snapshot; explicit review required.'); }
        }
    }

    /** Summaries for an authenticated admin review; no addresses or metadata values. */
    public static function review(array $current,array $incoming): array
    {
        $summarize=static function(array $order): array {
            return ['key'=>is_string($order['key']??null)?$order['key']:'',
                'status'=>is_string($order['status']??null)?$order['status']:'',
                'currency'=>is_string($order['currency']??null)?$order['currency']:'',
                'total'=>is_scalar($order['total']??null)?(string)$order['total']:'',
                'tax'=>is_scalar($order['tax']??null)?(string)$order['tax']:'',
                'shipping_net'=>is_scalar($order['shipping_net']??null)?(string)$order['shipping_net']:'',
                'shipping_tax'=>is_scalar($order['shipping_tax']??null)?(string)$order['shipping_tax']:'',
                'discount'=>is_scalar($order['discount']??null)?(string)$order['discount']:'',
                'lines'=>is_array($order['items']??null)?count($order['items']):0,
                'refunds'=>is_array($order['refunds']??null)?count($order['refunds']):0,
                'custom_fields'=>is_array($order['custom_fields']??null)?count($order['custom_fields']):0];
        };
        $changed=[];
        foreach (array_unique(array_merge(array_keys($current),array_keys($incoming))) as $key) {
            if (!array_key_exists($key,$current) || !array_key_exists($key,$incoming) || Protocol::fingerprint(['value'=>$current[$key]])!==Protocol::fingerprint(['value'=>$incoming[$key]])) { $changed[]=$key; }
        }
        sort($changed);
        return ['classification'=>self::equivalentAugmentation($current,$incoming)?'schema_augmentation_only':'review_required',
            'current'=>$summarize($current),'incoming'=>$summarize($incoming),'changed_fields'=>$changed,
            'native_check_required'=>true];
    }
}
