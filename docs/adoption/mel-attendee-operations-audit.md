# MEL Attendee Operations Audit

Audit of every attendee operational surface, storage path, export, and access
control in the MyEventLane platform, performed before implementing the Vendor
Attendee Operations Layer. Read-only inventory; no code removed.

## 1. Canonical attendee ownership map

```
checkout (cart)                                   vendor operations / check-in
─────────────────────────                         ─────────────────────────────
commerce_order                                    event (node bundle)
   └─ commerce_order_item                            └─ event_attendee (entity)
        └─ field_ticket_holder ──┐                       ├─ status, source, ticket_code
             (paragraphs.entity_reference_revisions,    ├─ extra_data (map)
              cardinality -1, target = attendee_answer) ├─ checked_in_at
                                 │                       └─ uid (optional)
                                 ▼
                         attendee_answer (paragraph)
                            ├─ field_first_name / field_last_name
                            ├─ field_email / field_phone
                            ├─ field_attendee_questions  (-1, attendee_extra_field)
                            ├─ field_checked_in (boolean)
                            ├─ field_checked_in_timestamp
                            └─ field_checked_in_by (entity_reference user)

rsvp (RSVP form)
─────────────────────────
rsvp_submission (entity)  ──upsert──►  event_attendee
   ├─ attendee_name, email, guests, phone
   └─ event_id (entity ref)
```

Two persistence paths exist intentionally:

- **`attendee_answer` paragraphs** — the **checkout snapshot** of organiser
  questions answered at purchase time, owned by `commerce_order_item.field_ticket_holder`.
  Mutable while an order is unlocked (see `AttendeeParagraphAccessResolver::isOrderLocked()`).
- **`event_attendee` entity** — the **canonical operational attendance record**
  used by every vendor surface. RSVPs, ticket purchases, and manual entry all
  produce one row per attendee here. Source is recorded on `source` and the
  link back to the order item is `order_item`.

Vendors and admins consume `event_attendee`. Customers continue to interact
with `attendee_answer` paragraphs via the checkout pane.

## 2. Operational UX gaps

| # | Gap | Surface |
|---|-----|---------|
| 1 | Vendor attendee state is presented inconsistently — `confirmed`, `Confirmed`, `Yes`, `No`, raw enum values appear across templates. | `VendorAttendeeController::list`, `myeventlane-vendor-event-attendees.html.twig`, `event/attendees.html.twig` |
| 2 | No canonical badge / palette for the attendance lifecycle. | All vendor attendee templates |
| 3 | No event-grouped, ticket-grouped composite view; attendees show flat or per-event without ticket-tier rollups. | `myeventlane-vendor-attendees-dashboard.html.twig` |
| 4 | "Export CSV" links go to three different controllers depending on entry point. | Vendor dashboard, vendor event attendees, RSVP page |
| 5 | Search / filter by attendance state is client-side only (`data-mel-table-search`) and only by name/email. | `myeventlane_vendor_theme/main.js` |
| 6 | Inline empty-state copy in Twig (`No attendees yet`, `No attendees found for this event`). | Multiple |
| 7 | Sticky operational actions (export, mark all checked-in) absent on mobile. | All vendor attendee templates |
| 8 | Refunded, cancelled, and pending order outcomes are not surfaced as attendee states. | `event_attendee.status` only carries lifecycle states, not order-derived states |

## 3. Duplicated attendee rendering

| Surface | Path | Notes |
|---------|------|-------|
| Vendor attendees & sales dashboard (module) | `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-attendees-dashboard.html.twig` | Renders KPI bar + per-event card. |
| Vendor attendees & sales dashboard (theme include) | `web/themes/custom/myeventlane_theme/templates/myeventlane-vendor-attendees-dashboard.html.twig` | Wraps the module template. |
| Per-event attendee list | `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-event-attendees.html.twig` | Tables per ticket type (own markup). |
| Vendor theme per-event attendees | `web/themes/custom/myeventlane_vendor_theme/templates/event/attendees.html.twig` | Independent table markup. |
| Vendor theme audience snapshot | `web/themes/custom/myeventlane_vendor_theme/templates/vendor/audience.html.twig` | Independent table markup. |
| Order detail vendor view | `web/themes/custom/myeventlane_vendor_theme/templates/event/order-view.html.twig` | Inline attendee rows per order. |
| Order detail (customer) | `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-order-detail.html.twig` | `ticket.attendees` rendered from preprocessed `order_items_attendees`. |
| Checkout pane | `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php` | Form, not display. |
| Check-in module | `web/modules/custom/myeventlane_checkin/templates/checkin-list.html.twig`, `checkin-page.html.twig` | Independent rows. |
| Vendor check-in (checkout flow) | `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-checkin.html.twig` | Independent rows. |

`VendorAttendeePresentationService::buildVendorRowFromEventAttendee()` already
exists as the canonical row payload but is consumed by only two of the surfaces
above. The remainder produce their own ad-hoc shapes.

## 4. Duplicated export logic

Four CSV exporters exist:

| Route | Controller | Source | Schema | Hardening |
|-------|-----------|--------|--------|-----------|
| `myeventlane_views.attendee_csv` | `AttendeeCsvController::handle` | `attendee_answer` paragraphs | First/Last/Email/Phone/Question/Answer/Checked in/Time | Paragraph entity access only; route requires only `access content`; no formula-injection guard |
| `myeventlane_event_attendees.vendor_export` | `VendorAttendeeController::export` | `event_attendee` via `VendorAttendeePresentationService::buildCsvExportRow()` | Name/Email/Phone/Source/Variation/Ticket Code/Custom answers/Checked In/Checked In At | Owner / vendor users / admin gate; no formula-injection guard; optional email obfuscation |
| `myeventlane_checkout_paragraph.export_csv` | `AttendeeExportController::export` | Same as above | Same as above | `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()` checked inside controller; no formula guard |
| `myeventlane_rsvp.export_csv` | `VendorRsvpExportController::export` | `UserRsvpRepository` rows | First/Last/Email/Status/Created | `myeventlane_rsvp.vendor_event_access` route gate; uses `sprintf` (not `fputcsv`); no formula guard |

Convergence target: a single `MelAttendeeExportBuilder` that owns canonical
header order, formula-injection escaping, and row formatting; existing four
controllers continue to own URLs, but delegate row generation.

## 5. Attendee route inventory

| Route ID | Path | Surface | Access |
|----------|------|---------|--------|
| `myeventlane_checkout_flow.vendor_attendees` | `/vendor/attendees` | Vendor dashboard | `access content` + `VendorAttendeesController::checkAccess` |
| `myeventlane_event_attendees.vendor_list` | `/vendor/events/{node}/attendees` | Per-event vendor list | `myeventlane_vendor.access.vendor_console:access` |
| `myeventlane_event_attendees.vendor_export` | `/vendor/events/{node}/attendees/export` | Per-event vendor CSV | `VendorAttendeeController::access` |
| `myeventlane_checkout_paragraph.export_csv` | (in module routing) | Workspace-parity CSV | `access content` + controller-internal access |
| `myeventlane_views.attendee_csv` | `/dashboard/attendees/export` | Legacy paragraph CSV | `access content` + paragraph entity access |
| `myeventlane_rsvp.export_csv` | `/vendor/event/{event}/rsvps/export` | RSVP-only CSV | `myeventlane_rsvp.vendor_event_access:access` |
| `myeventlane_checkout_flow.vendor_checkin_scan` | (vendor scan) | QR scan landing | `access content` + custom |
| `myeventlane_checkout_flow.vendor_checkin_action` | (manual toggle) | Per-paragraph toggle | `_entity_access: paragraph.update` |
| `myeventlane_checkin.page` / `myeventlane_checkin.toggle` | `/vendor/events/{node}/check-in/*` | Event-scoped check-in | Module-internal |

## 6. Attendee dashboard inventory

- Vendor "Attendees & Sales" dashboard (`/vendor/attendees`) — `VendorAttendeesController::dashboard`.
- Per-event attendees (`/vendor/events/{node}/attendees`) — `VendorAttendeeController::list`.
- Vendor dashboard cards (myeventlane_dashboard) — surface attendee counts.
- Vendor analytics — surfaces aggregate attendee KPIs via
  `VendorAnalyticsViewModelBuilder` (separate module, no schema overlap).
- Event Studio — surfaces attendee question schema for editing only, not
  attendee rows.

## 7. Vendor-only access inventory

Vendor and admin gates currently in use:

- `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()` — workspace parity (owner / vendor users / `field_event_vendor`).
- `VendorOwnershipResolver::vendorOwnsEvent()` / `getStoreForUser()` — store-based ownership.
- `myeventlane_vendor.access.vendor_console:access` — vendor console route gate.
- `myeventlane_rsvp.vendor_event_access:access` — RSVP route gate.
- `paragraph.update` route entity access — for paragraph mutations.
- `myeventlane_checkout_paragraph_entity_access()` — per-paragraph view/update gate
  using `vendor_ownership_resolver` + `access_resolver`.
- `EventAttendeeAccessControlHandler` — per-`event_attendee` access.

These rules are correct but distributed. The new
`MelAttendeeOperationsAccess` consolidates *operational* access decisions (view
list, export, check-in) so they can be reused by every attendee surface
without duplicating ownership chains.

## 8. CSV export inventory

See section 4. Hardening gaps:

- No formula-injection escaping (`=`, `+`, `-`, `@`, `\t`, `\r`) on any of the
  four exporters.
- `VendorRsvpExportController` uses `sprintf` and does not quote commas or
  newlines in attendee names.
- `AttendeeCsvController` writes a CSV header that does not match
  the canonical `VendorAttendeePresentationService` shape.
- BOM / charset declared inconsistently across exporters.

## 9. Check-in readiness inventory

- **Storage**: `attendee_answer` paragraph has `field_checked_in`,
  `field_checked_in_timestamp`, `field_checked_in_by`. `event_attendee` has
  `status` (incl. `STATUS_CHECKED_IN`) and `checked_in_at`.
- **Existing transitions**: `AttendanceManager::checkIn(EventAttendee)`,
  `EventAttendee::checkIn()`, `VendorCheckInController::scanCheckIn()`.
- **Tokens**: `CheckInTokenService` (HMAC) for QR-style check-in URLs.
- **Audit gaps**: No single service records *who* checked in *which* attendee
  via *which* path with audit metadata. Two separate code paths exist (event
  attendee status vs. paragraph fields) with no enforced sync.

The new `MelAttendeeCheckinManager` wraps `AttendanceManager` and the paragraph
fields so a single transition path yields a single audit log entry. **No new
fields are added** — existing paragraph and entity fields are reused.

## 10. QR readiness inventory

- `CheckInTokenService` (HMAC) is the canonical token issuer.
- `QrCheckinController` (RSVP) and `VendorCheckInController` (checkout flow) both
  consume tokens, but with different validation logic.
- No QR secret leaks were found in Twig or JS; tokens are issued server-side and
  validated server-side.
- Mobile scanning UI is partial (`myeventlane_rsvp/qrscan` + `myeventlane_checkin/checkin.js`).

The architecture (token issuer + access policy + presenter) is now ready for a
future mobile scanning pass; this audit explicitly does not introduce new
scanning code.

## 11. Attendee analytics inventory

- `myeventlane_analytics` exposes vendor analytics dashboards but reads
  pre-aggregated data via `VendorAnalyticsViewModelBuilder`. No raw attendee
  PII flows through analytics; the audit confirms no new analytics changes are
  needed for this layer.

## 12. Security / privacy findings

| Finding | Severity | Notes |
|---------|----------|-------|
| Three CSV controllers lack spreadsheet formula injection escaping. | Medium | Convergence on `MelAttendeeExportBuilder` will add `=`/`+`/`-`/`@`/`\t`/`\r` prefix neutralisation and BOM. |
| `myeventlane_views.attendee_csv` route gate is only `access content`; access is enforced by paragraph entity access at row level. | Low | Functional but fragile; row-level filtering adds query cost. |
| `VendorAttendeesController` and `AttendeeExportController` use `\Drupal::service()` / `\Drupal::request()` inside instance methods. | Low | Pre-existing; out of scope for this layer; flagged as residual risk. |
| `VendorRsvpExportController::export` builds CSV via `sprintf` with no quoting. | Medium | Will be fixed by delegating to `MelAttendeeExportBuilder`. |
| Inline attendee empty-state copy in Twig is not subject to `MelReadinessHelper` review. | Low | Convergence on `mel_empty_state` + readiness slots planned. |

No PII leakage to public surfaces was found. Vendor isolation is enforced at
the entity-access and route-access layer, not in Twig.

## 13. Recommended convergence targets

1. **Single operational presenter**: `MelAttendeeOperationsPresenter`
   composes `VendorAttendeePresentationService` (rows) +
   `AttendanceManagerInterface` (loader) and produces a stable view-model
   shape for vendor attendee dashboards, per-event lists, and order detail.
2. **Single attendance state class**: `MelAttendeeAttendanceState` maps the
   `event_attendee.status` enum + commerce order signals to the operational
   states required for UI badges (`registered`, `checked_in`, `cancelled`,
   `refunded`, `pending`, `invalid`).
3. **Single check-in manager**: `MelAttendeeCheckinManager` wraps
   `AttendanceManager::checkIn()` and the paragraph fields with audit
   metadata, ownership validation, and duplicate prevention.
4. **Single access policy**: `MelAttendeeOperationsAccess` consolidates
   workspace-parity, store-ownership, and admin-override checks behind
   action-named methods (`canViewAttendees`, `canExportAttendees`,
   `canCheckInAttendees`).
5. **Single export builder**: `MelAttendeeExportBuilder` owns header order,
   formula-injection neutralisation, and per-row formatting; existing four
   controllers retain their URLs and delegate row generation.
6. **Governed empty states**: New slots in `MelReadinessHelper`
   (no attendees yet, no ticket sales yet, no RSVP submissions yet, export
   unavailable, search empty, filter empty) and matching builders in
   `GovernedOperationalTemplates`.
7. **Observability**: `mel_observability_page_payload` alter implementation in
   `myeventlane_checkout_flow.module` adds attendee-operational traces
   without leaking PII.
8. **SCSS**: One operational stylesheet
   (`_mel-attendee-operations.scss`) that themes the badges, rows, sticky
   actions, and filters using existing MEL tokens.

## Out of scope for this layer

- Adding new attendee entity types, fields, or schema migrations.
- Changing payment / Stripe Connect behaviour.
- Mobile scanner UI redesign.
- Reorganising the four export route URLs (preserved to avoid breakage).
- Refactoring legacy `\Drupal::service()` usage in pre-existing controllers
  (out of scope per "ONE TASK ONLY"; flagged as residual risk).

## 14. Validation log (Step 12)

Run from the workspace root unless noted. Sandbox cannot start DDEV; the
`ddev drush cr` step must be executed locally by the developer.

| Command | Result | Notes |
|---------|--------|-------|
| `php -l` on every new + modified PHP file (22 files) | OK | All files parse cleanly under PHP 8.5. |
| `composer validate --no-check-publish` | OK | `./composer.json` is valid. |
| `composer governance:audit` | OK | `architecture-audit`, `surface-audit`, `template-parity-audit` all report clean (no failures). |
| `composer governance:test` | OK | 66 tests, 264 assertions, 0 failures, 0 errors, 1 pre-existing warning, 88 PHPUnit-internal deprecations. |
| `npm run mel:lint` | OK | `check:hero` passes, `lint:css` passes (curated subset). |
| `npx stylelint web/themes/custom/myeventlane_theme/src/scss/components/_mel-attendee-operations.scss` | OK | New partial passes stylelint (range notation + empty-line-before fixed). |
| `vite build` (myeventlane_theme) | OK | 46 modules transformed; new chip / summary / sticky styles compiled into `dist/assets/main.*.css` (`mel-attendee-operations__chip--checked-in` confirmed present). |
| `vite build` (myeventlane_vendor_theme) | OK | 6 modules transformed. |
| `ddev drush cr` | NOT RUN HERE | Must be run locally; service rebuild required for the new `MelAttendeeOperationsAccess`, `MelAttendeeOperationsPresenter`, `MelAttendeeCheckinManager`, and `MelAttendeeExportBuilder` registrations. |

### New tests registered in `web/phpunit-governance.xml`

- `MelAttendeeAttendanceStateTest` — 12 cases.
- `MelAttendeeOperationsPresenterTest` — 4 cases.
- `MelAttendeeOperationsAccessTest` — 6 cases.
- `MelAttendeeCheckinManagerTest` — 6 cases.
- `MelAttendeeExportBuilderTest` — 8 cases.

All 36 new cases pass; total governance suite is 66 tests / 264 assertions.

### Files changed (operational layer scope)

New PHP services / value objects / interfaces:

- `web/modules/custom/myeventlane_checkout_flow/src/MelAttendeeAttendanceState.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelAttendeeOperationsAccess.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelAttendeeOperationsAccessInterface.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelAttendeeOperationsPresenter.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelAttendeeCheckinManager.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/MelAttendeeExportBuilder.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/VendorAttendeePresentationServiceInterface.php`
- `web/modules/custom/myeventlane_vendor/src/Service/EventVendorAccessCheckerInterface.php`

Controllers retargeted to delegate (URLs and access checks unchanged):

- `web/modules/custom/myeventlane_event_attendees/src/Controller/VendorAttendeeController.php`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php`
- `web/modules/custom/myeventlane_rsvp/src/Controller/VendorRsvpExportController.php`
- `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`

Governance / observability / surface integration:

- `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.module`
- `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.services.yml`
- `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.info.yml`
- `web/modules/custom/myeventlane_event_attendees/myeventlane_event_attendees.services.yml`
- `web/modules/custom/myeventlane_surface/src/MelReadinessHelper.php` (new attendee operations slots)
- `web/modules/custom/myeventlane_surface/src/GovernedOperationalTemplates.php` (matching builders)
- `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-attendees-dashboard.html.twig` (operational summaries section)

Theme:

- `web/themes/custom/myeventlane_theme/src/scss/components/_mel-attendee-operations.scss`
- `web/themes/custom/myeventlane_theme/src/scss/main.scss` (import)

Tests:

- `web/modules/custom/myeventlane_checkout_flow/tests/src/Unit/MelAttendeeAttendanceStateTest.php`
- `web/modules/custom/myeventlane_checkout_flow/tests/src/Unit/MelAttendeeOperationsPresenterTest.php`
- `web/modules/custom/myeventlane_checkout_flow/tests/src/Unit/MelAttendeeOperationsAccessTest.php`
- `web/modules/custom/myeventlane_checkout_flow/tests/src/Unit/MelAttendeeCheckinManagerTest.php`
- `web/modules/custom/myeventlane_event_attendees/tests/src/Unit/MelAttendeeExportBuilderTest.php`
- `web/phpunit-governance.xml` (registration of the five new test files)

### Architecture reasoning

- **`MelAttendeeAttendanceState` lives in `myeventlane_checkout_flow`** because the
  checkout flow already owns the attendee-presentation surface and the state
  vocabulary tracks both `EventAttendee` lifecycle and order-derived signals
  produced during checkout.
- **`MelAttendeeExportBuilder` lives in `myeventlane_event_attendees`** so the
  four CSV controllers (paragraph, vendor, rsvp, views) can depend on it
  without introducing a circular dependency on `myeventlane_checkout_flow`.
  The builder mirrors the state-derivation logic via private methods and
  asserts equivalence with the enum in unit tests, so the vocabulary is
  defined once in the enum but the export remains a leaf consumer.
- **`MelAttendeeOperationsAccess` is the single ownership gate.** It composes
  `EventVendorAccessCheckerInterface` (workspace parity / vendor membership)
  and the canonical "administer event attendees" / "administer commerce_order"
  permissions. All decisions are cacheable. Fail-closed on unknown bundles,
  anonymous accounts, and unresolvable events.
- **Two thin interfaces (`VendorAttendeePresentationServiceInterface`,
  `EventVendorAccessCheckerInterface`) and one new operations interface
  (`MelAttendeeOperationsAccessInterface`)** were extracted from existing
  `final` classes. The implementations remain `final`; existing call sites
  that type-hint the concrete class continue to work because the concrete
  class now also implements the interface. The interfaces exist exclusively
  to make the new operational layer unit-testable without doubling final
  classes.

### Ownership map (post-implementation)

| Surface | Reads from | Writes via | Access via |
|---------|------------|------------|------------|
| Vendor attendees dashboard (`/vendor/event/{event}/attendees`) | `MelAttendeeOperationsPresenter` | n/a (read only) | `MelAttendeeOperationsAccess::canViewAttendees` |
| Vendor attendee CSV export (vendor controller) | `MelAttendeeExportBuilder::buildRowsForEvent` | `streamCsv` | `MelAttendeeOperationsAccess::canExportAttendees` (via existing route gate) |
| RSVP-only CSV export | `MelAttendeeExportBuilder::buildRowsForEvent($event, FALSE, 'rsvp')` | `streamCsv` | Existing route + ownership |
| Paragraph attendee CSV export | `MelAttendeeExportBuilder::buildRowsForEvent` | `streamCsv` | Existing route + `EventVendorAccessChecker` |
| Views attendee CSV | `MelAttendeeExportBuilder::buildRowsForEvent` (event_attendee load) | `streamCsv` | `event_attendee` entity access |
| Future check-in (vendor list toggle, QR scan, admin override) | `MelAttendeeCheckinManager::checkIn` / `reverseCheckIn` | `AttendanceManager::checkIn` + paragraph mirror | `MelAttendeeOperationsAccess::canCheckInAttendees` |

### Duplication removed

- One CSV header definition (was three controllers).
- One row-formatting path (was three controllers + ad-hoc `sprintf`).
- One spreadsheet formula-injection guard (was zero).
- One UTF-8 BOM emission (was inconsistent).
- One operational state vocabulary (was inline strings in two Twig templates
  and one controller).
- One empty-state copy slot per scenario (was inline Twig strings).

### Residual risks

- `VendorAttendeesController` and `AttendeeExportController` still call
  `\Drupal::service()` / `\Drupal::request()` inside instance methods. Out
  of scope for this layer; tracked as a future refactor.
- `myeventlane_views.attendee_csv` route gate is still `access content`; the
  new builder enforces `event_attendee` entity-access on every row, but the
  route-level gate is unchanged. Stronger gate would be a follow-up.
- `MelAttendeeCheckinManager` uses the existing paragraph mirror fields
  (`field_checked_in*`) for ticket-sourced attendees; no actor uid base
  field is added to `event_attendee` (per "no schema change"). A future
  `hook_update_N` could promote the actor uid to a base field if the
  audit log needs to survive paragraph deletions.

### Future QR roadmap (no code in this layer)

1. Reuse `MelAttendeeCheckinManager::checkIn(..., source: SOURCE_QR_SCAN)`
   from a hardened mobile scanner controller.
2. Reuse `MelAttendeeOperationsAccess::canCheckInAttendees` as the access
   gate; no new policy needed.
3. Reuse `CheckInTokenService` for HMAC token issuance/validation.
4. Reuse `MelAttendeeOperationsPresenter::buildRow` to render the post-scan
   confirmation UI with consistent state badges.
