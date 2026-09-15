# Acceptance checks and external prerequisites

## Verified locally

Native WooCommerce and PrestaShop fixtures exercise products/variants, mapped order lines, contacts, optional independent customer accounts, source refund ledgers, suppliers, metadata, stock deltas, replay prevention, transaction rollback, retry exhaustion and worker locking. Cross-store signed bundles converge after concurrent native stock changes and cancellation/restoration without double movements. See tests/cross-store in the WooCommerce repository.

These results do not certify a real KerAwen cash-register transaction or payment gateway. Native ACF PRO storage is not certified by the free ACF fixture; supported complex-field translation is tested with synthetic local schemas.

## Hosting prerequisite

Use OPERATIONS.md to install and verify each hosting server's minute worker and monitor its health exit status. Shipping the command does not install a scheduler. Hosting access is required; use the existing site service account and private rotated logs.

## Real checkout / KerAwen acceptance

The shop operator should perform the financial confirmation steps on an agreed test product and payment method. Record source IDs, before/after stock, totals, taxes and status without exporting customer details publicly.

1. Woo order: check native payment/status, exactly one PrestaShop mirror, linked variation, same TTC total and tax, stock deducted once.
2. KerAwen sale: check native ticket/order, exactly one Woo mirror, mapped KerAwen status, stock deducted once.
3. Cancel or return on the originating platform: check the source fiscal/payment action, mirrored status/refund ledger and exactly one stock restoration.
4. Replay the bridge event: ensure no second order, money movement or stock adjustment.
5. Temporarily stop one test worker, make a source change, restart it and check eventual convergence and diagnostics recovery.
6. Concurrent edits: check catalog priority; real order/customer discrepancies must remain visible for review.

Do not create a second destination refund or fiscal document to mimic the source refund. Source refund records are informational mirrors. Financial operations belong to the originating platform.

## Integration boundaries

- KerAwen loyalty, gift-card redemption, purchasing and private fields require a supported vendor API contract and test access. Native PrestaShop supplier references are separate from private KerAwen procurement.
- Simultaneous last-unit sales need a shared reservation service. Asynchronous stock deltas converge but cannot provide an atomic checkout reservation across independent platforms.
- Packs, protected downloadable assets and multistore need separate platform-specific designs and fixtures.
- ACF field definitions/layouts are configured locally. Clone, user and arbitrary file references are not portable mappings. No live ACF inventory is needed when no fields are in use.
- Account creation is optional and off by default. No password/consent transfer or automatic email-based merge occurs; email collisions require review.
