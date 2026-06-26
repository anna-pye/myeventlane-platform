# Customer Acceptance — Launch Checklist (Go / No-Go)

This is the acceptance gate. **No item may be left unchecked at go-live.** `VERIFY-LIVE` items
require a running environment and a recorded pass/fail; this audit did **not** run them (no
validation commands were executed, per audit discipline).

Legend: ☐ open · ⛔ blocker (P0) · ⚠ pre-launch (P1) · ▢ fast-follow (P2/P3, may launch open).

---

## A. Hard gate — P0 (must be PASS before any go decision)

- ⛔ ☐ **CB-01** Checkout "paid/confirmed" state is bound to actual Commerce payment state; a pending/unpaid order never shows paid copy. *(VERIFY-LIVE: place an order with a pending payment fixture.)*
- ⛔ ☐ **CB-02** Buyer refund enforces owner-only, amount ≤ paid, and rejects double-refund. *(VERIFY-LIVE: non-owner, over-amount, repeat-request cases.)*
- ⛔ ☐ **CB-03** Payout totals derive only from settled/paid orders; `/stripe/webhook/payout` verifies signature and rejects forged calls. *(VERIFY-LIVE: reconcile payout to settled orders; send unsigned webhook.)*

> If any P0 fails → **NO-GO** until fixed and re-verified.

## B. Pre-launch — P1 (target: all PASS before public launch)

- ⚠ ☐ **CB-04** One canonical event-authoring path declared and signposted (wizard vs studio vs manage-event).
- ⚠ ☐ **CB-05** Saved Events: shipped, or removed from nav/marketing (product decision recorded).
- ⚠ ☐ **CB-06** `/calendar` returns events and is reachable — or removed from nav.
- ⚠ ☐ **CB-07** WCAG 2.1 AA pass on event → book → checkout → login; criticals fixed. *(VERIFY-LIVE)*
- ⚠ ☐ **CB-08** Full transactional email set mapped + send-tested (confirm, ticket delivery, refund, reminder, RSVP). *(VERIFY-LIVE)*
- ⚠ ☐ **CB-09** Pro-only routes deny non-Pro organisers gracefully. *(VERIFY-LIVE)*
- ⚠ ☐ **CB-10** Waitlist signup shows confirmation + position + next step. *(VERIFY-LIVE)*
- ⚠ ☐ **CB-11** Mobile booking CTA is prominent/sticky on the event page. *(VERIFY-LIVE)*
- ⚠ ☐ **CB-12** Publish vs submit-for-review states are consistent across authoring surfaces. *(VERIFY-LIVE)*
- ⚠ ☐ **CB-13** Home page exposes a skip-to-content link.

## C. Fast-follow — P2 (may launch open, schedule within 2 weeks)

- ▢ ☐ **CB-14** Branded zero-results copy on browse/search/category.
- ▢ ☐ **CB-15** Canonicalise `/organisers` + `/vendors` (one URL + 301).
- ▢ ☐ **CB-16** Single canonical analytics/insights home.
- ▢ ☐ **CB-17** Single canonical check-in path.
- ▢ ☐ **CB-18** Register/login MEL-shell visual parity.
- ▢ ☐ **CB-19** Friendly empty states on dashboard + my-tickets tabs.
- ▢ ☐ **CB-20** Onboarding progress indicator + resumability.
- ▢ ☐ **CB-21** Homepage marketing copy editorial sign-off.

## D. Polish — P3 (backlog)

- ▢ ☐ **CB-22** Refactor `VendorDashboardController` (2,856 lines).
- ▢ ☐ **CB-23** Home trust anchor (badges/stats).
- ▢ ☐ **CB-24** Verified-organiser badge / event count on public profile.

---

## E. Standing release hygiene (run before deploy — not yet run in this audit)

These are the project's own validation commands (`CLAUDE.md`). This audit did **not** run them;
they must pass at release:

- ☐ `ddev drush config:status` clean
- ☐ `ddev drush cim --preview` shows no surprise diffs
- ☐ `npm run lint` passes
- ☐ `npm run build` passes (theme assets current)
- ☐ `ddev drush cr` after deploy
- ☐ Smoke: anonymous can browse/search/view event/RSVP; customer can buy/refund-request/view tickets; organiser can author/publish/see payouts.

---

## F. Acceptance sign-off

| Gate | Status |
| --- | --- |
| A — P0 blockers all PASS | ☐ |
| B — P1 all PASS (or risk-accepted with owner sign-off) | ☐ |
| E — Release hygiene PASS | ☐ |

**Current audit verdict:** **NOT YET CLEAR** — Section A (P0) is open and unverified.
On Section A all-PASS, verdict moves to **GO** with P1/P2 as fast-follow (readiness ≈ 8.2/10).

> Audit discipline note: findings are repository-evidence based; no tests were run and no
> "passed" claim is made. Items marked **VERIFY-LIVE** must be executed in a running
> environment to close this gate.
