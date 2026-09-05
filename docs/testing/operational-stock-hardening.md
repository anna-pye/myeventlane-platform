# Operational extras: local stock hardening

## Contract

Commerce Stock is the inventory authority for the four operational extras variation types. Admission ticket capacity stays on the existing ticket path.

- Cart holds reserve finite stock for 15 minutes. Expired holds do not reserve inventory.
- Unpaid placement does not deduct stock. Commerce's paid event confirms stock before extras issuance.
- Confirmation locks variation IDs in sorted order, reloads catalogue state, checks other holds and writes sales plus an immutable order receipt atomically.
- Locks remain held until the outer Commerce transaction commits or rolls back.
- Repeated paid events do not deduct again. Receipts also cover unlimited extras and historical paid orders.
- Each extra belongs to exactly one organiser store. Stock reads never fall back to another store's inventory.
- Organiser stock-count saves use the same lock through the computed stock field's post-save transaction. Counts below active checkout holds are rejected.
- Placed extra quantities, prices, purchased variations, event assignment and deletion are blocked. Use the refund workflow and a replacement order.
- Refund/cancellation returns are capped by recorded sales and prior returns. Collected/redeemed units are never automatically restocked.
- Scanner redemption, Prepare → Ready → Collect and manual recovery share the order-item lock with returns and issuance.
- A successful extra scan or collection requires its audit record to save. Failure rolls back the state change.
- Option 2 remains an event-scoped, authenticated manual recovery form with a required reason. It cannot revive refunded, void, already collected or redeemed entitlements.

The organiser stock field is an **absolute count**, not an amount to add. A form left open during sales can still contain an old count; refresh and verify the physical count before changing it. The lock prevents a sale between the server's calculation and save, not a stale human stocktake.

## Local activation evidence — 4 September 2026

Served checkout: feature/mel-wallet-extras-fulfilment under this project's worktrees directory.

- DDEV backup: before-extras-stock-hardening-20260904.
- Applied only myeventlane_commerce_update_10008.
- Ran the named stock migration, not all unrelated deploy hooks or a broad configuration import.
- Created 5 audited stock transfers (10 new movement rows) and 14 legacy paid-order receipts. The initial migration message counted 15 candidate orders; the final table check found 14 eligible orders with attached extras. The counter now excludes candidates without attached extra lines.
- Compared all 16 existing order tables and all 7 original stock rows before/after: unchanged.
- Inventory totals unchanged; the stock ledger now has 17 rows.
- Browser read-only check: organiser dashboard and Event Studio extras both show 45 available/in stock for the existing four-option shirt.
- Fixed the missing manual_recovery_url theme declaration. Browser verification now reaches the recovery form with pass code, action and reason fields. No existing collection state was changed.
- Final regular regression run: 237 tests, 3,674 assertions, no failures/errors; one warning, existing PHPUnit deprecations, and one expected SQLite skip for the separate-process MariaDB test.
- MariaDB hardening run: 19 tests, 702 assertions, all passing. Separate scanner/issuance/stock/refund slice: 55 tests, 1,192 assertions, no failures/errors. Follow-up stock/template contract slice: 14 tests, 45 assertions, no failures/errors.
- Migration rerun: no-op. No database updates remain pending locally.

The local verification helper prints only counts and hashes. It is read-only unless explicitly opted in:

```sh
ddev drush php:script scripts/verify-operational-stock-migration.php
ddev exec env MEL_APPLY_STOCK_HARDENING=1 drush php:script scripts/verify-operational-stock-migration.php
```

The second command is a migration, not a diagnostic. Take a backup first. Its validation rolls back migration changes if original order rows, stock history or inventory totals differ.

## Regression commands

```sh
ddev exec bash scripts/mel-phpunit -c web/phpunit-governance.xml
ddev exec env SIMPLETEST_DB=mysql://db:db@db/db bash scripts/mel-phpunit -c web/phpunit-governance.xml web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalStockLifecycleKernelTest.php
```

The MariaDB run uses Drupal's isolated test-table prefixes, not customer tables. It includes a separate PHP/database process competing for the final-unit semaphore. This proves the database commit/lock boundary; it is not a real simultaneous browser/payment-provider test.

Refund tests combine real Commerce orders and stock transactions with controlled entitlement-storage fixtures. Scanner tests persist real ticket and audit entities, including a deliberately unavailable audit table in the isolated test database.

## Release gates and recovery

This is local activation, not production release. No commit, push, merge or staging/production deployment is implied.

Before release:

1. Review the whole existing feature branch and unrelated working changes separately.
2. Verify paid checkout, failed payment, late payment and webhook replay through the actual provider test environment.
3. Run two competing browser checkouts for the final unit.
4. Test pre/post-collection refunds, staff access boundaries and manual recovery in the rendered journey.
5. Verify Apple and Google Wallet issuance and update behaviour on actual devices. A saved Wallet display is not proof of current entitlement validity; collection must validate server state.
6. Arrange an attended maintenance window, take a database backup, apply schema updates, import the reviewed stock configuration, run the legacy stock migration before stock_hardening_v1, then verify counts and reopen traffic. Do not enable paid_stock_enabled without the receipt table and organiser-location migration.

A payment can arrive after its hold expires. If another buyer has taken the stock, confirmation fails closed and no new pass is issued. Staff must reconcile the provider payment and stock, then retry the existing issuance path or use the approved refund workflow. This change does not promise an automatic refund or add a durable payment-exception queue.

Historical paid orders without extras passes were not backfilled during stock migration. Review and reissue them through the existing issuance path before relying on those orders for event-day collection. Manual collection recovery requires an existing pass code; it is not a missing-entitlement issuance tool.

Return reconciliation failures are logged and leave stock unavailable. They require a retry of the same persisted refund/cancellation source; do not manually add stock to bypass an unresolved return.

Do not reverse the migration by editing old stock transactions or simply re-enabling Commerce Stock's automatic placed/cancel/update handlers. They would compete with the paid-stock lifecycle. Use an attended restore only when no subsequent legitimate activity would be lost, or plan a forward migration.
