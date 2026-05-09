# MEL live operations — convergence implementation notes

## 1. Architecture map

- **Operational route (canonical URL):** `myeventlane_event_attendees.vendor_operations` → `/vendor/events/{node}/operations`.
- **Controller physical location:** `VendorEventOperationsController` lives in **`myeventlane_checkout_flow`** (service `myeventlane_checkout_flow.controller.vendor_event_operations`) because `myeventlane_checkout_flow` already depends on `myeventlane_event_attendees`; the inverse dependency would create a **Composer/module cycle** if the controller lived in `myeventlane_event_attendees` and type-hinted `MelAttendeeCheckinManager`.
- **View-model:** `MelVenueOperationsViewModelBuilder` composes `MelAttendeeCheckinManager::readinessForEvent()`, `AttendanceManagerInterface::getAvailability()`, and `MelAttendeeOperationsPresenter::buildEventViewModel()` (no new SQL).
- **Presentation:** `mel_venue_operations` theme + Twig `mel-venue-operations.html.twig`; empty state via `GovernedOperationalTemplates::vendorAttendeeOperationsNoAttendeesYet()` (`mel_empty_state`).

## 2. Mutation authority map

All **event_attendee** check-in transitions intended for this slice route through **`MelAttendeeCheckinManager`**:

- `checkInAttendee()` / `undoCheckIn()` — aliases for `checkIn()` / `reverseCheckIn()`.
- `markArrived()` — `checkIn(..., SOURCE_MARK_ARRIVED)`.
- `markManualOverride(bool)` — check-in or `reverseCheckIn()`.
- `checkInForTicketParagraph()` / `undoCheckInForTicketParagraph()` — resolve `event_attendee` from `commerce_order_item` + `field_ticket_holder` index, then canonical transition + mirror.

**Mirror / entity fix:** `reverseCheckIn()` now clears **`checked_in_at`** when reverting to confirmed (aligned with API undo behaviour).

## 3. Route ownership map

| Concern | Owner module |
|---------|--------------|
| Route definition | `myeventlane_event_attendees.routing.yml` |
| Controller service + VM builder | `myeventlane_checkout_flow.services.yml` |
| Theme hook | `myeventlane_checkout_flow.module` (`hook_theme`) |

## 4. Operational governance map

- Vendor shell: `VendorConsoleBaseController::buildVendorPage('mel_event_workspace', …)` — inherits workspace **SurfaceNegotiator** metadata (state, workflow, operational policy, experience) from the vendor theme pipeline.
- Tabs: `VendorEventTabsService` adds **Live operations** tab (`operations` key).
- Vendor attendees primary action now prefers **`vendor_operations`** route, falling back to `myeventlane_checkin.page` if route building fails.

## 5. Duplication removal report

| Previous duplicate | Replacement |
|--------------------|-------------|
| Paragraph field writes in `VendorCheckInController::scanCheckIn` | `checkInForTicketParagraph` |
| Paragraph writes in `CheckinController::jsonCheckInParagraph` | `checkInForTicketParagraph` (`door_json` source) |
| `CheckInForm` submit paragraph toggles | `checkInForTicketParagraph` / `undoCheckInForTicketParagraph` |
| `VendorAttendeeController::checkIn` JSON | `checkInAttendee` (with legacy fallback if checkout_flow absent) |
| `QrCheckinController` Commerce RSVP branch | `checkInAttendee` when manager service exists |
| `VendorAttendeeApiController` entity `checkIn()` | `markManualOverride` when manager exists |
| `CheckInStorage` ticket toggle | `markManualOverride` when manager exists |

## 6. Security model

- **Door JSON:** unchanged CSRF gate; adds **503** if `MelAttendeeCheckinManager` is unavailable (refuses silent paragraph-only drift).
- **Token / paragraph paths:** existing paragraph access + vendor ownership checks stay in controllers; writes delegated to manager (which enforces `MelAttendeeOperationsAccess`).
- **API:** actor resolved to **vendor owner user** for audit-capable operations.

## 7. Observability model

- Existing **logger channel** `myeventlane_checkout_flow` on manager transitions.
- **MELObservabilitySystem** staff payload alter extended with `myeventlane_event_attendees.vendor_operations`.

## 8. Operational UX model

- Mobile-first layout classes on `mel-venue-operations` wrapper; metrics chips; attendee cards (first 40 rows); recent check-ins sidebar; search form GETs to canonical attendee list with `q`.

## 9. Accessibility notes

- Section headings and `aria-labelledby` on hero/metrics; visually hidden labels on search; ticket codes prefixed with screen-reader-only “Ticket code”.

## 10. Future QR roadmap

- Point handheld scanner POST/JSON flows at **`checkInForTicketParagraph`** or **`checkInAttendee`** with `SOURCE_QR_SCAN` / `SOURCE_MARK_ARRIVED`.
- **Do not** reintroduce paragraph-only writes for ticketed events while `myeventlane_checkout_flow` is enabled.

---

## Validation (executed in agent environment)

| Command | Result |
|---------|--------|
| `php -l` (all touched PHP files) | **OK** |
| `composer validate --no-check-publish` | **OK** |
| `cd web && ../vendor/bin/phpunit -c phpunit-governance.xml --filter MelAttendeeCheckinManagerTest` | **OK** (7 tests) |
| `cd web && ../vendor/bin/phpunit -c phpunit-governance.xml` | **OK** (67 tests, 23s; 1 PHPUnit warning + deprecations). |
| `composer governance:audit` | **Not run here** — run locally. |
| `npm run mel:lint` / `npm run mel:build` | **Not run here** — run locally after Twig/SCSS consumption review. |
| `ddev drush cr` | **Not run here** — required after deploy to rebuild container routes/services. |

## Residual risk

- **Module disable:** If `myeventlane_checkout_flow` is disabled, several paths fall back to legacy behaviour or return **503** (door JSON) — operational environments should keep checkout_flow enabled.
- **`TicketCheckinService`** and **legacy RSVP** paths remain separate persistence models; counts may diverge if those subsystems are used alongside `event_attendee` without future integration work.
