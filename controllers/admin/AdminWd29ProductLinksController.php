<?php
/** Back-office-only lookup, authenticated by PrestaShop's native admin controller. */
class AdminWd29ProductLinksController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
        $this->display_header = false;
        $this->display_footer = false;
    }

    public function viewAccess($disable = false)
    {
        return \WD29\Bridge\ProductLinksAdmin::canRead($this->context);
    }

    public function postProcess()
    {
        header('Cache-Control: private, no-store');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        try {
            if (Tools::getValue('action') !== 'lookup') { throw new RuntimeException('Requête invalide.'); }
            $result = \WD29\Bridge\ProductLinksAdmin::lookup($this->module, $_POST);
            echo json_encode(['ok' => true] + $result);
        } catch (Throwable $error) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'state' => 'unavailable', 'message' => 'Lien indisponible. Rechargez la fiche et vérifiez vos droits.']);
        }
        exit;
    }
}
