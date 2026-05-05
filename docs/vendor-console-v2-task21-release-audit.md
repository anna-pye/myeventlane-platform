# MEL Vendor Console v2 — TASK 21 release audit

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Release readiness audit, PR cleanup notes, and staging smoke checklist. **No production code edits** in TASK 21 unless a release blocker is found (none found).

---

## 1. Release objective

Ship **Vendor Console v2** with:

- Canonical `/vendor/...` shell, Event Studio create/edit, advanced ticket manager, analytics, settings, and messaging brand surfaces aligned with TASK 0–20 docs.
- **Paid-ticket smoke baseline** on event **1094** (“The Newest Show”): healthy `field_ticket_types`, clean `mel:tickets:audit`, no required repairs.
- **No user-facing links** to invalid routes (`myeventlane_vendor.console.create_event`) or forbidden legacy CTAs (`/node/add/event`, `/vendor/events/add`, `/vendor/event/...`, `/vendor/studio`) except documented safe cases (routing definitions, redirects, internal API paths, tests, docs).
- Staging/production deploy supported by checklist below; **1592** treated as **local data drift** unless an operator explicitly applies a reviewed repair.

---

## 2. Files changed overview (TASK 0–20 aggregate / current branch)

**Working tree snapshot:** `git diff --stat` reports **128 files changed**, ~6777 insertions / ~7388 deletions (large branch consolidating vendor console, tickets, checkout UX, themes, and config).

| Bucket | Representative paths / notes |
| ------ | ------------------------------ |
| **docs** | `docs/vendor-console-v2-*.md` (audit, route map, access matrix, nav cleanup, TASK 13–20 notes); **TASK 21** adds this file + PR summary + route-map appendix. |
| **vendor module** | `web/modules/custom/myeventlane_vendor/**` — dashboard, events, workspace, ticket manager, services, routing, libraries; removed legacy ticket card/workspace form paths per TASK 15–20. |
| **vendor theme** | `web/themes/custom/myeventlane_vendor_theme/**` — shell nav, SCSS, Twig, Vite build; `package-lock.json` may differ after `npm run mel:build`. |
| **event module** | `web/modules/custom/myeventlane_event/**` — ticket type lifecycle, wizard tickets, route subscriber; **deleted** `EventFormTicketBuilderCallbacks.php`. |
| **event studio** | `web/modules/custom/myeventlane_event_studio/**` — controllers, forms, save/MEL payload, wizard UI; **deleted** `MelTicketTypeManager.php` (consolidation). |
| **analytics** | `web/modules/custom/myeventlane_analytics/**` — vendor dashboard view model, templates, controller. |
| **checkout / commerce** | `myeventlane_checkout_flow`, `myeventlane_checkout_paragraph`, `myeventlane_commerce`, `config/sync/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`, ticket variation displays, donation field config (untracked in initial status where present). |
| **messaging / profile / settings** | `myeventlane_messaging`, `myeventlane_vendor_settings`, Pro branding controller. |
| **debug module** | `myeventlane_debug` — response subscriber / services (verify no production log flood; see §6). |
| **public theme** | `myeventlane_theme`, `myeventlane_radix` — footer/header CTAs, checkout SCSS/JS/Twig, hero. |
| **config** | `config/sync/**` — checkout flow, commerce variation displays, views, **deleted** `commerce_price.commerce_currency.USD.yml` (**flag for PR**: confirm intentional vs accidental drift). |
| **generated / dist** | Public + vendor theme `dist/` under each theme after `npm run mel:build` (commit per team policy). |

**Unexpected / review in PR**

- **`config/sync/commerce_price.commerce_currency.USD.yml` deleted** — not obviously part of vendor console; confirm with commerce/finance owner before merge.
- **`composer.lock` modified** — ensure paired `composer.json` change intent and CI install consistency.
- **Large diff** — reviewer focus: access boundaries, ticket reconciliation, checkout templates (TASK instructions forbade further refactors in TASK 21).

---

## 3. Canonical route / nav checklist

| Check | Result |
| ----- | ------ |
| `myeventlane_vendor.console.dashboard` → `/vendor/dashboard` | **Present** (`ddev drush route`). |
| `myeventlane_vendor.console.events` → `/vendor/events` | **Present**. |
| `myeventlane_event_studio.create` → `/vendor/events/create` | **Present**. |
| `myeventlane_event_studio.edit` → `/vendor/events/{node}/edit` | **Present** (+ section routes). |
| `myeventlane_vendor.console.event_workspace` → `/vendor/events/{event}` | **Present**. |
| `myeventlane_vendor.console.event_tickets` → `/vendor/events/{event}/tickets` | **Present**. |
| `myeventlane_analytics.dashboard` → `/vendor/analytics` | **Present** (module enabled in env). |
| `myeventlane_checkout_flow.vendor_attendees` → `/vendor/attendees` | **Present**. |
| `myeventlane_vendor.console.settings` → `/vendor/settings` | **Present**. |
| `myeventlane_vendor.console.messaging_brand` → `/vendor/dashboard/messaging/brand` | **Present**. |
| `myeventlane_pro.branding` → `/vendor/settings/branding` | **Present**. |
| Legacy `myeventlane_vendor.console.events_add` → `/vendor/events/add` | **Present** (redirect/safe legacy per TASK 12). |

**Navigation (vendor theme)** — verified in code (`_myeventlane_vendor_theme_build_full_vendor_shell_nav_items`):

1. **Order:** Dashboard → Events → Analytics → Attendees (only if `vendor_attendees` route accessible) → Profile.  
2. **Shell primary action:** “Create Event” → `myeventlane_event_studio.create` when `_myeventlane_vendor_theme_named_route_accessible` passes.  
3. **Account menu / header:** Vendor links built from named routes with `Url::access()` filtering (`myeventlane_vendor_theme.theme`); anonymous users get empty vendor menus.  
4. **`OrganiserContextBlock`:** Create link uses **`myeventlane_event_studio.create`** with per-link `access()` — no `myeventlane_vendor.console.create_event`.

---

## 4. Ticket flow checklist

| Step | Status |
| ---- | ------ |
| Event 1094 has paid mode + `field_product_target` + active tiers on `field_ticket_types` | **Yes** (PHP eval: types 111, 112 active; product 54). |
| Inverse `mel_ticket_type` rows for inactive tiers | Present (109, 110 inactive); audit treats as **info** only. |
| `ddev drush mel:tickets:audit --event=1094` | **0 errors, 0 warnings**; info `inactive_inverse_ticket_types_ignored`. |
| `ddev drush mel:tickets:repair --event=1094` (dry run) | **No actions** (Drush may emit a “Skipped: no_repair_actions” notice — interpret as success). |
| Advanced manager + Studio tickets save path | Per TASK 19–20 docs; **no new regressions** identified in TASK 21 static review. |
| Public matrix / checkout selection | TASK 20 SCSS + `TicketSelectionForm` label; **browser not re-run** in TASK 21. |

---

## 5. Access checklist (TASK 11 assumptions)

| Topic | Finding |
| ----- | ------- |
| `VendorConsoleAccess` + `administer nodes` bypass | Still documented in `myeventlane_vendor.routing.yml`; service logs decisions. |
| Workspace parity | `EventVendorAccessChecker::accountHasWorkspaceParityForEvent` used from RSVP `VendorEventAccess` and vendor controllers (grep sample in TASK 21 run). |
| Team / `field_vendor_users` | Membership queries and controllers reference team field consistently. |
| `EventTicketsAccess` | Retained on ticket routes (stronger than VC-only). |
| `vendor_attendees` | Separate `VendorAttendeesController::checkAccess`; nav item omitted when route not accessible. |
| Pro routes | `_myeventlane_pro_access` + permissions on analytics/branding as before. |
| Broad `_access: 'TRUE'` on vendor pages | No new vendor console pages flagged in this audit. |

**Release blocker:** none found in static review + route list.

---

## 6. Debug / logging checklist

| Check | Result |
| ----- | ------ |
| `ddev drush state:get mel.debug_boost_candidates` | **Empty / not set** (treated as off). |
| `ddev drush state:get mel.debug_http_response_trace` | **Empty / not set** (treated as off). |
| Watchdog sample (`ddev drush ws --count=50`) | Recent entries include **`TEMP_DEBUG`** notices in `myeventlane_commerce` (attendee sync / questions) — **residual noise** for production hygiene review (not introduced in TASK 21). |
| Ticket reconciliation / cart / Studio | Routine notices; **no** orphan-mapping spam or typed-property fatals in sample. |
| Order 452 path | Sample shows `ticket_issuance_failure` / critical monitoring around attendee timing — **local order activity**; not used as branch regression proof without reproduction steps. |

**Watchdog truncate:** not executed (avoids destructive SQL in local DB per caution).

---

## 7. Legacy link checklist (grep)

**Command:** `grep -R "myeventlane_vendor.console.create_event\|/node/add/event\|/vendor/events/add\|/vendor/event/\|/vendor/studio" -n web/modules/custom web/themes/custom config/sync`

| Classification | Examples |
| ---------------- | -------- |
| **Safe legacy route definition** | `myeventlane_vendor.routing.yml` (`/vendor/events/add`, `/vendor/event/...`, `/vendor/studio` API tree). |
| **Safe redirect / legacy attendees** | `myeventlane_event_attendees.routing.yml` + `LegacyVendorEventAttendeePathController`; `ManageEventTicketsController` docblock. |
| **Safe internal / API** | `vendor-studio.js` default endpoints; `myeventlane_vendor_theme.theme` active-section prefix for `/vendor/studio`. |
| **CSS selectors** | `hide-admin-sidebar.css` path matchers. |
| **Tests** | `VendorReportingAccessTest.php` hits `/vendor/event/.../rsvps`. |
| **Docs / markdown** | Admin theme README, theme VITE-FIXES, radix checklist. |
| **Install-only default** | `myeventlane_core.install` registers a **shortcut** with `internal:/node/add/event` — **not** runtime vendor UI; note for staff installs only. |
| **Internal dashboard data** | `VendorDashboardController.php` builds `waitlist_url` as hardcoded `/vendor/event/{id}/waitlist` — **legacy URL string**; should 301 to plural path if surfaced; **not** classified as TASK 21 blocker (bookmark-compatible); suggest follow-up to use `Url::fromRoute` on canonical attendees/waitlist route. |

**Release blockers (UI link to forbidden paths):** **None** in grep scope. **`myeventlane_vendor.console.create_event`** appears **only in docs** (historical mentions), not in PHP/Twig under `web/`.

---

## 8. Build / lint / cache checklist

| Command | Result |
| ------- | ------ |
| `php -l` on each changed `*.php` from `git diff --name-only` | **All: no syntax errors** (loop over changed files). |
| `composer validate --no-check-publish` | **OK** (`./composer.json is valid`). |
| `npm run mel:lint` | **OK** (hero check + stylelint on scoped SCSS). |
| `npm run mel:build` | **OK** (Vite builds for public + vendor themes; `npm ci` in each). |
| `ddev drush cr` | **OK** (`Cache rebuild complete`). |

**Notes:** npm warns about `Unknown env config "devdir"` (environment). `npm audit` reports moderate issues in public theme deps — **existing tooling noise**; confirm CI policy.

---

## 9. Local smoke result

| Item | Result |
| ---- | ------ |
| Drush route sanity | **Pass** (§3). |
| Event **1094** PHP field snapshot | **Pass** — paid, product 54, active tiers 111/112 on `field_ticket_types`. |
| `mel:tickets:audit` / `repair` **1094** | **Pass** (§4). |
| Event **1592** audit | **Fail (data drift)** — `variation_without_ticket_type` (repairable `reconcile_event_ticket_references`); see §10 / §12. |
| Browser smoke (dashboard, events, workspace, tickets, Studio, public, checkout, settings, analytics, anonymous) | **Not run** in this session. |

---

## 10. Staging smoke checklist

### Pre-deploy

- [ ] Branch pushed; PR opened with labels/reviewers.
- [ ] CI pipeline green (PHP, JS, any Drupal checks).
- [ ] **Config export/import reviewed** — especially checkout flow, ticket variation displays, and **USD currency YAML deletion** (confirm intent).
- [ ] No `mel.debug_*` state keys enabled in target env.
- [ ] DB backup / snapshot before deploy.

### Deploy

- [ ] `composer install --no-dev` (or project standard).
- [ ] `drush deploy` / `drush updb` / `drush cim` per environment playbook.
- [ ] `drush cr` after code+config.
- [ ] Built assets present (theme `dist/` or CI artifact) matching commit.

### Post-deploy smoke

- [ ] Vendor login → `/vendor/dashboard` (action queue, no duplicate legacy rails).
- [ ] `/vendor/events` — list + filters + Create Event → Studio create.
- [ ] `/vendor/events/{nid}` workspace — loads; ticket readiness messaging sane.
- [ ] `/vendor/events/{nid}/tickets` — Advanced manager; Active + Save and sync; post-save audit copy.
- [ ] Event Studio edit `/vendor/events/{nid}/edit` — ticket step recognises paid setup.
- [ ] Public event page — only **active** tiers in matrix.
- [ ] Checkout — select active variation; cannot select invalid/sold-out where applicable.
- [ ] `/vendor/analytics` — loads for Pro-eligible vendor; no placeholder fake totals.
- [ ] `/vendor/settings` — save no fatal.
- [ ] `/vendor/dashboard/messaging/brand` — branding subsection + parity with settings fields.
- [ ] `/vendor/attendees` — if user should have access.
- [ ] **Anonymous / customer** — no vendor console links in public header/footer for non-trusted users.
- [ ] **Mobile ~390px** — dashboard, events index, ticket manager, public matrix.
- [ ] `drush ws --count=50` — no unexpected error storm after smoke.

---

## 11. PR summary draft

See dedicated file: **[`docs/vendor-console-v2-pr-summary.md`](vendor-console-v2-pr-summary.md)** (paste-ready for GitHub).

---

## 12. Residual risks

1. **1592 local drift** — `field_ticket_types` out of sync with inverse ticket types; repair not auto-applied in TASK 21.  
2. **Commerce `TEMP_DEBUG` logging** — noisy in watchdog; consider removing or gating before production.  
3. **Order-level race** — sample watchdog shows ticket issuance check vs attendee creation ordering for a real order; investigate separately if reproduced.  
4. **`waitlist_url` legacy string** in dashboard view-model — prefer canonical route generation in a future small PR.  
5. **Config deletion USD currency** — must be validated to avoid accidental commerce misconfiguration.  
6. **Browser / cart E2E** — not executed in TASK 21; staging checklist compensates.  
7. **`both` mode + autosave** — unchanged residual from TASK 19 docs.

---

## Appendix A — Changeset classification command log (reference)

```text
git status -sb
git diff --stat
git diff --name-only
git log --oneline --decorate -10
```

Captured 2026-05-05 on `feature/mel-vendor-console-v2` (see §2 for summary).

---

## Appendix B — Event 1592 decision (local data drift)

**Audit:** `variation_without_ticket_type` — `field_ticket_types` missing published ticket types 102, 103 that reference the event inversely.

**Repair dry-run:** would run `TicketTierLifecycleService::reconcileEventTicketReferences()` (`reconcile_event_ticket_references`).

**TASK 21 decision:** **`--apply` not run** — operator may run after manual verification:

```bash
ddev drush mel:tickets:repair --event=1592 --apply
```

Only after confirming no orphan/product ambiguity in their environment and that merging inverse rows is desired for local smoke data.

---

## Appendix C — Event 1094 commands (clean baseline)

```bash
ddev drush mel:tickets:audit --event=1094
ddev drush mel:tickets:repair --event=1094
```

**Expected:** 0 errors / 0 warnings; optional info on ignored inactive inverse tiers; repair reports nothing to apply.
