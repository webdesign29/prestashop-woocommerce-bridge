<?php
namespace WD29\Bridge;
/** Retain Image rows and files; detach only the current-shop association, recoverable from metadata. */
final class Gallery
{
    private static function owned(int $pid,array $meta): array
    {
        $ids=[];
        foreach ((array)($meta['images']??[]) as $hash=>$id) {
            if (!preg_match('/^[a-f0-9]{64}$/D',(string)$hash)) { continue; }
            $image=new \Image((int)$id);
            if (\Validate::isLoadedObject($image) && (int)$image->id_product===$pid) { $ids[]=(int)$id; }
        }
        return array_values(array_unique($ids));
    }
    public static function apply($product,array $desired,array &$meta,bool $remove=false): void
    {
        if (!$product instanceof \Product || !(int)$product->id) { throw new \RuntimeException('Saved product required for gallery.'); }
        $pid=(int)$product->id; $shop=(int)\Context::getContext()->shop->id;
        if ($remove && \Shop::isFeatureActive()) { throw new \RuntimeException('Gallery removals require a single-shop configuration.'); }
        $desired=array_values(array_unique(array_filter(array_map('intval',$desired)))); $owned=self::owned($pid,$meta);
        foreach ($desired as $id) {
            $image=new \Image($id);
            if (!\Validate::isLoadedObject($image) || (int)$image->id_product!==$pid) { throw new \RuntimeException('Gallery image does not belong to product.'); }
        }
        $archive=(array)($meta['gallery_detached']??[]);
        foreach ($desired as $id) {
            if (isset($archive[$id]) && (int)$archive[$id]['id_shop']===$shop && in_array($id,$owned,true)) {
                $image=new \Image($id);
                if (!$image->isAssociatedToShop($shop) && !$image->associateTo([$shop],$pid)) { throw new \RuntimeException('Gallery image association restore failed.'); }
                unset($archive[$id]);
            }
        }
        if ($remove) {
            foreach (array_diff($owned,$desired) as $id) {
                $row=\Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.'image_shop` WHERE id_image='.$id.' AND id_shop='.$shop.' AND id_product='.$pid);
                if (!$row) { continue; }
                $archive[$id]=['id_image'=>$id,'id_shop'=>$shop,'id_product'=>$pid,'cover'=>$row['cover']];
                if (!\Db::getInstance()->delete('image_shop','id_image='.$id.' AND id_shop='.$shop.' AND id_product='.$pid)) { throw new \RuntimeException('Gallery association detach failed.'); }
            }
        }
        // Preserve manual cover; only select a new one when the detached image left no visible cover.
        $cover=\Db::getInstance()->getValue('SELECT id_image FROM `'._DB_PREFIX_.'image_shop` WHERE id_product='.$pid.' AND id_shop='.$shop.' AND cover=1');
        if (!$cover) {
            $first=$desired?reset($desired):\Db::getInstance()->getValue('SELECT id_image FROM `'._DB_PREFIX_.'image_shop` WHERE id_product='.$pid.' AND id_shop='.$shop.' ORDER BY id_image');
            if ($first && !\Db::getInstance()->update('image_shop',['cover'=>1],'id_image='.(int)$first.' AND id_shop='.$shop.' AND id_product='.$pid)) { throw new \RuntimeException('Gallery cover recovery failed.'); }
        }
        $meta['gallery_detached']=$archive; \Cache::clean('Product::getCover*');
    }
    public static function applyCombination($combination,array $desired,array &$meta,bool $remove=false): void
    {
        if (!$combination instanceof \Combination || !(int)$combination->id) { throw new \RuntimeException('Saved combination required.'); }
        if ($remove && \Shop::isFeatureActive()) { throw new \RuntimeException('Combination gallery removals require single shop.'); }
        $pid=(int)$combination->id_product; $aid=(int)$combination->id; $owned=self::owned($pid,$meta);
        $desired=array_values(array_unique(array_filter(array_map('intval',$desired))));
        foreach ($desired as $id) { $image=new \Image($id); if (!\Validate::isLoadedObject($image) || (int)$image->id_product!==$pid) { throw new \RuntimeException('Combination image belongs to another product.'); } }
        $rows=\Db::getInstance()->executeS('SELECT id_image FROM `'._DB_PREFIX_.'product_attribute_image` WHERE id_product_attribute='.$aid);
        if (!is_array($rows)) { throw new \RuntimeException('Combination image query failed.'); }
        $current=array_map('intval',array_column($rows,'id_image')); $removed=$remove?array_values(array_intersect(array_diff($current,$desired),$owned)):[];
        $ids=array_values(array_unique(array_merge($desired,array_diff($current,$removed))));
        $archived=(array)($meta['combination_gallery_detached'][$aid]??[]);
        $meta['combination_gallery_detached'][$aid]=array_values(array_diff(array_unique(array_merge($archived,$removed)),$ids));
        if (!$combination->setImages($ids)) { throw new \RuntimeException('Combination gallery associations failed.'); }
    }
    public static function restoreCombination($combination,array &$meta): void
    {
        $aid=(int)$combination->id;
        $rows=\Db::getInstance()->executeS('SELECT id_image FROM `'._DB_PREFIX_.'product_attribute_image` WHERE id_product_attribute='.$aid);
        if (!is_array($rows)) { throw new \RuntimeException('Combination image query failed.'); }
        $ids=array_merge(array_map('intval',array_column($rows,'id_image')),(array)($meta['combination_gallery_detached'][$aid]??[]));
        self::applyCombination($combination,$ids,$meta,false);
    }
    public static function restore($product,array &$meta): void
    {
        $current=$product->getImages((int)\Context::getContext()->language->id);
        $ids=array_merge(array_map('intval',array_column($current,'id_image')),array_map('intval',array_keys((array)($meta['gallery_detached']??[]))));
        self::apply($product,$ids,$meta,false);
    }
}
