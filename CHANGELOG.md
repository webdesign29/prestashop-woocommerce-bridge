## 0.6.4

- Panneaux natifs dans les fiches produit, commande et client : état de synchronisation, comparaison à la demande, activité récente et actions individuelles confirmées, sans changer le mode automatique.
- Liens vers la fiche publique du produit et vers l’administration distante de la fiche correspondante ; connexion et droits natifs exigés, aucun jeton distant partagé.
- Aperçu des variations enregistrées (ajouts, modifications, retraits). Le produit et ses variations sont synchronisés ensemble ; les retraits à examiner bloquent l’action, les quantités existantes conservent leur suivi par événements.
- Protections des modifications locales des comptes clients facultatifs, y compris adresses et nouveaux champs ; distinction explicite entre répertoire Sync et compte client natif.
- Correction des prix de déclinaisons PrestaShop : aperçu des nouvelles déclinaisons sans correspondance préalable et invalidation du cache de prix avant le calcul de l’empreinte après synchronisation.
- Lectures différées, sans polling ni scan de catalogue ; index d’activité par fiche ajoutés aux installations existantes. Mettre les deux connecteurs à jour.

## 0.6.3

- Add signed, read-only inspection of one mapped product and its stock for the opt-in Commerce Pro product workspace; no catalogue scans or customer information.
- Preserve original/copy identities, conflict protections and stock movement queues.

# Inklura Sync 0.6.2

- Les pages d’administration ne chargent plus les rapports et instantanés natifs des autres pages. La vue d’ensemble ne parcourt plus les commandes et remboursements masqués.
- Chaque étape d’une synchronisation complète ne relit qu’une fiche source au lieu d’une page entière. Les comparaisons conservent leurs lots de 20, et les partenaires plus anciens restent compatibles.
- Pagination vérifiée avec les copies importées exclues, les pages vides et les conflits ; protections contre les comparaisons périmées et les doublons conservées.

# Inklura Sync 0.6.1

- Présentation native de chaque administration : onglets, boutons, tableaux et panneaux WordPress ; onglets, composants de formulaire et panneaux PrestaShop. Les styles et la typographie de la plateforme sont conservés.
- En-tête compact, suppression de la bannière décorative, identification des deux boutiques et pages de comparaison conservées.
- Confirmations simples et centrées, contrôles lisibles sur mobile. Aucun changement du protocole, des modes ou des données synchronisées.

# Inklura Sync 0.6.0

- Administration répartie en pages : vue d’ensemble, comparaison et synchronisation, activité, rapports, réglages, licence et mises à jour. Navigation et liens directs conservent les protections natives de chaque plateforme.
- Comparaison en lecture seule des produits, commandes et contacts dans les deux sens, avec WordPress à gauche et PrestaShop à droite, identification original/copie, écarts par famille et filtre des fiches identiques.
- Création ou mise à jour individuelle des produits et contacts, y compris lorsque le suivi automatique est arrêté. Les comparaisons périmées, modifications locales et conflits ne sont pas écrasés.
- Synchronisation complète dans le sens choisi (produits, contacts, commandes) ou d’une seule famille, par requêtes bornées, avec progression, arrêt après la fiche courante et journal copiable. Gardez la page ouverte ; relancer reconnaît les fiches déjà appliquées.
- Les variantes, images et champs autorisés suivent les produits. Les différences de stock sont signalées séparément : les quantités existantes gardent leur suivi par événements, sans forçage d’inventaire. Les contacts ne créent des comptes natifs que si cette option est déjà activée.
- Les modes automatiques restent inchangés. Mettre les deux connecteurs à jour en 0.6.0 pour utiliser toutes les comparaisons et les transferts manuels.

# 0.5.2 — PrestaShop

- Décodage des entités HTML des villes WooCommerce avant enregistrement des adresses PrestaShop, pour les commandes importées et les comptes clients optionnels. Une ville contenant `&#8211;` est enregistrée avec son tiret Unicode, sans rejet de validation.
- Conservation du snapshot original et des contrôles de conflits : les anciennes adresses restent reconnues, toute modification locale réelle impose toujours une revue.
- Compatible avec le connecteur WordPress 0.5.1 ; aucun changement de mode ni de création de comptes par défaut.

# 0.5.1

- Les deux administrations affichent clairement la boutique consultée et le domaine du partenaire, avec des repères WordPress et PrestaShop constants.
- Comparaison des commandes côte à côte : WordPress à gauche, PrestaShop à droite, références et statuts natifs de chaque boutique, distinction original/copie et destination nommée sur les boutons.
- Rapports : origine lisible, identifiants de la copie locale, filtres originaux/imports et sens WordPress → PrestaShop ou PrestaShop → WordPress dans le journal.
- Libellés des captures précisant les boutiques concernées ; la présentation ne change pas les règles de synchronisation.

# 0.5.0

- Nouveau panneau « Commandes à synchroniser » dans les deux plugins : comparaison des commandes originales avec la boutique partenaire, dans les deux sens, par lots de 20.
- Import ou envoi manuel d’une commande absente ou modifiée, même lorsque le suivi automatique est arrêté ou en audit. Le mode automatique reste inchangé ; une licence autorisant les écritures est nécessaire sur la destination.
- Affichage des écarts de statut, montants, articles, coordonnées, métadonnées et remboursements ; protection contre les modifications concurrentes et les conflits locaux, sans doublon lors d’une nouvelle tentative.
- Exclusion des brouillons de checkout WooCommerce non validés.
- Compatibilité PrestaShop 9.1 : validation native des jetons CSRF aléatoires Symfony et remplacement de l’ancienne fonction de réécriture des URL. Vérifié sur PrestaShop 8.2.8 et 9.1.5.
- Mettez les deux plugins à jour en 0.5.0 avant d’utiliser la comparaison manuelle.

# 0.4.0

- Administration en français : réglages, actions, en-têtes des rapports, diagnostics et messages. Les valeurs techniques (codes de diagnostic, identités, commandes CLI) ne changent pas.
- PrestaShop : bandeau dans tout le back-office quand le mode live est suspendu ou que le délai de grâce de la licence court ; enregistré automatiquement, y compris après une mise à jour en un clic.

# 0.3.2

- Messages de licence : l’état n’est plus répété dans le texte du serveur.
- WordPress : lien « Réglages » dans la liste des extensions.
- README : section licence et mises à jour.

# 0.3.1

- WordPress : le canal de mise à jour est aussi disponible depuis WP-CLI et les tâches planifiées ; il n’est chargé que lorsque WordPress recherche des nouveautés.

# 0.3.0

- Panneau Licence (saisie, état, vérification, retrait de la clé) dans les deux plugins.
- Vérification quotidienne signée (Ed25519) depuis le worker ; échec réseau sans effet ; diagnostics `licence_*`.
- Mode live rabattu en audit quand la licence ne le permet pas ; reprise automatique.
- Mises à jour depuis l’administration (WordPress : écran Extensions ; PrestaShop : bouton dans le panneau Licence), SHA-256 contrôlé.

# 0.2.2

- Présentation commune des panneaux, indicateurs, rapports repliables, tableaux défilants et badges d’état.

# 0.1.4

- Import historical order lines independently of catalog availability; attach later without changing stock or totals.
- Add native order reconciliation totals and a private source-owned customer contact directory.
- Synchronize customer/guest contact snapshots with signed, deduplicated events; do not create login accounts or copy credentials/consents.
- Add automatic contact-table migration and customer capture controls.

# 0.1.3

- Synchronize native product brands/manufacturers and tags; show them in the catalog audit.
- Decode category HTML entities before PrestaShop validation.
- Record a declared source-wide order discount on WooCommerce mirrors when it reconciles the exact source total.

# 0.1.2

- PrestaShop: derive variable base prices from children, preserve tracked backorder policy and block incompatible mixed inventory modes before creating products.

# 0.1.1

- Add a read-only catalog audit with source identities, destination mappings, tax basis and unknown inventory.

# Changelog

## 0.1.0

- Appairage direct et webhooks signés.
- Réunion initiale des catalogues et identités d’origine persistantes.
- Stock par écarts, déduplication et reprise des livraisons.
- Produits simples, déclinaisons et commandes miroirs natives.
- Fiscalité explicitement configurée et conflits visibles.
- Tests de protocole et tests natifs sur bases isolées.
