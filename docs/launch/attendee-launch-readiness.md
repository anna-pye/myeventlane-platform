# Attendee launch readiness

Final polish pass for the customer (attendee) journey. Not a new feature system.

Branch: `feature/attendee-launch-readiness` (on top of wallet-production + digital-pass work).

Related:

- [digital-ticket-experience.md](../architecture/digital-ticket-experience.md)
- [wallet-configuration.md](../operations/wallet-configuration.md)
- [account-dashboard-bookings-architecture.md](../architecture/account-dashboard-bookings-architecture.md)

---

## 1. Architecture ownership

| Surface | Owner | Notes |
|---------|-------|-------|
| Checkout | `myeventlane_checkout_flow` + Commerce | Flow `mel_event_checkout` |
| Booking confirmation | Theme completion + `MelReadinessHelper` | Customer language |
| Confirmation email | `myeventlane_messaging` · `order_confirmation` | Canonical ACE template |
| Dashboard | `myeventlane_account` · `/my-account` | Hub |
| My Bookings | `MyTicketsController` · `/my-tickets` | Route path unchanged |
| Digital Pass | Order detail + `MyTicketsOrderViewModelBuilder` | QR hero |
| Wallet | `myeventlane_wallet` | No second stack |
| Event Readiness (customer) | Same digital pass VM + `MelReadinessHelper` | Collapsed accordion |
| Reminders | Messaging 7d/24h (+ automation 2h) | Parallel owners — do not merge in this pass |
| Ticket PDF / QR | `myeventlane_tickets` | Canonical |
| Check-in | Multiple vendor surfaces | Consolidation recommended later |

### Duplicate ownership (recommend consolidation — out of this polish scope)

1. Check-in: `myeventlane_tickets` vs `myeventlane_checkin` vs `myeventlane_checkout_flow` vendor routes vs door ops.
2. Bookings list: `/my-tickets` vs live `/my-events` customer dashboard.
3. Reminder pipelines: messaging vs automation 2h.

---

## 2. Journey map (attendee)

| Step | Sees | Primary action | Next | Confusion risk |
|------|------|----------------|------|----------------|
| Paid booking | Checkout | Pay | Confirmation | Fees language |
| Free RSVP | Checkout / RSVP | Confirm | Confirmation | “Free” vs ticket |
| Guest | Confirmation email + booking number | Open email links | Optional sign-in | Finding bookings later |
| Authenticated | Dashboard CTA | My Bookings | Digital Pass | My Tickets wording (fixed) |
| Confirmation | Booking confirmed | Open Digital Pass / calendar | Email | — |
| Email | Booking confirmed | View My Bookings / PDF | Pass | Stale “Order” on some templates (partially fixed) |
| Dashboard | Bookings hub | My Bookings | List | Parallel `/my-events` |
| Digital Pass | QR hero → wallet → PDF → readiness → purchase | Show QR / wallet | Arrival | Wallet gated by credentials |
| PDF | Ticket PDF | Download / print | Entry | — |
| Wallet | Apple `.pkpass` / Google Generic JWT | Add to device | Entry | Needs prod credentials + badge assets |
| Reminder | 7d / 24h / 2h email | Open bookings / event | Pass | CTA copy drift (fixed on 7d/24h) |
| Check-in | Staff scan | Admit | Done | Multi-scanner IA |
| After / cancel / refund | Status on pass / refund email | Contact organiser | — | Check-in duplicates |

---

## 3. Issues found → fixed (this pass)

| Issue | Fix |
|-------|-----|
| “My Tickets” page title | → **My Bookings** (route path kept) |
| Guest continuity “My tickets” | → My Bookings (`MelReadinessHelper`, confirmation email) |
| Reminder CTA / footer “Order #” | → View My Bookings / Booking # |
| Receipt “Your order” | → booking language |
| Refund buyer “Order” + CTA | → Booking + View My Bookings |
| “On-site fulfillment” customer copy | → **At the event** |
| Digital Pass eyebrow | → Digital Pass |
| List CTA | → Open Digital Pass |
| Back link / booking buttons | 44–48px touch targets |
| Corrupt `wallet-buttons.html.twig` | Restored valid Twig |
| Official wallet badges | Documented install path; text CTAs until official SVGs drop-in |

---

## 4. Test matrix (evidence)

| Scenario | Result |
|----------|--------|
| Paid / free / guest / auth booking | Not re-run E2E in browser this pass — covered by existing kernel/unit suites |
| Booking confirmation / email templates | Terminology patched in install + sync where listed |
| Dashboard / My Bookings / Digital Pass | Titles + CTAs updated; lint/build OK |
| PDF / QR | No intentional regressions; QR ownership unchanged |
| Apple / Google Wallet | Code-complete on prior branch; gate + Generic Pass JWT; buttons hidden until capability |
| Reminder 7d/24h | CTA/footer wording fixed |
| Check-in / cancel / refund | UX copy only; no architecture change |
| Automated validation | See § Validation |

### Automated validation (run)

- `git diff --check`
- `composer validate --no-check-publish`
- `ddev drush cr`
- `npm run mel:lint` / `npm run mel:build`
- Wallet + ticket + QR + digital-pass PHPUnit/Kernel (as available)

---

## 5. Known limitations

1. Official Apple/Google **badge SVGs not in repo** (license/download from vendor portals) — text CTAs until `assets/web/` populated.
2. `config/sync` `google_issuer_id` may still be `GOCSPX-…` — Google gate stays off until numeric issuer set.
3. Apple/Google physical-device accept needs real credentials on host.
4. Check-in multi-route duplication unresolved (GO with monitoring).
5. Tax invoice email subject may still say “Order #” (legal invoice language — leave unless product revises).
6. Playwright / full Functional matrix not executed in this agent pass.

---

## 6. Outstanding recommendations

1. Drop official wallet badge SVGs into `myeventlane_wallet/assets/web/` and wire Twig `<img>`.
2. Consolidate check-in to one vendor route family.
3. Deprecate or redirect customer `/my-events` in favour of `/my-tickets`.
4. Align automation 2h reminder CTA with My Bookings / Digital Pass.
5. Staging: run full E2E matrix (paid, RSVP, guest, wallet, scan).

---

## 7. Go / No-Go

**Conditional GO for attendee soft-launch** after:

- [ ] Staging config: QR secret, wallet PEMs/SA (or accept wallet buttons hidden)
- [ ] Numeric Google issuer ID in config
- [ ] Spot-check confirmation + reminder emails on staging
- [ ] One paid + one guest booking walkthrough on 390px device

**No-Go for “wallet as a launch promise”** until Apple/Google credentials + device verification are done.
