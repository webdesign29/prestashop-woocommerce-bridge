<?php
/** Retired temporary demo inspection route. No source or customer data is returned. */
class Wd29woobridgeNativeauditModuleFrontController extends ModuleFrontController
{
    public $ajax=true;public $display_header=false;public $display_footer=false;
    public function init(){http_response_code(404);header('Cache-Control: no-store');exit;}
    public function initContent(){http_response_code(404);header('Cache-Control: no-store');exit;}
}
