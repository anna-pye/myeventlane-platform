# MyEventLane Public Site Audit & Fixes

**Date:** December 11, 2025  
**Scope:** Public-facing site (myeventlane.com domain)  
**Status:** ✅ Complete

---

## Executive Summary

This document outlines the comprehensive audit and fixes applied to the MyEventLane public site to resolve event type labelling issues, badge inconsistencies, and routing problems.

### Key Issues Fixed

1. **Event teasers always showed "Free"** regardless of event type
2. **RSVP events** now correctly show "RSVP" pill badge
3. **Paid events** now correctly show "Tickets" pill badge
4. **Booking pages** use correct labels based on event type
5. **Pill badge component** created for consistent styling across site
6. **Event type detection** centralized in new `EventTypeService`

---

## A. Files Modified

### New Files Created

| File | Purpose |
|------|---------|
| `web/modules/custom/myeventlane_event/src/Service/EventTypeService.php` | Centralized event type detection service |
| `web/themes/custom/myeventlane_theme/templates/components/event-type-pill.html.twig` | Reusable pill badge component |
| `web/themes/custom/myeventlane_theme/src/scss/components/_event-type-pill.scss` | Pill badge styles |

### Files Modified

| File | Changes |
|------|---------|
| `web/modules/custom/myeventlane_event/myeventlane_event.services.yml` | Registered `EventTypeService` |
| `web/modules/custom/myeventlane_event/myeventlane_event.module` | Updated `preprocess_node()` to work for ALL view modes |
| `web/themes/custom/myeventlane_theme/templates/node--event--teaser.html.twig` | Complete rewrite with event type logic |
| `web/themes/custom/myeventlane_theme/templates/node--event--full.html.twig` | Complete rewrite with event type badges |
| `web/themes/custom/myeventlane_theme/templates/includes/event-card.html.twig` | Fixed field references and added pill badges |
| `web/themes/custom/myeventlane_theme/templates/commerce/myeventlane-event-book.html.twig` | Updated labels and badges |
| `web/themes/custom/myeventlane_theme/src/scss/main.scss` | Added import for `_event-type-pill.scss` |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | Added `hook_theme()` for pill component |

---

## B. Event Type Logic

### Source of Truth

The `EventTypeService` and `EventModeManager` are the canonical sources for event type detection.

### Event Types

| Type | `field_event_type` Value | Display Label | Badge Color | CTA Label |
|------|--------------------------|---------------|-------------|-----------|
| RSVP | `rsvp` | RSVP | Blue (#3b82f6) | "RSVP Now" |
| Paid | `paid` | Tickets | Coral (#ff6f61) | "Buy Tickets" |
| Both | `both` | RSVP & Tickets | Purple (#8d79f6) | "Buy Tickets" |
| External | `external` | External | Teal (#14b8a6) | "Get Tickets" |
| None | _(empty/undefined)_ | Unavailable | Gray | "View Event" |
| Featured | _(via `field_promoted`)_ | Featured | Gold (#f59e0b) | — |

### Detection Logic

```php
// In EventTypeService::getEventType()
$eventType = $node->field_event_type->value;
$hasProduct = !$node->field_product_target->isEmpty();
$hasExternalUrl = !$node->field_external_url->isEmpty();

if ($eventType === 'external' && $hasExternalUrl) {
  return 'external';
}
if ($eventType === 'both' && $hasProduct) {
  return 'both';
}
if ($eventType === 'rsvp') {
  return 'rsvp'; // or 'both' if has hybrid product
}
if ($eventType === 'paid' && $hasProduct) {
  return 'paid';
}
return 'none';
```

---

## C. Pill Badge Component

### Usage in Templates

```twig
{% include '@myeventlane_theme/components/event-type-pill.html.twig' with {
  type: event_type,          {# 'rsvp', 'paid', 'both', 'external', 'featured' #}
  label: event_type_label,   {# Optional: Override default label #}
  size: 'sm',                {# 'sm', 'md', 'lg' #}
} only %}
```

### Available Types

- `rsvp` - Blue gradient, white text
- `paid` - Coral gradient, white text
- `both` - Purple gradient, white text
- `external` - Teal gradient, white text
- `featured` - Gold gradient, white text
- `none` - Gray background, muted text
- `free` - Green gradient, white text
- `sold-out` - Red gradient, white text
- `waitlist` - Amber gradient, white text

---

## D. Template Variables

### Available in ALL Event Templates

These variables are now available in `node--event--teaser.html.twig`, `node--event--full.html.twig`, and any other event node template:

| Variable | Type | Description |
|----------|------|-------------|
| `event_type` | string | Event type: 'rsvp', 'paid', 'both', 'external', 'none' |
| `event_type_label` | string | Human-readable: 'RSVP', 'Tickets', etc. |
| `event_cta_label` | string | Full CTA text: 'RSVP Now', 'Buy Tickets' |
| `event_cta_short` | string | Short CTA: 'RSVP', 'Tickets', 'View' |
| `event_is_free` | bool | TRUE if RSVP only |
| `event_has_paid_tickets` | bool | TRUE if paid or both |
| `event_is_bookable` | bool | TRUE if can be booked |
| `event_is_rsvp` | bool | TRUE if RSVP enabled |
| `event_is_paid` | bool | TRUE if tickets enabled |
| `event_is_external` | bool | TRUE if external link |

### Legacy Variables (Backward Compatibility)

| Variable | Maps To |
|----------|---------|
| `event_mode` | `event_type` |
| `event_rsvp_enabled` | `event_is_rsvp` |
| `event_tickets_enabled` | `event_is_paid` |

---

## E. Verification Checklist

### Pre-Deployment

- [ ] Run `ddev drush cr` to clear all caches
- [ ] Run `ddev npm run build` in theme directory to compile SCSS
- [ ] Run `ddev exec vendor/bin/phpcs web/modules/custom/myeventlane_event` for coding standards
- [ ] Run `ddev exec vendor/bin/phpstan web/modules/custom/myeventlane_event` for static analysis

### Visual Verification

#### Homepage

- [ ] Featured events block shows correct badges (RSVP/Tickets)
- [ ] Upcoming events block shows correct badges
- [ ] No event shows "Free" unless it's an RSVP event

#### Events Listing (/events)

- [ ] All event teasers display correct event type pill
- [ ] RSVP events show blue "RSVP" pill
- [ ] Paid events show coral "Tickets" pill
- [ ] Featured events show gold "Featured" pill (if promoted)
- [ ] CTA buttons show correct text ("RSVP" vs "Tickets")

#### Category Pages (/events/category/*)

- [ ] Event teasers show correct badges
- [ ] Consistent with main events listing

#### Event Full Page (/event/*)

- [ ] Hero image shows event type badge
- [ ] Featured badge shows if event is promoted
- [ ] Sidebar shows correct price ("Free Event" vs "Tickets Available")
- [ ] CTA button shows correct text ("RSVP Now" vs "Buy Tickets")

#### Booking Page (/event/*/book)

- [ ] Page title shows correct heading ("RSVP for this Event" vs "Buy Tickets")
- [ ] Event type badge shows in image area
- [ ] Free events show blue "RSVP Required" badge
- [ ] Paid events show coral "Tickets Available" badge

#### Calendar (/calendar)

- [ ] Events display with correct badges in calendar popups

#### My Profile / My Events

- [ ] User's registered events show correct type badges

#### Search Results

- [ ] Event teasers in search show correct badges

### Routing Verification

- [ ] `/events` - Shows event listing ✓
- [ ] `/event/{id}` - Shows event full page ✓
- [ ] `/event/{id}/book` - Shows booking form ✓
- [ ] `/calendar` - Shows calendar ✓
- [ ] No vendor routes visible on public domain ✓
- [ ] All links stay on public domain ✓

### Edge Cases

- [ ] Events with no `field_event_type` set default to "RSVP" (free)
- [ ] Past events show "Event Ended" instead of CTA
- [ ] Sold-out events show "Join Waitlist" (if capacity reached)
- [ ] External events show external link icon

---

## F. Deployment Steps

```bash
# 1. Clear caches
ddev drush cr

# 2. Build theme assets
cd web/themes/custom/myeventlane_theme
ddev npm run build

# 3. Verify no PHP errors
ddev exec vendor/bin/phpcs web/modules/custom/myeventlane_event

# 4. Deploy (if using git)
git add .
git commit -m "fix: Event type labelling and pill badges for public site"
git push

# 5. On production, clear caches again
drush cr
```

---

## G. Rollback Plan

If issues arise, revert the following files:

```bash
git checkout HEAD~1 -- web/modules/custom/myeventlane_event/myeventlane_event.module
git checkout HEAD~1 -- web/modules/custom/myeventlane_event/myeventlane_event.services.yml
git checkout HEAD~1 -- web/themes/custom/myeventlane_theme/templates/node--event--teaser.html.twig
git checkout HEAD~1 -- web/themes/custom/myeventlane_theme/templates/node--event--full.html.twig
git checkout HEAD~1 -- web/themes/custom/myeventlane_theme/templates/includes/event-card.html.twig
git checkout HEAD~1 -- web/themes/custom/myeventlane_theme/templates/commerce/myeventlane-event-book.html.twig
drush cr
```

---

## H. Known Limitations

1. **Views without node preprocess**: If any Views directly render fields without using the teaser template, they won't have access to `event_type` variables. Solution: Use teaser view mode or add custom Views preprocess.

2. **Third-party integrations**: Any external systems consuming event data via API will need to use the new `EventTypeService` or check `field_event_type` directly.

3. **Vendor links in header**: The header still contains links to vendor dashboard for logged-in vendors. This is intentional - vendors need to access their dashboard from the public site.

---

## I. Future Improvements

1. **Waitlist badge**: Add visual indicator when event capacity is reached
2. **Countdown badge**: Show "Last X spots" when near capacity
3. **Price range**: Show price range for events with multiple ticket types
4. **Accessibility**: Add ARIA labels to badges for screen readers

---

_Document generated by AI audit - December 11, 2025_















