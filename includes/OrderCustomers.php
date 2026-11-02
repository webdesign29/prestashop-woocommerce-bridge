<?php
namespace WD29\Bridge;

/** Canonical registered-customer identity; never matches or merges accounts by email. */
final class OrderCustomers
{
    public static function source(Engine $engine,int $id): ?string
    {
        if ($id<1) { return null; }
        if ($engine->adapter->site()==='woo') {
            $user=get_userdata($id);
            if (!$user || array_values((array)$user->roles)!==['customer']) { return null; }
            $origin=(string)get_user_meta($id,'_wd29_bridge_customer_origin',true);
            if ($origin!=='') { return preg_match('/^ps:customer:[1-9][0-9]*$/D',$origin)?$origin:null; }
        } else {
            $user=new \Customer($id);
            if (!\Validate::isLoadedObject($user) || $user->deleted || $user->is_guest) { return null; }
            $links=$engine->sql('SELECT record_key FROM {b}account_links WHERE native_id=? LIMIT 2',[$id]);
            if ($links) { return count($links)===1 && preg_match('/^woo:customer:[1-9][0-9]*$/D',$links[0]['record_key'])?$links[0]['record_key']:null; }
        }
        return Protocol::key($engine->adapter->site(),'customer',$id);
    }

    public static function resolve(Engine $engine,$key): int
    {
        if ($key===null || $key==='') { return 0; }
        if (!is_string($key) || !preg_match('/^(woo|ps):customer:([1-9][0-9]{0,14})$/D',$key,$parts)) { throw new \RuntimeException('Invalid registered order customer identity.'); }
        if ($parts[1]!==$engine->adapter->site()) {
            if (empty($engine->config()['native_customers'])) { return 0; }
            $links=$engine->sql('SELECT native_id FROM {b}account_links WHERE record_key=? LIMIT 2',[$key]);
            if (count($links)!==1 || (int)$links[0]['native_id']<1) { throw new \RuntimeException('Registered order customer is not linked yet; synchronize the customer contact first.'); }
            $id=(int)$links[0]['native_id'];
        } else { $id=(int)$parts[2]; }
        if (self::source($engine,$id)!==$key) { throw new \RuntimeException('Registered order customer account or origin changed; identity review required.'); }
        $owners=$engine->sql('SELECT record_key FROM {b}account_links WHERE native_id=? LIMIT 2',[$id]);
        if (count($owners)>1 || ($owners && $owners[0]['record_key']!==$key)) { throw new \RuntimeException('Registered order customer link is ambiguous.'); }
        return $id;
    }
}
