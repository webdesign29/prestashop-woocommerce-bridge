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
