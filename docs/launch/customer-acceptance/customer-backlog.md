# Customer Acceptance — Backlog

Every item is evidence-based and carries a priority. Source = `customer-acceptance.md`.
Priorities: **P0** launch blocker · **P1** pre-launch · **P2** fast-follow · **P3** polish.

`VERIFY-LIVE` = the item is a confirmation step requiring a running environment, not
necessarily a defect. It must be closed (pass/fail) before sign-off.

---

## P0 — Launch blockers (Commerce correctness / money / access)

| ID | Item | Evidence | Acceptance criteria to close |
| --- | --- | --- | --- |
| CB-01 | Confirm "paid/confirmed" checkout copy is bound to **actual payment state**, never to mere order placement | `mel_confirm_paid` in `commerce/commerce-checkout-completion.html.twig` | An unpaid/pending order never renders paid-state hero/copy; verified against a pending payment fixture |
| CB-02 | Verify buyer **refund** guards: ownership, max = paid amount, no double-refund | `myeventlane_refunds.buyer_refund` / `BuyerRefundForm.php` (283 lines) | Non-owner blocked; over-amount rejected; second request on an already-refunded item rejected |
| CB-03 | Verify **payout** amounts derive only from settled/paid orders; Stripe payout webhook verifies signature | `/vendor/payouts`, `/stripe/webhook/payout` | Payout total reconciles to settled orders; unsigned/invalid webhook rejected |

## P1 — Pre-launch (friction, trust, path clarity, coverage)

| ID | Item | Evidence | Acceptance criteria |
| --- | --- | --- | --- |
| CB-04 | Declare and signpost **one canonical event-authoring path** | wizard (`/build/*`) vs studio (`/vendor/studio/*`) vs manage-event (`/vendor/event/*`) | Single primary CTA to author; others redirect or are clearly secondary |
| CB-05 | Product decision on **Saved Events**: implement or remove from launch nav/messaging | No saved-events route found (grep + catalogue) | Feature shipped, or removed from IA/marketing |
| CB-06 | Confirm **/calendar** renders, is populated, and is reachable from nav | `page--calendar.html.twig` only | Route returns 200 with events, or removed from nav |
| CB-07 | Formal **WCAG 2.1 AA** pass on event → book → checkout → login | 124/236 templates carry a11y primitives | AA audit recorded; criticals fixed |
| CB-08 | Enumerate full **transactional email** set + deliverability | `email/mel-email-base.html.twig`, notifications | Order confirm, ticket delivery, refund, reminder, RSVP all mapped + send-tested |
| CB-09 | Verify **Pro entitlement** gating on every Pro-only route | `myeventlane_pro` routes, `ProBrandingController` | Non-Pro organiser denied gracefully on each Pro route |
| CB-10 | Confirm **waitlist** customer confirmation + position messaging | `WaitlistController`, signup/position routes | Customer sees confirmation + position + next step |
| CB-11 | Confirm **mobile booking CTA** prominence on event page | `node--event.html.twig` sidebar card | Booking CTA visible/sticky on small viewports |
| CB-12 | Confirm publish vs **submit-for-review** states consistent across authoring surfaces | `/build/publish`, `/studio/.../submit-review` | One moderation model; states do not diverge |
| CB-13 | Confirm landing page exposes a **skip link** | not present in `page--front.html.twig` block override | Skip-to-content link on home |

## P2 — Fast-follow

| ID | Item | Evidence | Acceptance criteria |
| --- | --- | --- | --- |
| CB-14 | Branded **zero-results** copy on browse/search/category displays | discovery shell + Views | MEL-branded empty state per display |
| CB-15 | Canonicalise duplicate organiser directory (`/organisers` + `/vendors`) | both → `VendorPublicController` | One canonical URL + 301 |
| CB-16 | Declare canonical **analytics** home | `myeventlane_analytics` / `_reporting` / `_vendor_analytics` overlap | Single insights entry point |
| CB-17 | Declare canonical **check-in** path | `myeventlane_checkin` / `_checkout_flow` / `_tickets` / `_rsvp` overlap | Single primary check-in route |
| CB-18 | Register/login page MEL-shell styling parity | thin `extends` templates | Visual parity with MEL brand |
| CB-19 | Per-tab empty states on customer dashboard + my-tickets | `MyTicketsController`, `CustomerDashboardController` | Friendly empty states verified |
| CB-20 | Organiser onboarding progress indicator + resumability | `/vendor/onboard/*` step controllers | Step indicator + resume on return |
| CB-21 | Final editorial pass on homepage marketing copy vs canonical browse copy | documented divergence in `page--front.html.twig` | Copy reviewed/approved |

## P3 — Polish / debt

| ID | Item | Evidence | Acceptance criteria |
| --- | --- | --- | --- |
| CB-22 | Refactor `VendorDashboardController` (2,856 lines) | file length | Split into services/controllers; no behaviour change |
| CB-23 | Add a single trust anchor on home (badges/stats) | no trust strip on home | Trust element present |
| CB-24 | Verified-organiser badge / event count on public profile | organiser full view | Trust signal on profile |

---

## Live-verification register (must be closed for sign-off)

CB-01, CB-02, CB-03 (P0) and CB-06, CB-07, CB-08, CB-09, CB-10, CB-11, CB-12 (P1) are all
**VERIFY-LIVE**. They cannot be discharged from repository evidence and gate the acceptance
verdict.
