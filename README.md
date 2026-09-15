# WD29 PrestaShop → WooCommerce Bridge

Connecteur direct avec [le module partenaire](https://github.com/webdesign29/woocommerce-prestashop-bridge).

Nom technique du module : **wd29woobridge**. Page : **Modules → WD29 WooCommerce Bridge → Configurer**. Point de réception fourni par `getModuleLink`, utilisable avec et sans réécriture d’URL.

## Fonctionnement

- Deux boutiques appairées directement en HTTPS, sans service intermédiaire.
- Webhooks HMAC-SHA256, horodatage, identifiant de livraison et déduplication persistante.
- File d’attente avec reprises, ordre de traitement par fiche et journal des erreurs.
- Identités `woo:product:ID`, `ps:product:ID` et correspondances des déclinaisons. Les UGS vides ne servent jamais à fusionner des fiches.
- Rapprochement initial par réunion des catalogues : les produits propres à chaque boutique sont conservés.
- Produits simples et variables, noms, descriptions, catégories, attributs, prix et images.
- Stock initial conservé ; mouvements suivants propagés par écarts, avec verrous et protection contre les doubles livraisons. Une disponibilité sans quantité reste une quantité inconnue.
- Commandes copiées comme enregistrements natifs, sans nouvelle opération de paiement. Une commande miroir ne doit pas provoquer un deuxième mouvement de stock. Les modifications financières restent sur la boutique d’origine ; les statuts peuvent revenir vers celle-ci.
- Conflits de catalogue : pause pour examen, priorité WooCommerce ou priorité PrestaShop, à régler pareil des deux côtés.
- Contrôle périodique de pages de 10 produits et 10 commandes pour récupérer les hooks manqués. La fréquence dépend du cron et du volume du catalogue.

## État et limites

**Version candidate à recette, pas une certification KerAwen.** Les tests automatisés utilisent WordPress/WooCommerce et PrestaShop natifs, sans le module commercial KerAwen. Une recette sur les boutiques appairées reste nécessaire avant exploitation.

Les cas suivants sont bloqués ou demandent une adaptation explicite : multiboutique, packs et téléchargements, variations génériques « toutes les tailles », stock mutualisé au niveau du parent WooCommerce, écotaxe, fiscalité sans correspondance, devises différentes et commandes dont les frais/remises ne correspondent pas aux lignes exportées. Les remboursements monétaires ne sont jamais exécutés par le connecteur. Les suppressions ne sont pas propagées automatiquement. La gestion des médias est additive côté PrestaShop ; les suppressions/remplacements complexes de galeries demandent une recette spécifique. Les métadonnées d’extensions tierces ne sont pas migrées automatiquement.

Des prix dont la TVA est désactivée côté WooCommerce restent marqués « prix affiché, fiscalité non renseignée ». Le module PrestaShop demande alors la base HT/TTC, le taux et, si nécessaire, le groupe de règles fiscales. Aucun taux n’est déduit du nom d’un produit. L’import d’un produit PrestaShop taxé vers un WooCommerce sans taxes configurées est bloqué.

## Mise en service

1. Installer les deux archives de la même version, puis ouvrir leurs pages de configuration.
2. Définir une même clé aléatoire d’au moins 32 caractères sur les deux sites. La conserver uniquement dans les configurations privées des boutiques. Ne jamais la publier dans GitHub, un ticket ou un journal.
3. Copier l’URL de webhook de chaque boutique dans la configuration de l’autre. Passer les deux en **audit** et utiliser « Test connection ».
4. Confirmer les correspondances fiscales et la priorité en cas de conflit. Les deux boutiques doivent utiliser la même priorité.
5. Capturer les deux catalogues par lots, puis les commandes si leur historique doit être repris. Les commandes conservent les lignes historiques même lorsque leur produit est indisponible ; le lien au catalogue est réparé dès que le produit est synchronisé.
6. Examiner les erreurs et les quantités non suivies, puis activer **live** sur les deux sites seulement après recette. Le mode audit reçoit les événements mais ne modifie pas les fiches, stocks et commandes à partir des événements entrants.
7. Configurer un vrai cron WordPress, idéalement chaque minute. WP-Cron dépend autrement des visites. WooCommerce déclenche aussi le traitement du module PrestaShop, y compris lorsque sa vitrine est en maintenance ; seul le point de réception signé bénéficie de cette exception.
8. Vérifier une création, une modification, une déclinaison, une vente, une annulation et la répétition du même webhook. Vérifier aussi la caisse réelle si KerAwen est utilisé.

La désactivation conserve les correspondances et l’historique de déduplication. Ne pas réutiliser ces tables pour une autre paire de boutiques. Les événements contiennent des données de commande dans la base privée du site ; leurs contenus ne sont pas inclus dans le journal synthétique.

## Développement et tests

`php tests/protocol.php` vérifie les signatures, l’expiration, l’altération des messages, les identités et les quantités inconnues.

Les tests natifs refusent de démarrer hors des bases jetables `wd29woo` et `wd29ps`, sur les domaines de test `woo.example.test` et `ps.example.test`. Ils vident les tables du connecteur **uniquement dans ces installations jetables**. Ils ne doivent jamais être lancés sur une boutique client.

Le protocole et le moteur communs se trouvent dans `includes/Protocol.php` et `includes/Engine.php` dans les deux dépôts. Ces fichiers doivent rester identiques pour une même version du protocole. `python3 scripts/package.py` construit une archive dans `dist/` à partir d’une liste de fichiers autorisés ; les tests, les réglages et les secrets ne sont pas embarqués.

## Licence

GPL-2.0-or-later. Voir `LICENSE`.

### Test natif de ce dépôt

```sh
WD29_PS_ROOT=/chemin/prestashop php tests/prestashop-integration.php
```

### Stock des déclinaisons

PrestaShop partage une politique de commande hors stock pour toutes les déclinaisons. Les produits WooCommerce mélangeant des quantités suivies et des disponibilités sans quantité sont bloqués avant création pour éviter de rendre commandable une déclinaison à zéro. Harmoniser leur suivi de quantité avant import. Le prix de base des parents variables sans prix propre est calculé à partir des prix de leurs déclinaisons.

### Champs complémentaires (0.1.3)

Les marques WooCommerce sont reliées au fabricant PrestaShop (une marque maximum), et les étiquettes sont synchronisées dans la langue par défaut. Les champs absents des anciens événements ne suppriment pas ces valeurs. Les groupes ACF, les métadonnées privées des extensions, les champs de caisse KerAwen et les modèles SEO ne sont pas copiés automatiquement : ils demandent une correspondance explicite et une recette.

Une remise globale PrestaShop déclarée, dont le montant explique exactement l’écart du total, est conservée dans la commande miroir WooCommerce sous une ligne négative « Source order discount ». Les autres écarts restent bloqués.

### Commandes et répertoire clients (0.1.4)

Les commandes sont indépendantes de la disponibilité du catalogue. Une ligne sans produit local conserve son nom, sa quantité, ses montants et sa référence d’origine ; son lien est réparé ultérieurement sans modifier les montants ni le stock. Le tableau « Order reconciliation » affiche les totaux natifs, statuts et lignes encore sans lien.

Le « Customer contact directory » est un répertoire privé de copies de coordonnées, disponible dans la configuration du connecteur aux administrateurs autorisés. Les profils clients et contacts invités des commandes sont transmis dans les deux sens avec les webhooks signés et la déduplication. La copie s’actualise quand la fiche d’origine change, via le contrôle périodique. Les administrateurs modifient les coordonnées sur leur boutique d’origine. Les identités sont conservées par source et identifiant ; les emails ne fusionnent jamais automatiquement deux fiches. Les noms, email, téléphone, société et adresses de facturation/livraison sont transmis.

Ce répertoire ne crée ni ne fusionne de comptes de connexion WordPress ou PrestaShop : les comptes existants, mots de passe, rôles, tokens de paiement et consentements marketing ne sont pas transférés. Les adresses historiques des commandes restent leurs instantanés. La synchronisation des suppressions et de tout le carnet d’adresses demande un traitement explicite ; seuls les profils présents et les contacts des commandes sont parcourus. Les répertoires et leurs données restent dans les bases privées des boutiques et ne doivent jamais être publiés dans le dépôt.

La mise à jour ajoute automatiquement une table privée de contacts et conserve les configurations, correspondances et historiques existants. Installer les deux versions avant de reprendre les échanges.

### Source order statuses (0.1.5)
Unknown PrestaShop order states travel with their native ID and label. WooCommerce automatically registers a private administrative status (for example `PrestaShop: Reçue`). The WordPress bridge panel offers a mapping to existing WooCommerce statuses and applies it to unchanged mirrors. This mapping changes mirror presentation only, retaining the canonical source status and financial snapshot. It does not mark a source sale paid, issue a new invoice, or create another stock movement.

The signed connection health response includes the last worker state and UTC timestamp. A successful peer worker run clears the previous connection warning; per-record failures remain in the journal.

### Extended catalog and recovery (0.1.7)
- Non-variation WooCommerce attributes map to native PrestaShop features, including multiple values. Dimensions convert between native store units through centimetres. Product and combination EAN/UPC/ISBN/MPN travel explicitly; WooCommerce uses its native global identifier where available and retains supplementary codes in private product metadata. A 14-digit GTIN has no native PrestaShop EAN13 slot and is preserved in bridge metadata instead.
- Variation images are imported and attached to their native combination/variation. Downloads retain the existing peer-host, HTTPS, MIME, size and pixel limits. PrestaShop galleries remain additive. WooCommerce retains additional variation image attachments in private metadata because its native variation has one primary image.
- Stock-management modes participate in catalog fingerprints. Initial unmapped products can recover from obsolete invalid queued snapshots: the newest valid full snapshot supersedes older catalog events, and earlier stock events already included in its count are marked ignored. Existing mapped product quantities are never rebased. A repeated stock-mode transition matching the initial count is idempotent.
- The customer directory now transports the native PrestaShop address book and the two standard WooCommerce addresses (maximum 100 entries per profile). Only allowlisted contact fields are transferred. A bounded rescan of previously captured source contacts detects removed profiles and clears their mirrored coordinates with a deletion marker. Historical order addresses and native login accounts are untouched.
- New fields are visible in the private catalog audit; contact summaries include delivery address and address-book count. Existing ACF/private KerAwen data, custom SEO templates, supplier relations, purchasing cost semantics, login-account migration, native financial refunds and the real KerAwen acceptance test remain outside the verified mappings. Do not treat arbitrary private plugin metadata or passwords as portable fields.

### Archived order products, SEO and purchasing (0.1.8)
Referenced WooCommerce products in the trash are included as archived catalog records. PrestaShop imports them inactive and unavailable for ordering, allowing historical order lines to be linked. The original WordPress trash item is never restored by the bridge. This does not propagate permanent product deletion.

PrestaShop meta title/description map to Yoast product metadata. When Yoast is available, its source templates are resolved before transfer; the original source template is preserved. Unresolved template expressions are not published on the other platform. This covers product titles/descriptions, not the entire SEO plugin configuration.

The primary supplier name/reference and the explicit net unit purchasing cost map to native PrestaShop supplier/product fields. WooCommerce exposes dedicated product fields (Prix d’achat HT, Fournisseur principal, Référence fournisseur), separate from the retail price and tax-inclusive price entry. Additional suppliers, purchase orders, commercial-module metadata and unrelated accounting/COGS integrations are not modified.

### Stock availability policy (0.1.9)
Catalog updates now refresh the PrestaShop ordering-out-of-stock policy for existing products, without resetting tracked quantities. The WooCommerce bridge panel exposes an explicit batched action to set unknown source-owned variation quantities to zero and disable backorders. Known quantities and PrestaShop-owned products are preserved; parent-managed inventory requires a separate allocation decision. Only use the action after choosing this inventory policy.


## Historical 0.1.10 scope (superseded by 0.2.0 below)

Configure the explicit allowlist in the WooCommerce bridge panel. Empty by default:

```json
[
  {"id":"material_note","source":"meta","key":"material_note"},
  {"id":"care_note","source":"acf","key":"field_your_local_key"}
]
```

These mappings apply to products and variations, not order/customer metadata. Use stable unique transport IDs. ACF field definitions must already exist locally; use a field key for fields not yet saved. The bridge never inventories all metadata or ACF groups. WooCommerce single-value metadata supports JSON scalars and nested arrays (64 KiB per value, depth 8, 50 mappings). Native price/stock fields, bridge internals and credential-like keys are rejected. Only explicitly selected business metadata should be added.

Supported ACF types: text, textarea, number, range, email, URL, true/false, select, checkbox, radio, button group, date, date/time, time and color. Values and local ACF references are written through WooCommerce metadata storage; native ACF reads are covered by integration tests. ACF must be active for an ACF mapping. Field definitions, defaults and validation hooks are not synchronized. Media, relationships, taxonomies, repeaters, groups, flexible content and other extension-specific types require dedicated adapters; their local IDs cannot be treated as cross-store identities.

PrestaShop retains selected values privately in the bridge mapping snapshot and re-exports them unchanged on catalog edits. This does not create native PrestaShop features or KerAwen fields, or a custom field editor there. Existing conflict handling applies. Explicit missing-value markers propagate deletion; omitted IDs leave WooCommerce fields untouched. Removing an allowlist mapping does not delete local source metadata. WordPress scalar storage conventions apply; arrays preserve their JSON structure. Duplicate rows for one metadata key are not supported.

Validation: native WooCommerce/PrestaShop suites plus `WD29_WP_ROOT=/path/to/disposable/wordpress php tests/custom-fields-integration.php` in the WooCommerce repository with official ACF installed (database must be wd29woo, domain woo.example.test). See [ACF field API](https://www.advancedcustomfields.com/resources/get_field_object/).


## Version 0.2.0 — broader synchronization and operational checks

- Visual Woo field allowlist with product, variation, order and registered-customer scopes; HPOS order metadata.
- ACF native groups, image URLs, synchronized-product relationships; PRO repeaters/galleries are supported when available, with native PRO storage not yet exercised in our free-ACF fixture. See CUSTOM-FIELDS.md for exact type and identity boundaries.
- PrestaShop bridge editor for already synchronized product/variant/order custom values, with stale-edit detection. This is not a KerAwen field mapping.
- Additive multiple supplier purchasing records, per parent/variant, with currency validation and independent primary selection; see SUPPLIERS.md.
- Source-owned refund/credit-slip audit records; no second money movement, fiscal document, or stock restoration. Native mirrored order-line IDs stay stable, including upgrades from old payloads.
- Optional native customer accounts with independent passwords, no automatic email merge, source-owned profile updates and standard billing/shipping addresses. Disabled by default. Additional addresses remain in the complete private contact directory. No marketing consent transfer; Woo account notifications suppressed. Native account deletion/deactivation is not automatically propagated.
- CLI-only server worker, structured diagnostics and keyed errors; see OPERATIONS.md. An actual server schedule still requires installation on the hosting account.
- PrestaShop stock workers now record automated movements under an unsaved system actor (employee ID0), avoiding failure when no staff member is logged in.

Validated with native Woo/PS integration suites, real HPOS storage, free ACF, supplier/account/refund fixtures, MySQL rollback/replay/retry/worker-lock tests, and cross-store concurrent stock movements. The cross-store test uses signed file bundles and native PS adjustments; it is not a real KerAwen cash-register transaction or an HTTPS end-to-end checkout.

Remaining: supported KerAwen API contract for loyalty/redemption/procurement/private fields; real POS acceptance tests; hosting scheduler activation; ACF PRO native tests and unsupported field types; shared reservation for simultaneous last-unit sales; advanced catalog types (packs, protected downloads, multistore), destructive gallery/product deletion semantics and full fiscal/payment refund workflows. Ordinary asynchronous stock synchronization alone cannot prevent simultaneous last-unit overselling.
