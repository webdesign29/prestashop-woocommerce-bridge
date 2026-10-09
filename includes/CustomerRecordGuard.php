<?php
namespace WD29\Bridge;

/** Read-only protection for the optional native customer copy. Never returns PII. */
final class CustomerRecordGuard
{
    public static function inspect(Engine $engine, string $key, ?array $incoming=null): array
    {
        $result=['state'=>'conflict','native_id'=>0,'reason'=>'identity_invalid'];
        $foreign=$engine->adapter->site()==='woo'?'ps':'woo';
        if (!preg_match('/^'.$foreign.':(?:customer|guest):[1-9][0-9]{0,14}$/D',$key)) { return $result; }
        try {
            $links=$engine->sql('SELECT native_id FROM {b}account_links WHERE record_key=? LIMIT 2',[$key]);
            $result['native_id']=(int)($links[0]['native_id']??0);
            if (empty($engine->config()['native_customers'])) { return array_merge($result,['state'=>'directory_only','reason'=>'native_customers_disabled']); }
            if (strpos($key,':guest:')!==false) { return array_merge($result,['state'=>$links?'conflict':'directory_only','reason'=>$links?'account_link_invalid':'guest_contact_only']); }
            if (!$links) { return array_merge($result,['state'=>'not_linked','reason'=>'native_account_not_created']); }
            if (count($links)!==1 || $result['native_id']<1) { return array_merge($result,['reason'=>'account_link_invalid']); }
            $id=$result['native_id'];
            $owners=$engine->sql('SELECT record_key FROM {b}account_links WHERE native_id=? LIMIT 2',[$id]);
            if (count($owners)!==1 || $owners[0]['record_key']!==$key) { return array_merge($result,['reason'=>'account_link_ambiguous']); }
            $customer=new \Customer($id);
            if (!\Validate::isLoadedObject($customer) || $customer->deleted) { return array_merge($result,['reason'=>'native_account_missing']); }
            if ($customer->is_guest) { return array_merge($result,['reason'=>'native_account_origin_changed']); }
            $shop=\Context::getContext()->shop;
            if ((int)$customer->id_shop!==(int)$shop->id || (int)$customer->id_shop_group!==(int)$shop->id_shop_group) { return array_merge($result,['reason'=>'native_account_shop_changed']); }
            $rows=$engine->sql('SELECT data FROM {b}contacts WHERE record_key=? LIMIT 2',[$key]);
            $data=count($rows)===1?json_decode($rows[0]['data'],true):null;
            if (!is_array($data) || ($data['key']??'')!==$key || !empty($data['guest']) || !empty($data['deleted']) || !is_array($data['billing']??null) || !is_array($data['shipping']??null)) { return array_merge($result,['reason'=>'contact_baseline_missing']); }
            if ($incoming!==null && (($incoming['key']??'')!==$key || !is_array($incoming['billing']??null) || !is_array($incoming['shipping']??null))) { return array_merge($result,['reason'=>'incoming_contact_invalid']); }
            foreach (['first_name'=>'firstname','last_name'=>'lastname','email'=>'email','company'=>'company'] as $source=>$native) {
                if (!is_string($data[$source]??null) || (string)$customer->$native!==$data[$source]) { return array_merge($result,['reason'=>'native_profile_changed']); }
            }
            foreach (['billing','shipping'] as $kind) {
                $source=PrestaAdapter::nativeAddress($data[$kind]);
                $newAddress=false;
                // Only complete source addresses are written by CustomerAccounts::apply.
                if (empty($source['address_1']) || empty($source['city']) || empty($source['country'])) {
                    if ($incoming===null) { continue; }
                    $source=PrestaAdapter::nativeAddress($incoming[$kind]); $newAddress=true;
                    if (empty($source['address_1']) || empty($source['city']) || empty($source['country'])) { continue; }
                }
                $profile=$newAddress?$incoming:$data;
                $addresses=$engine->sql('SELECT id_address FROM `'._DB_PREFIX_.'address` WHERE id_customer=? AND alias=? AND deleted=0 LIMIT 2',[$id,'WD29 '.$kind]);
                if (!$addresses && $newAddress) { continue; }
                if (count($addresses)!==1) { return array_merge($result,['reason'=>count($addresses)>1?'native_address_ambiguous':'native_address_missing']); }
                $address=new \Address((int)$addresses[0]['id_address']);
                if (!\Validate::isLoadedObject($address) || $address->deleted || (int)$address->id_customer!==$id || $address->alias!=='WD29 '.$kind) { return array_merge($result,['reason'=>'native_address_origin_changed']); }
                $country=(int)\Country::getByIso($source['country']);
                if (!$country || (int)$address->id_country!==$country) { return array_merge($result,['reason'=>'native_address_changed']); }
                $state=0;
                if (!empty($source['state'])) {
                    $states=$engine->sql('SELECT id_state FROM `'._DB_PREFIX_.'state` WHERE id_country=? AND iso_code=? LIMIT 2',[$country,$source['state']]);
                    if (count($states)!==1) { return array_merge($result,['reason'=>'native_address_state_unavailable']); }
                    $state=(int)$states[0]['id_state'];
                }
                if ((int)$address->id_state!==$state) { return array_merge($result,['reason'=>'native_address_changed']); }
                foreach (['first_name'=>'firstname','last_name'=>'lastname','address_1'=>'address1','address_2'=>'address2','city'=>'city','postcode'=>'postcode','company'=>'company','phone'=>'phone'] as $field=>$native) {
                    $expected=$source[$field]??(in_array($field,['first_name','last_name'],true)?($profile[$field]??''):'');
                    if ((string)$address->$native!==(string)$expected) { return array_merge($result,['reason'=>'native_address_changed']); }
                }
            }
            return array_merge($result,['state'=>'linked','reason'=>'native_account_unchanged']);
        } catch (\Throwable $error) { return array_merge($result,['state'=>'conflict','reason'=>'native_account_unavailable']); }
    }
}
