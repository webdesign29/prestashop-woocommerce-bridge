# Current companion release

Commerce Pro0.7.5 provides the optional KerAwen customer, shared coupon, gift-card/store-credit payment, loyalty and native checkout modules for compatible installations. Sync0.8.12 carries signed transport and identity mappings. See https://plugins.inklura.fr/docs/kerawen for setup and exact supported versions. Shopify checkout does not spend KerAwen balances. The dated inspection below is retained as historical Sync-only evidence.

# KerAwen demo integration — 2026-10-20

Demo inspection: PrestaShop 8.2.4, KerAwen 2.11.9 and its `loyalty` module 2.2.2. The WooCommerce demo has WooCommerce and Inklura Sync; it has no installed gift-card or loyalty payment extension. Vendor source was inspected privately. It is not bundled in this connector. The temporary signed inspection route has been retired to HTTP 404.

## Implemented

- Registered PrestaShop originals remain eligible for capture and manual comparison after Woo purchases. The mirrored-order exclusion applies only to guest customers created for mirrors; linked imported accounts retain their explicit origin.
- Registered contacts are linked through explicit canonical identities and `account_links`, never matched by email. Creating, updating and replaying a registered order preserves that account, including a customer originally copied from the other store. Guests remain guests.
- Native customer copies receive account activation changes. Disabled PrestaShop imports cannot authenticate in WordPress with passwords or existing login cookies. Passwords, sessions, marketing consent and privileged roles are not copied. Historical order identity survives deactivation.
- Automatic and manual customer updates check the previous native copy before writing. Local profile, identity or role changes require review. Deleted source accounts are retained and disabled rather than deleted.
- Native sale-price imports reuse an existing rule of the exact unique scope. Replays, sale updates/removal and unrelated native promotion scopes are covered by regression tests.
- Woo quantity changes create native PrestaShop stock movements in the same transaction as the quantity change. Native PrestaShop stock movements propagate back to Woo. Exact event replay does not create another movement.
- `commerce_account` reads the linked customer's authoritative KerAwen points and account-bound cart rules. `commerce_catalog` reads generic discount definitions while excluding typed bearer gift cards, credit and loyalty balances. `commerce_order_link` provides safe diagnostics for a mapped Woo order.
- With `kerawen_accounts` enabled in the Woo bridge configuration, the signed-in customer's account dashboard reads their KerAwen points and available account rules directly. No independently spendable Woo wallet or coupon is created. An unavailable source is displayed as unavailable, never as a cached spendable balance or a zero balance.
- Native mirror payment/refund/financial edits become visible queue conflicts instead of repeated retries or automatic overwrites. An exception requiring native review never creates or changes a payment or invoice.

## Historical Sync-only financial scope

| Operation | Status |
| --- | --- |
| Two-way coupon/cart-rule definition import and redemption counters | Not implemented |
| Spending KerAwen gift cards or credit at Woo checkout | Not implemented |
| Atomic reserve, debit, release and refund against a common balance | Not implemented |
| Issuing a native gift card for a paid Woo gift-card purchase | Not implemented |
| Earning, cancelling or converting KerAwen loyalty points for Woo purchases | Not implemented |
| Mirroring credit-slip audit information in order snapshots | Existing source-owned refund ledger; does not issue a fiscal credit slip or a new spendable credit |

The installed POS API authenticates employees and performs native checkout/cashdrawer operations. It is not a shopper balance endpoint. Calling its checkout on a mirrored Woo order would risk creating a second sale, fiscal record or stock movement.

Native gift cards and credit are typed `CartRule` records. KerAwen converts them to payments, rather than treating them as ordinary tax-reducing discounts. Partial use can create a new native rule ID while retaining the original customer-facing code. Native loyalty conversion can also move rule associations. Copying a scalar amount or coupon ID into Woo is insufficient.

A subsequent payment integration must use KerAwen as authority, preserve tender/tax accounting, track partial-use descendants, and demonstrate idempotent reserve/debit/release/refund behavior across POS and Woo checkout. It must use native loyalty earning, eligibility, cancellation and conversion rules. These operations must be tested with overlapping checkouts, network retries, failed/cancelled payments and refunds before being enabled.

## Demo verification

Synthetic Woo customer 8 is linked to PrestaShop customer 6. The fixture stock product is Woo 32 / PrestaShop 11. Zero-value customer-link test orders Woo 36 / PS 5 and Woo 37 / PS 6 are cancelled and retained for audit. They created no payment, invoice or stock reduction.

Woo order 31 / PS order 4 has native payment/validation changes. It requires explicit order review. The connector preserves that native history.

Standalone regressions cover sale-rule adoption/replay, stock ledger creation and rollback, canonical registered identities, native customer conflict guards, and permanent review versus temporary retry classification. Native Woo checks cover legacy storage and HPOS, account/customer links, guest isolation, password and valid-cookie deactivation/reactivation, password preservation, and protection of locally edited or privileged copies.
