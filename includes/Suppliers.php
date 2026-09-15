<?php
namespace WD29\Bridge;

/** Additional supplier purchasing records, independent of the primary supplier. */
final class Suppliers
{
    public static function validate(array $rows): array
    {
        if (count($rows)>100) { throw new \RuntimeException('Too many supplier records.'); }
        $seen=[]; $out=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['name']??null) || trim($row['name'])!==$row['name'] || $row['name']==='' || mb_strlen($row['name'])>64 || preg_match('/[<>;={}\x00-\x1f]/u',$row['name'])) { throw new \RuntimeException('Invalid supplier name.'); }
            if (isset($seen[$row['name']])) { throw new \RuntimeException('Duplicate supplier name.'); } $seen[$row['name']]=true;
            if (!is_string($row['reference']??null) || mb_strlen($row['reference'])>64 || preg_match('/[<>;={}\x00-\x1f]/u',$row['reference'])) { throw new \RuntimeException('Invalid supplier reference.'); }
            $price=$row['purchase_price_net']??null;
            if (!is_scalar($price) || !is_numeric($price) || !is_finite((float)$price) || (float)$price<0 || (float)$price>1000000000000) { throw new \RuntimeException('Invalid supplier purchasing price.'); }
            if (!is_string($row['currency']??null) || !preg_match('/^[A-Z]{3}$/D',$row['currency'])) { throw new \RuntimeException('Supplier currency must be an ISO code.'); }
            $out[]=['name'=>$row['name'],'reference'=>$row['reference'],'purchase_price_net'=>number_format((float)$price,6,'.',''),'currency'=>$row['currency']];
        }
        usort($out,function($a,$b){return strcmp($a['name'],$b['name']);});
        return $out;
    }
    private static function identity($product): array
    {
        if ($product instanceof \Combination) { $pid=(int)$product->id_product; $aid=(int)$product->id; }
        elseif ($product instanceof \Product) { $pid=(int)$product->id; $aid=0; }
        else { throw new \RuntimeException('Native product or combination required for suppliers.'); }
        if ($pid<1 || ($product instanceof \Combination && $aid<1)) { throw new \RuntimeException('Save product before applying suppliers.'); }
        return [$pid,$aid];
    }
    public static function export($product): array
    {
        [$pid,$aid]=self::identity($product);
        $records=\Db::getInstance()->executeS('SELECT ps.product_supplier_reference,ps.product_supplier_price_te,ps.id_currency,s.name FROM `'._DB_PREFIX_.'product_supplier` ps LEFT JOIN `'._DB_PREFIX_.'supplier` s ON s.id_supplier=ps.id_supplier WHERE ps.id_product='.$pid.' AND ps.id_product_attribute='.$aid);
        if (!is_array($records)) { throw new \RuntimeException('Supplier export query failed.'); }
        $rows=[];
        foreach ($records as $record) {
            $currency=new \Currency((int)$record['id_currency']);
            if (!\Validate::isLoadedObject($currency)) { throw new \RuntimeException('Source supplier currency is not configured.'); }
            $rows[]=['name'=>(string)$record['name'],'reference'=>(string)$record['product_supplier_reference'],
                'purchase_price_net'=>(string)$record['product_supplier_price_te'],'currency'=>(string)$currency->iso_code];
        }
        return self::validate($rows);
    }
    public static function apply($product,array $rows): void
    {
        [$pid,$aid]=self::identity($product); $rows=self::validate($rows); $plans=[];
        // Validate all currencies and identities before the first write. Caller supplies transaction boundary.
        foreach ($rows as $row) {
            if (!\Validate::isCatalogName($row['name']) || !\Validate::isReference($row['reference'])) { throw new \RuntimeException('Supplier fields are incompatible with PrestaShop.'); }
            $currency=(int)\Currency::getIdByIsoCode($row['currency']);
            if (!$currency) { throw new \RuntimeException('Destination supplier currency is not configured.'); }
            $matches=\Db::getInstance()->executeS('SELECT id_supplier,name FROM `'._DB_PREFIX_.'supplier` WHERE name="'.pSQL($row['name']).'"');
            if (!is_array($matches) || count($matches)>1 || (count($matches)===1 && $matches[0]['name']!==$row['name'])) { throw new \RuntimeException('Ambiguous supplier name; review destination identity.'); }
            $plans[]=[$row,$currency,$matches?(int)$matches[0]['id_supplier']:0];
        }
        foreach ($plans as [$row,$currency,$sid]) {
            if (!$sid) { $supplier=new \Supplier(); $supplier->name=$row['name']; $supplier->active=true; if (!$supplier->add()) { throw new \RuntimeException('Supplier could not be created.'); } $sid=(int)$supplier->id; }
            $id=(int)\ProductSupplier::getIdByProductAndSupplier($pid,$aid,$sid);
            $record=$id?new \ProductSupplier($id):new \ProductSupplier();
            $record->id_product=$pid; $record->id_product_attribute=$aid; $record->id_supplier=$sid;
            $record->product_supplier_reference=$row['reference']; $record->product_supplier_price_te=$row['purchase_price_net']; $record->id_currency=$currency;
            if (!$record->save()) { throw new \RuntimeException('Supplier product record could not be saved.'); }
        }
    }
}
