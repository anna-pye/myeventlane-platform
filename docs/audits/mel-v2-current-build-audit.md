# MEL v2 — current build read-only audit

**Date:** 2026-04-27  
**Branch:** `feature/mel-v2-task-based-completion-audit` (at time of audit)  
**Method:** read-only: Drush, repo inspection, one runtime route match in DDEV, no `cex`, no fixes, no merges.  
**Scope note:** This documents **what the codebase and environment report**. Functional QA (browsers, payments, real Stripe) was **not** performed.

---

## 1. Git and environment

| Check | Result |
|--------|--------|
| current branch | `feature/mel-v2-task-based-completion-audit` |
| latest commit | `477d9a4d Merge pull request #203 from anna-pye/stripe-intent-type` |
| working tree | **clean** (`git status --short` empty) |
| `composer validate` | **pass** — `./composer.json is valid` |
| `ddev drush status` | **pass** — Drupal **11.3.8**, DB connected, bootstrap successful |
| default theme | `myeventlane_theme` |
| admin theme | `gin` |
| `ddev drush theme:status` | **failed** — *Command "theme:status" is not defined* (suggested: `config:status`, `theme:enable`, etc.) |

**Enabled MEL custom modules (representative, from `ddev drush pm:list … \| grep -E "myeventlane\|mel_"`)** include the full MEL set: e.g. `myeventlane_core` … `myeventlane_webhooks`, `mel_admin_dashboard` / `mel_ticket` as shown in Drush output; **no attempt** to list every sub-module name token that wrapped on one line. Key commerce/help/event modules: `myeventlane_commerce`, `myeventlane_rsvp`, `myeventlane_checkout_flow`, `myeventlane_checkout_paragraph`, `myeventlane_event_studio`, `myeventlane_vendor`, `myeventlane_stripe`, `myeventlane_help_assistant`, `myeventlane_help_centre*`, `myeventlane_staff_playbooks*`, etc.

**Prioritised findings (§1):**

- **P2:** `ddev drush theme:status` is not available on this Drush; use `drush config:get system.theme` or UI if theme status is needed.  
- **P3:** Local `npm` warnings re `devdir` during `mel:lint` / `mel:build` (no task fix).

---

## 2. Routes (names + file locations or config source)

Sources: `ddev drush route \| grep …` (sample), `myeventlane_*.routing.yml` files, `config/sync/views.*.yml` for Views pages, and **runtime** route match in DDEV for `/home` and a few paths.

| Area | Route name(s) | Path / note | File / config |
|------|---------------|-------------|---------------|
| **Homepage / discovery** | `view.frontpage.page_1` | **`/home`** (Views) | `system.site` front = `/home`; `config/sync/system.site.yml`; View `frontpage` display `page_1` |
| **/events** | `view.upcoming_events.page_events` | **`/events`** | `config/sync/views.view.upcoming_events.yml` (display `page_events`, `path: events`); view description: canonical public discovery |
| **Category** | `view.upcoming_events.page_category` (and block displays) | **`/events/category/%** (taxonomy in path)** | same view file; `path: events/category/%` |
| **Event full** | `entity.node.canonical` | Resolves to node path (often pathauto) — e.g. `/node/{id}` or alias; not hard-coded in one custom `routing.yml` for all | Core + Pathauto; bundle `event` |
| **Event booking (unified)** | `myeventlane_commerce.event_book` | **`/event/{node}/book`** | `web/modules/custom/myeventlane_commerce/myeventlane_commerce.routing.yml` |
| **RSVP → book redirect** | `myeventlane_rsvp.public_rsvp_form` | **`/event/{event}/rsvp`** | `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml` **→ `RsvpRedirectController::redirectToBooking`** |
| **RSVP form / thank you** | `myeventlane_rsvp.form`, `myeventlane_rsvp.thankyou` | `/event/{node}/rsvp/form`, `/event/{event}/rsvp/thank-you` | same file |
| **Paid ticket (Commerce)** | `commerce_checkout.*`, `commerce_checkout.form` | **`/checkout`**, **`/checkout/{commerce_order}/{step}`** | Contrib; step includes payment and completion |
| **Confirmation** | `commerce_checkout.form` (step) | part of **checkout** flow — e.g. complete step on order | See checkout flow `mel_event_checkout` in config (below) |
| **Vendor dashboard** | `myeventlane_vendor.console.dashboard` (matched) | **`/vendor/dashboard`** | `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml` — route key near `path: '/vendor/dashboard'` |
| **Event Studio create** | `myeventlane_event_studio.create` | **`/vendor/events/create`** | `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml` |
| **Event Studio edit (wizard)** | `myeventlane_event_studio.edit`, `…edit_basic`, `…tickets`, etc. | `/vendor/events/{node}/edit`, sub-steps | same |
| **Stripe Connect onboarding** | `myeventlane_vendor.stripe_connect` | **`/stripe/connect`** | `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml` — `StripeConnectController::connect` |
| **Stripe callback** | `myeventlane_vendor.stripe_callback` | **`/stripe/connect/callback`** | same, `::callback` |
| **(Additional)** | `myeventlane_vendor.*` | `/vendor/onboard/stripe-return`, `/vendor/onboard/stripe-refresh` | same file (onboarding return URLs) |
| **Help centre** | `myeventlane_help_centre.home` etc. | `/help`, `/help/index`, `/help/vendors`, etc. | `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.routing.yml` |
| **Help assistant** | `myeventlane_help_assistant.page` / `ask` | **`/help/assistant`** (GET + POST) | `web/modules/custom/myeventlane_help_assistant/myeventlane_help_assistant.routing.yml` |
| **Staff playbooks (example)** | `myeventlane_staff_playbooks.governance_dashboard` | **`/admin/myeventlane/governance`** (permission: `administer escalations`) | `web/modules/custom/myeventlane_staff_playbooks/myeventlane_staff_playbooks.routing.yml` — **not** exhaustive of all staff routes |

**Runtime checks (DDEV, router):**

- `/home` → `view.frontpage.page_1` (Views `ViewPageController`).  
- `/events` → `view.upcoming_events.page_events`.  
- `/checkout` → `commerce_checkout.checkout`.  
- `/vendor/dashboard` → `myeventlane_vendor.console.dashboard`.  

`ddev drush route` is **huge**; the pipeline returned exit **141** (pipe closed / `head`).

---

## 3. Event Studio (audit)

| Topic | Notes |
|-------|--------|
| **Create** | `myeventlane_event_studio.create` → `EventStudioController::buildCreate` at `/vendor/events/create` |
| **Edit** | `myeventlane_event_studio.edit` + sub-routes (basic, datetime, tickets, description, preview, publish) under `/vendor/events/{node}/edit/...` |
| **Access** | Create: `myeventlane_vendor.access.vendor_console:access` (routing); steps: `node:update` |
| **Ticket builder** | `EventStudioTicketsForm` at `…/edit/tickets` |
| **RSVP / paid save paths** | **RSVP** uses redirect to **unified book** `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml` — paid flows go through **Commerce** cart and **mel_event_checkout** (separate from Studio save in normal checkout). **Studio** saves tickets through domain forms; exact sync to Commerce product/variation is in **form/service** code (not re-read in full in this pass). **No code edits** in this task. |
| **Commerce product/variation sync** | Pushed to `myeventlane_tickets` / `myeventlane_commerce` (see project `project-rules.md` references); full trace = Task later if needed. |
| **AJAX** | `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml` — `autosave` POST, AI POST, ticket suggestions POST with CSRF header requirements. |
| **Libraries** | `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.libraries.yml` — `mel_event_studio` (CSS+JS+location autocomplete); shell-only variant. |
| **Active theme on vendor** | `myeventlane_vendor_theme` (watchdog sample: *Vendor isolation active on /vendor/dashboard with theme myeventlane_vendor_theme*) |

**Prioritised (§3):**

- **P2:** **Two** vendor event editing surfaces in routing exist — **Event Studio** (`/vendor/events/.../edit/...`) and **legacy** `/vendor/events/{event}/build/...` wizard in `web/modules/custom/myeventlane_event/myeventlane_event.routing.yml` — risk of **operator confusion** if not documented.  
- **P2:** Parallel **old** editor paths like `/vendor/event/{event}/...` in same vendor file — product should confirm canonical UX.

---

## 4. Booking and checkout

| Topic | Notes |
|-------|--------|
| **Free RSVP path** | `myeventlane_rsvp` routes + redirect to `myeventlane_commerce.event_book` (unify with paid booking surface). |
| **Paid** | `BookController` + `commerce_checkout` + flow **`mel_event_checkout`**. |
| **Checkout flow plugin** | `config/sync/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml` — `plugin: mel_event_checkout`, panes: `mel_buyer_details`, `ticket_holder_paragraph`, `mel_donation`, `mel_legal_consent`, `payment_information` (…), order summary in sidebar, several panes disabled. |
| **Payment / summary** | `payment_information` step `checkout` weight 4; `order_summary` in `_sidebar` with `commerce_checkout_order_summary` view. |
| **Ticket holder** | `ticket_holder_paragraph` (Commerce checkout pane) — dedicated attendee handling also exists at `myeventlane_commerce` routes like `/cart/attendee-info/{order_item}`. |
| **Confirmation** | **Commerce** checkout `complete` (or custom completion) is **on the `commerce_checkout.form` step**; exact template/pane = theme + flow config. |
| **Email / tickets** | Not fully traced (queues, rules, `myeventlane_tickets`); would need Task focused on comms. |

**Prioritised (§4):**

- **P1:** `payment_information` in sync config has `require_payment_method: false` — may be **intentional** for $0, but **validates in staging** for mixed paid/RSVP.  
- **P2:** Several panes on `_disabled` — **matches** a deliberate layout; do not "fix" without product sign-off.  
- **P3:** Guest flags `guest_new_account: true` — confirm with privacy/help copy.

---

## 5. Vendor dashboard

| Topic | Notes |
|-------|--------|
| **Entry** | `/vendor/dashboard` — `myeventlane_vendor` console. |
| **Attendees (global)** | `myeventlane_checkout_flow.vendor_attendees` — `/vendor/attendees` (controller access check). |
| **Menu / link** | Not re-audited menu YAML in this pass; `myeventlane_vendor` defines many **vendor** and **/dashboard** fallbacks. |
| **Attendee data / CSV** | `web/modules/custom/myeventlane_event_attendees/myeventlane_event_attendees.routing.yml` — `/vendor/events/{node}/attendees/export` etc.; `myeventlane_views` has `AttendeeCsvController` with access notes in class docblock. |
| **Admin/staff** | MEL **admin** dashboard: `myeventlane_admin_dashboard` routes (orders, financials, payouts, `admin/myeventlane`, webhooks) — see Drush `route` output prefix `myeventlane_admin_dashboard.`. |
| **Analytics** | `myeventlane_analytics.dashboard` — `/vendor/analytics`, per-event under `/vendor/analytics/event/{node}`. |

**Prioritised (§5):**

- **P2:** Multiple overlapping routes for vendor event management (Studio vs “studio” API vs “event” path) — **governance/UX** risk.  
- **P1:** Payout/stripe **admin** paths and `/stripe/webhook/payout` exist — **operational** security and signing must be part of **Task 3**.

---

## 6. Stripe Connect (recon only — not full audit)

| Topic | Notes |
|-------|--------|
| **Controllers** | `web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php` — `connect`, `callback`, plus return routes in `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml` |
| **Service layer** | `myeventlane_stripe` (and `StripeService` as referenced in branch log messages on `fix/stripe-connect` — not diffed in this worktree) |
| **Store relationship** | Vendor ↔ Commerce store mapping lives in MEL custom modules; exact field names: **out of scope** for this pass (no schema edits). |
| **connect account storage** | Typically on vendor/connector entity/fields — **confirm in Task 3** with `services.yml` + install entity definitions. |
| **Onboarding loop** | `fix/stripe-connect` branch log mentions **onboarding** improvements and `ApiErrorException` handling; **this audit branch** does not include the latest 3 commits from `fix/stripe-connect` (see below). |
| **fix/stripe-connect vs this branch** | `git log feature/mel-v2-task-based-completion-audit..fix/stripe-connect` shows: `e0653f59` (connect API failure handling in controller), `37851723` (StripeService / express / logging), `af70f4c0` (integration + user feedback) — **not in** current feature branch. |
| **Dedicated full audit** | **Yes, recommended** — current pass is only mapping and divergence note. |

**Prioritised (§6):**

- **P1 (merge/product):** `feature/mel-v2-task-based-completion-audit` is **behind** `fix/stripe-connect` by **3** commits; Stripe behaviour and operator messaging **differ** until integrated.  
- **P0:** None proven without runtime Stripe tests — do **not** list as launch blocker on evidence above alone.

---

## 7. Help and AI support

| Topic | Evidence |
|--------|------------|
| **help_article** | `node.type` + fields in `config/sync`; `field.storage.node.field_audience` and usage on `help_article` and landing types (grep on sync). |
| **field_audience** | In Search API: `config/sync/search_api.index.mel_content.yml` — indexed as `field_audience` (help audience canonical). |
| **staff_playbook** | Content type `config/sync/node.type.staff_playbook.yml` + `field_internal_only` and related fields; **not** listed in `mel_content` content bundle grep as `staff_playbook` in the same index snippet. |
| **Help Assistant** | `web/modules/custom/myeventlane_help_assistant/src/Service/HelpRetriever.php` — `type = help_article` only; `field_audience` filtered to public/vendor; **rejects** `staff`; requires `field_help_ai_allowed` and status checks. |
| **Search API** | Index id **`mel_content`**; query restricts by type and audience. |
| **Staff leak** | **Code path** in HelpRetriever explicitly **excludes** staff-tagged and non-public/vendor content for assistant retrieval; **separate** risk: Views/routes to staff playbooks must stay permission-gated (not fully audited here). |

**Prioritised (§7):**

- **P1:** Re-verify any **View** or **search** display that might expose `help_article` with staff audience to the wrong role (theme-only hiding is not enough) — **spot-check** in Task follow-up, not in this file’s scope.  
- **P2:** `mel_debug` and similar **verbose** log entries in watchdog (not help-specific but noisy).

---

## 8. Theme and frontend (recon)

| Area | Notes |
|------|--------|
| **Event cards / full / home** | Large SCSS/twig in `web/themes/custom/myeventlane_theme` (e.g. `components/_event-*.scss`, `node--event--full.html.twig`) — **no line-by-line** review. |
| **Discovery / filters** | `web/modules/custom/myeventlane_core/myeventlane_core.routing.yml` — `/mel/filter-events` (AJAX fragment) + Views `config/sync/views.view.upcoming_events.yml` for `/events`, `events/today`, `events/free`, etc. |
| **Category** | `/events/category/...` from same view. |
| **Checkout** | Default Commerce checkout theme behaviour + MEL panes; vendor checkout questions under vendor routes. |
| **Vendor / Studio** | `myeventlane_vendor_theme` (Vite in `package.json`); **Event Studio** CSS/JS in module + vendor theme. |
| **Break / duplicate styles** | Public theme main bundle ~**567 kB** gzip ~85 kB (build output) — **performance** and cascade risks worth profiling later. |
| **Accessibility** | Not **audited** with tools in this pass — **P2** to run axe/keyboard in a dedicated a11y task. |

**Prioritised (§8):**

- **P2:** CSS bundle size and two-theme split (public vs vendor) — follow performance budget.  
- **P2:** `mel:lint` covers a **fixed** list of SCSS files; new partials not in the list are **unlinted** (known pattern).

---

## Commands run (required + supporting)

- `git status --short` — **clean**  
- `git branch --show-current`  
- `git log -1 --oneline`  
- `composer validate` — **ok**  
- `ddev drush status` — **ok**  
- `ddev drush theme:status \|\| true` — **failed** (see §1)  
- `ddev drush pm:list --type=module --status=enabled \| grep -E "myeventlane\|mel_"`
- `ddev drush route \| grep -Ei "…" \| head -200` — **exit 141** (truncation/pipe)  
- `npm run mel:lint \|\| true` — **pass** (Stylelint; npm `devdir` notice)  
- `npm run mel:build \|\| true` — **pass**  
- `ddev drush ws --count=50` — **pass** (recent notices)  
- Supporting: `ddev drush ev` for route match on `/home`, `/events`, `/vendor/dashboard`, and `git log feature/…..fix/stripe-connect`

**Failed (recorded, not “fixed”):** `ddev drush theme:status` — *Command "theme:status" is not defined.*

**Watchdog (sample, last 50):** Mostly `mel_debug` (BOOST CANDIDATE) and `mel_admin_access_debug` / `mel_theme_debug` (vendor dashboard). **P2:** reduce debug noise in production configs.

---

## Global prioritised summary

| ID | Level | Item |
|----|-------|------|
| 1 | **P1** | `fix/stripe-connect` has **3 commits** not on this audit branch — Stripe/onboarding and `StripeService` work **diverge**; needs merge decision + **Task 3** full audit. |
| 2 | **P1** | `mel_event_checkout` has `require_payment_method: false` on payment pane — **verify** for paid events before launch. |
| 3 | **P2** | Overlapping vendor event routes (Event Studio, wizard, legacy “event/…”) — document canonical paths. |
| 4 | **P2** | Drush 13 has no `theme:status` — use alternate commands. |
| 5 | **P2** | Noisy `mel_debug` in watchdog. |
| 6 | **P2** | Large public CSS output + SCSS lint allowlist. |
| 7 | **P3** | npm audit **moderate** in theme deps. |

**P0 (launch blocker) from this read-only pass:** **None established** (no production smoke, no failed core bootstrap, no payment E2E).

---

## Recommended next task

**TASK 3 — Full Stripe Connect audit only** (controllers, `StripeService`, store/account linkage, webhooks, `charges_enabled` / `requirements` gates, `fix/stripe-connect` diff vs this branch, and a written test matrix; **no** implementation in Task 3 unless the audit explicitly defers to a “fix” task).

STOP — Task 2 only.
