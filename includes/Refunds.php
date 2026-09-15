<?php
namespace WD29\Bridge;

/** Source-owned audit records. Never initiates a payment, restock or fiscal document. */
final class Refunds
{
    public static function validate(array $rows, string $origin): array
    {
        if (!in_array($origin,['woo','ps'],true) || count($rows)>500) { throw new \RuntimeException('Invalid refund ledger.'); }
        $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !preg_match('/^'.preg_quote($origin,'/').':refund:[1-9][0-9]*$/D',(string)($row['source_id']??'')) || isset($seen[$row['source_id']])) { throw new \RuntimeException('Invalid or duplicate refund identity.'); }
            $seen[$row['source_id']]=true;
            foreach (['amount','net','tax','shipping_net','shipping_tax'] as $field) {
                if (!array_key_exists($field,$row) || ($row[$field]!==null && (!is_scalar($row[$field]) || !is_numeric($row[$field]) || !is_finite((float)$row[$field]) || (float)$row[$field]<0 || (float)$row[$field]>1000000000000))) { throw new \RuntimeException('Invalid refund amount.'); }
            }
            if (!is_string($row['reason']??null) || strlen($row['reason'])>4000 || !is_string($row['created']??null) || strlen($row['created'])>40 || !in_array($row['payment_refunded']??null,[true,false,null],true) || !is_array($row['lines']??null) || count($row['lines'])>500) { throw new \RuntimeException('Invalid refund details.'); }
            $lineIds=[];
            foreach ($row['lines'] as $line) {
                if (!is_array($line) || !preg_match('/^[1-9][0-9]*$/D',(string)($line['source_line_id']??'')) || isset($lineIds[$line['source_line_id']])) { throw new \RuntimeException('Invalid refund line identity.'); }
                $lineIds[$line['source_line_id']]=true;
                foreach (['quantity','net','tax'] as $field) { if (!isset($line[$field]) || !is_scalar($line[$field]) || !is_numeric($line[$field]) || !is_finite((float)$line[$field]) || (float)$line[$field]<0 || (float)$line[$field]>1000000000000) { throw new \RuntimeException('Invalid refund line amount.'); } }
            }
        }
        usort($rows,function($a,$b){return strcmp($a['source_id'],$b['source_id']);});
        return array_values($rows);
    }
    public static function validateOrder(array $data): array
    {
        $origin=explode(':',(string)($data['key']??''))[0];
        $rows=self::validate($data['refunds']??[],$origin); $sum=0.0;
        foreach ($rows as $row) { if ($row['amount']!==null) { $sum+=(float)$row['amount']; } }
        if (!isset($data['total']) || !is_numeric($data['total']) || $sum>(float)$data['total']+0.02) { throw new \RuntimeException('Refund total exceeds original order total.'); }
        return $rows;
    }
    public static function export($order): array
    {
        $rows=[];
        $slips=\OrderSlip::getOrdersSlip((int)$order->id_customer,(int)$order->id);
        if (!is_array($slips)) { throw new \RuntimeException('Credit-slip source query failed.'); }
        foreach ($slips as $slip) {
            $lines=[]; $details=\OrderSlip::getOrdersSlipDetail((int)$slip['id_order_slip']);
            if (!is_array($details)) { throw new \RuntimeException('Credit-slip line query failed.'); }
            foreach ($details as $line) {
                $lines[]=['source_line_id'=>(string)$line['id_order_detail'],'quantity'=>(float)$line['product_quantity'],'net'=>(string)$line['amount_tax_excl'],
                    'tax'=>(string)max(0,(float)$line['amount_tax_incl']-(float)$line['amount_tax_excl'])];
            }
            $net=(float)$slip['total_products_tax_excl']; $gross=(float)$slip['total_products_tax_incl'];
            $shippingNet=(float)$slip['total_shipping_tax_excl']; $shippingGross=(float)$slip['total_shipping_tax_incl'];
            // Legacy partial slips may have only ambiguous amount fields. Preserve them without inventing tax or totals.
            $legacy=$net==0 && $gross==0 && $shippingNet==0 && $shippingGross==0 && ((float)$slip['amount']!=0 || (float)$slip['shipping_cost_amount']!=0);
            $row=['source_id'=>'ps:refund:'.$slip['id_order_slip'],'amount'=>$legacy?null:(string)($gross+$shippingGross),
                'net'=>$legacy?null:(string)($net+$shippingNet),'tax'=>$legacy?null:(string)max(0,$gross+$shippingGross-$net-$shippingNet),
                'shipping_net'=>$legacy?null:(string)$shippingNet,'shipping_tax'=>$legacy?null:(string)max(0,$shippingGross-$shippingNet),
                'reason'=>'','created'=>(string)$slip['date_add'],'payment_refunded'=>null,'lines'=>$lines];
            if ($legacy) { $row['legacy_amount']=(string)$slip['amount']; $row['legacy_shipping_amount']=(string)$slip['shipping_cost_amount']; $row['amount_basis']='legacy_unspecified'; }
            $rows[]=$row;
        }
        return self::validate($rows,'ps');
    }
    public static function apply($order,array $data): array
    {
        $origin=explode(':',(string)($data['key']??''))[0];
        if ($origin!=='woo') { throw new \RuntimeException('Only source WooCommerce refund records may be mirrored.'); }
        // Caller stores these records in the existing canonical order snapshot. No OrderSlip is generated.
        return self::validateOrder($data);
    }
}
