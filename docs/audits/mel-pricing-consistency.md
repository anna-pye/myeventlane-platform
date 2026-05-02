# MEL Pricing Consistency Audit

Date: 2026-05-02

## Scope

Searched for attendee-facing event pricing labels and legacy price rendering logic across custom modules and MEL themes:

- `Free`
- `Free RSVP`
- event card price/status labels
- product/default variation price rendering
- checkout summary pricing labels
- vendor event dashboard/overview pricing labels

## Canonical Source

All event-level pricing display now resolves through:

`Drupal\myeventlane_event\Service\BookingFlowResolver::getDisplayPricing()`

The canonical labels are:

- RSVP: `Free RSVP`
- paid single price: formatted compact price from Commerce currency formatter
- paid multiple prices: `From <price>`
- paid zero-price ticket: `Free`
- mixed paid currencies: `Multiple prices`
- external: `External`

Transactional prices, such as order item totals and checkout totals, remain Commerce order prices because they represent the actual selected cart/order amount rather than event-level display pricing.

## Verification Notes

### Event Teaser Cards

- `myeventlane_theme.theme` card badge/status helpers no longer inspect `field_event_type`, `field_product_target`, default variations, or manual dollar formatting for event pricing.
- Card badge/status labels use `BookingFlowResolver::getDisplayPricing()`.
- RSVP labels were normalised from `FREE` / `Free · RSVP` to `Free RSVP`.

### Event Full Page

- `myeventlane_event_preprocess_node()` already injects `mel_display_pricing` from `BookingFlowResolver::getDisplayPricing()`.
- `node--event--full.html.twig` continues to render sidebar/mobile pricing from `mel_display_pricing.label`.
- The RSVP mode chip now uses `Free RSVP` for label consistency.
- The legacy `node--event.html.twig` template now renders `mel_display_pricing.label` for pricing/ticketbox display.
- The legacy `event-tickets.html.twig` template no longer renders the default product variation price directly.

### Vendor Dashboard / Event Overview

- `VendorEventOverviewController` now resolves display pricing through `BookingFlowResolver::getDisplayPricing()`.
- The event workspace header meta line includes the canonical pricing label when available.

### Checkout Summary

- `CheckoutGroupedSummaryBuilder` now injects `BookingFlowResolver` and adds resolver-backed event `display_pricing` to each event group.
- `mel-checkout-order-summary-grouped.html.twig` renders that event pricing label above selected ticket lines.
- Line item totals, subtotal, GST, fees, and order total remain Commerce-derived transactional amounts.

## Changed Files

- `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`
- `web/themes/custom/myeventlane_theme/templates/event/event-card.html.twig`
- `web/themes/custom/myeventlane_theme/templates/event/event-tickets.html.twig`
- `web/themes/custom/myeventlane_theme/templates/node/node--event.html.twig`
- `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`
- `web/themes/custom/myeventlane_theme/templates/commerce/mel-checkout-order-summary-grouped.html.twig`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/CheckoutGroupedSummaryBuilder.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/CheckoutUxAttacher.php`
- `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.services.yml`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventOverviewController.php`

## Residual Risk

- Search results still include non-display configuration labels such as Event Studio option labels (`Free RSVP`) and Pro plan copy (`FREE PLAN`). These are not event pricing displays.
- Search results still include transactional/payment displays that must remain order-derived Commerce amounts.
