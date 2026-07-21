# MyEventLane Vendor Experience Independent Audit

**Date:** 2026-07-20
**Auditor:** Independent discovery audit (repository evidence only; no runtime environment was exercised)
**Scope:** End-to-end organiser ("vendor") journey — account creation through return management — on Drupal 11 / Commerce 3.
**Repository state at audit:** branch `main`, working tree dirty with untracked files (`.claude/`, `.mel-local-phpunit-backup-path.txt`, `.tmp-ace-phase3-backup-*`, `.worktrees/`, `private/wallet-dev/`, `tests/`, two `.phpunit.result.cache` files). Reported as required; not altered.

---

## 1. Executive finding

**Overall verdict: Strong core, fragmented edges.** The canonical path — action-first onboarding → Event Studio → readiness-gated publish → dashboard — is well engineered and speaks organiser language. Around it sits a ring of parallel legacy surfaces (four to five event-editing route families, four check-in/door surfaces), several confirmed permission dead ends, and one confirmed journey trap on the create path.

- **Launch risk: Moderate.** No evidence the money path (Stripe Connect → paid publish gate) is broken; it is the best-engineered part of the platform. The risk is organiser abandonment and support load from dead ends, not payment failure.
- **Most important strength:** The Event Studio publish pipeline. `EventReadinessService` + `EventStudioPublishController` produce plain-language, recoverable, server-enforced publish gating (stale-state protection, autosave-draft protection, Stripe charge-readiness enforced server-side via `PaidPublishStripeGate`).
- **Most important weakness:** Route/permission drift. Three confirmed cases where routes require permissions the `vendor` role does not hold (check-in module entirely; ticket groups/access-codes/widgets list pages; ticket email resend), producing 403 dead ends inside surfaces the organiser is invited into.
- **Can a new vendor complete the full target journey unaided?** **Mostly yes, with two qualified failures.** Stages 1–10 (account → publish → sell → manage attendees) are completable via the canonical path. Stage 11 (view performance) is only partially available without a Pro subscription. Stage 12 (return, create another event) is compromised by the single-draft resume trap: while any unpublished event exists, "Create event" resumes it instead of creating a new one, with no "start fresh" choice.
- **Confidence level: High** for routing/access/service-layer findings (read directly). **Medium** for rendered-UI and mobile findings (inferred from templates/CSS; no browser run). **Medium** for Commerce entity-permission scope findings (config read; runtime behaviour not exercised).

---

## 2. Audit scope and evidence

**Modules reviewed (primary, code read):**
`myeventlane_vendor`, `myeventlane_event_studio`, `myeventlane_core` (OnboardingManager), `myeventlane_stripe`, `myeventlane_tickets`, `myeventlane_rsvp`, `myeventlane_checkin`, `myeventlane_event_attendees`, `myeventlane_refunds`, `myeventlane_event` (wizard), `myeventlane_auth` (routing), `myeventlane_vendor_comms` (structure), `myeventlane_vendor_settings` (routing).

**Modules surveyed (structure/routing only):** `myeventlane_dashboard`, `myeventlane_boost`, `myeventlane_vendor_analytics`, `myeventlane_commerce`, `myeventlane_account`, `myeventlane_checkout_flow`, plus the full 80+ custom module inventory in `web/modules/custom/`.

**Key files read in full or substantial part:**
- Routing: `myeventlane_vendor.routing.yml` (903 lines), `myeventlane_event_studio.routing.yml`, `myeventlane_tickets.routing.yml`, `myeventlane_checkin.routing.yml`, `myeventlane_rsvp.routing.yml`, `myeventlane_event_attendees.routing.yml`, `myeventlane_refunds.routing.yml`, `myeventlane_event.routing.yml` (wizard), `myeventlane_auth.routing.yml`
- Access: `VendorConsoleAccess`, `EventTicketsAccess`, `TicketOperationsAccess`, `EventVendorAccessChecker`, `VendorConsoleBaseController::assertEventOwnership`
- Onboarding: `OnboardingManager` (1,053 lines), `VendorOnboardAccountController`, `VendorOnboardProfileForm`, `CreateEventGatewayController`
- Stripe: `StripeConnectController` (525 lines), `VendorStripeService`, `PaidPublishStripeGate`, `VendorEventStudioCreateService`
- Event Studio: `EventStudioController`, `EventStudioPublishController`, `EventReadinessService`, section plugin inventory (17 sections)
- Dashboard: `VendorDashboardController` (2,607 lines; method map + `dashboard()` body), `EventWorkspaceController`
- Config: `config/sync/user.role.vendor.yml`; permissions YAML for vendor/tickets/rsvp/checkin modules
- Frontend: template inventories and grep passes over `myeventlane_vendor_theme` SCSS/dist, `myeventlane_event_studio` templates/CSS, `myeventlane_vendor` templates/CSS

**Tests reviewed:** inventory of 112 test files across custom modules (concentrations: `myeventlane_surface` 21, `myeventlane_tickets` 14, `myeventlane_checkout_flow` 9, `myeventlane_event_studio` 7, `myeventlane_vendor` 5 incl. `VendorConsoleAccessKernelTest`).

**Documentation observed but deliberately treated as secondary:** ~90 governance/audit markdown files in `docs/` (e.g. `vendor-console-v2-*`, `event-studio-architecture.md`, `onboarding-source-of-truth-audit.md`). Where docs and code disagree, code was treated as truth.

**Areas I could not confirm from repository evidence:**
- Rendered output, actual 390 px behaviour, screen-reader behaviour (no browser session run).
- Live Stripe behaviour, webhook-driven status refresh, actual email delivery.
- Whether Commerce's `update default commerce_order` / `delete default commerce_order` permissions grant *any-order* (non-own) scope in the installed Commerce 3 beta — flagged below at medium confidence.
- Active configuration in the running database vs `config/sync` (config drift is itself a finding; see F-07).
- Whether `myeventlane_checkin` is reachable from any rendered navigation (no menu link found for it; its routes are broken regardless — F-01).

---

## 3. Current vendor journey

Reconstructed from routing + controllers. "Vendor language OK?" flags Drupal/Commerce concept leakage.

| Stage | Entry point | Vendor action | System state | Next destination | Friction | Evidence |
|---|---|---|---|---|---|---|
| 1. Create account | `/vendor/onboard` → `/vendor/onboard/account` (`VendorOnboardAccountController`) | Sign in or register (links to core `user.login` / `user.register` with `destination=/vendor/onboard/profile`) | Drupal user account | `/vendor/onboard/profile` | Step banner says "Step 1 of 7" but the flow effectively completes at step 2 (see F-08). Registration itself is stock Drupal `user.register`. | `VendorOnboardAccountController.php:47-79` |
| 2. Become organiser | `/vendor/onboard/profile` (`VendorOnboardProfileForm`) | Enter organiser name + accept Vendor Terms checkbox | `myeventlane_vendor` entity created (`ensureVendorExists`), `vendor` role granted (`ensureVendorAccess`), onboarding state → `complete`, terms audit fields written | `/vendor/events/create?mel_first_event=1` (or `destination`) | Terms checkbox has no visible link to the terms text in the form definition (F-09). Vendor entity created with placeholder name "Organiser" then renamed. | `VendorOnboardProfileForm.php:144-256`, `OnboardingManager.php:400-521` |
| 3. Business setup | No mandatory step. Store is created lazily (`VendorStoreSubscriber::ensureStoreForVendor` at Stripe connect; `resolveCommerceStoreForVendor` links by uid) | None required | `commerce_store` created/linked on demand | — | Good: organiser never sees "create a store". Store existence is implicit. | `StripeConnectController.php:78-100`, `VendorEventStudioCreateService.php:168-193` |
| 4. Connect Stripe | `/stripe/connect` (also `/vendor/onboard/stripe`); dashboard Stripe panel; publish-readiness error | Redirected to Stripe Express Account Link; returns via `/stripe/callback` (+ legacy alias) | `field_stripe_account_id`, `field_stripe_charges_enabled`, status fields on store; synced to vendor entity | Dashboard (or `destination`) | Optional until publishing paid tickets — correct model. Error copy is calm and specific. One raw infrastructure message leaks to organisers (F-15). | `StripeConnectController.php:186-436`, `VendorConsoleAccess.php:69-75` |
| 5. Create event | `/create-event` gateway → `/vendor/events/create` → creates draft node, redirects to `/vendor/events/{nid}/studio` | Click "Create event" | Draft `node.event` ("Untitled event", unpublished) created immediately | Event Studio workspace, `overview` section | **Single-draft resume trap:** if *any* unpublished event exists, both entries redirect to it — no way to start a second draft while one exists (F-03). | `CreateEventGatewayController.php:94-215`, `EventStudioController.php:62-97`, `VendorEventStudioCreateService.php:42-68` |
| 6. Add RSVP / tickets | Studio sections `tickets`, `capacity`, `questions` (`/vendor/events/{nid}/studio/tickets` etc.); ticket manager `/vendor/events/{event}/tickets` (`EventTicketManagerForm`) | Choose booking mode (`rsvp` / `paid` / `both` / `external`), define ticket types (`mel_ticket_type` entities) | Ticket type entities; capacity fields | Same workspace | Core flow works. Sub-surfaces (groups/access-codes/widgets **list** pages) 403 for the vendor role (F-02). | `myeventlane_event_studio.routing.yml:80-120`, `myeventlane_tickets.routing.yml:73-239`, `EventTicketsAccess.php` |
| 7. Review | Studio topbar readiness panel; preview at `entity.node.canonical` | Review errors/warnings/recommendations | `EventReadinessService::evaluate()` — no state change | — | Excellent: itemised "completed" list plus errors in plain language. | `EventReadinessService.php:34-112`, `EventStudioController.php:154-199` |
| 8. Publish | POST `/vendor/events/{nid}/studio/publish` (CSRF header token) | Click Publish | Node published only if readiness passes; paid events additionally server-gated on Stripe charges-enabled | Same page (JSON-driven UI update) | 409 on stale/dirty/autosave-draft states with recovery URLs — exemplary. | `EventStudioPublishController.php:46-127`, `PaidPublishStripeGate.php:37-75` |
| 9. Promote / sell | Studio `promotions` section; `/vendor/boost`; `/vendor/events/{event}/promotion` (comms form); Boost purchase flow | Optional Boost purchase, share, comms | Boost entitlements, comms queue | — | Legacy placeholder routes (`/vendor/event/{event}/promote`, `/payments`, `/comms`, `/advanced`) are declared "non-functional UX stubs" in routing comments — dead ends if reached (F-04). | `myeventlane_vendor.routing.yml:838-903` |
| 10. Manage attendees | `/vendor/events/{nid}/attendees`; door: `/vendor/events/{nid}/operations/door`; RSVP list `/vendor/events/{event}/rsvps`; CSV exports | View, check in, export | `event_attendee` entities; check-in flags | — | Works via the canonical operations surface. But **four** parallel check-in/door surfaces exist and one (`myeventlane_checkin`) is hard-broken for organisers (F-01, F-05). | `myeventlane_event_attendees.routing.yml`, `myeventlane_checkin.routing.yml`, `myeventlane_tickets.routing.yml:257-395`, `myeventlane_rsvp.routing.yml:140-188` |
| 11. View performance | Dashboard KPI strip (30-day revenue/orders/tickets/RSVPs); `/vendor/events/{event}/analytics` | Review numbers | Read models + live Stripe calls | — | Event-level analytics route requires `use pro financial analytics` + Pro access — not in the vendor role. Non-Pro organisers get dashboard KPIs only (F-11). | `myeventlane_vendor.routing.yml:621-636`, `user.role.vendor.yml`, `VendorDashboardController.php:295-537` |
| 12. Return | `/dashboard` and `/vendor` → `entrypointRedirect`; `/vendor/dashboard`; `/vendor/events` list with status/attention flags | Find events, continue drafts, act on alerts | — | — | Events index is genuinely organiser-shaped (status, "needs attention", filters). Weakened by F-03 (create trap) and dashboard weight (F-10). | `VendorEventIndexViewModelBuilder.php`, `myeventlane_vendor.links.menu.yml` |

---

## 4. Vendor capability map

| Capability | Exists | Complete | Discoverable | Mobile-ready | Vendor language | Verdict |
|---|---|---|---|---|---|---|
| Account creation / login (incl. dual-domain SSO) | ✅ | ✅ | ✅ | Unverified | ✅ | Keep |
| Organiser onboarding (profile + terms) | ✅ | ✅ | ✅ | Unverified | ✅ | Keep; fix step count + terms link |
| Stripe Connect Express onboarding | ✅ | ✅ | ✅ | n/a (offsite) | ✅ mostly | **Preserve** |
| Stripe status on dashboard | ✅ | ✅ | ✅ | Unverified | ✅ (phase-driven copy) | Preserve; cache the API calls |
| Event creation (Event Studio) | ✅ | ✅ | ✅ | ✅ (44 px targets, breakpoints, reduced-motion in shell CSS) | ✅ | **Preserve**; fix draft-resume trap |
| Event editing — Studio sections (17 plugins) | ✅ | ✅ | ✅ | ✅ per CSS | ✅ | **Preserve** (canonical) |
| Event editing — `/edit/*` step forms | ✅ | Partial | Low | Unverified | ✅ | Consolidate/redirect |
| Event editing — `/build/*` wizard | ✅ | Legacy | Low | Unverified | ✅ | Admin-only per comments; route access broader than comment claims — tighten |
| Event editing — legacy `/vendor/event/{event}/*` manage pages | ✅ | Partial + 4 placeholder stubs | Low | Unverified | ✅ | Retire |
| Drafts + autosave | ✅ | ✅ | ✅ (restore prompts) | ✅ | ✅ | **Preserve** |
| Publish / unpublish with readiness gating | ✅ | ✅ | ✅ | ✅ | ✅ | **Preserve** |
| Moderation workflow | Partial | Unclear | — | — | — | Vendor role holds `use editorial transition publish` etc., and a `submit-review` route exists (`VendorStudioController::submitReview`); canonical Studio publish path does not use content moderation. Competing publish models (F-13) |
| Free RSVP configuration | ✅ | ✅ | ✅ | Unverified | ✅ | Keep |
| Paid ticket types (`mel_ticket_type`) | ✅ | ✅ | ✅ | ✅ (ticket manager CSS has 44 px targets) | ✅ | **Preserve** |
| Ticket groups | ✅ | ✅ | ❌ list route 403 for vendor role | — | ✅ | **Fix permission** (F-02) |
| Access codes | ✅ | ✅ | ❌ list route 403 for vendor role | — | ✅ | **Fix permission** (F-02) |
| Embedded purchase widgets | ✅ | ✅ | ❌ list route 403 for vendor role | — | ✅ | **Fix permission** (F-02) |
| Capacity / stock | ✅ | ✅ | ✅ (Studio section + readiness checks) | — | ✅ | Keep |
| Orders per event + order detail | ✅ | ✅ | ✅ | Unverified | ✅ | Keep |
| Refund requests (approve/reject) + direct refunds + event cancel | ✅ | ✅ | ✅ (role holds `manage_refunds`, `cancel_events`) | Unverified | ✅ | Keep |
| Ticket resend | ✅ | ✅ | ❌ role lacks `resend ticket emails` | — | ✅ | **Fix permission** (F-02) |
| Ticket assignment/transfer (`/ticket/assign/{token}`) | ✅ | ✅ (token-validated) | Attendee-side | — | ✅ | Keep |
| Attendee management + CSV export | ✅ | ✅ | ✅ | Unverified | ✅ | Keep |
| Check-in — operations Door Mode (canonical) | ✅ | ✅ | ✅ | Unverified | ✅ | **Preserve** |
| Check-in — `myeventlane_checkin` module | ✅ | ❌ broken permissions | ❌ | — | — | **Remove or repair** (F-01) |
| Check-in — tickets PWA scanner (`/event/{event}/tickets/checkin*`) | ✅ | ✅ | Low | PWA manifest/SW present | ✅ | Consolidate (F-05) |
| Check-in — RSVP scan/list/PDF | ✅ | ✅ | Low (legacy path family) | Unverified | ✅ | Consolidate (F-05) |
| Waitlist management | ✅ | ✅ | Legacy path (`/vendor/event/{node}/waitlist`) | Unverified | ✅ | Move to canonical family |
| Attendee communications (queued, rate-limited) | ✅ | ✅ | ✅ (`/vendor/events/{event}/promotion`) | Unverified | ✅ | Keep |
| Promotion / Boost | ✅ | ✅ | ✅ | Unverified | Mostly (one "Commerce store" leak) | Keep; fix copy |
| Vendor analytics (event-level) | ✅ | ✅ | Pro-gated | Unverified | ✅ | Product decision needed (F-11) |
| Payouts view | ✅ | ✅ | ✅ | Unverified | ✅ | Keep |
| Vendor settings | ✅ | ✅ | ✅ (account menu) | Unverified | ✅ | Keep |
| Vendor dashboard | ✅ | ✅ (very feature-dense) | ✅ | Unverified | ✅ | Keep; decompose + cache (F-10) |
| Help centre / escalations portal | ✅ (modules present) | Not audited in depth | ✅ (role has `view vendor help centre`) | — | — | Keep |
| Series / recurring instances | ✅ (`ManageSeriesInstancesController`) | Partial (legacy family) | Low | — | — | Assess |
| AI assist (Studio) + vendor AI panel | ✅ | ✅ (CSRF-protected, usage-tracked) | ✅ | — | ✅ | Keep |

---

## 5. Experience findings

Severity scale: **Critical** (blocks a journey stage), **High** (dead end / trust damage), **Medium** (friction/confusion), **Low** (polish).

---

**F-01 — `myeventlane_checkin` routes require permissions that do not exist**
- **Severity:** High (Critical if this were the only check-in surface; it is not)
- **Stage:** 10 (manage attendees / event day)
- **Impact:** All five routes under `/vendor/events/{node}/check-in*` require `_permission: 'myeventlane_checkin.access'`, `.scan`, `.toggle`. The module's `permissions.yml` defines `access check-in`, `scan qr codes`, `toggle check-in status` — which the vendor role holds. The route strings are never defined anywhere, so every organiser receives 403; only admin-bypass roles pass.
- **Evidence:** `myeventlane_checkin.routing.yml:7,22,37,51,66` vs `myeventlane_checkin.permissions.yml`; `user.role.vendor.yml` (holds the *defined* names).
- **First failure point:** Organiser opens any check-in URL from this module → Access denied.
- **Root cause:** Permission rename without route update (access/config drift).
- **Type:** Access + configuration.
- **Direction:** Since `myeventlane_event_attendees.vendor_operations_door` is commented as the "canonical check-in surface", either delete `myeventlane_checkin`'s routes (redirect to Door Mode) or fix the permission names. Do not leave a broken duplicate.
- **Preserve:** The canonical Door Mode surface.
- **Confidence:** High (static evidence is conclusive).

---

**F-02 — Vendor role lacks permissions required by ticket sub-surfaces (inconsistent access model)**
- **Severity:** High
- **Stage:** 6 (ticket setup), 10 (attendee service)
- **Impact:** Three *list* routes require `_permission: 'manage own events tickets'` (`.../tickets/groups`, `.../tickets/access-codes`, `.../tickets/widgets`), and `ticket_resend` requires `resend ticket emails`. Neither permission is in `user.role.vendor.yml`. Meanwhile the corresponding *add/edit/delete* routes use the custom `EventTicketsAccess`, which falls back to `access vendor console` + ownership and therefore **allows** the same organiser. Result: an organiser can add a ticket group but 403s on the group list; cannot resend a ticket email at all.
- **Evidence:** `myeventlane_tickets.routing.yml:73-83,129-139,185-195,397-408`; `EventTicketsAccess.php` (fallback branch); `user.role.vendor.yml` (neither permission present).
- **First failure point:** Any navigation to a list page or resend action.
- **Root cause:** Two access models (`_permission` vs custom checker) applied inconsistently within one module.
- **Type:** Access + configuration.
- **Direction:** Point the three list routes at `myeventlane_tickets.access.event_tickets:access` (consistent with siblings), and either add `resend ticket emails` to the vendor role or route resend through the same checker.
- **Preserve:** `EventTicketsAccess` itself — its "manage permission OR console+ownership" logic is the right canonical model.
- **Confidence:** High.

---

**F-03 — Single-draft resume trap on "Create event"**
- **Severity:** High
- **Stage:** 5 and 12 (create; return journey)
- **Impact:** `findLatestUnpublishedEventNidForUser()` returns the newest node with `status = 0` authored by the user. Both `/create-event` and `/vendor/events/create` redirect to that node's Studio instead of creating a new draft. Any unpublished event — an in-progress draft, a deliberately unpublished past event, an event awaiting changes — permanently captures the "Create event" button. There is no "Resume draft or start new?" choice anywhere in the create path.
- **Evidence:** `VendorEventStudioCreateService.php:42-68`; `CreateEventGatewayController.php:204-206`; `EventStudioController.php:74-82`.
- **First failure point:** Second event creation while any event is unpublished.
- **Root cause:** "Single draft resume" heuristic conflates *draft* with *unpublished*.
- **Type:** UX + architecture (state model).
- **Direction:** Offer an explicit choice (resume newest draft / start new), or narrow the query to genuinely-new drafts (e.g. untitled + never published), or drop auto-redirect when the user explicitly clicked "Create event" from the events index.
- **Preserve:** The instant-draft-scaffold pattern itself (create node → land in Studio) is good.
- **Confidence:** High.

---

**F-04 — Legacy `/vendor/event/{event}/*` family with declared placeholder stubs**
- **Severity:** Medium (High if any surviving link points at the stubs)
- **Stage:** 9 (promotion), general navigation
- **Impact:** The singular-path family (`edit`, `design`, `content`, `tickets` [redirects], `checkout-questions`, `series`, plus `promote` / `payments` / `comms` / `advanced` explicitly commented as "non-functional UX stubs") coexists with the canonical plural family. RSVP management and waitlist also still live under the singular family (`/vendor/event/{event}/rsvps`, `/waitlist`) while attendees got 301 redirects to canonical. A vendor navigating these gets an inconsistent mixture of working pages, redirects, and stubs.
- **Evidence:** `myeventlane_vendor.routing.yml:270-284,768-903`; `myeventlane_rsvp.routing.yml:112-179`; `myeventlane_event_attendees.routing.yml` (legacy 301 pattern shows the intended treatment).
- **Root cause:** Incomplete migration to the vendor-console-v2 route family (the `docs/vendor-console-v2-route-map.md` effort, partially executed).
- **Type:** Architecture + UX.
- **Direction:** Finish the migration: 301 every legacy route to its canonical equivalent (the attendees module already demonstrates the pattern) and delete the four placeholder stubs.
- **Preserve:** The canonical `/vendor/events/{event}/…` family and the 301-redirect pattern.
- **Confidence:** High.

---

**F-05 — Four parallel check-in/door surfaces**
- **Severity:** Medium
- **Stage:** 10
- **Impact:** Door operations exist in (1) `myeventlane_event_attendees`/`myeventlane_checkout_flow` Door Mode (canonical), (2) `myeventlane_checkin` (broken, F-01), (3) `myeventlane_tickets` check-in + QR scan + analytics + PWA (`/event/{event}/tickets/checkin*`), (4) `myeventlane_rsvp` check-in list/PDF/scan (`/vendor/event/{event}/rsvps/checkin`, `/vendor/event/{event}/scan`). Ticket-based and RSVP-based attendees are checked in through different UIs with different capabilities (PWA offline support only on the tickets one).
- **Evidence:** routing files cited in section 3, stage 10.
- **Root cause:** Ticketing v1 → v2 and RSVP flows each grew their own door surface; convergence (per `docs/offline-venue-operations-convergence.md`) is incomplete.
- **Type:** Architecture.
- **Direction:** Converge on Door Mode; port the PWA/offline capability from the tickets scanner into it; redirect the rest.
- **Preserve:** The PWA/offline machinery (manifest + service worker + batch API) — it is the most operationally capable of the four.
- **Confidence:** High for existence/duplication; medium for capability comparison (not exercised).

---

**F-06 — Vendor role carries broad Commerce and admin-flavoured permissions**
- **Severity:** High (security posture), pending runtime confirmation
- **Stage:** Cross-cutting
- **Impact:** `user.role.vendor.yml` grants `access commerce_order overview`, `update default commerce_order`, `delete default commerce_order`, `unlock orders`, `view any profile`, `view user email addresses`, `administer url aliases`, `access files overview`, `access content overview`. In stock Commerce, `update/delete default commerce_order` are not ownership-scoped ("own" variants are separate strings). If effective, an organiser could open and modify *any* order via admin order routes, cross-vendor. `view any profile` + `view user email addresses` widen attendee PII exposure beyond the vendor's own events.
- **Evidence:** `config/sync/user.role.vendor.yml` permissions list. I cannot confirm the runtime scope of the Commerce entity permissions from repository evidence alone (Commerce 3 beta permission provider not read).
- **Root cause:** Permissions accreted to unblock flows instead of scoped access checks.
- **Type:** Security + access.
- **Direction:** Audit each grant against a concrete vendor need; replace order-wide grants with the store-scoped access the vendor console already implements (`VendorEventOrdersController` correctly scopes via `assertEventOwnership` + store match). Test cross-vendor order access explicitly.
- **Preserve:** The console's own order views — they already do this correctly.
- **Confidence:** Medium (config confirmed; runtime effect unverified).

---

**F-07 — Runtime mutation of role config (`ensureVendorAccess`) and store→vendor writes in read paths**
- **Severity:** Medium
- **Stage:** 2, 4
- **Impact:** `OnboardingManager::ensureVendorAccess()` patches the saved `vendor` role's permission list at runtime if `access vendor console` is missing, then saves the role entity. This silently diverges active config from `config/sync`; the next `drush cim` reverts it and re-breaks onboarding, creating a drift loop. Similarly `resolveCommerceStoreForVendor()` saves the vendor entity inside what callers treat as a read ("is Stripe connected?") path.
- **Evidence:** `OnboardingManager.php:476-521`; `VendorEventStudioCreateService.php:168-193`.
- **Root cause:** Defensive self-healing in place of guaranteed config.
- **Type:** Architecture + configuration drift.
- **Direction:** Ensure the exported role config includes the permission (it does today) and demote the runtime patch to a logged alert; move entity writes out of readiness checks into explicit sync points (the Stripe callback already does this properly).
- **Preserve:** The idempotent design intent — just relocate the writes.
- **Confidence:** High.

---

**F-08 — Onboarding progress indicator misrepresents the flow**
- **Severity:** Low–Medium
- **Stage:** 1–2
- **Impact:** Step pages declare `#total_steps = 7` (account, profile, stripe, branding, first-event, boost, complete routes all exist), but `VendorOnboardProfileForm::submitForm()` marks onboarding `complete` at step 2 when name + terms are present, then routes to Event Studio. "Step 2 of 7" implies five more required steps; the honest framing is "2 required steps, the rest optional".
- **Evidence:** `VendorOnboardAccountController.php:50` (`#total_steps => 7`), `VendorOnboardProfileForm.php:100-101,248-256`.
- **Type:** UX/content.
- **Direction:** Relabel as required-vs-optional (e.g. "Set up in 2 steps — then polish when you're ready"), or drop numeric total.
- **Preserve:** The action-first model itself (Studio unlocked immediately; progressive requirements gate publish, not entry — `OnboardingManager::isVendorEventStudioUnlocked`).
- **Confidence:** High for code; the rendered indicator was not visually verified.

---

**F-09 — Terms acceptance without a visible terms link**
- **Severity:** Medium (trust + legal)
- **Stage:** 2
- **Impact:** The required checkbox is titled "I agree to the Vendor Terms of Service" with no `Url`/link in the form element, despite `LegalSettingsService` holding policy URLs and versions. Acceptance metadata (version, timestamp, IP/UA) is captured rigorously — the recording is stronger than the presentation.
- **Evidence:** `VendorOnboardProfileForm.php:144-149` (no link render element); `myeventlane_legal` service injected for versioning only. I cannot confirm whether a Twig template adds a link (`form--organiser-onboard-profile-form.html.twig` is referenced in a comment but was not located/read).
- **Type:** Content + legal.
- **Direction:** Render the checkbox label with a link to the versioned terms page.
- **Preserve:** The audit-trail capture (`applyVendorLegalFieldsFromStateFlags`).
- **Confidence:** Medium.

---

**F-10 — Dashboard god-controller: 2,607 lines, ~25 dependencies, live external calls, no render cache**
- **Severity:** Medium (performance/maintainability), grows with vendor count
- **Stage:** 12
- **Impact:** `VendorDashboardController::dashboard()` assembles KPI cards, events table, boost opportunities, best event, Stripe status, notifications, account summary, quick actions, growth cards, AI usage, audience summary, waitlist analytics, plus a *legacy template compatibility* payload — on every request, with no `#cache` metadata on the returned array. `VendorStripeService` documents that "vendor theme calls this on many pages" and suggests 5-minute caching that is not implemented (`getAvailableBalanceFormatted`). Duplicate event queries (`getUserEvents` + `getPublishedUserEvents` called three times).
- **Evidence:** `VendorDashboardController.php` (method map; `dashboard()` at 295-537; legacy keys 414-424); `VendorStripeService.php:115-124`.
- **Root cause:** Every dashboard iteration added a data source to one method; the newer `VendorDashboardViewModelBuilder` (1,294 lines) coexists with, rather than replaces, the legacy assembly.
- **Type:** Performance + architecture.
- **Direction:** Finish the view-model migration; cache Stripe balance/status lookups (state or keyvalue.expirable with short TTL); drop the legacy-template compatibility payload once `myeventlane_theme`'s override is confirmed dead.
- **Preserve:** The KPI read-model services (`VendorKpiService`, `MetricsAggregator`) — the data layer is sound; the assembly is the problem.
- **Confidence:** High.

---

**F-11 — Event-level analytics is Pro-only; base offer has no per-event performance page**
- **Severity:** Medium (product decision, not a defect)
- **Stage:** 11
- **Impact:** `/vendor/events/{event}/analytics` requires `use pro financial analytics` + `_myeventlane_pro_access`. The vendor role holds neither, so non-Pro organisers rely on dashboard KPI strips and the events table. Grassroots organisers ("easier for grassroots organisers" positioning) get less performance insight than competitors offer free.
- **Evidence:** `myeventlane_vendor.routing.yml:621-636`; `user.role.vendor.yml`; `myeventlane_vendor.permissions.yml` (`use pro financial analytics`).
- **Type:** Product.
- **Direction:** Decide the free/Pro analytics boundary deliberately (see §16). A free per-event page with tickets sold / RSVPs / revenue total, with Pro adding financial breakdowns, would fit MEL's positioning.
- **Confidence:** High on the gating; the in-page upsell/denial experience was not verified.

---

**F-12 — Drupal/Commerce concept exposure (confirmed instances)**
See §8 for the full list. Severity Low–Medium individually; collectively they undercut the otherwise consistent organiser language.

---

**F-13 — Competing publish/state models**
- **Severity:** Medium
- **Stage:** 7–8
- **Impact:** The canonical Studio publishes via `EventStudioSaveService::setNodePublishedState` (readiness-gated, no moderation), yet the vendor role carries editorial-workflow transitions (`use editorial transition publish`, `create_new_draft`, `send_back_to_draft`) and a separate `submit-review` POST route exists on the legacy studio controller (`VendorStudioController::submitReview`). Multiple state resolvers coexist (`myeventlane_core EventStateResolver`, `myeventlane_event_state EventStateResolverInterface` — both injected into the dashboard). Whether content moderation actually constrains event nodes at runtime I cannot confirm from repository evidence; the role config's `workflows.workflow.editorial` dependency says it is at least configured.
- **Evidence:** `user.role.vendor.yml` (transitions + workflow dependency); `myeventlane_vendor.routing.yml:473-487`; `VendorDashboardController.php:20,40` (dual state resolvers).
- **Type:** Architecture.
- **Direction:** Pick one publish authority (the readiness pipeline is the better one), and either remove moderation from the event bundle or integrate it as the review mechanism behind `submitReview` — not both.
- **Confidence:** Medium.

---

**F-14 — Stripe status truth is field-cached with no visible refresh loop**
- **Severity:** Medium
- **Stage:** 4, 8
- **Impact:** `PaidPublishStripeGate` (correctly) trusts store fields, which are updated when the organiser passes through `/stripe/connect` or `/stripe/callback`. If Stripe later disables charges (risk review, KYC lapse), the store field stays stale until the organiser re-runs Connect. A webhooks module exists (`myeventlane_webhooks`) but I did not verify it updates `field_stripe_charges_enabled`; **I cannot confirm from the available repository evidence** that account.updated events refresh the gate.
- **Evidence:** `PaidPublishStripeGate.php` (fields only, "no live Stripe API calls"); `StripeConnectController::connect/callback` (the only observed writers).
- **Type:** Architecture + payment readiness.
- **Direction:** Verify/implement `account.updated` webhook → store field sync; surface "last verified" on the dashboard Stripe panel.
- **Confidence:** Medium.

---

**F-15 — One raw infrastructure error message shown to organisers**
- **Severity:** Low
- **Stage:** 4
- **Impact:** When the platform Stripe key is missing, organisers see a messenger error mentioning `MEL_STRIPE_SECRET_KEY`, PHP-FPM pools, `fastcgi_param`, `drush cr` and `.ddev/config.local.yaml`. Ops-facing content in a vendor-facing channel.
- **Evidence:** `StripeConnectController.php:305-308`.
- **Direction:** Log the detail; show organisers "Payments are temporarily unavailable — our team has been notified."
- **Confidence:** High.

---

**F-16 — No functional test of the end-to-end organiser journey**
- **Severity:** Medium
- **Impact:** 112 test files exist, but they are predominantly Kernel/Unit (access checks, section contracts, governance builders). No BrowserTest/FunctionalJavascript covering register → onboard → connect → create → ticket → publish → order → check-in was found. The three permission-drift findings (F-01, F-02) are exactly the class of regression a journey test catches.
- **Evidence:** test inventory (§2); e.g. `VendorConsoleAccessKernelTest.php` exists, no `*JourneyTest`/`*FunctionalTest` for the vendor path found.
- **Direction:** See §18.
- **Confidence:** High for absence within custom modules; the repo-root `tests/` directory (untracked) was not audited.

---

## 6. Approach–Probe–Present–Listen–Extend–Return assessment

Scores 1–5. Evidence-based; rendered UI not exercised, so scores lean on copy and flow logic in code.

| Stage | Approach | Probe | Present | Listen | Extend | Return |
|---|---|---|---|---|---|---|
| Onboarding | **4** — warm, purposeful copy ("Let's get your organiser set up 👋", "This helps people trust your events") | **5** — asks only name + terms; everything else deferred | **3** — "Step 2 of 7" misframes (F-08); terms link missing (F-09) | **4** — destination honoured, state persisted, back link present | **4** — routes straight into first event | **4** — resume-setup panel + badge on dashboard, stage-aware next action |
| Stripe Connect | **4** — publish-time framing ("Connect Stripe before publishing") is honest | **4** — asks nothing itself; delegates KYC to Stripe Express | **4** — phase-driven dashboard copy (7 distinct states) is excellent | **4** — callback distinguishes complete/charges/pending; masks account IDs | **3** — post-connect lands on dashboard, not back into the publish attempt unless `destination` set | **4** — `/stripe/manage` login-link with eligibility fallback |
| Event creation (Studio) | **5** — zero-friction scaffold, lands in a purposeful workspace | **4** — booking-mode question (`rsvp/paid/both/external`) is the right first fork | **5** — section nav + topbar + readiness summary | **5** — autosave (12 s), restore prompts, stale-revision protection | **4** — AI assist, recommendations ("add a banner image…") | **2** — the create entry itself is trapped by F-03 |
| Ticket setup | **4** | **4** — `mel_ticket_type` abstraction hides products/variations | **4** | **4** — readiness names precise gaps ("Add at least one active paid ticket") | **2** — groups/access-codes/widgets lists 403 (F-02) | **3** |
| Publish | **5** | **5** — readiness evaluates rather than interrogates | **5** — errors/warnings/completed/recommendations, all vendor-language | **5** — 409s with restore URLs; server-side Stripe gate | **4** — post-publish share/Boost paths exist | **4** |
| Attendee management | **4** | **3** | **4** | **4** | **3** — resend blocked (F-02); four check-in doors (F-05) | **4** |
| Dashboard / return | **4** — organiser-worded, alerts, action queue | **3** | **3** — very dense; legacy + new blocks coexist | **3** | **3** | **3** — events index with attention flags is strong; create-again trap and Pro-walled analytics weaken it |

**Every score ≤3 is explained by a numbered finding above** (F-02, F-03, F-05, F-08, F-09, F-10, F-11).

---

## 7. Acknowledge–Align–Assure assessment

| State | Acknowledge | Align | Assure | Notes |
|---|---|---|---|---|
| Publish blocked — readiness errors | ✅ itemised | ✅ each error names the goal-blocking gap | ✅ organiser stays in Studio with sections to fix; work saved | Model implementation. `EventStudioPublishController` 422 payload |
| Publish blocked — unsaved/stale/autosave (409) | ✅ "Save this section before changing publish state" / "This event changed after this section loaded" | ✅ | ✅ restore URL provided; explicitly frames safety ("Refresh to continue safely") | Model implementation |
| Paid publish without Stripe | ✅ "Connect Stripe before publishing paid tickets. Stripe must be ready to accept charges…" | ✅ | ⚠️ message names the requirement but (in the gate string) not the action link — Studio readiness UI may add it; unverified | `PaidPublishStripeGate::blockedMessage` |
| Stripe connect failures | ✅ specific per cause | ✅ | ✅ mostly ("try again in a few minutes", "contact Support") — but F-15 leaks ops detail, and repeated failures always dump to dashboard rather than a dedicated recovery page | `StripeConnectController` catch blocks |
| Stripe callback partial states | ✅ four distinct outcomes | ✅ | ✅ "complete the remaining steps in your Stripe account setup" | Deliberately avoids leaking raw Stripe state strings (code comment says so) |
| Onboarding incomplete, event creation attempted | ✅ warning "Finish your organiser setup to unlock full features" | ✅ | ✅ creation still allowed (action-first) | Good pattern |
| No organiser profile at Stripe connect | ✅ "We couldn't find your organiser profile." | ⚠️ | ⚠️ "Please contact Support" with no link/channel in the message | |
| Check-in module 403 (F-01) | ❌ generic Drupal access-denied | ❌ | ❌ | Broken state has no designed response |
| Ticket list-page 403s (F-02) | ❌ generic access-denied | ❌ | ❌ | Same |
| Legacy placeholder pages | ⚠️ `ManageEventPlaceholderController` renders *something* (noindexed) — content quality unverified | — | — | Should not exist (F-04) |
| Empty dashboard (new organiser) | ✅ `show_welcome` branch exists | ✅ | Unverified rendering | |
| Draft-resume hijack (F-03) | ❌ silent redirect; organiser is never told why they landed in an old event | ❌ | ❌ | Needs an explicit choice moment |

**Pattern:** designed states are handled with genuine care; *undesigned* states (permission drift, legacy stubs, silent redirects) fall through to raw Drupal responses. The AAA discipline exists — it just doesn't cover the drift class of failure.

---

## 8. Drupal and Commerce concept exposure

Confirmed, with file evidence. Anything not listed was not confirmed as exposed.

| Exposure | Where | Recommendation |
|---|---|---|
| "SKU" (column header, hint text "SKU, price, and status for each option") | `mel-event-studio-extra-variant-preview.html.twig:11,18,32` | **Rename** ("Code" or hide entirely for extras) |
| "Variation summary" | same template, line 10 | **Rename** ("Options summary") |
| "Product" column in analytics table | `myeventlane_vendor_theme/templates/event/analytics.html.twig:201,210` | **Rename** ("Item" / "Ticket") |
| "You need to set up your Commerce store before you can boost events." | `myeventlane_vendor_theme/templates/vendor/boost.html.twig:100` | **Rewrite** ("Finish payment setup to boost events") + link |
| "Vendor" vs "Organiser" split identity: URL namespace `/vendor/*`, entity type `myeventlane_vendor`, role "Vendor", "Vendor Terms of Service" checkbox — while page titles/copy say "Organiser" (dashboard even carries the comment "MEL LANGUAGE STANDARD: Use 'Organiser' not 'Vendor'") | routing, `user.role.vendor.yml`, `VendorOnboardProfileForm.php:146` | **Retain** machine names (renaming entities/routes is high-risk churn); **standardise** all user-visible strings to "Organiser", including the terms label |
| "Draft/Published" status | Studio topbar, events index | **Retain** — vendor-comprehensible and honest |
| Vendor entity admin edit form linked from dashboard (`vendor_edit_url` → `entity.myeventlane_vendor.edit_form`, an `/admin/structure/...` route) | `VendorDashboardController.php:305-310` | **Move** — organisers should edit their profile via `/vendor/settings`, not a Field-UI admin form (route also requires `administer myeventlane vendor`, so for most organisers this is a hidden-or-403 link; unverified whether template renders it conditionally) |
| Raw ops/infra error text (Stripe key) | `StripeConnectController.php:305-308` | **Hide** (log-only) |
| "Commerce product editor" phrasing | `mel-event-studio-extra-product-setup-summary.html.twig` (template docblock only — comment, not output) | No action (not user-visible) |

Not found in vendor-facing output (checked): "node", "order item", "moderation state", "entity". The abstraction layer (`mel_ticket_type` instead of product/variation) is genuinely effective.

---

## 9. Architecture and ownership assessment

- **Vendor model:** Custom `myeventlane_vendor` entity. Membership = `uid` (owner) **or** `field_vendor_users` (team), resolved uniformly by `UserVendorMembershipQuery` (both branches, `accessCheck(TRUE)`).
- **Store ownership:** One `commerce_store` per vendor via `field_vendor_store`; created lazily by `VendorStoreSubscriber::ensureStoreForVendor`; store-by-uid fallback exists in two places (`PaidPublishStripeGate`, `VendorEventStudioCreateService`) and self-heals the vendor→store link.
- **Event ownership:** `node.uid` (author) **or** `field_event_vendor` → `field_vendor_users`. Enforced by `VendorConsoleBaseController::assertEventOwnership` and `EventVendorAccessChecker::accountHasWorkspaceParityForEvent`, applied consistently across ~40 controller call sites (verified by grep).
- **Ownership gap:** `EventVendorAccessChecker` checks `field_vendor_users` but **not the vendor entity's own `uid`**. A vendor *owner* who neither authored an event nor appears in `field_vendor_users` fails the parity check while `UserVendorMembershipQuery` counts them as a member. Orphan-risk scenario: team member creates event → leaves → owner (not in `field_vendor_users`) loses workspace access to it. Confidence: high on the code asymmetry; whether it occurs in practice depends on how `field_vendor_users` is populated at vendor creation (not verified).
- **Ticket product ownership:** ticket types are `mel_ticket_type` entities linked to events; role permissions grant `create/edit own mel_ticket_type`. Legacy `commerce_product` "ticket" permissions also remain on the role (`create ticket commerce_product`, `update own ticket commerce_product`) — evidence of the v1→v2 ticketing migration (`docs/TICKETING_V2_MIGRATION.md`) leaving both live.
- **Order access:** console order views scope orders through the event's store (`VendorEventOrderViewController` docblock: "order must belong to event's store"). Undercut by the role's global Commerce order permissions (F-06).
- **Attendee access:** `event_attendee` entities gated per event via ownership checks; exports gated by `VendorAttendeeController::access`.
- **Stripe association:** account ID lives on the **store** (`field_stripe_account_id`), mirrored onto the vendor entity. Store is the source of truth; the mirror is one-way and validated (`acct_` prefix, query-vs-store mismatch rejected and logged masked).
- **Permission boundaries:** three-layer model — role permission (`access vendor console`) → path-namespace gate (`VendorConsoleAccess`) → per-entity ownership assertion. Sound design; the failures are drift (F-01/F-02), not design.
- **Cross-vendor isolation:** strong on console routes; weak wherever role-level Commerce/admin permissions apply (F-06).
- **Duplicate/competing state:** onboarding state entity dedupes itself defensively (duplicate detection + deletion with "FIX REQUIRED" logging — symptomatic of past duplication bugs); dual event-state resolvers; readiness-vs-moderation publish models (F-13).

---

## 10. Security and privacy findings

1. **Role-level Commerce order permissions** (F-06) — the single most important item to verify and likely tighten. Includes `delete default commerce_order`.
2. **`view any profile` + `view user email addresses`** on the vendor role — attendee PII exposure beyond the organiser's own events; scope to own-event attendees.
3. **Check-in mutation routes:** `myeventlane_checkin.toggle` route (broken anyway) is not marked `methods: [POST]` in routing — GET-mutable if permissions were fixed naively. The canonical Door Mode validate route accepts `GET|POST` for a mutation path (`myeventlane_event_attendees.routing.yml`, `vendor_operations_door_validate`) — verify the GET branch is read-only.
4. **CSRF:** good discipline on Studio endpoints (`_csrf_request_header_token` on autosave/publish/AI, with an accurate explanatory comment about `_csrf_token` vs header token); `attendee checkin` POST-only with custom access. Logout is CSRF-protected.
5. **Stripe hygiene: strong.** Account IDs masked in logs (`StripeService::maskAccountId`), never placed in query strings by design (comment + implementation in `buildAccountLinkUrls`), redirect hosts allow-listed (`connect.stripe.com` / `dashboard.stripe.com`), no-cache headers on offsite redirects, secrets resolved from gateway config and never echoed.
6. **Exports:** RSVP/attendee CSV exports sit behind the same custom access checks as their list pages — consistent.
7. **Open-redirect handling:** `destination` on the Stripe callback is constrained to site-relative paths (`str_starts_with($dest, '/')`); `VendorOnboardProfileForm` uses `Url::fromUserInput` with exception fallback.
8. **Sensitive logging:** decision logging in `VendorConsoleAccess` is debug-level and content-safe; no PII observed in log calls read.
9. **`/stripe/*` force-allow:** `VendorConsoleAccess` force-allows any authenticated-or-not request on `/stripe/*` paths *for this checker* (controllers then enforce login). Defensible, but it means route security for `/stripe/manage` rests entirely on the controller; acceptable as implemented, fragile if new `/stripe/*` routes are added without controller-level checks.

---

## 11. Accessibility and mobile findings

Static evidence only (no rendered audit — treat as a follow-up validation item).

**Critical blockers:** none found in code. (Absence of evidence, not evidence of absence.)

**High-friction:**
- The dashboard's sheer density (KPIs + alerts + activity + growth cards + boost + AI panel + onboarding panel) is a mobile scroll burden by construction; no mobile-specific pruning was observed in the controller.
- Legacy surfaces (wizard, manage-event pages) predate the current CSS system; their mobile behaviour is unverified and they should be retired rather than fixed (F-04).

**Consistency (positive evidence):**
- Studio shell CSS: `min-height: 44px` touch targets (3 instances + one 40 px), breakpoints down to 420 px, `prefers-reduced-motion: reduce` block (`mel-event-studio-shell.css:1278`).
- Ticket manager CSS: six `min-height: 44px` declarations.
- Vendor theme SCSS: 21 rules at ≤479 px, reduced-motion support in five partials, 44 focus-visible/contrast-related rules, `min-width:44px` utilities.
- Templates: 166 `aria-*`/`role=` attributes in vendor theme templates, 70 in Studio templates/JS; responsive tables use `data-label` cell attributes (analytics, variant preview) for stacked mobile rendering.

**Enhancements:**
- No `prefers-contrast` handling found in Studio module CSS (present in theme).
- Focus management after Studio section swaps and 409-recovery flows is JS-behaviour I could not verify; include in the validation plan.
- Colour-only status: status chips carry text labels in view models (`statusLabel`, `attentionLabel`) — good pattern; verify severity is not conveyed by colour alone in rendering.

---

## 12. Performance and cacheability

- **Dashboard (F-10):** no `#cache` on the returned build; multiple redundant event queries; per-request Stripe API calls (`getStripeConnectStatus` path + theme-level `hasRecentPayout`/balance calls acknowledged in code comments as running "on many pages" uncached). Highest-value performance fix in the vendor surface.
- **Studio workspace:** correct cache metadata (`$node->getCacheTags()`, `route`/`user`/`user.permissions` contexts) — `EventStudioController.php:194-197`.
- **Access checks:** `VendorConsoleAccess` adds `session` context to every result, which fragments dynamic page cache for all vendor routes; likely deliberate (onboarding state) but worth revisiting. `EventTicketsAccess` uses `cachePerUser` + entity dependency — correct.
- **Onboarding flags:** `computeVendorFlags` loads up to 10 event nodes fully to test two fields (`vendorHasTickets`) — fine at current scale, avoidable with an entity query on the field values.
- **Events index:** view-model builder loads all vendor events then filters in PHP (`filterRowsInternal`); no pagination observed in the builder signature. Unbounded for large organisers — verify and paginate past ~50 events.
- **Onboarding state self-healing deletes** run inside read paths (`loadVendorStateByUid` deletes duplicates during what callers treat as a lookup) — write-on-read, same class as F-07.
- **Frontend:** Vite-built theme (`dist/`), PurgeCSS strategy documented in `myeventlane_radix`. Bundle weights unverified.

---

## 13. What MEL should preserve (mandatory)

1. **The publish pipeline** — `EventReadinessService` + `EventStudioPublishController` + `PaidPublishStripeGate`. Server-enforced, vendor-worded, recoverable (409s with restore URLs, stale-revision detection). This is better than most commercial ticketing platforms' publish UX and is the platform's trust anchor.
2. **Stripe Connect implementation** — Express Account Links with masked logging, host allow-listing, no IDs in query strings, phase-driven status copy (7 states), field-based readiness checks that avoid API calls in gates. Preserve wholesale.
3. **Action-first onboarding** — two required steps, Studio unlocked immediately, requirements gate *publish* not *entry* (`isVendorEventStudioUnlocked`). This is the correct grassroots-organiser model; fix its labelling, not its shape.
4. **Event Studio's plugin-section architecture** — 17 section plugins with per-section access, writability, autosave and readiness capability flags; section contract tests exist. This is the extensibility spine; all consolidation should converge *into* it.
5. **The layered access model** — role permission → path-namespace gate → `assertEventOwnership`, applied consistently across ~40 call sites, with kernel tests. Fix drift; keep the design.
6. **`mel_ticket_type` abstraction** — organisers configure "ticket types", never products/variations. The single most effective anti-Drupal-exposure decision in the codebase.
7. **Vendor-language error copy discipline** — messenger strings across Stripe, publish, and onboarding are calm, specific and blame-free (one F-15 exception).
8. **Door Mode + attendee entity model** — single operational surface concept with POST-only mutation and export controls.
9. **Mobile/a11y groundwork** — 44 px targets, reduced-motion, aria coverage, data-label responsive tables. The foundations are in place; don't rebuild them.
10. **The legacy-route 301 pattern** already demonstrated by `myeventlane_event_attendees` — the template for finishing F-04.

---

## 14. Independent recommendations

Ordered by leverage. Class key: **[copy/config]**, **[contained UX]**, **[consolidation]**, **[workflow]**, **[architecture]**, **[product]**.

**R1. Permission-drift repair sweep [copy/config → access]**
- *Problem:* F-01, F-02 dead ends.
- *Outcome:* every route an organiser can see, an organiser can open.
- *Behaviour:* fix `myeventlane_checkin` permission names or delete its routes; switch the three ticket list routes to `EventTicketsAccess`; grant/route-fix `resend ticket emails`.
- *Components:* two routing YAMLs, possibly `user.role.vendor.yml`. *Dependencies:* none. *Security:* net positive. *Config impact:* role export. *Testing:* route-access kernel tests per fixed route. *Risk:* low. *Order:* first.

**R2. Create-event choice moment [contained UX]**
- *Problem:* F-03 trap.
- *Outcome:* organiser explicitly chooses "resume draft" vs "start new".
- *Behaviour:* when `findLatestUnpublishedEventNidForUser` hits, render a lightweight interstitial (or events-index banner) instead of silently redirecting; keep auto-resume only for the `mel_first_event=1` onboarding case.
- *Components:* `CreateEventGatewayController`, `EventStudioController::buildCreate`, one template. *Risk:* low. *Order:* first wave.

**R3. Route-family completion: one event surface [consolidation]**
- *Problem:* F-04, F-05 — five edit families, four check-in surfaces.
- *Outcome:* every event-management URL either is canonical (`/vendor/events/{event}/…`) or 301s to it.
- *Behaviour:* 301 the `/vendor/event/*` singular family (RSVPs, waitlist, design, content, checkout-questions, series) into Studio sections or canonical pages; delete the four placeholder stubs; retire `/edit/*` step forms and `/build/*` wizard routes for non-admins (wizard route access currently allows all console users despite the "admins only" comment — tighten `_custom_access`); converge check-in onto Door Mode and port the PWA/offline capability from the tickets scanner.
- *Architectural implications:* deletes ~25 routes, several controllers/forms become dead code. *Dependencies:* R1 (don't consolidate onto broken permissions). *Testing:* redirect tests + journey test. *Risk:* medium (link inventory needed — menus, emails, help docs). *Order:* the "first cohesive improvement".

**R4. Dashboard decomposition and caching [architecture]**
- *Problem:* F-10, §12.
- *Outcome:* faster return visits; maintainable dashboard.
- *Behaviour:* complete migration to `VendorDashboardViewModelBuilder`; cache Stripe status/balance (keyvalue.expirable, 5-min TTL, invalidated by connect callback); remove legacy-template payload; add cache metadata (per-user contexts, event-list cache tags).
- *Risk:* medium. *Order:* short-term, after R3 removes legacy consumers.

**R5. Order/PII permission tightening [config + security]**
- *Problem:* F-06.
- *Outcome:* provable cross-vendor isolation.
- *Behaviour:* runtime-verify the scope of `update/delete default commerce_order`, `access commerce_order overview`, `view any profile`, `view user email addresses`; remove or replace each with console-scoped equivalents; add a cross-vendor access test (vendor A must 403 on vendor B's order/attendee/export routes).
- *Risk:* medium (something may silently depend on a broad grant — the tests will reveal it). *Order:* immediate verification; removal short-term.

**R6. Stripe status freshness [workflow]**
- *Problem:* F-14.
- *Behaviour:* handle `account.updated` webhooks → `applyConnectStatusToCommerceStore`; show "last verified" in the dashboard panel; optional daily reconciliation drush command.
- *Dependencies:* `myeventlane_webhooks` module (present; contents unaudited). *Risk:* low–medium. *Order:* short-term.

**R7. Onboarding honesty pass [copy/config]**
- *Problem:* F-08, F-09, F-15, "contact Support" without a link.
- *Behaviour:* required-vs-optional step framing; terms link in checkbox label; replace infra error; link Support mentions to the help centre.
- *Risk:* minimal. *Order:* quick win.

**R8. Free per-event performance page [product]**
- *Problem:* F-11.
- *Behaviour:* (pending §16 decision) a non-Pro event performance page — tickets sold, RSVPs, capacity fill, revenue total, check-in rate — reusing `VendorKpiService`/read models; Pro retains financial breakdowns and exports.
- *Risk:* low technically; pricing implications are Anna's call. *Order:* medium-term.

**R9. Publish-model unification [architecture + product]**
- *Problem:* F-13.
- *Behaviour:* decide whether MEL has pre-publish review. If no: strip editorial transitions from the vendor role and remove `submitReview`. If yes: make review a readiness-pipeline state, not a parallel moderation workflow.
- *Order:* medium-term (needs §16 decision).

**R10. Vendor-owner parity fix [contained fix]**
- *Problem:* §9 ownership gap.
- *Behaviour:* add `vendor->getOwnerId()` to `EventVendorAccessChecker` (and `assertEventOwnership`) so vendor owners always reach their team's events; or guarantee owners are always in `field_vendor_users` at creation.
- *Risk:* low. *Order:* first wave.

---

## 15. Quick wins

All low-risk, evidence-backed, independent of later architecture:

1. Fix `myeventlane_checkin` route permission strings (or delete the routes) — one YAML file (F-01).
2. Switch the three ticket list routes to `myeventlane_tickets.access.event_tickets:access` (F-02).
3. Add `resend ticket emails` to the vendor role export (F-02).
4. Link the Vendor Terms in the onboarding checkbox label (F-09).
5. Replace the `MEL_STRIPE_SECRET_KEY` organiser-facing error with a calm message (F-15).
6. Rename "SKU" → "Code", "Variation summary" → "Options summary", "Product" → "Ticket/Item", and rewrite the "Commerce store" boost message (§8).
7. Standardise "Vendor Terms of Service" → "Organiser Terms of Service" in UI strings (machine names untouched).
8. Reframe step indicator to required-vs-optional (F-08).
9. Add `methods: [POST]` to `myeventlane_checkin.toggle` if the module is kept (§10.3).
10. Delete the four `ManageEventPlaceholderController` stub routes (they are declared non-functional and unlinked; removal is safe by the routing file's own comment).

---

## 16. Product decisions required (Anna)

1. **Analytics boundary:** what event-performance data is free vs Pro? (F-11 / R8.)
2. **Review-before-publish:** does MEL want any human/AI review step before events go live, or is readiness-gated self-publish the model? Determines R9 and the fate of the editorial workflow + `submit-review` route.
3. **Multiple simultaneous drafts:** is "one draft at a time" an intentional simplification or an accident of F-03? R2's design depends on the answer.
4. **`myeventlane_checkin` module:** repair or retire? (Retire recommended given Door Mode is canonical.)
5. **Boost prominence:** Boost appears in onboarding (step 6), dashboard (top-opportunity + entitlements + export), events index, and Studio promotions. Is that the intended commercial weight for a "community-first" brand, or should promotion be quieter until an event is live?
6. **Vendor teams:** is multi-user organiser access (field_vendor_users) a supported feature? If yes, R10 plus an invite UI (none found — **I cannot confirm any team-invitation UI exists from repository evidence**); if no, simplify the ownership model.
7. **Legacy `myeventlane_theme` dashboard override:** the controller still ships a compatibility payload for it — is the legacy theme deprecated? (Determines how aggressively R4 can cut.)
8. **Pro positioning of event analytics vs `myeventlane_analytics_pageviews` / audience data** — which single "performance" story is told to organisers?

---

## 17. Prioritised roadmap

**Immediate blockers (days):**
- R1 permission sweep (F-01, F-02) + regression tests
- R5 verification step (cross-vendor order access test) — verify before assuming safe
- Quick wins 4, 5, 9

**First cohesive improvement (1–2 sprints):**
- R2 create-event choice moment (F-03)
- R10 owner parity
- R3 phase 1: 301 the legacy singular family + delete stubs + tighten wizard access
- Quick wins 6–8, 10

**Short-term (next quarter):**
- R3 phase 2: check-in convergence onto Door Mode (port PWA capability)
- R4 dashboard decomposition + Stripe caching
- R5 permission removals (post-verification)
- R6 webhook-driven Stripe freshness
- Journey functional test (§18) — build alongside R3 so consolidation is protected

**Medium-term product work:**
- R8 free performance page (after decision 1)
- R9 publish-model unification (after decision 2)
- Team invitation UI or ownership simplification (after decision 6)

**Later enhancements:**
- Events index pagination for large organisers
- Rendered accessibility audit + fixes from §18 findings
- Onboarding state entity cleanup (retire self-healing dedupe once cause is fixed)

Dependency logic: permission fixes precede consolidation (don't 301 onto 403s); consolidation precedes dashboard slimming (legacy consumers removed first); product decisions gate only the medium-term lanes.

---

## 18. Validation plan

**Automated (kernel/unit):**
- Route-access matrix test: for every `/vendor/*` route, assert expected allow/deny for: anonymous, plain authenticated, vendor role (own event), vendor role (other vendor's event), admin. This single table-driven test would have caught F-01, F-02 and guards R1/R3/R5.
- `EventReadinessService` scenario tests (each error/warning branch).
- `PaidPublishStripeGate` field-state matrix.
- Onboarding state machine: create → profile submit → complete; duplicate-state resolution.

**Functional (browser) — the missing layer (F-16):**
- Journey A (free): register → onboard (2 steps) → create event → RSVP mode → publish → public RSVP → attendee appears → Door Mode check-in.
- Journey B (paid): as A with paid ticket → publish blocked pre-Stripe (assert message) → simulate connected store fields → publish succeeds → order → ticket → resend → refund request round-trip.
- Journey C (return): second event creation with an existing unpublished event (guards R2); draft autosave restore; stale-revision 409 recovery.

**Access tests:** cross-vendor isolation suite from R5 (orders, attendees, exports, comms, refunds).

**Mobile checks (390 px, real browser):** Studio each section; ticket manager; Door Mode; dashboard scroll depth; events index filters; sticky topbar behaviour.

**Accessibility checks:** keyboard-only publish flow including 409 recovery focus handling; screen-reader labels on readiness panel and status chips; contrast pass on status severities; reduced-motion verification.

**Configuration validation:** CI assertion that every `_permission:` string in custom routing YAML exists in some `permissions.yml` or core/contrib (would permanently prevent the F-01 class); `drush cim` round-trip test to detect runtime config mutation (F-07).

**Performance checks:** dashboard request profile before/after R4 (query count, Stripe call count, wall time); events index with 200+ events.

---

## 19. Final scorecard (0–10)

| Dimension | Score | Rationale |
|---|---|---|
| Onboarding clarity | **7** | Two-step action-first flow is excellent; misleading step count, missing terms link, stock register page. |
| Payment readiness | **8** | Best-in-codebase: Express onboarding, phase-driven copy, server-side publish gate. −2 for staleness risk (F-14) and one leaked ops error. |
| Event creation | **8** | Instant scaffold into a strong workspace with autosave. −2 for the resume trap undermining repeat creation. |
| Ticket setup | **6** | Core ticket types solid and vendor-worded; groups/access-codes/widgets lists 403; legacy product permissions linger. |
| RSVP setup | **7** | First-class booking mode with donations recommendation; management stranded on legacy paths. |
| Publishing confidence | **9** | Readiness panel + recoverable 409s + server enforcement. The platform's high-water mark. |
| Attendee management | **6** | Canonical list/export/Door Mode work; four check-in surfaces, one broken; resend blocked. |
| Analytics usefulness | **5** | Good dashboard KPIs; per-event depth paywalled with no free fallback page. |
| Mobile usability | **6** | Deliberate groundwork (44 px, breakpoints, data-labels) on canonical surfaces; unverified in-browser; dashboard density and legacy surfaces drag. |
| Accessibility | **6** | Real aria/reduced-motion/focus investment in code; no rendered verification; legacy surfaces unknown. |
| Error recovery | **7** | Designed failures are handled with genuine AAA care; undesigned failures (403 drift, silent redirects) fall to raw Drupal. |
| Return experience | **6** | Organiser-shaped events index with attention flags and stage-aware onboarding resume; create trap, heavy dashboard, Pro-walled depth. |
| **Overall vendor confidence** | **7** | The canonical spine deserves trust — including with money. The score is capped by drift at the edges: every 403 dead end inside an invited surface spends trust the core has earned. |

---

*Audit-only engagement: no code, configuration, or routing was modified. The only repository change is this document.*
