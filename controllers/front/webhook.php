<?php
class Wd29woobridgeWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    public $auth = false;
    public $display_header = false;
    public $display_footer = false;
    // Only this HMAC-authenticated endpoint stays reachable while the storefront is in maintenance.
    protected function displayMaintenancePage() {}
    protected function displayRestrictedCountryPage() {}
    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }
        try {
            $body = file_get_contents('php://input', false, null, 0, \WD29\Bridge\Protocol::MAX_BYTES + 1);
            $message = \WD29\Bridge\Protocol::verify($body, $this->module->bridge()->config()['secret'] ?? '', [
                'timestamp' => $_SERVER['HTTP_X_WD29_TIMESTAMP'] ?? '', 'nonce' => $_SERVER['HTTP_X_WD29_NONCE'] ?? '', 'signature' => $_SERVER['HTTP_X_WD29_SIGNATURE'] ?? '']);
        } catch (Throwable $error) { http_response_code(401); exit('{"ok":false}'); }
        try { echo json_encode($this->module->bridge()->receive($message), JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { http_response_code(400); echo '{"ok":false}'; }
        exit;
    }
}
