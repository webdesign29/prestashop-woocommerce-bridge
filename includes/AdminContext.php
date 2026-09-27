<?php
namespace WD29\Bridge;

/** Read-only labels for the administration being viewed and record ownership. */
final class AdminContext
{
    public static function name(string $side): string { return $side === 'woo' ? 'WordPress / WooCommerce' : 'PrestaShop'; }
    private static function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    public static function stores(Engine $engine, string $local): string
    {
        $remote = $local === 'woo' ? 'ps' : 'woo';
        $urls = [$local => $engine->adapter->siteUrl(), $remote => $engine->config()['peer'] ?? ''];
        $html = '<div class="wd-store-context" aria-label="Boutiques de cette connexion">';
        foreach (['woo', 'ps'] as $side) {
            $host = parse_url($urls[$side], PHP_URL_HOST) ?: 'Boutique partenaire non configurée';
            $html .= '<div class="wd-store wd-side-'.$side.'" data-store-platform="'.$side.'" data-store-local="'.($side === $local ? 'true' : 'false').'"><span class="wd-store-role">'.($side === $local ? 'Vous êtes ici · cette administration' : 'Boutique partenaire configurée').'</span><strong>'.self::name($side).'</strong><span class="wd-store-host">'.self::e($host).'</span></div>';
        }
        return $html.'</div>';
    }
    public static function table(\DOMElement $table, string $section, string $local): void
    {
        $doc = $table->ownerDocument; $remote = $local === 'woo' ? 'ps' : 'woo';
        $localName = self::name($local); $remoteName = self::name($remote);
        $heads = iterator_to_array($table->getElementsByTagName('th')); $identity = null; $localId = null; $direction = null;
        foreach ($heads as $i => $th) {
            $name = trim($th->textContent);
            if (in_array($name, ['Origine','Identité','Client source','Commande'], true)) { $identity = $i; }
            if ($name === 'Origine') { $th->textContent = 'Création de la fiche'; }
            if ($name === 'ID local') { $localId = $i; $th->textContent = 'Fiche sur '.$localName; }
            if ($name === 'Compte local (ID)') { $th->textContent = 'Compte sur '.$localName.' (ID)'; }
            if ($name === 'Sens') { $direction = $i; $th->textContent = 'De → vers'; }
            if ($name === 'Total / statut / lignes ici') { $th->textContent = 'Copie sur '.$localName; }
            if ($name === 'Total / statut / lignes reçus') { $th->textContent = 'Version reçue de '.$remoteName; }
        }
        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = iterator_to_array($row->getElementsByTagName('td')); if (!$cells) { continue; }
            if ($direction !== null && isset($cells[$direction])) {
                $value = trim($cells[$direction]->textContent);
                if (in_array($value, ['in','out'], true)) { $cells[$direction]->textContent = $value === 'in' ? $remoteName.' → '.$localName : $localName.' → '.$remoteName; }
            }
            if ($identity === null || !isset($cells[$identity])) { continue; }
            $key = trim($cells[$identity]->textContent);
            if (!preg_match('/^(woo|ps):(product|variant|order|customer|guest):([0-9]+)$/D', $key, $match)) { continue; }
            $origin = $match[1]; $own = $origin === $local;
            $row->setAttribute('data-wd-origin', $own ? 'original' : 'imported');
            $cell = $cells[$identity]; $cell->textContent = '';
            $badge = $doc->createElement('span'); $badge->setAttribute('class', 'wd-origin wd-side-'.$origin); $badge->textContent = 'Créé sur '.self::name($origin); $cell->appendChild($badge);
            $code = $doc->createElement('small'); $code->setAttribute('class', 'wd-record-key'); $code->textContent = $key; $cell->appendChild($code);
            if ($localId !== null && isset($cells[$localId])) {
                $id = trim($cells[$localId]->textContent);
                $cells[$localId]->textContent = ctype_digit($id) ? ($own ? 'Original' : 'Copie importée').' · #'.$id : 'Pas encore appliqué sur '.$localName;
            }
        }
        if (!in_array($section, ['Catalogue','Commandes','Contacts clients'], true)) { return; }
        $table->setAttribute('data-wd-origin-table', 'true');
        $label = $doc->createElement('label'); $label->setAttribute('class', 'wd-origin-filter'); $label->appendChild($doc->createTextNode('Afficher sur '.$localName.' : '));
        $select = $doc->createElement('select'); $select->setAttribute('data-wd-origin-filter', 'true');
        foreach (['all'=>'Toutes les fiches suivies','original'=>'Originaux créés sur '.$localName,'imported'=>'Copies provenant de '.$remoteName] as $value=>$text) { $option=$doc->createElement('option');$option->setAttribute('value',$value);$option->textContent=$text;$select->appendChild($option); }
        $label->appendChild($select); $table->parentNode->insertBefore($label,$table);
    }
}
