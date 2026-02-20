# Phase 8 — Humanitix Parity Gap

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1

Humanitix is an event ticketing platform with charity-focused features. This report compares MyEventLane v2 capabilities against Humanitix-style expectations.

---

## Feature Comparison

| Feature | Humanitix | MyEventLane v2 | Gap |
|---------|-----------|----------------|-----|
| Tiered ticket pricing | ✓ | ✓ (ticket_type_config, variations) | Parity |
| Donation at checkout | ✓ | ✓ (DonationPane, preset + custom) | Parity |
| Donation rounding ("round up") | ✓ | ✗ | **Strategic gap** |
| QR check-in | ✓ | ✓ (myeventlane_checkin, QrCheckinController) | Parity |
| Organizer payout dashboard | ✓ | ✓ (VendorPayoutsController, Stripe Connect) | Parity |
| Refund automation | ✓ | ✓ (RefundProcessor, request_refunds) | Parity |
| Multi-event reporting | ✓ | ✓ (myeventlane_reporting, VendorInsightsController) | Parity |
| Ticket transfers | ✓ | ✗ | **Strategic gap** |
| Free events / RSVP | ✓ | ✓ (field_event_type: rsvp, RsvpPublicForm) | Parity |
| Stripe Connect | ✓ | ✓ (StripeConnectPaymentService) | Parity |
| 100% profits to charity | N/A (Humanitix model) | Platform fee model (organizer or attendee) | Different model |

---

## Technical Risks (MyEventLane-specific)

1. **firebase/php-jwt CVE** — OAuth/social login path. Humanitix may use different stack.
2. **Config drift** — Order types, roles differ from sync. Deployment consistency risk.
3. **Stripe webhook** — Unverified if signing secret not set.
4. **Secrets in repo** — Backup/audit folders with keys. Humanitix likely has strict secrets handling.
5. **Alpha/RC contrib** — conditional_fields (alpha6), inline_entity_form (rc). Stability risk.

---

## Strategic Feature Gaps

### 1. Donation Rounding

- **Humanitix:** "Round up to nearest dollar" at checkout.
- **MyEventLane:** Preset ($5, $10, $20, $50, $100) or custom amount. No automatic round-up.
- **Impact:** Lower donation volume vs. round-up UX. Product decision.

### 2. Ticket Transfers

- **Humanitix:** Attendees can transfer tickets to another person.
- **MyEventLane:** No ticket transfer flow identified.
- **Impact:** Manual process (refund + repurchase or support). Reduces self-service.

### 3. Waitlist

- **MyEventLane:** field_waitlist_capacity, AttendanceWaitlistManager exist.
- **Status:** Implementation present; maturity not fully audited.

---

## Summary

| Category | Status |
|----------|--------|
| Core ticketing | Parity |
| Donations | Parity (no round-up) |
| Check-in | Parity |
| Payouts | Parity |
| Refunds | Parity |
| Reporting | Parity |
| Ticket transfers | Gap |
| Donation round-up | Gap |
