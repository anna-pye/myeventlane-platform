# MEL Staging Smoke Sign-off — Task 18

Date: 2026-05-01

## Summary

Staging baseline smoke checks passed for the public homepage, events listing, and help centre.

## Baseline Checks

- `/`: 200
- `/events`: 200
- `/help`: 200

## Cleared Pre-Smoke Blockers

- Current-tree Stripe secret exposure: resolved in Task 13B.
- `myeventlane_vendor_follow` missing table/entity errors: not reproducing after current release/schema state.
- `myeventlane_public_analytics_event` missing table errors: not reproducing after current release/schema state.
- Homepage 500: not reproducing; `/` and `/home` return 200.
- Abandoned cart `Order::isEmpty()` issue: fixed.
- `OnboardingState::getOwnerId()` type issue: fixed.

## Watchdog Classification

Current post-baseline watchdog entries are not P0/P1 launch blockers.

P2 / expected or historical:
- Invalid CSRF warnings on follow/analytics endpoints from direct/manual requests.
- Anonymous vendor route access-denied diagnostics.
- `/.git/config` probe.
- `/sitemap.xml` missing.
- Cron already-running warnings.
- Historical vendor-follow / analytics-table errors from earlier release state.
- Historical abandoned-cart workflow errors from earlier code state.

## Manual Browser Smoke

Public:
- Homepage: Pass
- Events: Pass
- Help: Pass
- Category page: Not recorded
- Paid event page: Not recorded
- RSVP event page: Not recorded
- Paid booking: Not recorded
- RSVP booking: Not recorded
- Cart: Not recorded
- Checkout to payment: Not recorded

Vendor:
- Dashboard: Not recorded
- Settings: Not recorded
- Edit event: Not recorded
- Create RSVP draft: Not recorded
- Publish RSVP: Not recorded
- Create paid draft: Not recorded
- Publish paid: Not recorded
- Analytics/export owner access: Not recorded
- Cross-vendor denial: Not recorded

## PR Readiness

Ready for PR continuation from a staging baseline perspective, provided the remaining manual browser smoke items are either completed separately or accepted as pending QA.

## Remaining Follow-up

- Decide the final source branch. `cursor/remove-submit-msg-1992d` appears current and synced; `cursor/onboard-storage-fix-128b4` is heavily diverged.
- Complete manual browser smoke if required before merge.
