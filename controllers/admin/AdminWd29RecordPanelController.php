<?php
class AdminWd29RecordPanelController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct(); $this->ajax = !(($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && in_array($_GET['action'] ?? '', ['open','module'], true)); $this->display_header = false; $this->display_footer = false;
    }
    /** PS9 checks URL tokens before legacy checkToken. This is authenticated navigation only. */
    private function moduleNavigation(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'module' && \WD29\Bridge\RecordPanelAdmin::canConfigure($this->context, $this->module);
    }
    public function isAnonymousAllowed()
    {
        if ($this->moduleNavigation()) { return true; }
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'open' && is_string($_GET['kind'] ?? null)
            && \WD29\Bridge\RecordPanelAdmin::canRead($this->context, $_GET['kind']);
    }
    public function checkToken()
    {
        if ($this->moduleNavigation()) { return true; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'open' && is_string($_GET['kind'] ?? null)
            && \WD29\Bridge\RecordPanelAdmin::canRead($this->context, $_GET['kind'])) { return true; }
        return parent::checkToken();
    }
    public function viewAccess($disable = false)
    {
        if (($_GET['action'] ?? '') === 'module') { return \WD29\Bridge\RecordPanelAdmin::canConfigure($this->context, $this->module); }
        $kind = Tools::getValue('kind');
        return is_string($kind) && \WD29\Bridge\RecordPanelAdmin::canRead($this->context, $kind);
    }
    public function postProcess()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'module') {
            header('Cache-Control: private, no-store');
            try { Tools::redirectAdmin(\WD29\Bridge\RecordPanelAdmin::moduleUrl($this->module, $_GET)); }
            catch (Throwable $error) { http_response_code(403); echo 'Accès au module refusé.'; }
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'open') {
            header('Cache-Control: private, no-store');
            try { Tools::redirectAdmin(\WD29\Bridge\RecordPanelAdmin::openUrl($this->module, $_GET)); }
            catch (Throwable $error) { http_response_code(403); echo 'Accès à la fiche refusé.'; }
            exit;
        }
        header('Cache-Control: private, no-store'); header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
        try { echo json_encode(\WD29\Bridge\RecordPanelAdmin::dispatch($this->module, $_POST) + ['ok' => true]); }
        catch (Throwable $error) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Opération refusée. Rechargez la fiche et relancez la comparaison ; vérifiez vos droits et la connexion.']);
        }
        exit;
    }
}
