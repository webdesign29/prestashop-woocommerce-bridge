<?php
/** Licence panel, shared by both plugins. The platform supplies its own form token. GPL-2.0-or-later. */
namespace WD29\Bridge;

final class LicenceAdmin
{
    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    /** Handle a licence form action. Returns the message to display, or null if not a licence action. */
    public static function handle(Engine $engine, string $action, string $key): ?string
    {
        $licence = $engine->licence();
        if ($action === 'licence_save') { $licence->setKey($key); return $licence->summary()['text'] ?: 'Clé enregistrée.'; }
        if ($action === 'licence_check') { $licence->check('check'); $s = $licence->summary(); return $s['error'] !== '' ? $s['error'] : ($s['text'] ?: 'Licence vérifiée.'); }
        if ($action === 'licence_remove') { return (string) ($licence->removeKey()['message'] ?? 'Clé retirée.'); }
        return null;
    }

    /** @param string $hidden platform form token fields; $update extra HTML for the update row (platform specific) */
    public static function render(Engine $engine, string $hidden, string $update = ''): string
    {
        $s = $engine->licence()->summary();
        $configured = $engine->config()['mode'] ?? 'disabled';
        $state = ['ok' => 'wd-state-good', 'warn' => 'wd-state-warn', 'bad' => 'wd-state-error'][$s['tone']];
        $html = '<h2>Licence</h2><div class="wd-licence"><p><span class="wd-state ' . $state . '">' . self::e($s['label']) . '</span> ' . self::e($s['text']) . '</p>';
        if ($configured === 'live' && !$s['live']) { $html .= '<p class="wd-licence-alert">Le mode live est configuré mais suspendu par la licence : cette boutique fonctionne en mode audit.</p>'; }
        $rows = array_filter(['Clé' => $s['hint'], 'Formule' => $s['plan'] ? ucfirst($s['plan']) : '', 'Paires utilisées' => $s['pairs'], 'Échéance' => $s['expires'], 'Dernière vérification' => $s['checked']]);
        if ($rows) {
            $html .= '<table class="widefat table wd-licence-facts"><tbody>';
            foreach ($rows as $label => $value) { $html .= '<tr><th>' . self::e($label) . '</th><td>' . self::e($value) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        if ($s['error'] !== '') { $html .= '<p><small>Dernier essai : ' . self::e($s['error']) . ' Nouvel essai automatique ; rien ne change en attendant.</small></p>'; }
        $html .= $update;
        $html .= '<form method="post">' . $hidden . '<p><label>Clé de licence <input name="licence_key" autocomplete="off" spellcheck="false" placeholder="WD29-XXXX-XXXX-XXXX-XXXX" value=""></label></p>'
            . '<button name="bridge_action" value="licence_save">' . ($s['hint'] !== '' ? 'Remplacer la clé' : 'Enregistrer la clé') . '</button>';
        if ($s['hint'] !== '') {
            $html .= '<button name="bridge_action" value="licence_check">Vérifier maintenant</button>'
                . '<button name="bridge_action" value="licence_remove" onclick="return confirm(\'Libérer la paire de cette boutique et retirer la clé ?\')">Retirer la clé de cette boutique</button>';
        }
        $html .= '</form><p><small>Seuls la clé, le type de boutique, son adresse, celle de la boutique partenaire et la version du module sont envoyés à plugins.inklura.fr, une fois par jour. Aucune donnée de catalogue, de commande ou de client.</small></p></div>';
        return $html;
    }
}
