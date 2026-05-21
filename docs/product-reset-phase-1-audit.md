# MEL Product Reset — Phase 1 Audit

**Date:** 2026-05-21  
**Branch target:** `feature/mel-product-reset-phase-1`  
**Scope:** Discovery confidence, event booking confidence, Event Studio guided foundation, checkout trust polish, vendor dashboard attention — **refactor/extend only**.

---

## Working tree blocker (pre-edit)

Current branch: `feature/sidebar-carousel-convergence` with **unrelated dirty work**:

| Path | Status |
|------|--------|
| `web/modules/custom/myeventlane_commerce/*` | Modified |
| `web/modules/custom/myeventlane_event/*` (sidebar carousel builder) | Modified |
| `web/modules/custom/myeventlane_event_studio/*` (branding forms) | Modified |
| `web/themes/custom/myeventlane_theme/*` (carousel JS/SCSS/Twig) | Modified |
| `docs/sidebar-carousel-convergence-audit.md` | Untracked |

**Phase 1 edits must not include these files in commits.** Stash or commit carousel work separately before merging Phase 1.

---

## 1. Discovery — routes, views, templates

### Canonical listing

| Asset | Role |
|-------|------|
| `config/sync/views.view.upcoming_events.yml` | **Canonical** public discovery (`/events`, today, weekend, free, category, popular) |
| `config/sync/views.view.all_events.yml` | **Deprecated** — label says use `upcoming_events` |
| `config/sync/views.view.front_discover_events.yml` | Homepage discover block source |
| `config/sync/views.view.mel_home_events.yml` | Homepage embed |

### Page routes (Views page displays)

| Display | Path | Notes |
|---------|------|-------|
| `page_events` | `/events` | Main browse; exposed filters; custom empty when filtered |
| `page_today` | `/events/today` | Date chip |
| `page_this_weekend` | `/events/this-weekend` | Date chip |
| `page_free` | `/events/free` | Free & RSVP filter |
| `page_category` | `/events/category/%` | Taxonomy argument |
| `page_popular` | `/events/popular` | Boost/popularity |

**No dedicated “Online events” page display** — `field_event_type` is filterable in YAML but no `page_online` route. **Defer** online quick link unless exposed filter is wired in theme.

### Theme templates

| File | Role |
|------|------|
| `templates/includes/mel-browse-events-page-shell.html.twig` | Shared `/events` shell (header + page header search/categories + results) |
| `templates/page--events.html.twig`, `page--view--upcoming-events--page-events.html.twig` | Include browse shell |
| `templates/views/includes/mel-events-discovery-filters.html.twig` | Quick chips: All upcoming, Today, Weekend, Free & RSVP |
| `templates/views/views-view--upcoming-events.html.twig` | Discovery wrapper + filter include |
| `templates/components/event-card/mel-event-card.html.twig` | **Canonical** public card (`event_ui`, single CTA chip) |
| `templates/node--event--teaser.html.twig` | View mode teaser → includes mel-event-card |
| `src/scss/pages/_discovery.scss`, `_mel-browse.scss` | Grid density, browse header, empty browse styles |

### Preprocess / theme logic

- `myeventlane_theme.theme` — `upcoming_events` displays, browse route helpers (~L3147, L3426).
- Card fields via `myeventlane_event_preprocess_node()` + `myeventlane_event_finalize_event_ui()`.

---

## 2. Event full page — templates and CTA

| File | Role |
|------|------|
| `templates/node/node--event--full.html.twig` | V2 layout: hero, meta bar, main + **sidebar rail** |
| `templates/event/sidebar/mel-event-sidebar-slide-booking.html.twig` | Dominant booking CTA (uses partial) |
| `templates/event/sidebar/mel-event-sidebar-slide-trust.html.twig` | Trust, calendar, decision prompts |
| `templates/node/partial--event-full-booking-cta.html.twig` | **Single** CTA partial (sidebar + mobile) |
| `src/scss/components/_event-full.scss` | Sticky sidebar, `.mel-mobile-cta` |

### CTA resolution (do not duplicate)

| Service | ID | Role |
|---------|-----|------|
| `BookingFlowResolver` | `myeventlane_event.booking_flow_resolver` | Canonical mode, availability, primary CTA |
| `EventCtaResolver` | `myeventlane_event.event_cta_resolver` | **Deprecated wrapper** → delegates to BookingFlowResolver |

Preprocess exposes `event_cta`, `cta_type`, `event_ui`, `mel_display_pricing` to Twig.

### Existing confidence features

- Mobile sticky CTA bar (`.mel-mobile-cta`)
- Organiser card (`mel_organiser`)
- Policies / refund / cancellation fields
- Share section + social chips
- ICS: route `myeventlane_event.calendar_ics` → `/event/{node}/calendar.ics`
- Sidebar carousel slides (booking, trust, social, gallery) — **in progress on other branch**

---

## 3. Event Studio — routes, forms, shell

### Module

`web/modules/custom/myeventlane_event_studio/`

| Area | Key files |
|------|-----------|
| Routes | `myeventlane_event_studio.routing.yml` — `/vendor/events/create`, `/{node}/edit`, `/{node}/studio/*` sections |
| Access | `src/Access/EventStudioAccess.php` + `EventVendorAccessChecker` |
| Workspace | `Controller/EventStudioController::workspace`, `templates/mel-event-studio-workspace.html.twig` |
| Guided builder | `templates/mel-event-studio.html.twig`, `mel-event-studio-nav.html.twig`, `js/mel-event-studio.js` (`MEL_STEPS`) |
| Shell CSS | `css/mel-event-studio-shell.css` |
| Readiness | `src/DTO/EventReadinessResult.php`, workspace readiness strip in `mel-event-studio-workspace.html.twig` |
| Preview | Live preview card in `mel-event-studio.html.twig` sidebar |

### Guided steps (existing)

1. Basics & date (`#mel-step-identity`)
2. Tickets or RSVP (`#mel-step-tickets`)
3. Details (`#mel-step-standout`)
4. Guest questions (`#mel-step-attendee`)
5. Preview (`#mel-step-preview`)
6. Publish (`#mel-step-publish`)

Save/publish: `EventStudioSaveService`, publish controller, `#mel-save-draft-studio`, `#mel-publish-now` — **do not replace**.

---

## 4. Booking / checkout trust UI

| Asset | Role |
|-------|------|
| `myeventlane_checkout_flow` — `MelEventCheckoutFlow` | Step label **"Complete booking"** already set |
| `CheckoutUxAttacher` | Grouped summary + `mel_checkout_confidence` sidebar |
| `MelReadinessHelper::customerCheckoutSidebarConfidenceLines()` | Secure Stripe / email / calendar copy |
| `myeventlane_theme.theme` | Forces checkout next button → "Complete booking" |
| `src/scss/components/_checkout.scss` | `.mel-checkout-confidence` |

**No Stripe/order logic changes** for Phase 1.

---

## 5. Vendor dashboard

| Asset | Role |
|-------|------|
| Route | Vendor console dashboard (see `myeventlane_vendor` + `VendorDashboardController`) |
| View model | `VendorDashboardViewModelBuilder` — `action_queue`, `attention_events`, `upcoming_events` |
| Template | `myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` |
| Attention | `buildAttentionEvents()` — uses `attention_reasons` on event rows |

**No `myeventlane_vendor_dashboard` module** — dashboard lives under `myeventlane_vendor` + `myeventlane_dashboard` services.

---

## 6. Services to reuse (mandatory)

| Service | Module |
|---------|--------|
| `myeventlane_event.booking_flow_resolver` | Event public booking |
| `myeventlane_event.event_cta_resolver` | Twig compatibility only |
| `myeventlane_event.icalendar_builder` | Calendar downloads |
| `myeventlane_event_studio.*` save/readiness/workspace | Studio |
| `myeventlane_vendor.event_access_checker` | Vendor event ownership |
| `myeventlane_vendor.action_queue_builder` | Dashboard actions |
| `myeventlane_surface.vendor_dashboard_action_queue_governance` | Action queue governance |
| `myeventlane_checkout_flow.checkout_ux_attacher` | Checkout UX |
| `myeventlane_core.mel_readiness_helper` | Copy + readiness summaries |
| `MelDataPresentationManager` / `SurfaceNegotiator` | Surface attributes (`data-mel-checkout-trust`) |

---

## 7. Do not extend (legacy / duplicate)

| Item | Reason |
|------|--------|
| `views.view.all_events` | Deprecated |
| `EventCtaResolver` as new decision point | Use `BookingFlowResolver` |
| Parallel checkout / ticket purchase | Commerce panes only |
| Legacy event wizard routes | Redirected to Event Studio (`VendorLegacyWizardRedirectSubscriber`) |
| New discovery search engine | Views + Search API already exist |
| Second vendor dashboard module | Use `VendorDashboardViewModelBuilder` |
| Duplicate event card Twig | Use `mel-event-card.html.twig` only |

---

## 8. Security / access boundaries

| Surface | Enforcement |
|---------|-------------|
| Event Studio | `EventStudioAccess` + `_entity_access: node.update` + vendor ownership via `EventVendorAccessChecker` |
| Vendor dashboard | Vendor console access plugin / store context |
| Customer tickets | `MyTicketsOrderAccess`, order ownership |
| Public event page | Published nodes only; no attendee PII in `event_ui` / public preprocess |
| Checkout | Commerce order access + Stripe via existing gateway |
| Staff | Explicit permissions (`administer nodes`, commerce store admin) |

---

## 9. Recommended implementation slices (Phase 1)

### Slice A — Discovery confidence

- Enhance `mel-browse-events-page-shell.html.twig`: community/trust lede + include existing date chips.
- Align `page_events` Views empty text with governed empty-state pattern (filter-specific copy + links).
- SCSS: browse intro strip via `_mel-browse.scss` tokens only.

### Slice B — Event booking confidence

- Refine `mel-event-sidebar-slide-trust.html.twig` copy (RSVP + paid secure messaging).
- Add one-line mobile trust hint in `partial--event-full-booking-cta.html.twig` or mobile block — **avoid** forking CTA logic.
- **Do not** edit carousel builder PHP on this branch.

### Slice C — Event Studio foundation

- Add short guided-builder intro on `mel-event-studio.html.twig` (steps already exist).
- Sticky save footer for `mel-event-studio__footer-actions` in shell CSS.
- Optional nav label tweak: “Visibility & publish” on publish step only.

### Slice D — Checkout trust

- Tune `MelReadinessHelper::customerCheckoutSidebarConfidenceLines()` strings (copy only).
- Verify checkout SCSS readability on mobile (no flow changes).

### Slice E — Vendor dashboard

- Copy/structure polish on `attention_events` strip in `dashboard.html.twig`.
- No new services; rely on existing `attention_reasons`.

---

## 10. Files proposed for change (Phase 1)

| File | Slice |
|------|-------|
| `docs/product-reset-phase-1-audit.md` | Audit |
| `docs/product-reset-phase-1-deferred.md` | Deferred |
| `web/themes/custom/myeventlane_theme/templates/includes/mel-browse-events-page-shell.html.twig` | A |
| `web/themes/custom/myeventlane_theme/src/scss/pages/_mel-browse.scss` | A |
| `config/sync/views.view.upcoming_events.yml` | A (empty text only) |
| `web/themes/custom/myeventlane_theme/templates/event/sidebar/mel-event-sidebar-slide-trust.html.twig` | B |
| `web/themes/custom/myeventlane_theme/templates/node/partial--event-full-booking-cta.html.twig` | B |
| `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig` | C |
| `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-nav.html.twig` | C |
| `web/modules/custom/myeventlane_event_studio/css/mel-event-studio-shell.css` | C |
| `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` | D |
| `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` | E |

**No new PHP services** unless audit proves a gap — none identified for Phase 1 foundation.

---

## Assumptions

1. Sidebar carousel convergence merges separately; Phase 1 trust/booking templates must remain compatible with carousel partials.
2. Config change to `views.view.upcoming_events.yml` is exported intentionally (empty area text only).
3. “Create event” link for public empty state points to marketing/onboarding URL or is omitted for anonymous users (vendor create requires auth).
