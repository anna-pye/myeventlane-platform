# MEL v2 — Current build audit

**Date:** 29 April 2026  
**Scope:** Read-only snapshot of the repository and local DDEV site (`https://myeventlane.ddev.site`). No code changes were made during this audit.

**Plan (executed):** Capture git/environment state, run the mandated CLI checks, extract routes via Drush and routing YAML, then cross-check critical subsystems (Event Studio, checkout, vendor console, Stripe Connect, help/AI, themes) against source and config. Findings are **evidence-based**; where behaviour was not exercised in a browser, it is called out as an **assumption** to verify manually.

---

## 1. Git and environment

| Item | Value |
|------|--------|
| **Current branch** | `cursor/use-vendor-query-6161b` |
| **Latest commit** | `44e4de07` — *Refactor TicketSalesService to utilize UserVendorMembershipQuery for event management. Removed deprecated methods and updated revenue calculations to improve clarity and maintainability.* |
| **Working tree** | **Clean** (`git status --short` produced no output) |
| **Composer** | `composer validate` — **valid** |
| **Drupal (drush status)** | **11.3.8**; bootstrap successful; DB connected; site URI `https://myeventlane.ddev.site`; PHP **8.3.23**; config `config/sync` |
| **Default (frontend) theme** | `myeventlane_theme` |
| **Admin theme** | `gin` |
| **Drush** | 13.7.2.0 |

**Enabled custom MEL modules (from `ddev drush pm:list --type=module --status=enabled | grep -E "myeventlane|mel_"`):** a large set is enabled, including (non-exhaustive): `mel_ticket`, `myeventlane_account` through `myeventlane_webhooks` per the Drush listing (see command log). Core platform modules such as `myeventlane_commerce`, `myeventlane_checkout_flow`, `myeventlane_event_studio`, `myeventlane_rsvp`, `myeventlane_stripe`, `myeventlane_vendor`, `myeventlane_help_centre`, `myeventlane_help_assistant`, `myeventlane_staff_playbooks`, and related MEL feature modules are **enabled** in this environment.

**Note:** `ddev drush theme:status` is **not a defined Drush command** in this project’s Drush (see failed commands below). Theme source of truth for this audit is `drush status` (default/admin theme fields).

---

## 2. Routes (Drush + routing reference)

Paths below are from `ddev drush route | grep -E "event|vendor|checkout|help|stripe|rsvp|ticket"` and custom `*.routing.yml` where noted. Standard Drupal entity routes (e.g. `entity.node.canonical` for event nodes) apply unless path aliases override.

| Area | Route name (representative) | Path |
|------|----------------------------|------|
| **Homepage / discovery** | `view.frontpage.page_1` | `/home` (matches `system.site: front: /home` in `config/sync/system.site.yml`) |
| **Event filter (AJAX/API-style)** | `myeventlane_core.event_filter` | `/mel/filter-events` |
| **Events listing (views)** | `view.upcoming_events.page_events` | `/events/{arg_0}` |
| **Category / time-sliced lists** | `view.upcoming_events.page_category` | `/events/category/{arg_0}` |
| | `view.upcoming_events.page_today` | `/events/today/{arg_0}` |
| | `view.upcoming_events.page_this_weekend` | `/events/this-weekend/{arg_0}` |
| | `view.upcoming_events.page_free` | `/events/free/{arg_0}` |
| **Event full page** | `entity.node.canonical` (event bundle) | `/node/{node}` *default*; public URL typically via **path alias** (not expanded in this audit) |
| **Event booking (commerce)** | `myeventlane_commerce.event_book` | `/event/{node}/book` |
| **RSVP booking** | `myeventlane_rsvp.public_rsvp_form` | `/event/{event}/rsvp` |
| | `myeventlane_rsvp.form` | `/event/{node}/rsvp/form` |
| | `myeventlane_rsvp.thankyou` | `/event/{event}/rsvp/thank-you` |
| **Paid ticket path** | Cart + checkout: `commerce_cart.*` (not in grep); checkout | `commerce_checkout.checkout` | `/checkout` |
| | `commerce_checkout.form` | `/checkout/{commerce_order}/{step}` |
| **Confirmation / post-purchase** | `myeventlane_checkout_flow.my_tickets` | `/my-tickets` |
| | `myeventlane_checkout_flow.order_detail` | `/my-tickets/order/{commerce_order}` |
| | Commerce checkout **complete** step uses `commerce_checkout.form` with `step` = `complete` (standard Commerce) |
| **Vendor dashboard** | `myeventlane_vendor.console.dashboard` | `/vendor/dashboard` |
| | `myeventlane_vendor.shell.vendor_root` | `/vendor` |
| | `myeventlane_vendor.shell.dashboard` | `/dashboard` (alias-style entry) |
| **Event Studio create** | `myeventlane_event_studio.create` | `/vendor/events/create` |
| **Event Studio edit** | `myeventlane_event_studio.edit` | `/vendor/events/{node}/edit` |
| | Sub-routes | `/vendor/events/{node}/edit/basic`, `…/datetime`, `…/tickets`, `…/description`, `…/preview`, `…/publish` |
| **Legacy / parallel vendor event UI** | `myeventlane_vendor.manage_event.*`, `myeventlane_event.wizard.*` | e.g. `/vendor/event/{event}/edit`, `/vendor/events/{event}/build/*` — **multiple Event UIs coexist**; product choice of “canonical” editor is a **process** question |
| **Stripe Connect onboarding** | `myeventlane_vendor.stripe_connect` | `/stripe/connect` |
| | `myeventlane_vendor.stripe_onboard_refresh` | `/vendor/onboard/stripe-refresh` |
| | Onboarding steps | `/vendor/onboard/stripe`, etc. (`myeventlane_vendor.onboard` family) |
| **Stripe callback** | `myeventlane_vendor.stripe_callback` | `/stripe/callback` |
| | `myeventlane_vendor.stripe_callback_legacy` | `/stripe/connect/callback` |
| | `myeventlane_vendor.stripe_onboard_return` | `/vendor/onboard/stripe-return` (also uses callback controller) |
| | `myeventlane_vendor.stripe_manage` | `/stripe/manage` |
| **Commerce Stripe (gateway OAuth)** | `commerce_stripe.connect.oauth_return` | `/stripe-connect/oauth/return/{commerce_payment_gateway}` — **admin/gateway** flow, distinct from vendor Connect |
| **Help centre** | `myeventlane_help_centre.home` | `/help` |
| | | `/help/index`, `/help/search`, `/help/category/{category}`, audience hubs `/help/attendees`, `/help/organisers`, `/help/vendors`, `/help/policies` |
| | Vendor-scoped | `/vendor/help`, `/vendor/help/topic/{category}` |
| **Help assistant** | `myeventlane_help_assistant.page`, `myeventlane_help_assistant.ask` | `/help/assistant` |
| **Help Centre AI ask** | `myeventlane_help_centre_ai.ask` | `/help/ask` |
| **Staff playbooks** | `myeventlane_staff_playbooks.governance_dashboard` | `/admin/myeventlane/governance` |
| | AI summary | `myeventlane_staff_playbooks_ai.summary` | `/admin/myeventlane/playbooks/{node}/ai/summary` |

---

## 3. Event Studio

| Topic | Finding |
|-------|---------|
| **Create route** | `/vendor/events/create` — `_custom_access: 'myeventlane_vendor.access.vendor_console:access'` plus `access content` (`myeventlane_event_studio.routing.yml`). |
| **Edit route** | `/vendor/events/{node}/edit` — `_entity_access: 'node.update'`; integer node parameter. |
| **Ticket builder** | `EventStudioTicketsForm` attaches `myeventlane_vendor/ticket_cards` and `core/drupal.ajax`; actions delegated to `EventTicketsBuilder::handleAction` (AJAX rebuild pattern). |
| **RSVP / paid ticket save** | `EventStudioSaveService` persists node; `MelTicketTypeManager::onEventStudioSaveComplete` runs after non-draft save — reconciles tiers, `applyStudioTierRows`, then `syncCommerceAndPublishCatalogSignal` (Commerce sync for paid/both). Errors logged on sync failure. |
| **Commerce product/variation sync** | Implemented via `MelTicketTypeManager::syncCommerceAndPublishCatalogSignal` → `ticketTierLifecycle->syncPaidTiers` for `paid` / `both` event types. |
| **AJAX submit** | Ticket tab uses AJAX callbacks consistent with `EventStudioForm` contract (`handleAction` reloads node into form state). Autosave: POST `/vendor/events/autosave`. AI endpoints: POST with CSRF header requirement on routes (see routing comments). |
| **Libraries** | `myeventlane_event_studio.libraries.yml`: `mel_event_studio` (CSS `mel-event-studio*.css`, JS `mel-event-studio.js`, deps `address_autocomplete`); `mel_event_studio_shell_only` for shell without full wizard JS. |
| **Theme on vendor routes** | `VendorThemePagePreprocess` / vendor console pipeline (see `myeventlane_vendor.services.yml`). Watchdog sample shows **Vendor isolation** with theme **`myeventlane_vendor_theme`** on `/vendor/dashboard`. |
| **Styles source** | Event Studio CSS ships from **module** (`myeventlane_event_studio/css/...`) with explicit weight comments for cascade vs vendor theme globals. Vendor ticket cards library referenced from vendor module. **Assumption:** verify Event Studio pages attach `mel_event_studio` on all edit tabs in browser (not every sub-route re-checked line-by-line here). |

---

## 4. Booking and checkout

| Topic | Finding |
|-------|---------|
| **Free RSVP path** | Primary public flow: `/event/{event}/rsvp` → thank-you `/event/{event}/rsvp/thank-you`. |
| **Paid ticket path** | Booking entry `/event/{node}/book` → Commerce cart → `/checkout` → `/checkout/{commerce_order}/{step}`. |
| **Checkout flow plugin** | Config `commerce_checkout.commerce_checkout_flow.mel_event_checkout`: plugin **`mel_event_checkout`** (`MelEventCheckoutFlow`). Default order type ties to this flow (`commerce_order.commerce_order_type.default` third-party settings). |
| **Payment pane** | Panes include `payment_information` on step `checkout` with `always_display: true`, `require_payment_method: false` — **zero-balance orders may still show payment UI** unless Commerce/core hides it at runtime (behaviour to verify with a $0 cart). |
| **Order summary** | Sidebar: `order_summary` view `commerce_checkout_order_summary`; duplicate panes disabled per install/post-update (`grouped_order_summary`, `mel_fee_transparency` → `_disabled`). |
| **Ticket holder info** | Pane `ticket_holder_paragraph` (“Attendee details”) on main checkout step. |
| **Confirmation** | Commerce checkout **complete** step on `/checkout/{order}/complete`; order viewing also via `/my-tickets` and `/my-tickets/order/{commerce_order}`. |
| **Ticket issuing** | Routes under `myeventlane_tickets` (PDF download, scan, check-in API) — see Drush route list. |
| **Email confirmation** | `myeventlane_messaging` provides templates and queue (`order_confirmation`, `rsvp_confirmation` per module tests/docs); staff resend route `myeventlane_messaging.resend_order_confirmation`. **Operational status** (queues/cron) not verified in this audit. |

---

## 5. Vendor dashboard

| Topic | Finding |
|-------|---------|
| **Access checks** | Central service `myeventlane_vendor.access.vendor_console` (`VendorConsoleAccess`) — used broadly for vendor console routes; integrates onboarding + trusted staff bypass via `VendorConsoleTrust` (see class docblocks). |
| **Menu / dropdown** | Not traced UI-by-UI in this audit; routes exist for `/vendor/dashboard`, `/vendor/events`, settings, etc. |
| **Vendor-only visibility** | Relies on route `_custom_access` and entity access for events (e.g. `VendorConsoleBaseController` patterns). **Manual QA** recommended for cross-vendor boundaries. |
| **Admin/staff access** | `VendorConsoleTrust::accountIsTrustedForVendorConsole` allows elevated accounts without weakening entity checks on sensitive operations — verify parity with product policy. |
| **Attendee data boundaries** | Vendor attendee APIs and exports: e.g. `myeventlane_api.vendor.attendees.*`, `/vendor/events/{node}/attendees`, `/api/v1/vendor/events/{node}/exports/csv`, `/vendor/export-attendees/{event}/download`. **Server-side enforcement** must be validated per endpoint (audit scope: inventory only). |
| **RSVP CSV export** | `myeventlane_rsvp.export_csv` → `/vendor/event/{event}/rsvps/export`. |
| **Sales / analytics** | `/vendor/analytics`, `/vendor/events/{event}/analytics`, reporting charts under `/vendor/charts/...`, insights under `/vendor/events/{event}/insights`. |

---

## 6. Stripe Connect

| Topic | Finding |
|-------|---------|
| **Controller** | `StripeConnectController` — `connect`, `callback`, `manage`; uses `StripeService`, `UserVendorMembershipQuery`, `VendorStoreSubscriber`. |
| **Callback** | Routes `/stripe/callback`, `/stripe/connect/callback`, `/vendor/onboard/stripe-return` → same controller methods; **`_access: 'TRUE'`** on callbacks — relies on internal validation (account vs store) rather than login-only route gate. |
| **Vendor profile link** | Resolves vendor via membership query; prefers vendor with `field_vendor_store` populated. |
| **Store creation/linking** | `getStoreForConnect` uses `VendorStoreSubscriber::ensureStoreForVendor`; `syncVendorStoreReference` writes store ID to vendor. |
| **Connected account storage** | Stripe IDs/status synced to Commerce store fields and mirrored to vendor fields when present (`syncStripeAccountFieldsToVendor`). |
| **Error logging** | Uses `logger.factory` channels (`myeventlane_vendor`); API errors logged with masked IDs in places (`StripeService::maskAccountId`). |
| **Onboarding loop risk** | Refresh URL points to `stripe_connect`; return URL to `stripe_callback`. If Stripe returns repeatedly without `charges_enabled`, user may see repeated redirects — **product/UX risk**, not proven failure in this audit. |
| **Existing connected vendors** | Code path short-circuits when account ID exists and `charges_enabled` — message “already connected” (`connect()`). Mismatch between query `account_id` and store logs error and aborts. **Assumption:** production monitoring should confirm no regressions after deploy. |

---

## 7. Help and AI support

| Topic | Finding |
|-------|---------|
| **help_article fields** | Config references include `field_audience`, `field_help_article_type`, `field_help_summary`, `field_help_keywords`, `field_help_topic`, `field_help_status`, `field_featured_help`, `field_related_help_articles`, etc. (see `config/sync` and views). |
| **field_audience** | Used in views (`mel_help_articles_by_audience`, organiser/vendor help views) and indexed on **Search API** `mel_content` as `field_audience`. |
| **staff_playbook** | Content type `staff_playbook` with dedicated fields (priority, reply snippets, internal-only, AI summary, etc. per module install config). |
| **Access control** | `myeventlane_staff_playbooks.module` implements `node_access` / create access so **staff-only** creation and restricted view for playbook nodes. |
| **Search API index** | **`mel_content`** indexes help-related fields and events; bundle selection configurable per index config. Separate indexes: `mel_categories`, `mel_vendors`. |
| **Help Assistant retrieval** | `HelpRetriever` queries index **`mel_content`**, fulltext on title/summary/body/keywords, filters `type = help_article`, applies **audience filter** (anonymous: `public`; authenticated: `public` + `vendor`); excludes `staff` in code paths; requires `field_help_ai_allowed`, documentation status, node access. |
| **Vendor AI retrieval** | `UnifiedHelpRetriever` (preferred per deprecation notice on legacy `HelpArticleRetriever`) used from `VendorAiAssistantForm`; legacy class queries nodes with `accessCheck(FALSE)` then maps — **review unified path for parity** (vendor AI uses unified retriever in submit path per current code). |
| **Staff-only leak risk** | Help Assistant explicitly filters staff audience and rejects staff-tagged nodes in `nodeAudienceAllowedForAssistant`. Staff playbooks are separate content type with admin routes — **lower risk** if routes remain admin-only; Help Centre AI route `/help/ask` requires `access myeventlane help assistant` permission. |

---

## 8. Theme and frontend

| Topic | Finding |
|-------|---------|
| **Event cards / full / discovery** | Primary SCSS under `myeventlane_theme` includes `_event-card.scss`, `_event-full.scss`, `_event-hero.scss`, `_event-book.scss`; discovery filters tied to `/mel/filter-events` + views. |
| **Category pages** | Views-driven URLs under `/events/category/...` and related upcoming_events variants. |
| **Checkout** | Classes `mel-checkout-single-page`, `mel-checkout-flow-mel-event` applied in `MelEventCheckoutFlow::buildForm`. |
| **Vendor dashboard** | Built with `myeventlane_vendor_theme` per runtime logs; separate Vite build (`mel:build` builds both themes). |
| **Event Studio** | Module CSS + vendor theme; ticket builder pulls `myeventlane_vendor/ticket_cards`. |
| **Mobile breakpoints** | Theme uses shared `tokens/breakpoints` and mixed `@media` (e.g. 900px, 600px, 480px in wizard-related SCSS) — **multiple breakpoint sources** → risk of inconsistent spacing between components. |
| **Accessibility risks** | Not systematically tested in this audit; AJAX-heavy Studio and checkout warrant axe/keyboard passes before launch. |
| **Duplicate/competing styles** | Overlap between module-attached CSS (`mel-event-studio*.css`) and theme bundles; vendor theme `main.css` ~326 kB gzip ~46 kB — watch specificity and load order. |

---

## Prioritised findings

### P0 — Launch blockers

_None identified from read-only commands and static review alone._ Remaining launch risk is **unverified** runtime behaviour (payments, emails, Stripe live keys, access control edge cases).

### P1 — Important

1. **Checkout payment pane config:** `require_payment_method: false` and `always_display: true` on `payment_information` may confuse users or expose unnecessary UI for free orders — **verify** paid vs free vs donation scenarios in QA.
2. **Parallel Event UIs:** Routes for Event Studio, vendor workspace, and legacy `/vendor/event/{event}/build/*` wizard coexist — risk of **documentation/training drift** and inconsistent publish gates.
3. **Stripe callback routes open (`_access: TRUE`):** Mitigated by controller logic but warrants **security review** and penetration-style retest (session fixation, CSRF, parameter tampering).

### P2 — Polish

1. **`npm run mel:lint`:** Stylelint phase **killed with signal 9** (see failed commands) — local OOM/sandbox; fix CI/local lint reliability before relying on it as a gate.
2. **Watchdog noise:** Recent logs show many `mel_debug` “BOOST CANDIDATE” notices and `myeventlane_domain_events` “Projection miss” debug entries — may impact log signal-to-noise in production if debug logging is enabled.
3. **`ddev drush theme:status` unavailable** — use `drush status` or config for theme reporting in runbooks.

### P3 — Later

1. Deprecated `HelpArticleRetriever` still present; ensure all call sites use `UnifiedHelpRetriever`.
2. Consolidate breakpoint tokens vs ad hoc `@media` widths for long-term maintainability.

---

## Commands run

| Command | Result |
|---------|--------|
| `git status --short` | Clean |
| `git branch --show-current` | `cursor/use-vendor-query-6161b` |
| `git log -1 --oneline` | `44e4de07 Refactor TicketSalesService...` |
| `composer validate` | `./composer.json is valid` |
| `ddev drush status` | Success (Drupal 11.3.8, themes gin / myeventlane_theme) |
| `ddev drush theme:status \|\| true` | **Failed** — command not defined |
| `ddev drush pm:list --type=module --status=enabled \| grep -E "myeventlane\|mel_" \|\| true` | Success (long list) |
| `ddev drush route \| grep -E "event\|vendor\|checkout\|help\|stripe\|rsvp\|ticket" \|\| true` | Success (569 matching lines in captured output) |
| `npm run mel:lint \|\| true` | **Partial failure** — `lint:css` killed (signal 9) after hero check passed |
| `npm run mel:build \|\| true` | Success — both `myeventlane_theme` and `myeventlane_vendor_theme` Vite builds completed |
| `ddev drush ws --count=50 \|\| true` | Success |

---

## Failed commands (exact)

1. **`ddev drush theme:status \|\| true`**  
   **Error:** `Command "theme:status" is not defined.` (Drush suggests other commands; exit status 1.)

2. **`npm run mel:lint`** (via `mel:lint` script)  
   **Error:** After `check:hero` and starting `lint:css`: `sh: line 1: … Killed: 9 … npm run lint:css` — process terminated (likely resource limits).

---

## Recommended Task 3

**Focus:** Close the gaps this audit could not execute end-to-end:

1. **Manual QA scripts:** RSVP vs paid booking vs donation checkout; confirm payment pane visibility and confirmation emails with cron running.
2. **Fix or replace `mel:lint` CSS step** so CI/local reliably completes (investigate OOM, reduce Stylelint scope, or split jobs).
3. **Security pass:** Stripe callbacks, vendor attendee exports/APIs, and cross-vendor access — scripted API tests + role matrix.
4. **Logging policy:** Confirm `mel_debug` / domain projection debug verbosity for production.

---

## Assumptions (confirm before relying)

1. Path aliases for events resolve to human-readable URLs (canonical entity route remains `/node/{id}` internally).
2. Queue workers for messaging/notifications run in each environment (DDEV vs staging vs prod).
3. Search API indexes are built and not stale (`mel_content` especially for Help Assistant).

---

*End of audit.*
