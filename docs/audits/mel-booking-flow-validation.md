# MEL BookingFlowResolver Validation

Date: 2026-05-02
Scope: validate `BookingFlowResolver` against real local Drupal fixtures and public booking UI behavior.

## Fixture Setup

Created local QA fixtures with Drush on `https://myeventlane.ddev.site`.

- Fixture stamp: `20260502160431`
- Commerce store used: `2`
- RSVP event: node `1578`
- Paid event: node `1579`, ticket types `90`, `91`, product `91`
- External event: node `1580`, external URL `https://example.com/mel-qa-external-20260502160431`
- Sold out event: node `1581`, ticket type `92`, product `92`, variation `4127`, completed order `439`, order item `644`
- Draft event: node `1582`

Note: content moderation defaulted new event nodes to `draft`; nodes `1578`-`1581` were explicitly moved to `published`. Node `1582` was left unpublished for the draft scenario.

## Scenario Results

### 1. RSVP Event

Expected:

- Mode: `rsvp`
- Availability: `available`
- CTA: `RSVP free`, enabled, `/event/1578/book`
- Form: `Drupal\myeventlane_rsvp\Form\RsvpPublicForm`
- Event page shows RSVP CTA; CTA click loads RSVP form.

Actual:

- Resolver returned `booking_mode=rsvp`, `availability=available`, `form=Drupal\myeventlane_rsvp\Form\RsvpPublicForm`
- `getPrimaryCta()` returned label `RSVP free`, URL `/event/1578/book`, `disabled=false`
- Event page `/node/1578` returned `200` and contained `RSVP free`
- Event page CTA hrefs pointed to `/event/1578/book`
- Booking URL `/event/1578/book` returned `200` and contained the RSVP form

Result: pass.

### 2. Paid Event

Expected:

- Mode: `paid`
- Availability: `available`
- CTA: `Get your tickets`, enabled, `/event/1579/book`
- Form: `Drupal\myeventlane_commerce\Form\TicketSelectionForm`
- Event page shows ticket CTA; CTA click loads ticket selection.

Actual:

- Resolver returned `booking_mode=paid`, `availability=available`, `form=Drupal\myeventlane_commerce\Form\TicketSelectionForm`
- `getPrimaryCta()` returned label `Get your tickets`, URL `/event/1579/book`, `disabled=false`
- Event page `/node/1579` returned `200` and contained `Get your tickets`
- Event page CTA hrefs pointed to `/event/1579/book`
- Booking URL `/event/1579/book` returned `200` and contained ticket selection content for the QA ticket tiers

Result: pass.

### 3. External Event

Expected:

- Mode: `external`
- Availability: `available`
- CTA: `View details`, enabled, external URL
- Form: `NULL`
- Event page CTA links externally; MEL booking is bypassed.

Actual:

- Resolver returned `booking_mode=external`, `availability=available`, `form=NULL`
- `getPrimaryCta()` returned label `View details`, URL `https://example.com/mel-qa-external-20260502160431`, `route=NULL`, `disabled=false`
- Event page `/node/1580` returned `200` and contained `View details`
- Event page CTA hrefs pointed directly to the external URL
- Booking URL `/event/1580/book` returned `302` to the external URL

Result: pass.

### 4. Sold Out Event

Expected:

- Mode: `paid`
- Availability: `sold_out`
- CTA: disabled `Sold out`
- Form: `NULL`
- Event page shows disabled sold-out CTA; booking URL does not load ticket selection.

Actual:

- Resolver returned `booking_mode=paid`, `availability=sold_out`, `form=NULL`
- `getPrimaryCta()` returned label `Sold out`, `url=NULL`, `disabled=true`, `remaining=0`
- Event page `/node/1581` returned `200` and contained `Sold out`
- Event page exposed no booking href for `/event/1581/book`
- Booking URL `/event/1581/book` returned `200` and displayed sold-out copy, with no ticket form

Result: pass.

### 5. Draft Event

Expected:

- Mode: `unavailable`
- Availability: `unavailable`
- CTA: disabled / hidden
- Form: `NULL`
- Public event page is not accessible; no booking form loads.

Actual:

- Resolver returned `booking_mode=unavailable`, `availability=unavailable`, `form=NULL`
- `getPrimaryCta()` returned `cta_type=none`, `url=NULL`, `disabled=true`
- Event page `/node/1582` returned `403`
- Booking URL `/event/1582/book` returned `200` with `Booking is not available for this event`, and no RSVP or ticket form

Result: pass for "no booking form"; edge case noted below because the booking route itself still renders a public shell for an unpublished event.

## Mismatch Log

No resolver-versus-UI mismatch was found for the core CTA/form/redirect outcomes across the five fixtures.

Edge case:

- Draft node `1582` correctly resolves to `unavailable` and does not render a form, but `/event/1582/book` still returns `200` to anonymous users with an unavailable message. If "draft -> no booking allowed" means the route should be access denied/not found rather than a public booking shell, this is a server-side access gap to address separately.

## Duplication Scan

Searched direct usages of:

- `field_ticket_types`
- `field_event_type`

In:

- Twig templates
- Controllers
- JS

### Public Twig Usage Affecting Booking Decisions

`web/themes/custom/myeventlane_theme/templates/node/node--event.html.twig`

- Directly reads `node.field_event_type.value`
- Sets CTA label and CTA URL in Twig
- Branches RSVP, both, external, and product-linked states directly
- This is still booking-decision duplication if this template is active for any event display.

`web/themes/custom/myeventlane_theme/templates/node--event.html.twig`

- Directly reads `node.field_event_type.value == 'rsvp'`
- Sets a `FREE` hero stamp
- This is not form routing, but it is public booking-mode presentation derived directly from the field instead of `BookingFlowResolver` / preprocessed UI state.

### Public Twig Usage Not Flagged As Booking Decisions

`web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`

- Mentions `field_ticket_types` only in a comment saying the field is intentionally not rendered.

`web/themes/custom/myeventlane_vendor_theme/templates/components/vendor-event-card.html.twig`

- Emits `data-event-type` for vendor UI metadata.
- Not public booking decision behavior.

`web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig`

- Renders event studio form inputs for ticket configuration.
- Not public booking decision behavior.

### Controller Usage

Flagged as not public booking decisions:

- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventOverviewController.php` uses `field_event_type` for vendor console meta labels only.
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorStudioSchemaController.php` exposes schema fields for studio.
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorStudioController.php` serializes studio data.
- `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioAutosaveController.php` maps Event Studio draft payload fields.
- `web/modules/custom/myeventlane_help_assistant/src/Controller/EventSuggestionController.php` reads assistant suggestion payload fields.
- `web/modules/custom/myeventlane_help_centre/src/Controller/HelpActionController.php` uses `field_event_type` and `field_ticket_types` to add a default RSVP ticket from a help action. This affects setup automation, not runtime public booking decisions.

### JS Usage

Flagged as not public runtime booking decisions:

- `web/themes/custom/myeventlane_theme/js/editor-sections.js`
- `web/themes/custom/myeventlane_theme/src/js/event-form.js`
- `web/themes/custom/myeventlane_vendor_theme/js/vendor-studio.js`
- `web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js`
- `web/modules/custom/myeventlane_tickets/js/event-wizard.js`
- `web/modules/custom/myeventlane_help_assistant/js/event-suggestions.js`

These are editor, Event Studio, wizard, or suggestion UI scripts. They can affect setup and preview behavior, but they do not enforce public booking decisions.

## Bugs / Risks Found

1. Public Twig duplication remains in `web/themes/custom/myeventlane_theme/templates/node/node--event.html.twig`; it can independently choose CTA label and URL from `field_event_type` and `field_product_target`.

2. Public presentation duplication remains in `web/themes/custom/myeventlane_theme/templates/node--event.html.twig`; it can label an event `FREE` from `field_event_type` directly.

3. Unpublished events do not load a booking form, but `/event/{node}/book` still renders a `200` booking shell for a draft node. This may be acceptable as a disabled state, but it is worth reviewing against hard access expectations for unpublished content.

## Commands Run

- `ddev drush status --fields=uri,bootstrap,db-status --format=json`
- `ddev drush php:eval` to list Commerce stores
- `ddev drush php:eval` to create the five fixtures
- `ddev drush php:eval` to publish the four non-draft fixtures
- `ddev drush cr`
- `ddev drush php:eval` to capture resolver output
- Python HTTP fetch against `https://myeventlane.ddev.site/node/{nid}` and `/event/{nid}/book`
- `rg` scans for `field_ticket_types` and `field_event_type` in Twig, controllers, and JS
