# MEL live operations — convergence implementation notes

## 1. Architecture map

- **Operational route (canonical URL):** `myeventlane_event_attendees.vendor_operations` → `/vendor/events/{node}/operations`.
- **Door Mode (canonical vendor URL):** `myeventlane_event_attendees.vendor_operations_door` → `/vendor/events/{node}/operations/door` (JSON: `vendor_operations_door_validate` → `/vendor/events/{node}/operations/door/validate`).
- **Controller physical location:** `VendorEventOperationsController` lives in **`myeventlane_checkout_flow`** (service `myeventlane_checkout_flow.controller.vendor_event_operations`) because `myeventlane_checkout_flow` already depends on `myeventlane_event_attendees`; the inverse dependency would create a **Composer/module cycle** if the controller lived in `myeventlane_event_attendees` and type-hinted `MelAttendeeCheckinManager`.
- **View-model:** `MelVenueOperationsViewModelBuilder` composes `MelAttendeeCheckinManager::readinessForEvent()`, `AttendanceManagerInterface::getAvailability()`, and `MelAttendeeOperationsPresenter::buildEventViewModel()` (no new SQL).
- **Door JSON (shared):** `MelDoorCheckinValidateService` in `myeventlane_checkout_flow` — used by `CheckinController::validate()` and `VendorEventOperationsController::doorValidate()` so scanner and manual paths share one implementation and `MelAttendeeCheckinManager::checkInForTicketParagraph(..., SOURCE_DOOR_JSON)`.
- **Presentation:** `mel_venue_operations` theme + Twig `mel-venue-operations.html.twig`; `mel_vendor_door_checkin` + `mel-vendor-door-checkin.html.twig`; theme SCSS `components/_live-operations.scss`; empty state via `GovernedOperationalTemplates::vendorAttendeeOperationsNoAttendeesYet()` (`mel_empty_state`).

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
- Vendor attendees primary “Check-in” CTA prefers **`vendor_operations_door`**, then **`vendor_operations`**, then `myeventlane_checkin.page` if route building fails. Attendees & Sales dashboard cards use the same door URL when `AttendeeEventStatsService` / controller enrichment supplies it.

## 5. Duplication removal report

| Previous duplicate | Replacement |
|--------------------|-------------|
| Paragraph field writes in `VendorCheckInController::scanCheckIn` | `checkInForTicketParagraph` |
| Paragraph writes in `CheckinController` (delegated validate) | `MelDoorCheckinValidateService` → `checkInForTicketParagraph` (`SOURCE_DOOR_JSON`) |
| `CheckInForm` submit paragraph toggles | `checkInForTicketParagraph` / `undoCheckInForTicketParagraph` |
| `VendorAttendeeController::checkIn` JSON | `checkInAttendee` (with legacy fallback if checkout_flow absent) |
| `QrCheckinController` Commerce RSVP branch | `checkInAttendee` when manager service exists |
| `VendorAttendeeApiController` entity `checkIn()` | `markManualOverride` when manager exists |
| `CheckInStorage` ticket toggle | `markManualOverride` when manager exists |

## 6. Security model

- **Door JSON:** unchanged CSRF gate (`mel_door_checkin`); **503** if `MelDoorCheckinValidateService` / `MelAttendeeCheckinManager` is unavailable (refuses silent paragraph-only drift).
- **Public `/event/{event}/checkin` access:** `CheckinController::checkAccess()` now aligns with workspace parity via `EventVendorAccessCheckerInterface` plus staff permissions (`administer event attendees`, `administer commerce_order`, `bypass node access`) — same intent as `MelAttendeeOperationsAccess` for check-in surfaces.
- **Vendor door routes:** `VendorConsoleBaseController::assertEventOwnership()` + `MelAttendeeOperationsAccess::canCheckInAttendees()` before rendering or JSON.
- **Token / paragraph paths:** existing paragraph access + vendor ownership checks stay in controllers; writes delegated to manager (which enforces `MelAttendeeOperationsAccess`).
- **API:** actor resolved to **vendor owner user** for audit-capable operations.

## 7. Observability model

- Existing **logger channel** `myeventlane_checkout_flow` on manager transitions.
- **MELObservabilitySystem** staff payload alter extended with `myeventlane_event_attendees.vendor_operations`, `vendor_operations_door`, and `vendor_operations_door_validate`.

## 8. Operational UX model

- Hybrid layout: metric cards (totals, rate, ready, not eligible, capacity), two-column split (search + recent left; attendee cards right), large row CTAs with POST check-in to `vendor_checkin` when eligible; Door Mode uses `myeventlane_tickets/checkin_scanner` when that module is enabled + `mel_vendor_door_checkin` behaviour for torch/scanner wiring.

## 9. Accessibility notes

- Section headings and `aria-labelledby` on hero/metrics; visually hidden labels on search; ticket codes prefixed with screen-reader-only “Ticket code”.

## 10. Future QR roadmap

- Vendor Door Mode already posts scanned payloads to **`MelDoorCheckinValidateService`** (same as manual entry). Extend PWA/offline queue only behind explicit product approval.
- **Do not** reintroduce paragraph-only writes for ticketed events while `myeventlane_checkout_flow` is enabled.

---

## Validation (executed in agent environment)

| Command | Result |
|---------|--------|
| `php -l` (touched PHP files) | **OK** |
| `composer validate` | **OK** |
| `composer governance:test` | **OK** (68 tests) |
| `composer governance:audit` | **OK** |
| `npm run mel:lint` | **OK** (after theme `npm ci`) |
| `npm run mel:build` | **OK** |
| `ddev drush cr` | **Not run here** — required locally after pull for routes/libraries/services. |

## Residual risk

- **Module disable:** If `myeventlane_checkout_flow` is disabled, several paths fall back to legacy behaviour or return **503** (door JSON) — operational environments should keep checkout_flow enabled.
- **`TicketCheckinService`** and **legacy RSVP** paths remain separate persistence models; counts may diverge if those subsystems are used alongside `event_attendee` without future integration work.
