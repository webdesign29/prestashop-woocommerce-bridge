<?php
/** Invalidate only this module's PHP bytecode after native in-place import. */
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_0_7_10($module)
{
    $root=dirname(__DIR__);
    if (function_exists('opcache_invalidate')) {
        $files=[$root.'/wd29woobridge.php'];
        foreach (['includes','controllers','upgrade'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink() && $file->getExtension()==='php') { $files[]=$file->getPathname(); }
            }
        }
        foreach ($files as $file) { clearstatcache(true,$file); @opcache_invalidate($file,true); }
    }
    return true;
}
