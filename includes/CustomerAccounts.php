<?php
namespace WD29\Bridge;
final class CustomerAccounts
{
    public static function apply(Engine $engine,array $data): int
    {
        if (!preg_match('/^woo:customer:[1-9][0-9]*$/D',$data['key']) || !empty($data['guest']) || !empty($data['deleted'])) { throw new \RuntimeException('Only active registered source customers can create accounts.'); }
        $link=$engine->sql('SELECT native_id FROM {b}account_links WHERE record_key=?',[$data['key']])[0]??null;
        $id=(int)($link['native_id']??0); $email=$data['email'];
        if (!\Validate::isEmail($email) || !\Validate::isName($data['first_name']) || !\Validate::isName($data['last_name']) || $data['first_name']==='' || $data['last_name']==='') { throw new \RuntimeException('Native account requires valid names and email.'); }
        $collision=$engine->sql('SELECT id_customer FROM `'._DB_PREFIX_.'customer` WHERE email=? AND deleted=0 AND is_guest=0 AND id_customer<>? LIMIT 1',[$email,$id]);
        if ($collision) { throw new \RuntimeException('Customer email already belongs to another account; manual identity review required.'); }
        $customer=$id?new \Customer($id):new \Customer();
        if ($id && (!\Validate::isLoadedObject($customer) || $customer->deleted)) { throw new \RuntimeException('Mapped native account no longer exists.'); }
        if (!$id) {
            $customer->passwd=\Tools::hash(bin2hex(random_bytes(32))); $customer->active=true; $customer->is_guest=false;
            $customer->newsletter=false; $customer->optin=false; $customer->id_default_group=(int)\Configuration::get('PS_CUSTOMER_GROUP');
            $customer->id_shop=(int)\Context::getContext()->shop->id; $customer->id_shop_group=(int)\Context::getContext()->shop->id_shop_group;
        }
        $customer->firstname=$data['first_name']; $customer->lastname=$data['last_name']; $customer->email=$email;
        $customer->company=$data['company'];
        if (!$customer->save()) { throw new \RuntimeException('Native customer account could not be saved.'); }
        foreach (['billing','shipping'] as $kind) {
            $source=$data[$kind];
            if (empty($source['address_1']) || empty($source['city']) || empty($source['country'])) { continue; }
            $country=(int)\Country::getByIso($source['country']);
            if (!$country) { throw new \RuntimeException('Customer address country is not configured.'); }
            $alias='WD29 '.$kind;
            $existing=$engine->sql('SELECT id_address FROM `'._DB_PREFIX_.'address` WHERE id_customer=? AND alias=? AND deleted=0',[(int)$customer->id,$alias]);
            if (count($existing)>1) { throw new \RuntimeException('Ambiguous source customer address.'); }
            $address=$existing?new \Address((int)$existing[0]['id_address']):new \Address();
            $address->id_customer=(int)$customer->id; $address->alias=$alias; $address->id_country=$country;
            $address->firstname=$source['first_name']??$data['first_name']; $address->lastname=$source['last_name']??$data['last_name'];
            foreach (['address_1'=>'address1','address_2'=>'address2','city'=>'city','postcode'=>'postcode','company'=>'company','phone'=>'phone'] as $from=>$to) { $address->$to=$source[$from]??''; }
            $address->id_state=0;
            if (!empty($source['state'])) {
                $states=$engine->sql('SELECT id_state FROM `'._DB_PREFIX_.'state` WHERE id_country=? AND iso_code=?',[$country,$source['state']]);
                if (!$states) { throw new \RuntimeException('Customer address state is not configured.'); } $address->id_state=(int)$states[0]['id_state'];
            }
            if (!$address->save()) { throw new \RuntimeException('Customer source address could not be saved.'); }
        }
        // Additional source addresses remain in the complete private contact directory.
        $engine->sql('INSERT INTO {b}account_links (record_key,native_id) VALUES (?,?) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)',[$data['key'],(int)$customer->id]);
        return (int)$customer->id;
    }
}
