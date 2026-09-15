# Additional suppliers

`Suppliers::export($product): array` and `Suppliers::apply($product, $rows): void` operate on each saved product or variation separately. A row contains exact supplier `name`, `reference`, net `purchase_price_net`, and ISO `currency`.

PrestaShop reads/writes native ProductSupplier records. WooCommerce retains private `_wd29_suppliers` structured data. Updates are additive: matching names update, omitted suppliers remain. The existing singular primary-supplier fields control the default supplier separately; this helper never changes it or wholesale price. Root integration calls apply after native product IDs exist, within the bridge transaction, and saves Woo metadata afterward.

Unknown currencies, ambiguous destination supplier names, duplicate rows and invalid prices fail visibly. Currency amounts are retained without conversion. Combinations have their own records; product-level records do not silently propagate to every combination. Supplier deletion, purchasing orders, receipts and KerAwen private procurement APIs are outside this helper.

This does not map supplier names to globally reliable business identifiers. Exact-name identity follows the existing bridge convention and rejects ambiguous matches. Renames need explicit review to avoid accidental duplicate supplier companies.
