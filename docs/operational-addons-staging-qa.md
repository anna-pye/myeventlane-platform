# Operational add-ons MVP — staging deployment and QA

Staging readiness checklist for Phase 4F (customer booking add-ons, post-purchase guidance, vendor order visibility, MVP hardening). **Presentation/read-only only** — no fulfilment mutation.

**Related:** [operational-addons-mvp-qa.md](./operational-addons-mvp-qa.md) (local DDEV script), [vendor-operational-addon-order-visibility.md](./vendor-operational-addon-order-visibility.md).

## Workflow

DDEV → feature branch → PR → merge to `main` → CI build artifact → `deploy-staging.yml` → browser QA on staging.

Do **not** patch staging directly from a laptop.

---

## A. Pre-deploy checks

| Check | Command / action | Pass criteria |
| --- | --- | --- |
| Branch | `git branch --show-current` | Target feature branch merged to `main` via PR (e.g. `feature/operational-addons-mvp-hardening`) |
| Latest hardening | `git log --oneline -5` | Includes `fix(commerce): harden operational add-ons mvp flow` (549bb5ce or later on merged line) |
| Working tree | `git status --short` | Clean before PR merge |
| Remote sync | `git fetch origin && git branch -vv` | Branch pushed; resolve ahead/behind before PR if diverged |
| Composer | `composer validate --no-check-publish` | Valid |
| PHP lint | `php -l` on changed operational add-on PHP | No syntax errors |
| Whitespace | `git diff --check` | Clean |
| Theme lint/build | `npm run mel:lint` && `npm run mel:build` | Pass |
| Unit tests | See [Automated tests](#automated-tests) | All pass |
| Config review | `ddev drush cst` / `ddev drush config:status` | No unexpected **deletions** of `field_mel_op_capabilities`, operational Commerce types, or `field_mel_operational_product` |
| Deploy script | `scripts/deploy/remote-deploy.sh` | Present in artifact; validates theme `dist/` and vendor theme `dist/` |

### Config classification (local baseline 2026-05-16)

| Item | Classification | Notes |
| --- | --- | --- |
| `crop.type.event_hero` | **B — Known unrelated drift** | Only `Different` entry in `drush cst`; review before any `cim`, not introduced by Phase 4F |
| Operational Commerce types/fields in `config/sync` | **A — Expected** (already in repo) | e.g. `commerce_product.commerce_product_type.operational_merchandise`, `field.storage.commerce_product.field_mel_operational_product`, `field.field.commerce_product.*.field_event` |
| `field_mel_op_capabilities` on events | **A — Expected** | Present in sync; **STOP** if `config:status` shows **delete** for this field |

Phase 4F commits (`d0c68a69` … `84d4c3d1`) did **not** change `config/sync` — this release slice is **code + module CSS + docs**.

### Staging deploy expectations

| Step | Required for this MVP? | Notes |
| --- | --- | --- |
| CI `composer install` + theme `npm run build` | **Yes** | `reusable-build.yml`; artifact includes `vendor/`, theme `dist/`, module CSS under `myeventlane_commerce/css/` |
| `RUN_UPDB=1` on remote deploy | **No** (unless staging pending updates) | Local: `drush updatedb:status` → no pending. Phase 4F did not add new `hook_update_N` for add-ons |
| `RUN_CIM=1` on remote deploy | **Only if** staging lacks operational Commerce config from earlier phases | Default staging workflow does **not** set `RUN_CIM`; operational types must already exist on staging or run a one-off `cim` with ops approval |
| `drush cr` | **Yes** | Always runs in `remote-deploy.sh` finalize |
| Frontend rebuild on server | **No** | Built in CI artifact |

Default `deploy-staging.yml` invokes `remote-deploy.sh` **without** `RUN_UPDB` / `RUN_CIM` (both default `0`).

---

## B. Post-deploy smoke checks

| # | Test | Expected |
| --- | --- | --- |
| 1 | `https://staging.myeventlane.com.au` | Homepage loads |
| 2 | Vendor domain login | Vendor console accessible |
| 3 | `/vendor/events/{event}` | Event workspace loads |
| 4 | `/event/{nid}/book` (paid event) | Booking page loads |
| 5 | Checkout | Completes with test payment |
| 6 | My Tickets | Loads for purchaser |
| 7 | Order detail / commerce order user view | Loads |

---

## C. Add-on setup QA

| # | Step | Expected |
| --- | --- | --- |
| 1 | Paid event with ticket product | Event bookable |
| 2 | Create/link operational product (Event Studio productisation or Commerce admin) | Product bundle ∈ operational types |
| 3 | Publish product + variation | Both visible in admin |
| 4 | Assign Commerce store | Required — no store → hidden on book page |
| 5 | `field_event` = event | Product scoped to event |
| 6 | `field_mel_operational_product` JSON | Normalized operational metadata (summary/chips) |

---

## D. Customer booking QA

| # | Test | Expected |
| --- | --- | --- |
| 1 | `/event/{nid}/book` | Ticket matrix renders |
| 2 | Add-on section | Below tickets when catalog exists; lede about collect at event |
| 3 | Add ticket + add-on qty &gt; 0 | Submit **Add extras to cart** |
| 4 | Cart / checkout | Ticket + add-on lines; `field_target_event` on add-on items when configured |
| 5 | Complete checkout | Success |
| 6 | Checkout completion | **Your add-ons** guidance when order has operational lines |

---

## E. Customer account QA

| # | Surface | Expected |
| --- | --- | --- |
| 1 | Checkout completion | Add-on guidance strip |
| 2 | My Tickets | Guidance on order card |
| 3 | Order detail | Guidance present |
| 4 | Commerce order user view | Guidance present |
| 5 | Tickets-only order | **No** add-on guidance strip |

---

## F. Vendor QA

| # | Test | Expected |
| --- | --- | --- |
| 1 | Event workspace | **View add-on orders** shortcut when catalog or purchases exist |
| 2 | `/vendor/events/{event}/addons` | Page loads for event owner / vendor team |
| 3 | Purchased add-ons | Grouped list (merch, hospitality, timed, bundles, parking) |
| 4 | No purchases | Empty state message |
| 5 | Unrelated vendor user | Access denied |
| 6 | Anonymous | Denied (vendor console gate) |
| 7 | Customer label | Display name / Customer #id / Guest — **not** raw email on add-on orders page |

---

## G. Negative QA

| # | Case | Expected |
| --- | --- | --- |
| 1 | Unpublished product | Hidden on book page |
| 2 | Unpublished variation | Hidden on book page |
| 3 | Product `field_event` ≠ event | Not listed on book page |
| 4 | Tampered variation id on POST | Validation error |
| 5 | Tickets-only order | No add-on guidance |
| 6 | Product without store | Not on book page; submit fails closed |

---

## H. Mobile QA

| # | Check | Expected |
| --- | --- | --- |
| 1 | Booking add-on section | No horizontal overflow |
| 2 | Chips | Wrap on narrow viewport |
| 3 | Add-on cards | Readable; qty inputs usable |
| 4 | Vendor add-on orders page | Cards readable; summary stats visible |
| 5 | Primary CTA | ~44px min tap target (`.mel-btn` on add-to-cart) |

---

## I. Pass/fail table

Copy into staging QA session notes:

| Area | Test | Expected result | Actual result | Pass/fail | Notes |
| --- | --- | --- | --- | --- | --- |
| Smoke | Staging homepage | Loads | | | |
| Smoke | Vendor login | Works | | | |
| Setup | Operational product + store + field_event | Configured | | | |
| Customer | Book page add-on section | Visible when catalog exists | | | |
| Customer | Cart + checkout | Ticket + add-on | | | |
| Customer | Completion guidance | Shown for add-on order | | | |
| Customer | My Tickets guidance | Shown | | | |
| Customer | Tickets-only order | No guidance strip | | | |
| Vendor | Add-on orders page | Loads for owner | | | |
| Vendor | Unrelated user | Denied | | | |
| Negative | Unpublished product | Hidden | | | |
| Mobile | Booking overflow | None | | | |

---

## Automated tests

Run before PR merge (local or CI):

```bash
composer validate --no-check-publish

./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/EventOperationalAddonBuilderTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalAddonGuidanceBuilderTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/VendorOperationalAddonOrderBuilderTest.php \
  --do-not-cache-result

ddev exec bash -lc 'export SIMPLETEST_DB=sqlite://localhost/tmp/test.sqlite && ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalMerchandiseKernelTest.php --filter EventOperationalAddon --do-not-cache-result'
```

---

## Residual risks

- **Branch divergence:** If local and `origin/feature/operational-addons-mvp-hardening` differ, reconcile (rebase/merge) before PR.
- **Staging config:** Operational Commerce types must exist on staging from an earlier deploy; Phase 4F alone does not export new config.
- **Cache freshness:** Vendor add-on orders page uses order/product tags + **max-age 300s**; new purchase may take up to 5 minutes to appear without cache rebuild.
- **Performance:** `shouldSurfaceVendorAddonsTab()` scans orders — acceptable for MVP; watch large events on staging.
- **Default deploy:** `RUN_CIM=0` — confirm staging already has operational product types before relying on add-on UI.

## Explicit non-goals (staging)

No mark collected, mark prepared, scanner, QR wallet, entitlements, stock decrement, warehouse, shipping orchestration, checkout pane changes, or order mutation in this MVP.
