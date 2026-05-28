# Private Event Visibility Audit

**Date:** 2026-05-25
**Branch:** `audit/private-event-visibility`
**Auditor:** Architecture review (automated + manual code inspection)

---

## 1. Executive Summary

**MyEventLane does NOT currently have a complete private/unlisted/passcode event visibility model.**

The field `field_event_visibility` is referenced in vendor-facing PHP code (`VendorStudioController::saveSettings`, `VendorStudioController::buildEventPayload`) but:

- **No field storage config exists** in `config/sync`
- **No field instance config exists** in `config/sync`
- **No install hook creates the field**
- **No form display, view display, or Views filter references it**
- **`PublicEventVisibility` does not check it**
- **`PublicEventDiscoveryQueryAlter` does not filter on it**
- **`SearchController` does not filter on it**
- **`PublicEventApiController` does not filter on it**
- **`EventStructuredDataBuilder` does not check it**
- **`EventRecommendationService` does not check it**

The existing visibility model only handles **lifecycle state** (`field_event_state`: draft, scheduled, live, sold_out, ended, cancelled, archived) and **publish status** (node `status`). There is no mechanism to mark a published, live event as unlisted, private, or passcode-protected.

**Risk: CRITICAL** — If a vendor were to set a visibility value via VendorStudio (the JS would silently succeed on future field creation), there would be no enforcement preventing the event from appearing in all public surfaces.

---

## 2. Existing Systems Found

### 2.1 Fields (exported in config/sync)

| Field | Type | Purpose |
|-------|------|---------|
| `field_event_state` | `list_string` | Lifecycle state (draft/scheduled/live/sold_out/ended/cancelled/archived) |
| `field_event_state_override` | `list_string` | Admin override for cancelled/archived |
| `field_event_visibility` | **NOT EXPORTED** | Referenced in code but does not exist in config |

### 2.2 Services

| Service | File | Role |
|---------|------|------|
| `myeventlane_event.public_visibility` | `PublicEventVisibility.php` | Canonical PHP rules for public listability |
| `myeventlane_event.discovery_query_alter` | `PublicEventDiscoveryQueryAlter.php` | Views SQL alter for public discovery |
| `myeventlane_event_state.resolver` | `EventStateResolver.php` | Computes lifecycle state from timing/capacity/overrides |
| `myeventlane_event.structured_data_builder` | `EventStructuredDataBuilder.php` | JSON-LD builder (guards via `isPubliclyListable`) |
| `myeventlane_event.booking_flow_resolver` | `BookingFlowResolver.php` | Booking mode resolution |
| `myeventlane_commerce.ticket_access_code` | `TicketAccessCodeService.php` | Ticket-tier access codes (NOT event-level passcodes) |
| `myeventlane_commerce.ticket_tier_access` | `TicketTierAccessService.php` | Ticket visibility modes (public/hidden/access_code/group_only) |

### 2.3 Route Access

| Route | Access requirement | Notes |
|-------|-------------------|-------|
| `/event/{node}/book` | `_permission: 'access content'` | No visibility guard |
| `/events` | Views page (upcoming_events) | Views filters only; no visibility field |
| `/calendar` | Views page (events_calendar) | Views filters only; no visibility field |
| `/search` | `SearchController` | No visibility filter beyond type+date |
| `/api/v1/events` | `PublicEventApiController` | Uses `isPubliclyListable()` — lifecycle only |
| `/api/v1/events/{node}` | `PublicEventApiController` | Uses `isPubliclyListable()` — lifecycle only |
| Event canonical (node page) | Standard Drupal node access | Published = visible to all |

### 2.4 Views Filters (PublicEventDiscoveryQueryAlter)

The service `PublicEventDiscoveryQueryAlter` applies SQL filters to these public Views:

- `upcoming_events` (8 displays: page_events, page_free, page_today, page_this_weekend, page_popular, page_category, homepage_latest, homepage_tonight)
- `front_featured_events` (block_featured)
- `mel_home_events` (discover, tonight, under_20, near_you)
- `featured_events` (block_spotlight, block_featured)
- `featured_events_carousel`
- `front_discover_events` (block_discover)
- `front_recommended_events` (block_1)
- `featured_discover_recommended` (default, block_1)
- `new_events` (block_1, page_1)
- `events_calendar` (page_1, block_1)

**None of these filter on a visibility field.** They only filter:
- `field_event_state != 'ended'`
- Internal title markers (test/demo/copy/untitled)

### 2.5 Controllers (VendorStudioController)

`VendorStudioController::saveSettings()` (line 741) writes to `field_event_visibility` using `$event->hasField()` guard. Currently this guard returns `FALSE` because the field doesn't exist, so the code path is dead.

`VendorStudioController::buildEventPayload()` (line 1074) reads visibility for the Studio JS — returns empty string.

### 2.6 Twig/Preprocess Logic

No Twig template or preprocess function references `field_event_visibility` for display or access gating.

### 2.7 Existing Documentation

| Doc | Reference |
|-----|-----------|
| `docs/deploy/staging-public-discovery-seo-smoke-test.md` | Notes `field_event_visibility` is "referenced but not exported" and flags this as a risk |
| `docs/ux-trust-guidance-audit.md` | References access code UX for ticket tiers (not event-level) |

---

## 3. Missing or Unsafe Areas

| Question | Answer | Risk |
|----------|--------|------|
| Is `field_event_visibility` exported in config/sync? | **NO** | Critical |
| Is it referenced in code without existing in config? | **YES** (VendorStudioController) | High |
| Are private/unlisted/passcode events excluded from homepage? | **NO** (no concept exists) | Critical |
| Are they excluded from `/events`? | **NO** | Critical |
| Are they excluded from `/calendar`? | **NO** | Critical |
| Are they excluded from category pages? | **NO** | Critical |
| Are they excluded from search? | **NO** | Critical |
| Are they excluded from public API? | **NO** | Critical |
| Are they excluded from JSON-LD? | **NO** | Critical |
| Are they excluded from sitemap/SEO? | N/A (no sitemap module installed) | Medium |
| Can a vendor still preview/manage them? | Yes (standard node owner access) | Low |
| Can admin/staff still access them? | Yes (standard admin access) | Low |
| Can a customer access an unlisted event by direct URL? | N/A (no model exists) | N/A |
| Is passcode validation implemented anywhere? | **NO** (ticket-tier access codes exist, but not event-level) | High |

---

## 4. Risk Rating

| Surface | Risk | Notes |
|---------|------|-------|
| Homepage blocks | **Critical** | No visibility filtering beyond lifecycle state |
| `/events` listing | **Critical** | No visibility filtering |
| `/calendar` | **Critical** | No visibility filtering |
| Category pages | **Critical** | No visibility filtering |
| Search (`/search`) | **Critical** | No visibility filtering |
| Public API | **Critical** | Only lifecycle gated, not visibility gated |
| JSON-LD | **Critical** | Only lifecycle gated |
| Event canonical URL | **High** | Any published event viewable by anonymous |
| Booking route | **High** | Any published event bookable |
| Related events | **Critical** | No visibility filtering in `EventRecommendationService` |
| Saved events (View) | **Medium** | Shows all published events the user saved |
| Vendor/admin access | **Low** | Already functional via node ownership |

**Overall rating: CRITICAL — the visibility model does not exist. If the field were to be created and populated, no enforcement would activate automatically.**

---

## 5. Recommended Canonical Model

### Event Visibility Field

```yaml
field_name: field_event_visibility
type: list_string
allowed_values:
  public: Public
  unlisted: Unlisted
  private: Private
  passcode: Passcode
default_value: public
```

**Semantics:**

| Value | Listings/Search/Category/Homepage/Calendar/API/SEO | Direct URL | Booking | Notes |
|-------|-----------------------------------------------------|------------|---------|-------|
| `public` | Yes | Yes | Yes (per booking rules) | Default; backward-compatible |
| `unlisted` | No | Yes (anonymous) | Yes (per booking rules) | "Secret link" sharing model |
| `private` | No | Owner/vendor/admin/staff only | Owner/vendor/admin/staff only | Future invite system extends |
| `passcode` | No | Shows passcode gate | After passcode validated | Session/token unlocks access |

### Event Passcode Field (optional, Phase C)

```yaml
field_name: field_event_passcode
type: string
max_length: 255
```

- Never rendered in output.
- Never exposed in API responses.
- Ideally stored hashed (bcrypt/argon2) if persistent.
- Plain-text acceptable only for vendor-controlled short codes with session-scoped validation.

---

## 6. Recommended Services

### PublicEventVisibility (extend existing)

```php
// Extend existing service at:
// web/modules/custom/myeventlane_event/src/Service/PublicEventVisibility.php

public function isPubliclyListable(NodeInterface $event): bool;
// Add: check field_event_visibility === 'public'

public function isDirectlyViewable(NodeInterface $event, AccountInterface $account): bool;
// public + unlisted: TRUE for all
// private: owner/vendor/admin/staff only
// passcode: TRUE only if session holds valid passcode token

public function isSeoIndexable(NodeInterface $event): bool;
// Only 'public' visibility

public function isApiVisible(NodeInterface $event): bool;
// Only 'public' visibility

public function requiresPasscode(NodeInterface $event): bool;
// TRUE if visibility === 'passcode'

public function hasValidPasscodeAccess(NodeInterface $event, Request $request): bool;
// Check session token for passcode unlock
```

### Event Node Access (new or hook)

- `hook_node_access()` in `myeventlane_event.module` or dedicated access checker service
- Deny `view` for anonymous/authenticated on `private` events (unless owner/vendor/admin/staff)
- Deny `view` for anonymous/authenticated on `passcode` events (unless session has valid token)
- Allow `view` for `unlisted` events (direct URL access is intentional)

### Route Access for Booking

- Add access check to `myeventlane_commerce.event_book` route
- Deny booking for `private` events (unless vendor/admin/staff)
- Deny booking for `passcode` events (unless session has valid passcode token)
- Allow booking for `unlisted` events (direct URL intended to enable booking)

### PublicEventDiscoveryQueryAlter (extend existing)

- Add SQL condition: `field_event_visibility IS NULL OR field_event_visibility = '' OR field_event_visibility = 'public'`
- Apply to all public discovery displays

### SearchController

- Post-filter or pre-filter on visibility = public
- Best approach: add visibility to Search API index + filter at query level

---

## 7. Recommended Implementation Phases

### Phase A: Foundation (field + basic listing enforcement)

1. Create and export `field.storage.node.field_event_visibility.yml`
2. Create and export `field.field.node.event.field_event_visibility.yml` (default: `public`)
3. Add to form display (vendor Event Studio settings tab)
4. Add to VendorStudioController (already has code, just needs working field)
5. Update `PublicEventVisibility::isPubliclyListable()` to require `visibility === 'public'` (or empty/null for backward compatibility)
6. Update `PublicEventDiscoveryQueryAlter` to filter on visibility
7. Update `PublicEventApiController` (already uses `isPubliclyListable` — inherits fix)
8. Update `EventStructuredDataBuilder` (already uses `isPubliclyListable` — inherits fix)
9. Update `EventRecommendationService` to respect visibility
10. Update `SearchController` to filter non-public events
11. Run update hook to default all existing events to `public`

### Phase B: Direct URL + Private Access

1. Implement `hook_node_access()` for `private` events
2. Allow `unlisted` events on direct URL (no access restriction, just listing exclusion)
3. Add booking route access guard for `private` events
4. Add vendor/admin/staff bypass rules
5. Add cache contexts: `user.roles`, `user` (for ownership check)

### Phase C: Passcode Gate

1. Create `field_event_passcode` (or use configuration entity)
2. Implement passcode gate controller/form
3. Implement session-based token after validation
4. Add booking route access guard for `passcode` events
5. Add `X-Robots-Tag: noindex` for passcode event pages
6. Audit logging for passcode attempts
7. Rate limiting on passcode validation

### Phase D: Invite-Only (future)

1. Design invite entity/reference model
2. Implement invite generation + delivery
3. Implement invite validation on access
4. Consider token-based URLs vs authenticated invite lists

---

## 8. Exact Files Likely to Change

### Phase A

| File | Change |
|------|--------|
| `config/sync/field.storage.node.field_event_visibility.yml` | **NEW** — field storage |
| `config/sync/field.field.node.event.field_event_visibility.yml` | **NEW** — field instance |
| `config/sync/core.entity_form_display.node.event.default.yml` | Add visibility widget |
| `config/sync/core.entity_view_display.node.event.default.yml` | Hidden (never rendered) |
| `web/modules/custom/myeventlane_event/src/Service/PublicEventVisibility.php` | Add visibility check |
| `web/modules/custom/myeventlane_event/src/Service/PublicEventDiscoveryQueryAlter.php` | Add SQL filter |
| `web/modules/custom/myeventlane_event/src/Service/EventStructuredDataBuilder.php` | Inherits via `isPubliclyListable` |
| `web/modules/custom/myeventlane_api/src/Controller/PublicEventApiController.php` | Inherits via `isPubliclyListable` |
| `web/modules/custom/myeventlane_search/src/Controller/SearchController.php` | Add visibility filter |
| `web/modules/custom/myeventlane_core/src/Service/EventRecommendationService.php` | Add visibility condition |
| `web/modules/custom/myeventlane_event_state/myeventlane_event_state.install` | Update hook to default existing events |
| `web/modules/custom/myeventlane_event_studio/src/Form/EventInformationForm.php` | Add visibility selector |
| `config/sync/views.view.upcoming_events.yml` | Optional: add filter (belt+suspenders with query alter) |
| `config/sync/views.view.events_calendar.yml` | Optional: add filter |

### Phase B

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_event/myeventlane_event.module` | Add `hook_node_access()` |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.routing.yml` | Add access requirement |
| `web/modules/custom/myeventlane_commerce/src/Access/EventBookAccessCheck.php` | **NEW** — access checker |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml` | Register access checker |

### Phase C

| File | Change |
|------|--------|
| `config/sync/field.storage.node.field_event_passcode.yml` | **NEW** |
| `config/sync/field.field.node.event.field_event_passcode.yml` | **NEW** |
| `web/modules/custom/myeventlane_event/src/Controller/EventPasscodeGateController.php` | **NEW** |
| `web/modules/custom/myeventlane_event/src/Form/EventPasscodeForm.php` | **NEW** |
| `web/modules/custom/myeventlane_event/myeventlane_event.routing.yml` | Add passcode route |
| `web/modules/custom/myeventlane_event/src/EventSubscriber/PasscodeEventNoIndexSubscriber.php` | **NEW** |

---

## 9. Tests Needed

### Unit Tests

- `PublicEventVisibility::isPubliclyListable()` returns FALSE for unlisted/private/passcode
- `PublicEventVisibility::isPubliclyListable()` returns TRUE for public (and empty/null for backward compat)
- `PublicEventVisibility::isSeoIndexable()` returns FALSE for non-public
- `PublicEventVisibility::isDirectlyViewable()` returns TRUE for public + unlisted with anonymous
- `PublicEventVisibility::isDirectlyViewable()` returns FALSE for private with anonymous
- `PublicEventVisibility::isDirectlyViewable()` returns TRUE for private with owner/admin

### Kernel Tests

- Public event appears in Views query results
- Unlisted event does NOT appear in Views query results
- Private event does NOT appear in Views query results
- Passcode event does NOT appear in Views query results
- `EventRecommendationService` excludes non-public events from related
- API controller returns 404 for unlisted/private/passcode events in list endpoint
- API controller returns 404 for private events in single-event endpoint

### Functional Tests

- Anonymous can view unlisted event by direct URL
- Anonymous cannot view private event by direct URL (403)
- Anonymous cannot view passcode event detail without valid session (redirects to gate)
- Vendor owner CAN view their own private event
- Admin CAN view any private event
- Booking route denies anonymous for private events
- Booking route denies anonymous for passcode events without session token
- Booking route allows anonymous for unlisted events
- Passcode form validates correctly + sets session token
- After passcode validation, event detail and booking are accessible
- JSON-LD is NOT attached for non-public events
- Search does NOT return non-public events
- Homepage blocks do NOT display non-public events
- Category pages do NOT display non-public events
- Calendar does NOT display non-public events

### Cache Tests

- Cache contexts include `user` or `user.roles` on event pages with visibility enforcement
- Cache tags invalidate when visibility field changes

---

## 10. Notes on Existing Ticket-Tier Access Codes

The existing `mel_access_code` entity and `TicketAccessCodeService` handle **ticket-tier visibility** (individual ticket types within an event can be hidden/access-code-gated). This is a separate, orthogonal system:

- **Ticket-tier visibility** = "Which tickets within a visible event can this user see/buy?"
- **Event visibility** = "Can this event appear in public discovery at all?"

These must remain separate. The event visibility model governs the outer layer; ticket-tier access codes govern the inner layer.

---

## 11. Blockers and Open Questions

1. **No existing field to upgrade** — `field_event_visibility` must be created from scratch.
2. **Backward compatibility** — all existing events must default to `public` on migration.
3. **VendorStudio JS** — already has `settings_config.visibility` in its payload; will activate once the field exists. Confirm JS handles allowed values correctly.
4. **Sitemap** — no sitemap module is installed. When added, it must respect visibility.
5. **Metatag** — `config/sync/metatag.metatag_defaults.node.yml` (if exists) should add `noindex` for non-public events, or a response subscriber should handle this.
6. **Email digests / category follow** — if event digest/notification features exist, they must filter on visibility. Not found in current codebase but should be verified.
