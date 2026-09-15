<?php
/** CLI-only documentation fixture. Never loads either store or its configuration. */
namespace WD29\Bridge {
    final class Engine {
        public function config(): array { return ['mode'=>'audit']; }
        public function diagnostics(): array { return ['ok'=>true,'queue'=>[],'issues'=>[]]; }
    }
}
namespace {
    if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
    $root=dirname(__DIR__); $platform=basename($root)==='prestashop-woocommerce-bridge'?'ps':'woo';
    require $root.'/includes/AdminDesign.php';
    $peer=$platform==='ps'?'https://woo.example.test/wp-json/wd29-bridge/v1/webhook':'https://ps.example.test/module/wd29woobridge/webhook';
    $html='<div><h1>Bridge</h1><p>Démonstration de la configuration : données fictives, aucune boutique connectée.</p><form method="post">';
    $fields=[['Mode','<select name="mode"><option value="audit" selected>Audit — no incoming writes</option></select>'],[($platform==='ps'?'WooCommerce':'PrestaShop').' webhook','<input name="peer" type="url" value="'.$peer.'">'],['Simultaneous catalog edits','<select name="conflict_policy"><option value="review">Pause for review</option></select>'],['Shared secret','<input name="secret" type="password" autocomplete="off" value="" placeholder="Leave blank to keep the configured secret">']];
    if($platform==='ps'){$fields[]=['Source prices','<select name="display_basis"><option value="gross">Tax inclusive</option></select>'];$fields[]=['Reference tax rate (%)','<input name="display_tax_rate" value="20">'];}
    foreach($fields as [$label,$control]){$html.=$platform==='ps'?'<label>'.$label.'</label>'.$control:'<p><label>'.$label.' '.$control.'</label></p>';}
    $html.='<p><label><input type="checkbox" name="native_customers" value="1"> Create native customer accounts (optional)</label></p><p><label><input type="checkbox" name="sync_gallery_removals" value="1"> Recoverable removal of imported gallery images (optional)</label></p><button name="bridge_action" value="save">Save settings</button></form><hr><form><button name="bridge_action" value="health">Test connection</button><button name="bridge_action" value="tick">Process queue</button></form><h2>Latest events</h2><p>Exemple de journal. Aucun événement réel.</p><table><tr><th>seq</th><th>direction</th><th>kind</th><th>record_key</th><th>state</th></tr><tr><td>1</td><td>out</td><td>product</td><td>woo:product:1001</td><td>delivered</td></tr></table><h2>Order reconciliation</h2><p>Les commandes apparaissent ici après synchronisation.</p><table><tr><th>source</th><th>total</th></tr></table></div>';
    $page='<!doctype html><html lang="fr"><meta charset="utf-8"><title>WD29 — documentation demo</title><style>body{margin:0;background:#f3f5f8}#wd29-admin{max-width:1200px!important}form{margin:0}</style><body>'.\WD29\Bridge\AdminDesign::render($html,new \WD29\Bridge\Engine(),$platform).'<script>document.querySelectorAll("form").forEach(f=>f.addEventListener("submit",e=>e.preventDefault()));</script></body></html>';
    echo $page;
}
