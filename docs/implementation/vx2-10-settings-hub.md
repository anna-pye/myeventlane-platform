# VX2-10 — Workspace Settings & Support hub

**Epic:** VX2-10 Settings & Support  
**Date:** 2026-07-24  
**Authority:** MEL Product System · Convergence A10/A11 · Language Guide

## What shipped

### Workspace Settings (`/vendor/settings`)

- `VendorSettingsHubController` + `VendorSettingsHubBuilder` + `VendorWorkspaceHealthService`
- Theme: `settings-hub.html.twig` + `_settings-hub.scss`
- Workspace Health checklist: Profile · Stripe · Branding · Notifications · Policies · Help
- Sections deep-link existing surfaces (no parallel products)

### Profile (`/vendor/settings/profile`)

- Existing `VendorSettingsForm` moved off the hub route
- Venues placeholder retired → Manage venues link
- Business & payments Stripe desk removed → Open Payments
- Preferences retitled Notifications (existing fields only)
- Warm AU copy; back link to Workspace Settings

### Support (`/vendor/support`)

- `VendorSupportHubBuilder` + `support-hub.html.twig`
- Search Help · articles · Contact · open requests · policies
- Refunds note → Payments (no second refund desk)
- System status: future placeholder only

### Help / language

- `/help/vendors` → 301 `/help/organisers`
- Support Action Builder: Dashboard / Support labels (no “Vendor …”)

## Pattern

Mirrors Payments / Messages / Marketing hubs:

1. Console page chrome via `buildVendorPage`
2. Health card (`mel-card--status` + tone)
3. Jump nav · section cards · 44px targets · `prefers-reduced-motion`

## Analytics hooks (log only)

- `settings_hub_opened`
- `settings_hub_health`
- `support_hub_opened`
- `support_refunds_redirect`

Collector wiring remains deferred (same as VX2-06–09).

## QA checklist

- [ ] Desktop / tablet / 390px Settings hub
- [ ] Workspace Health checklist tones
- [ ] Profile save → returns to Settings hub
- [ ] Payments / Venues / Questions / Support / Help deep-links
- [ ] `/vendor/support/refunds` → Payments `#refunds`
- [ ] `/help/vendors` → `/help/organisers`
- [ ] Keyboard jump nav + focus rings
- [ ] No organiser-visible Vendor / Commerce / Store / Gateway

## Residual

- Brand colour themes / social preview templates / SMS prefs — documented future/missing
- Live system status page — placeholder only
- Manual visual QA sign-off
