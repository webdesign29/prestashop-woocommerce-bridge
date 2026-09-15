<?php
namespace WD29\Bridge;

final class PrestaAdapter
{
    public $engine;
    public function site(): string { return 'ps'; }
    public function prefix(): string { return _DB_PREFIX_; }
    public function config(): array { return json_decode((string) \Configuration::get('WD29_BRIDGE_CONFIG'), true) ?: ['mode' => 'disabled']; }
    public function workerStatus(?string $state=null): array
    {
        if ($state!==null) { \Configuration::updateValue('WD29_BRIDGE_WORKER',json_encode(['state'=>$state,'at'=>gmdate('c')])); }
        return json_decode((string)\Configuration::get('WD29_BRIDGE_WORKER'),true)?:[];
    }
    public function notice(string $text): void { \Configuration::updateValue('WD29_BRIDGE_NOTICE', strip_tags($text)); }
    public function scanOffset(string $kind): int { $value=json_decode((string)\Configuration::get('WD29_BRIDGE_SCAN'),true)?:[]; return (int)($value[$kind]??0); }
    public function saveScanOffset(string $kind,int $offset): void { $value=json_decode((string)\Configuration::get('WD29_BRIDGE_SCAN'),true)?:[]; $value[$kind]=$offset; \Configuration::updateValue('WD29_BRIDGE_SCAN',json_encode($value)); }
    public function sql(string $query, array $args = [])
    {
        $parts = explode('?', $query);
        if (count($parts) !== count($args) + 1) { throw new \LogicException('SQL parameter mismatch.'); }
        $query = array_shift($parts);
        foreach ($args as $i => $value) { $query .= ($value === null ? 'NULL' : "'" . pSQL((string) $value, true) . "'") . $parts[$i]; }
        $db = \Db::getInstance();
        $result = preg_match('/^\s*SELECT/i', $query) ? $db->executeS($query, true, false) : $db->execute($query);
        if ($result === false) { throw new \RuntimeException('Bridge database operation failed.'); }
        return $result;
    }
    private function lang(): int { return (int) \Configuration::get('PS_LANG_DEFAULT'); }
    private function shop(): int { return (int) \Context::getContext()->shop->id; }
    private function languages(string $value): array {
        $values = []; foreach (\Language::getLanguages(false) as $lang) { $values[(int) $lang['id_lang']] = $value; } return $values;
    }
    public function ids(string $kind, int $offset, int $limit): array
    {
        if ($kind==='customer') {
            $rows=$this->sql('SELECT c.id_customer FROM `'._DB_PREFIX_."customer` c WHERE c.deleted=0 AND NOT EXISTS (SELECT 1 FROM `"._DB_PREFIX_."orders` o WHERE o.id_customer=c.id_customer AND o.module='wd29woobridge') ORDER BY c.id_customer LIMIT ".(int)$offset.','.(int)$limit);
            return array_map(function($row){return $this->engine->contactId(Protocol::key('ps','customer',(int)$row['id_customer']));},$rows);
        }
        $table = $kind === 'order' ? 'orders' : 'product';
        $field = $kind === 'order' ? 'id_order' : 'id_product';
        return array_map('intval', array_column($this->sql('SELECT ' . $field . ' FROM `' . _DB_PREFIX_ . $table . '` ORDER BY ' . $field . ' LIMIT ' . (int) $offset . ',' . (int) $limit), $field));
    }
    private function stock(int $pid, int $aid, string $key): array
    {
        $unknown = $this->sql('SELECT quantity,stock_initialized FROM ' . _DB_PREFIX_ . 'wd29_bridge_map WHERE record_key=?', [$key])[0] ?? null;
        $importedUnknown = $unknown && $unknown['stock_initialized'] && $unknown['quantity'] === null;
        $quantity = (int) \StockAvailable::getQuantityAvailableByProduct($pid, $aid, $this->shop());
        return ['key' => $key, 'quantity' => $importedUnknown ? null : $quantity,
            'status' => $quantity > 0 || \Product::isAvailableWhenOutOfStock(\StockAvailable::outOfStock($pid, $this->shop())) ? 'instock' : 'outofstock',
            'backorders' => \Product::isAvailableWhenOutOfStock(\StockAvailable::outOfStock($pid, $this->shop()))];
    }
    public function product(int $id): array
    {
        $p = new \Product($id, false, $this->lang(), $this->shop());
        if (!\Validate::isLoadedObject($p)) { throw new \RuntimeException('Product no longer exists.'); }
        if (\Pack::isPack($id)) { throw new \RuntimeException('Product packs need a component mapping.'); }
        $key = $this->engine->identity('product', $id);
        $taxAddress = new \Address(); $taxAddress->id_country = (int)\Configuration::get('PS_COUNTRY_DEFAULT');
        $rate = (float) $p->getTaxesRate($taxAddress);
        if ((float)$p->ecotax !== 0.0) { throw new \RuntimeException('Ecotax needs an explicit mapping.'); }
        $data = ['key' => $key, 'type' => $p->hasAttributes() ? 'variable' : 'simple', 'name' => $p->name,
            'sku' => (string) $p->reference, 'description' => $p->description, 'short_description' => $p->description_short,
            'status' => $p->active ? 'publish' : 'draft', 'virtual' => (bool) $p->is_virtual,
            'currency' => (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code,
            'prices' => ['regular' => number_format((float) $p->price, 6, '.', ''), 'sale' => null, 'tax_rate' => $rate, 'basis' => 'net'],
            'weight_kg' => (string) $p->weight, 'categories' => [], 'images' => [], 'attributes' => [], 'variants' => [], 'inventory' => []];
        if (strtolower((string) \Configuration::get('PS_WEIGHT_UNIT')) !== 'kg') { throw new \RuntimeException('PrestaShop weight unit must be kg or explicitly mapped.'); }
        foreach ($p->getCategories() as $categoryId) {
            $c = new \Category((int) $categoryId, $this->lang()); $path = []; $seen = [];
            while ($c->id && !$c->is_root_category && !isset($seen[$c->id])) {
                $seen[$c->id] = true;
                if ((int) $c->id !== (int) \Configuration::get('PS_HOME_CATEGORY')) { array_unshift($path, $c->name); }
                $c = new \Category((int) $c->id_parent, $this->lang());
            }
            if ($path) { $data['categories'][] = $path; }
        }
        foreach ($p->getImages($this->lang()) as $row) {
            $url = \Context::getContext()->link->getImageLink($p->link_rewrite, $id . '-' . $row['id_image']);
            $data['images'][] = preg_replace('/^http:/', 'https:', $url);
        }
        $groups = []; $variants = [];
        foreach ($p->getAttributeCombinations($this->lang()) as $row) {
            $aid = (int) $row['id_product_attribute'];
            $groups[$row['group_name']][$row['attribute_name']] = $row['attribute_name'];
            if (!isset($variants[$aid])) {
                $vkey = $this->engine->identity('variant', $aid);
                $variants[$aid] = ['key' => $vkey, 'sku' => (string) $row['reference'], 'attributes' => [],
                    'prices' => ['regular' => number_format((float) $p->price + (float) $row['price'], 6, '.', ''), 'sale' => null, 'tax_rate' => $rate, 'basis' => 'net'],
                    'weight_kg' => (string) ((float) $p->weight + (float) $row['weight']), 'status' => $p->active ? 'publish' : 'draft'];
                $data['inventory'][] = $this->stock($id, $aid, $vkey);
            }
            $variants[$aid]['attributes'][$row['group_name']] = $row['attribute_name'];
        }
        foreach ($groups as $name => $options) { $data['attributes'][] = ['name' => $name, 'options' => array_values($options), 'variation' => true]; }
        $data['variants'] = array_values($variants);
        $anon = clone \Context::getContext(); $anon->customer = new \Customer(); $anon->cart = new \Cart();
        $anon->currency = new \Currency((int)\Configuration::get('PS_CURRENCY_DEFAULT'));
        $anon->country = new \Country((int)\Configuration::get('PS_COUNTRY_DEFAULT'));
        foreach ($data['variants'] as &$variant) {
            $aid=(int)$this->engine->mapping($variant['key'])['local_id']; $specific=null;
            $price=\Product::getPriceStatic($id,false,$aid,6,null,false,true,1,false,0,0,null,$specific,false,false,$anon,false);
            if ((float)$price < (float)$variant['prices']['regular'] - 0.000001) { $variant['prices']['sale']=number_format($price,6,'.',''); }
        }
        unset($variant);
        if (!$variants) {
            $specific=null;
            $price=\Product::getPriceStatic($id,false,false,6,null,false,true,1,false,0,0,null,$specific,false,false,$anon,false);
            if ((float)$price < (float)$data['prices']['regular'] - 0.000001) { $data['prices']['sale']=number_format($price,6,'.',''); }
        }
        // The parent quantity in PrestaShop is the sum of combinations, not a separate stock pool.
        $data['inventory'][] = $variants ? ['key' => $key, 'quantity' => null, 'status' => 'instock', 'backorders' => false] : $this->stock($id, 0, $key);
        $manufacturer = $p->id_manufacturer ? new \Manufacturer((int)$p->id_manufacturer) : null;
        $data['brands'] = $manufacturer && $manufacturer->id ? [$manufacturer->name] : [];
        $tags = \Tag::getProductTags($id); $data['tags'] = $tags[$this->lang()] ?? [];
        sort($data['tags']);
        return $data;
    }
    private function prices(array $prices): array
    {
        if ($prices['basis'] === 'display') {
            $rateValue = $this->config()['display_tax_rate'] ?? '';
            if ($rateValue === '') { throw new \RuntimeException('Confirm the tax rate for source display prices first.'); }
            $rate = (float) $rateValue;
            $factor = ($this->config()['display_basis'] ?? 'gross') === 'gross' ? 1 + $rate / 100 : 1;
        } else { $rate = (float) $prices['tax_rate']; $factor = 1; }
        $group = 0;
        if ($rate > 0) {
            $explicit = (int) ($this->config()['tax_rules'][(string)$rate] ?? 0);
            $rows = $this->sql('SELECT DISTINCT r.id_tax_rules_group FROM `' . _DB_PREFIX_ . 'tax_rule` r JOIN `' . _DB_PREFIX_ . 'tax` t ON t.id_tax=r.id_tax JOIN `' . _DB_PREFIX_ . 'tax_rules_group` g ON g.id_tax_rules_group=r.id_tax_rules_group WHERE g.active=1 AND g.deleted=0 AND r.id_country=? AND ABS(t.rate-?)<0.00001' . ($explicit ? ' AND r.id_tax_rules_group=' . $explicit : ''), [(int) \Configuration::get('PS_COUNTRY_DEFAULT'), $rate]);
            if (count($rows) !== 1) { throw new \RuntimeException('Tax rate has no unique PrestaShop tax-rule mapping.'); }
            $group = (int) $rows[0]['id_tax_rules_group'];
        }
        if ($prices['regular'] === null) { throw new \RuntimeException('Product has no regular price.'); }
        return ['regular' => number_format((float) $prices['regular'] / $factor,6,'.',''), 'sale' => $prices['sale'] === null ? null : number_format((float) $prices['sale'] / $factor,6,'.',''), 'group' => $group];
    }
    private function category(array $path): int
    {
        $parent = (int) \Configuration::get('PS_HOME_CATEGORY');
        foreach ($path as $name) {
            $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rows = $this->sql('SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c JOIN `' . _DB_PREFIX_ . 'category_lang` l ON c.id_category=l.id_category WHERE c.id_parent=? AND l.id_lang=? AND l.id_shop=? AND l.name=?', [$parent, $this->lang(), $this->shop(), $name]);
            if ($rows) { $parent = (int) $rows[0]['id_category']; continue; }
            $c = new \Category(); $c->name = $this->languages($name); $c->link_rewrite = $this->languages(\Tools::link_rewrite($name) ?: 'category');
            $c->id_parent = $parent; $c->active = true; if (!$c->add()) { throw new \RuntimeException('Category creation failed.'); }
            $parent = (int) $c->id;
        }
        return $parent;
    }
    private function attribute(string $groupName, string $name): int
    {
        $groups = $this->sql('SELECT id_attribute_group FROM `' . _DB_PREFIX_ . 'attribute_group_lang` WHERE id_lang=? AND name=?', [$this->lang(), $groupName]);
        if ($groups) { $gid = (int) $groups[0]['id_attribute_group']; }
        else {
            $g = new \AttributeGroup(); $g->name = $this->languages($groupName); $g->public_name = $g->name;
            $g->group_type = 'select'; $g->is_color_group = false;
            if (!$g->add()) { throw new \RuntimeException('Attribute group creation failed.'); } $gid = (int) $g->id;
        }
        $rows = $this->sql('SELECT a.id_attribute FROM `' . _DB_PREFIX_ . 'attribute` a JOIN `' . _DB_PREFIX_ . 'attribute_lang` l ON l.id_attribute=a.id_attribute WHERE a.id_attribute_group=? AND l.id_lang=? AND l.name=?', [$gid, $this->lang(), $name]);
        if ($rows) { return (int) $rows[0]['id_attribute']; }
        $a = new \ProductAttribute(); $a->id_attribute_group = $gid; $a->name = $this->languages($name);
        if (!$a->add()) { throw new \RuntimeException('Attribute creation failed.'); } return (int) $a->id;
    }
    private function initializeStock(int $pid, int $aid, string $key, array $inventories): void
    {
        foreach ($inventories as $inventory) {
            if ($inventory['key'] !== $key) { continue; }
            $qty = Protocol::quantity($inventory['quantity']);
            if ($qty !== null) { \StockAvailable::setQuantity($pid, $aid, $qty, $this->shop(), false); if ($aid === 0) { \StockAvailable::setProductOutOfStock($pid, !empty($inventory['backorders']) ? 1 : 0, $this->shop()); } }
            elseif ($aid === 0) { \StockAvailable::setProductOutOfStock($pid, $inventory['status'] === 'instock' ? 1 : 0, $this->shop()); }
            $this->engine->sql('UPDATE {b}map SET quantity=?,stock_initialized=1 WHERE record_key=?', [$qty, $key]);
            return;
        }
    }
    public function applyProduct(array $data, ?array $map): int
    {
        if ($data['currency'] !== (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code) { throw new \RuntimeException('Currency mismatch.'); }
        // WooCommerce variable parents have no native regular price; combinations carry it.
        if ($data['type'] === 'variable' && $data['prices']['regular'] === null) {
            $base = null;
            foreach ($data['variants'] as $row) {
                $candidate = $this->prices($row['prices']);
                if ($base === null || (float)$candidate['regular'] < $base) {
                    $base = (float)$candidate['regular']; $data['prices'] = $row['prices']; $data['prices']['sale'] = null;
                }
            }
        }
        $prices = $this->prices($data['prices']);
        if (!in_array($data['type'], ['simple', 'variable'], true)) { throw new \RuntimeException('Unsupported product type.'); }
        // A shared WooCommerce parent stock cannot be split among combinations without an inventory decision.
        if ($data['type'] === 'variable') {
            $stockModes = [];
            foreach ($data['inventory'] as $item) {
                if ($item['key'] === $data['key']) {
                    if ($item['quantity'] !== null) { throw new \RuntimeException('Parent-managed variant stock needs allocation to combinations.'); }
                    continue;
                }
                $stockModes[] = $item['quantity'] === null ? 'unknown:' . $item['status'] : 'tracked:' . (int)!empty($item['backorders']);
            }
            if (count(array_unique($stockModes)) > 1) {
                throw new \RuntimeException('Mixed combination stock modes cannot share one PrestaShop backorder policy; reconcile quantities first.');
            }
        }
        $p = $map ? new \Product((int) $map['local_id']) : new \Product();
        if ($map && !\Validate::isLoadedObject($p)) { throw new \RuntimeException('Mapped product no longer exists.'); }
        if ($map && $p->hasAttributes() && $data['type'] === 'simple') { throw new \RuntimeException('Removing combinations needs review.'); }
        if (isset($data['brands'])) {
            if (count($data['brands']) > 1) { throw new \RuntimeException('PrestaShop supports one manufacturer per product; choose a brand mapping.'); }
            $p->id_manufacturer = 0;
            if ($data['brands']) {
                $name = html_entity_decode($data['brands'][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $mid = (int)\Manufacturer::getIdByName($name);
                if (!$mid) { $m = new \Manufacturer(); $m->name = $name; $m->active = true; if (!$m->add()) { throw new \RuntimeException('Manufacturer creation failed.'); } $mid = (int)$m->id; }
                $p->id_manufacturer = $mid;
            }
        }
        $p->name = $this->languages(strip_tags(html_entity_decode($data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $p->link_rewrite = $this->languages(\Tools::link_rewrite($data['name']) ?: 'product');
        $p->description = $this->languages(\Tools::purifyHTML($data['description']));
        $p->description_short = $this->languages(\Tools::purifyHTML($data['short_description']));
        $p->reference = $data['sku']; $p->price = $prices['regular']; $p->id_tax_rules_group = $prices['group'];
        $p->active = $data['status'] === 'publish'; $p->is_virtual = (bool) $data['virtual']; $p->weight = (float) $data['weight_kg'];
        $p->available_for_order = true; $p->show_price = true;
        $categories = [];
        foreach ($data['categories'] as $path) { $categories[] = $this->category($path); }
        if (!$categories) { $categories[] = (int) \Configuration::get('PS_HOME_CATEGORY'); }
        $p->id_category_default = $categories[0];
        if (!$p->save()) { throw new \RuntimeException('Product save failed.'); }
        $p->updateCategories($categories);
        if (isset($data['tags'])) {
            // Synchronize the shop's default language; other language tags stay untouched.
            $this->sql('DELETE FROM `' . _DB_PREFIX_ . 'product_tag` WHERE id_product=? AND id_lang=?', [(int)$p->id,$this->lang()]);
            $tags = array_map(function ($tag) { return html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }, $data['tags']);
            if ($tags && !\Tag::addTags($this->lang(), (int)$p->id, $tags)) { throw new \RuntimeException('Product tag mapping failed.'); }
        }
        $this->engine->bind($data['key'], 'product', (int) $p->id);
        if (!$map) { $this->initializeStock((int) $p->id, 0, $data['key'], $data['inventory']); }
        foreach ($data['variants'] as $index => $row) {
            $vm = $this->engine->mapping($row['key']);
            $v = $vm ? new \Combination((int) $vm['local_id']) : new \Combination();
            if ($vm && (int) $v->id_product !== (int) $p->id) { throw new \RuntimeException('Combination parent mismatch.'); }
            $vp = $this->prices($row['prices']);
            if ($vp['group'] !== $prices['group']) { throw new \RuntimeException('All combinations must share the parent tax rule.'); }
            $v->id_product = (int) $p->id; $v->reference = $row['sku']; $v->price = number_format((float)$vp['regular'] - (float)$prices['regular'],6,'.','');
            $v->weight = (float) $row['weight_kg'] - (float) $p->weight; $v->minimal_quantity = 1;
            if (!$vm) { $v->default_on = $index === 0 ? 1 : null; }
            if (!$v->save()) { throw new \RuntimeException('Combination save failed.'); }
            $attributes = []; foreach ($row['attributes'] as $group => $name) { $attributes[] = $this->attribute($group, $name); }
            $v->setAttributes($attributes);
            $this->engine->bind($row['key'], 'variant', (int) $v->id);
            if (!$vm) { $this->initializeStock((int) $p->id, (int) $v->id, $row['key'], $data['inventory']); }
        }
        if (!$map && $data['type'] === 'variable') {
            foreach ($data['inventory'] as $item) {
                if ($item['key'] === $data['key']) { continue; }
                $allow = $item['quantity'] === null ? $item['status'] === 'instock' : !empty($item['backorders']);
                \StockAvailable::setProductOutOfStock((int)$p->id, $allow ? 1 : 0, $this->shop());
                break;
            }
        }
        \Product::updateDefaultAttribute((int) $p->id);
        $meta = $map && $map['snapshot'] ? json_decode($map['snapshot'], true) : [];
        $priceRows = array_merge([['key' => $data['key'], 'prices' => $data['prices']]], $data['variants']);
        foreach ($priceRows as $row) {
            $targetPrices = $this->prices($row['prices']);
            $specificId = (int) ($meta['specific_prices'][$row['key']] ?? 0);
            if ($targetPrices['sale'] === null && !$specificId) { continue; }
            $specific = $specificId ? new \SpecificPrice($specificId) : new \SpecificPrice();
            $variantMap = $row['key'] === $data['key'] ? null : $this->engine->mapping($row['key']);
            $specific->id_product = (int) $p->id; $specific->id_product_attribute = $variantMap ? (int) $variantMap['local_id'] : 0;
            $specific->id_shop = $this->shop(); $specific->id_shop_group = 0; $specific->id_currency = 0;
            $specific->id_country = 0; $specific->id_group = 0; $specific->id_customer = 0; $specific->id_cart = 0;
            $specific->price = -1; $specific->from_quantity = 1;
            $specific->reduction = $targetPrices['sale'] === null ? 0 : number_format(max(0, (float)$targetPrices['regular'] - (float)$targetPrices['sale']),6,'.','');
            $specific->reduction_type = 'amount'; $specific->reduction_tax = 0;
            $specific->from = '0000-00-00 00:00:00'; $specific->to = '0000-00-00 00:00:00';
            if (!$specific->save()) { throw new \RuntimeException('Sale price could not be stored.'); }
            $meta['specific_prices'][$row['key']] = (int) $specific->id;
        }
        foreach ($data['images'] as $index => $url) {
            $urlHash = hash('sha256', $url);
            if (!empty($meta['images'][$urlHash]) && \Validate::isLoadedObject(new \Image((int) $meta['images'][$urlHash]))) { continue; }
            $bytes = Protocol::imageBytes($url, $this->config()['peer']);
            $image = new \Image(); $image->id_product = (int) $p->id;
            $image->position = (int) \Image::getHighestPosition((int) $p->id) + 1;
            $image->cover = $index === 0 && !\Image::getCover((int) $p->id) ? 1 : null;
            if (!$image->add()) { throw new \RuntimeException('Image record creation failed.'); }
            $tmp = tempnam(_PS_TMP_IMG_DIR_, 'wd29');
            try {
                if (!$tmp || file_put_contents($tmp, $bytes) === false) { throw new \RuntimeException('Image staging failed.'); }
                $path = $image->getPathForCreation();
                if (!\ImageManager::resize($tmp, $path . '.jpg')) { throw new \RuntimeException('Image conversion failed.'); }
                foreach (\ImageType::getImagesTypes('products') as $type) {
                    if (!\ImageManager::resize($tmp, $path . '-' . $type['name'] . '.jpg', (int) $type['width'], (int) $type['height'])) { throw new \RuntimeException('Image thumbnail generation failed.'); }
                }
                $meta['images'][$urlHash] = (int) $image->id;
            } finally { if ($tmp && is_file($tmp)) { unlink($tmp); } }
        }
        $this->engine->sql('UPDATE {b}map SET snapshot=? WHERE record_key=?', [Protocol::encode($meta), $data['key']]);
        return (int) $p->id;
    }
    public function stockDelta(array $map, int $delta): void
    {
        $aid = $map['kind'] === 'variant' ? (int) $map['local_id'] : 0;
        $pid = $aid ? (int) (new \Combination($aid))->id_product : (int) $map['local_id'];
        if (!$pid) { throw new \RuntimeException('Stock product no longer exists.'); }
        \StockAvailable::updateQuantity($pid, $aid, $delta, $this->shop(), true);
    }
    public function stockQuantity(array $map): ?int
    {
        if ($map['stock_initialized'] && $map['quantity'] === null) { return null; }
        $aid = $map['kind'] === 'variant' ? (int) $map['local_id'] : 0;
        $pid = $aid ? (int) (new \Combination($aid))->id_product : (int) $map['local_id'];
        if (!$aid && (new \Product($pid))->hasAttributes()) { return null; }
        $rows = $this->sql('SELECT quantity FROM `' . _DB_PREFIX_ . 'stock_available` WHERE id_product=? AND id_product_attribute=? AND id_shop=?', [$pid, $aid, $this->shop()]);
        return $rows ? (int) $rows[0]['quantity'] : null;
    }
    public function stockSet(array $map, ?int $quantity): void
    {
        $aid = $map['kind'] === 'variant' ? (int) $map['local_id'] : 0;
        $pid = $aid ? (int) (new \Combination($aid))->id_product : (int) $map['local_id'];
        if ($quantity !== null) { \StockAvailable::setQuantity($pid, $aid, $quantity, $this->shop(), false); }
    }
    private function address(int $id, string $email): array
    {
        $a = new \Address($id);
        return ['first_name' => $a->firstname, 'last_name' => $a->lastname, 'company' => $a->company,
            'address_1' => $a->address1, 'address_2' => $a->address2, 'city' => $a->city, 'postcode' => $a->postcode,
            'country' => \Country::getIsoById((int) $a->id_country), 'state' => $a->id_state ? (new \State((int) $a->id_state))->iso_code : '',
            'email' => $email, 'phone' => $a->phone_mobile ?: $a->phone];
    }
    public function contactProfile(string $kind, int $id): array
    {
        $d=['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','company'=>'','billing'=>[],'shipping'=>[],'guest'=>false,'deleted'=>false];
        $c=new \Customer($id);
        if (!\Validate::isLoadedObject($c) || $c->deleted) { $d['deleted']=true; return $d; }
        $d['first_name']=$c->firstname; $d['last_name']=$c->lastname; $d['email']=$c->email; $d['company']=(string)$c->company; $d['guest']=(bool)$c->is_guest;
        $orders=$this->sql('SELECT id_address_invoice,id_address_delivery FROM `'._DB_PREFIX_."orders` WHERE id_customer=? AND module<>'wd29woobridge' ORDER BY id_order DESC LIMIT 1",[$id]);
        $addresses=$c->getAddresses($this->lang());
        $billing=$orders[0]['id_address_invoice']??($addresses[0]['id_address']??0);
        $shipping=$orders[0]['id_address_delivery']??$billing;
        if ($billing) { $d['billing']=$this->address((int)$billing,$c->email); $d['phone']=$d['billing']['phone']; }
        if ($shipping) { $d['shipping']=$this->address((int)$shipping,$c->email); }
        return $d;
    }

    public function orderContact(int $id): ?string
    {
        $o=new \Order($id); if (!\Validate::isLoadedObject($o) || $o->module==='wd29woobridge' || !$o->id_customer) { return null; }
        return Protocol::key('ps','customer',(int)$o->id_customer);
    }

    public function reconcileOrderLinks(int $id): void
    {
        $o=new \Order($id); if (!\Validate::isLoadedObject($o) || $o->module!=='wd29woobridge') { return; }
        $rows=$this->engine->sql("SELECT snapshot FROM {b}map WHERE kind='order' AND local_id=?",[$id]);
        $snapshot=json_decode($rows[0]['snapshot']??'{}',true); $index=0;
        foreach ($o->getOrderDetailList() as $row) {
            $source=$snapshot['items'][$index]??[]; $index++;
            if ($row['product_id'] || empty($source['product']) || $row['product_name']!==($source['name']??'') || (int)$row['product_quantity']!==(int)($source['quantity']??0)) { continue; }
            $map=$this->engine->mapping($source['product']); if (!$map) { continue; }
            $detail=new \OrderDetail((int)$row['id_order_detail']);
            $detail->product_attribute_id=$map['kind']==='variant'?(int)$map['local_id']:0;
            $detail->product_id=$detail->product_attribute_id?(int)(new \Combination($detail->product_attribute_id))->id_product:(int)$map['local_id'];
            if (!$detail->save()) { throw new \RuntimeException('Order catalog link could not be repaired.'); }
        }
    }

    public function orderSummary(int $id): array
    {
        $o=new \Order($id); $lines=$o->getOrderDetailList(); $missing=0;
        foreach ($lines as $row) { if (!$row['product_id']) { $missing++; } }
        return ['local_id'=>$id,'total'=>$o->total_paid_tax_incl,'currency'=>(new \Currency((int)$o->id_currency))->iso_code,
            'status'=>$this->config()['order_states'][(string)$o->current_state]??(string)$o->current_state,'lines'=>count($lines),'unlinked_lines'=>$missing];
    }

    public function order(int $id): array
    {
        $o = new \Order($id);
        if (!\Validate::isLoadedObject($o)) { throw new \RuntimeException('Order not found.'); }
        $statuses = $this->config()['order_states'] ?? [];
        $status = $statuses[(string) $o->current_state] ?? null;
        if (!$status) { $status = 'ps-state-' . (int)$o->current_state; }
        $key = $this->engine->identity('order', $id);
        $map = $this->engine->mapping($key);
        if (strpos($key, 'woo:') === 0 && !empty($map['snapshot'])) {
            $snapshot = json_decode($map['snapshot'], true, 64, JSON_THROW_ON_ERROR);
            $snapshot['status'] = $status;
            return $snapshot;
        }
        $customer = new \Customer((int) $o->id_customer); $items = [];
        foreach ($o->getOrderDetailList() as $row) {
            $pid = (int) $row['product_attribute_id'] ?: (int) $row['product_id'];
            $items[] = ['product' => $pid ? $this->engine->identity($row['product_attribute_id'] ? 'variant' : 'product', $pid) : null,
                'name' => $row['product_name'], 'quantity' => (int) $row['product_quantity'],
                'net' => $row['total_price_tax_excl'], 'tax' => (string) ((float) $row['total_price_tax_incl'] - (float) $row['total_price_tax_excl'])];
        }
        $data = ['key' => $this->engine->identity('order', $id), 'number' => (string) $o->reference, 'status' => $status,
            'currency' => (new \Currency((int) $o->id_currency))->iso_code, 'total' => (string) $o->total_paid_tax_incl,
            'tax' => (string) ($o->total_paid_tax_incl - $o->total_paid_tax_excl), 'shipping_net' => (string) $o->total_shipping_tax_excl,
            'shipping_tax' => (string) ($o->total_shipping_tax_incl - $o->total_shipping_tax_excl), 'discount' => (string) $o->total_discounts_tax_excl,
            'billing' => $this->address((int) $o->id_address_invoice, $customer->email),
            'shipping' => $this->address((int) $o->id_address_delivery, $customer->email), 'items' => $items, 'created' => date('c', strtotime($o->date_add))];
        if (strpos($status,'ps-state-')===0) { $data['source_status']=['id'=>(int)$o->current_state,'label'=>(string)(new \OrderState((int)$o->current_state,$this->lang()))->name]; }
        return $data;
    }
    public function applyOrder(array $data, ?array $map): int
    {
        $config = $this->config();
        if ($map && strpos($data['key'], 'ps:') === 0) {
            $current = $this->order((int) $map['local_id']); $incoming = $data;
            $sameStatus = $current['status'] === $incoming['status'];
            unset($current['status'], $incoming['status']);
            if (Protocol::fingerprint($current) !== Protocol::fingerprint($incoming)) { throw new \RuntimeException('Financial order edits belong on the source store.'); }
            if ($sameStatus) { return (int)$map['local_id']; }
            $state = array_search($data['status'], $config['native_states'] ?? [], true);
            if (!$state) { throw new \RuntimeException('No native PrestaShop order-status mapping.'); }
            $o = new \Order((int) $map['local_id']);
            if ((int) $o->current_state !== (int) $state) {
                $history = new \OrderHistory(); $history->id_order = (int) $o->id;
                $history->changeIdOrderState((int) $state, $o); $history->add();
            }
            return (int) $o->id;
        }
        $stateId = (int) ($config['mirror_states'][$data['status']] ?? 0);
        if (!$stateId) { throw new \RuntimeException('No mirrored order-status mapping.'); }
        $state = new \OrderState($stateId);
        if ($state->logable || $state->invoice || $state->paid) { throw new \RuntimeException('Mirror state must not generate invoices, payments or a second sale.'); }
        $currency = (int) \Currency::getIdByIsoCode($data['currency']);
        if (!$currency) { throw new \RuntimeException('Order currency not configured.'); }
        $net = 0.0; $gross = 0.0;
        foreach ($data['items'] as $row) { $net += (float) $row['net']; $gross += (float) $row['net'] + (float) $row['tax']; }
        if (abs($gross + (float) $data['shipping_net'] + (float) $data['shipping_tax'] - (float) $data['total']) > 0.02) {
            throw new \RuntimeException('Order fees or discounts need an explicit mapping.');
        }
        $o = $map ? new \Order((int) $map['local_id']) : new \Order();
        if (!$map) {
            $billing = $data['billing'];
            if (empty($billing['first_name']) || empty($billing['last_name']) || !\Validate::isEmail($billing['email'] ?? '')) {
                throw new \RuntimeException('Complete source billing identity is required.');
            }
            $customer = new \Customer(); $customer->firstname = $billing['first_name']; $customer->lastname = $billing['last_name'];
            $customer->email = $billing['email']; $customer->is_guest = true; $customer->active = true;
            $customer->passwd = \Tools::hash(bin2hex(random_bytes(32)));
            $customer->id_default_group = (int) \Configuration::get('PS_GUEST_GROUP');
            if (!$customer->add()) { throw new \RuntimeException('Guest customer could not be recorded.'); }
            $makeAddress = function (array $row) use ($customer) {
                $a = new \Address(); $a->id_customer = (int) $customer->id; $a->alias = 'Source order';
                $a->firstname = $row['first_name']; $a->lastname = $row['last_name']; $a->company = $row['company'] ?? '';
                $a->address1 = $row['address_1']; $a->address2 = $row['address_2'] ?? ''; $a->city = $row['city'];
                $a->postcode = $row['postcode']; $a->id_country = (int) \Country::getByIso($row['country']);
                $a->phone = $row['phone'] ?? '';
                if (!empty($row['state'])) {
                    $states = $this->sql('SELECT id_state FROM `' . _DB_PREFIX_ . 'state` WHERE id_country=? AND iso_code=?', [$a->id_country, $row['state']]);
                    if (!$states) { throw new \RuntimeException('Address state is not configured.'); } $a->id_state = (int) $states[0]['id_state'];
                }
                if (!$a->add()) { throw new \RuntimeException('Source address could not be recorded.'); } return (int) $a->id;
            };
            $o->id_customer = (int) $customer->id; $o->id_address_invoice = $makeAddress($billing);
            $o->id_address_delivery = $makeAddress(empty($data['shipping']['address_1']) ? $billing : $data['shipping']);
            $o->id_carrier = (int) ($config['carrier_id'] ?? \Configuration::get('PS_CARRIER_DEFAULT'));
            $o->id_shop = $this->shop(); $o->id_shop_group = (int) \Context::getContext()->shop->id_shop_group;
            $o->id_lang = $this->lang(); $o->id_currency = $currency; $o->conversion_rate = 1;
            $o->secure_key = $customer->secure_key;
            $cart = new \Cart(); $cart->id_customer = $o->id_customer; $cart->id_currency = $currency;
            $cart->id_lang = $o->id_lang; $cart->id_address_invoice = $o->id_address_invoice; $cart->id_address_delivery = $o->id_address_delivery;
            $cart->id_carrier = $o->id_carrier; $cart->secure_key = $customer->secure_key;
            if (!$cart->add()) { throw new \RuntimeException('Order cart could not be recorded.'); } $o->id_cart = (int) $cart->id;
            $o->reference = \Order::generateReference(); $o->module = 'wd29woobridge'; $o->payment = 'Recorded on source store';
            $o->total_paid_real = 0; $o->valid = false; $o->round_mode = (int) \Configuration::get('PS_PRICE_ROUND_MODE');
            $o->round_type = (int) \Configuration::get('PS_ROUND_TYPE');
            $o->note = 'Mirror of ' . $data['key'] . '; payment and invoice remain on source store.';
        }
        $o->current_state = $stateId;
        $o->total_paid = $o->total_paid_tax_incl = (float) $data['total'];
        $o->total_paid_tax_excl = (float) $data['total'] - (float) $data['tax'];
        $o->total_products = $net; $o->total_products_wt = $gross;
        $o->total_shipping = $o->total_shipping_tax_incl = (float) $data['shipping_net'] + (float) $data['shipping_tax'];
        $o->total_shipping_tax_excl = (float) $data['shipping_net'];
        $o->total_discounts = $o->total_discounts_tax_incl = $o->total_discounts_tax_excl = 0;
        // Lines already contain source discounts; the untouched source snapshot records the original discount amount.
        if (!$o->save()) { throw new \RuntimeException('Order record could not be saved.'); }
        if ($map) {
            foreach ($o->getOrderDetailList() as $row) { $detail = new \OrderDetail((int) $row['id_order_detail']); $detail->delete(); }
        }
        foreach ($data['items'] as $row) {
            if ((int) $row['quantity'] < 1) { throw new \RuntimeException('Order line quantity must be positive.'); }
            $pm = !empty($row['product']) ? $this->engine->mapping($row['product']) : null;
            $detail = new \OrderDetail(); $detail->id_order = (int) $o->id; $detail->id_shop = $this->shop(); $detail->id_warehouse = 0;
            $detail->product_attribute_id = $pm && $pm['kind'] === 'variant' ? (int) $pm['local_id'] : 0;
            $detail->product_id = $detail->product_attribute_id ? (int) (new \Combination($detail->product_attribute_id))->id_product : ($pm ? (int) $pm['local_id'] : 0);
            $detail->product_name = $row['name']; $detail->product_quantity = (int) $row['quantity'];
            $detail->product_quantity_in_stock = 0; $detail->total_price_tax_excl = (float) $row['net'];
            $detail->total_price_tax_incl = (float) $row['net'] + (float) $row['tax'];
            $detail->unit_price_tax_excl = $detail->product_price = number_format((float) $row['net'] / (int) $row['quantity'],6,'.','');
            $detail->unit_price_tax_incl = number_format($detail->total_price_tax_incl / (int) $row['quantity'],6,'.','');
            $detail->original_product_price = $detail->product_price;
            if (!$detail->add()) { throw new \RuntimeException('Order line could not be recorded.'); }
        }
        // Direct object recording deliberately avoids PaymentModule::validateOrder and its stock/payment side effects.
        $this->engine->bind($data['key'], 'order', (int) $o->id);
        $this->engine->sql('UPDATE {b}map SET snapshot=? WHERE record_key=?', [Protocol::encode($data), $data['key']]);
        return (int) $o->id;
    }
}
