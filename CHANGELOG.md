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

## 0.1.0 — candidate à recette

- Appairage direct et webhooks signés.
- Réunion initiale des catalogues et identités d’origine persistantes.
- Stock par écarts, déduplication et reprise des livraisons.
- Produits simples, déclinaisons et commandes miroirs natives.
- Fiscalité explicitement configurée et conflits visibles.
- Tests de protocole et tests natifs sur bases isolées.
