<?php
namespace WD29\Bridge;

/** The installed KerAwen/PrestaShop ledger remains authoritative. No copied spendable balance. */
final class KerawenCommerce
{
    private static function ready(Engine $e): void
    {
        if ($e->adapter->site()!=='ps' || !\Module::isEnabled('kerawen')) { throw new \RuntimeException('The authoritative KerAwen module is not available.'); }
        require_once _PS_MODULE_DIR_.'kerawen/defines.php';
        require_once _PS_MODULE_DIR_.'kerawen/api/constants.php';
        require_once _PS_MODULE_DIR_.'kerawen/classes/loyalty.php';
    }
    public static function receive(Engine $e,array $message): array
    {
        self::ready($e);
        switch ($message['op']??'') {
            case 'commerce_account': return self::account($e,$message['customer_key']??null);
            case 'commerce_catalog': return self::catalog($e,(int)($message['offset']??0));
            case 'commerce_order_link': return self::orderLink($e,$message['order_key']??null);
        }
        throw new \RuntimeException('Unknown commerce operation.');
    }
    private static function identity(Engine $e,$key): int
    {
        if (!is_string($key) || !preg_match('/^(ps|woo):customer:[1-9][0-9]{0,14}$/D',$key)) { throw new \RuntimeException('A registered, linked customer is required.'); }
        $id=OrderCustomers::resolve($e,$key);
        if (!$id) { throw new \RuntimeException('Native customer accounts must be enabled.'); }
        $customer=new \Customer($id);if (!$customer->active) { throw new \RuntimeException('Customer account is disabled.'); }
        return $id;
    }
    public static function account(Engine $e,$key): array
    {
        $id=self::identity($e,$key);$ctx=\Context::getContext();
        $plugin=\KerawenLoyalty::getPlugin();$info=$plugin instanceof \KerawenLoyaltyPrestashop?$plugin->getInfo($id):null;
        $loyalty=$info?array_intersect_key($info,array_flip(['points','value','minimal_value','discount_point_value','point_rate','convert_threshold','front_convert','module','error'])):null;
        $rows=$e->sql('SELECT cr.*,k.type kerawen_type,k.id_parent_cart_rule, l.name,(SELECT COUNT(*) FROM `'._DB_PREFIX_.'order_cart_rule` used_rule JOIN `'._DB_PREFIX_.'orders` used_order ON used_order.id_order=used_rule.id_order WHERE used_rule.id_cart_rule=cr.id_cart_rule AND used_order.id_customer=cr.id_customer) customer_uses FROM `'._DB_PREFIX_.'cart_rule` cr LEFT JOIN `'._DB_PREFIX_.'cart_rule_kerawen` k ON k.id_cart_rule=cr.id_cart_rule LEFT JOIN `'._DB_PREFIX_.'cart_rule_lang` l ON l.id_cart_rule=cr.id_cart_rule AND l.id_lang=? WHERE cr.id_customer=? ORDER BY cr.id_cart_rule LIMIT 501',[(int)$ctx->language->id,$id]);
        if (count($rows)>500) { throw new \RuntimeException('Customer has more than 500 rule records; a paginated review is required.'); }
        if ($plugin instanceof \KerawenLoyaltyPrestashop) {
            $loyaltyRules=$e->sql('SELECT DISTINCT id_cart_rule FROM `'._DB_PREFIX_.'loyalty` WHERE id_customer=? AND id_cart_rule>0',[$id]);$loyaltyIds=array_fill_keys(array_column($loyaltyRules,'id_cart_rule'),true);
            foreach ($rows as &$row) { if (isset($loyaltyIds[$row['id_cart_rule']])) { $row['kerawen_type']=_KERAWEN_CR_LOYALTY_; } } unset($row);
        }
        $rules=array_map([self::class,'rule'],$rows);
        $gate='Inklura\\CommercePro\\PrestaAuthorityGate';
        if (class_exists($gate,false)&&$gate::held()) {
            foreach ($rules as &$rule) { if ($gate::nativeRuleError((int)substr($rule['key'],13),$id)!==null) { $rule['available']=false; } } unset($rule);
        }
        return ['ok'=>true,'authority'=>'kerawen','customer_key'=>$key,'capabilities'=>['read'=>true,'reserve'=>false,'debit'=>false,'refund'=>false,'loyalty_earn'=>false],'checked_at'=>gmdate('c'),'currency'=>(string)$ctx->currency->iso_code,'loyalty'=>$loyalty,'rules'=>$rules];
    }
    public static function catalog(Engine $e,int $offset): array
    {
        if ($offset<0 || $offset>1000000) { throw new \RuntimeException('Invalid commerce page.'); }
        $rows=$e->sql('SELECT cr.*,k.type kerawen_type,k.id_parent_cart_rule,l.name FROM `'._DB_PREFIX_.'cart_rule` cr LEFT JOIN `'._DB_PREFIX_.'cart_rule_kerawen` k ON k.id_cart_rule=cr.id_cart_rule LEFT JOIN `'._DB_PREFIX_.'cart_rule_lang` l ON l.id_cart_rule=cr.id_cart_rule AND l.id_lang=? WHERE cr.id_customer=0 AND (k.type IS NULL OR k.type NOT IN (?,?,?)) ORDER BY cr.id_cart_rule LIMIT '.(int)$offset.',20',[(int)\Context::getContext()->language->id,_KERAWEN_CR_CREDIT_,_KERAWEN_CR_GIFT_CARD_,_KERAWEN_CR_LOYALTY_]);
        return ['ok'=>true,'authority'=>'kerawen','checked_at'=>gmdate('c'),'rows'=>array_map([self::class,'rule'],$rows),'next'=>count($rows)===20?$offset+20:null];
    }
    public static function rule(array $row): array
    {
        $keep=['code','name','active','date_from','date_to','quantity','quantity_per_user','partial_use','priority','minimum_amount','minimum_amount_tax','minimum_amount_currency','minimum_amount_shipping','free_shipping','reduction_percent','reduction_amount','reduction_tax','reduction_currency','reduction_product','reduction_exclude_special','gift_product','gift_product_attribute','country_restriction','carrier_restriction','group_restriction','cart_rule_restriction','product_restriction','shop_restriction'];
        $result=array_intersect_key($row,array_flip($keep));
        $types=[_KERAWEN_CR_CREDIT_=>'store_credit',_KERAWEN_CR_GIFT_CARD_=>'gift_card',_KERAWEN_CR_LOYALTY_=>'loyalty_voucher'];
        $result['key']='ps:cart_rule:'.(int)$row['id_cart_rule'];$result['kind']=$types[$row['kerawen_type']??'']??'coupon';
        $now=date('Y-m-d H:i:s');$result['available']=!empty($row['active']) && (int)($row['quantity']??0)>0 && (int)($row['quantity_per_user']??0)>(int)($row['customer_uses']??0) && (string)$row['date_from']<=$now && (string)$row['date_to']>=$now;
        $currency=new \Currency((int)($row['reduction_currency']??0));$result['currency']=\Validate::isLoadedObject($currency)?(string)$currency->iso_code:null;
        $result['customer_id']=(int)($row['id_customer']??0);$result['parent_id']=(int)($row['id_parent_cart_rule']??0);
        return $result;
    }
    public static function orderLink(Engine $e,$key): array
    {
        if (!is_string($key) || !preg_match('/^woo:order:[1-9][0-9]{0,14}$/D',$key)) { throw new \RuntimeException('Invalid mirrored order identity.'); }
        $map=$e->mapping($key);if (!$map || $map['kind']!=='order') { return ['ok'=>true,'order_key'=>$key,'state'=>'missing']; }
        $o=new \Order((int)$map['local_id']);$snapshot=json_decode($map['snapshot']??'{}',true)?:[];
        if (!\Validate::isLoadedObject($o)) { throw new \RuntimeException('Native order is unavailable.'); }
        $c=new \Customer((int)$o->id_customer);$nativeSafe=true;$nativeReview='';
        try { $e->adapter->orderConflictSnapshot((int)$o->id); } catch (\Throwable $error) { $nativeSafe=false;$nativeReview=$error->getMessage(); }
        return ['ok'=>true,'order_key'=>$key,'native_id'=>(int)$o->id,'customer_key'=>OrderCustomers::source($e,(int)$o->id_customer),
            'snapshot_customer_key'=>$snapshot['customer_key']??null,'native_customer_id'=>(int)$o->id_customer,'guest'=>(bool)$c->is_guest,
            'native_safe'=>$nativeSafe,'native_review'=>$nativeReview,'native_module'=>(string)$o->module,'invoice'=>(bool)$o->invoice_number,'valid'=>(bool)$o->valid,
            'payment_recorded'=>(float)$o->total_paid_real!=0,'native_total'=>(string)$o->total_paid_tax_incl,'source_total'=>$snapshot['total']??null,
            'native_state'=>(int)$o->current_state,'source_status'=>$snapshot['status']??null];
    }
}
