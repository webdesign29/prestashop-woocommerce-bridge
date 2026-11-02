<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_0_7_11($module)
{
    if (function_exists('opcache_invalidate')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension()==='php') { opcache_invalidate($file->getPathname(),true); }
        }
    }
    return true;
}
