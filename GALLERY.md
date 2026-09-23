# Recoverable gallery synchronization

The settings panel provides **Rattacher les images détachées** (Restore detached gallery images before 0.4). Enter a mapped product or variation key from the synchronization journal. Restoration uses the worker lock and a database transaction, saves the recovered associations, and captures the parent product for synchronization. No attachment or image file is deleted.

Option `sync_gallery_removals` defaults to false. No image file or Image object is deleted. For a single shop, absent bridge-owned imports can be hidden by removing only their `image_shop` association; Image rows, language rows, files and URL-hash mappings remain. Associations are archived inside the existing product bridge snapshot under `gallery_detached` and are restored when the source image returns. Manually added unmapped images remain visible. Multistore removal is refused because image and combination association scopes differ.

Integration after all product and variant image imports:

```php
$desiredIds = [];
foreach ($allImages as $url) {
    $desiredIds[] = (int) $meta['images'][hash('sha256', $url)];
}
Gallery::apply($p, $desiredIds, $meta, !empty($this->config()['sync_gallery_removals']));
```

`$allImages` MUST include the union of explicit parent and all variant image URLs; variants still need their images visible in the product image shop. For each explicitly supplied variant images field replace `setImages($ids)` with:

```php
Gallery::applyCombination($combination, $ids, $meta, !empty($this->config()['sync_gallery_removals']));
```

Persist the mutated `$meta` in the existing bridge product map snapshot after both calls, inside the bridge transaction. Owned imports are checked against both the URL-hash map and the Image's product ID. Combination associations use a separate `combination_gallery_detached` archive and preserve unmapped images. `Gallery::restore($product, $meta)` restores parent image shop associations; `Gallery::restoreCombination($combination, $meta)` restores archived combination associations. Preserve and persist metadata afterward.

Native Product::getImages joins image_shop. Core Image::delete may delete original image files, so it is deliberately never called by this helper. Direct shop-association detachment is an integration behavior tested in a disposable PS database; verify storefront/cache behavior with the production theme before enabling. Archived images remain discoverable through direct URLs and some back-office views; this is reversible gallery visibility, not asset erasure. Existing manual cover is retained; a replacement is selected only if no visible cover remains. Image ordering and original cover restoration are not a complete historical gallery rollback.

Tests create native Image records without downloading assets and verify association removal/restoration, retained records, replay, default additive behavior and manually added image preservation.
