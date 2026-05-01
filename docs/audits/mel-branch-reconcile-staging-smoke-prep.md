# MEL Branch Reconcile And Staging Smoke Prep

Date: 2026-05-01

## Summary

This audit reconciles the Task 14 staging P1 watchdog fixes from
`cursor/audit-doc-update-61d60` into the expected source branch,
`cursor/onboard-storage-fix-128b4`.

Source of truth: `cursor/onboard-storage-fix-128b4`.

Recommendation: do not deploy yet. The Task 14 fixes are reconciled locally, but
the latest watchdog sample still contains current PHP error entries unrelated to
the reconciled `Order::isEmpty()` and `OnboardingState::getOwnerId()` fixes.

## Branches Compared

- `origin/cursor/onboard-storage-fix-128b4`
- `origin/cursor/audit-doc-update-61d60`
- `origin/main`

Preflight result:

- Starting branch: `cursor/staging-follow-fix-5138c`
- Working tree before reconciliation: clean
- Relevant remote branches existed after `git fetch origin --prune`

Latest local source-branch history after reconciliation:

```text
HEAD fix(launch): resolve staging P1 watchdog issues
5f6ebd11 Refactor UserVendorMembershipQuery to add method for retrieving managed event node IDs, enhancing event visibility handling. Update RsvpStatsService to utilize the new method for RSVP count calculations, improving code clarity and reducing duplication.
d46f41ad Refactor VendorPayoutsController and TicketSalesService to include unpublished managed events in revenue calculations. Updated method names for clarity and improved documentation to reflect changes in event visibility for transaction history.
e5fc574c Refactor vendor revenue calculations to align with managed events. Updated methods to retrieve vendor revenue and event IDs, ensuring consistency in handling published events across services. Improved fallback logic for ticket metrics in VendorFinanceSummaryBuilder and VendorPayoutsController.
b608c842 Enhance TicketSalesService to include revenue calculations for events managed by the user. Added methods to retrieve published events authored by the user and those tied to their vendor membership. Updated service dependencies in the service configuration file.
cece2cdf refactor(metrics): update metrics descriptions and align revenue/RSVP data retrieval with managed events scope
c2cd63c5 fix(security): remove committed Stripe secret material
c03f2b55 docs(audit): add final launch readiness review
```

## Branch Comparison Result

`origin/cursor/audit-doc-update-61d60` contains more than only Task 14. The
remote branch diff also includes audit documentation and vendor service changes:

- `docs/audits/mel-v2-current-build-audit.md`
- `web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/UserVendorMembershipQuery.php`

Because of that unrelated drift, the full audit branch was not merged.

Commit containment checks:

- Task 14 `b44d43a4`: present on `origin/cursor/audit-doc-update-61d60`; not
  present on `origin/cursor/onboard-storage-fix-128b4` before reconciliation.
- Task 13B `c2cd63c5`: present on `origin/cursor/onboard-storage-fix-128b4`.
- Task 12 `3ebf4e16`: present on `origin/cursor/onboard-storage-fix-128b4`.

## Reconciliation Method

The source branch was checked out and fast-forward pulled:

```text
git checkout cursor/onboard-storage-fix-128b4
git pull --ff-only origin cursor/onboard-storage-fix-128b4
```

The branch was already up to date with origin, but the local branch was one
commit ahead before Task 14 was applied. The working tree was clean.

Only the Task 14 commit was cherry-picked:

```text
git cherry-pick b44d43a4
```

Cherry-pick result:

```text
[cursor/onboard-storage-fix-128b4 HEAD] fix(launch): resolve staging P1 watchdog issues
5 files changed, 447 insertions(+), 18 deletions(-)
```

No conflicts occurred.

## Files Brought Over

- `docs/audits/mel-staging-smoke-p1-watchdog-cleanup.md`
- `web/modules/custom/myeventlane_core/src/Entity/OnboardingState.php`
- `web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php`
- `web/modules/custom/myeventlane_pro/src/Service/AbandonedCartScheduler.php`

## Verification Commands And Results

```text
composer validate
```

Result: passed. `./composer.json is valid`.

```text
ddev drush cr
```

Result: passed. Cache rebuild completed.

```text
php -l web/modules/custom/myeventlane_pro/src/Service/AbandonedCartScheduler.php
php -l web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php
php -l web/modules/custom/myeventlane_core/src/Entity/OnboardingState.php
```

Result: passed. No syntax errors detected.

```text
ddev drush cron || true
```

Result: completed. Cron output:

```text
Boost reminder scan: no candidates in next 24h.
Cart abandoned scan: no candidates.
Event reminder scan: no candidates in next 24h.
```

```text
ddev drush php-eval '$storage=\Drupal::entityTypeManager()->getStorage("myeventlane_onboarding_state"); $ids=$storage->getQuery()->accessCheck(FALSE)->range(0,5)->execute(); foreach ($storage->loadMultiple($ids) as $e) { echo "state=".$e->id()." owner=".var_export($e->getOwnerId(), TRUE).PHP_EOL; }' || true
```

Result: completed. Sample output:

```text
state=2 owner=1
state=4 owner=1
state=7 owner=2
state=1 owner=7
state=23 owner=10
```

```text
ddev drush ws --count=120 --severity=Error
```

Result: completed.

Observed:

- No new `Order::isEmpty()` errors were shown after cron.
- No `OnboardingState::getOwnerId()` type errors were shown after the onboarding
  state sample.
- Current watchdog sample did show PHP `AssertionError` entries referencing
  `web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.info.yml`.
- Current watchdog sample also included repeated historical abandoned cart
  scheduler errors for `Call to undefined method Drupal\commerce_order\Entity\Order::getType()`.

## Secret Scan

Command:

```text
git grep -nE 'sk_(test|live)_[A-Za-z0-9]{20,}|pk_(test|live)_[A-Za-z0-9]{20,}|rk_live_[A-Za-z0-9]{20,}|whsec_[A-Za-z0-9]{20,}' -- . || true
```

Result: passed. No tracked full Stripe keys or webhook secrets matched.

## Push Result

Push was held at this checkpoint because verification found current watchdog
error entries. The reconciled commit is local on `cursor/onboard-storage-fix-128b4`
as the current local `HEAD`.

## Staging Smoke Checklist

Do not deploy unless Anna explicitly asks.

Public:

- `/`
- `/events`
- One category page
- One paid event page
- One RSVP event page
- Paid `/book`
- RSVP `/book`
- `/cart`
- Checkout to payment step
- Completion if safe

Vendor:

- `/vendor/dashboard`
- Edit existing event
- Create RSVP draft
- Publish RSVP
- Create paid draft
- Publish paid when Stripe ready
- Blocked publish when Stripe not ready
- Analytics owner/team
- Attendee export owner/team
- Other-vendor deep link denied

Help/security:

- `/help`
- `/help/assistant`
- Vendor help route without session
- Anonymous vendor dashboard blocked
- `/my-tickets` isolation

Staging watchdog commands:

```bash
./vendor/bin/drush ws --count=200 --severity=Error
./vendor/bin/drush ws --count=100 --severity=Warning
./vendor/bin/drush ws --count=250 | grep -A4 -B2 -Ei "abandoned|Order::isEmpty|OnboardingState::getOwnerId|ticket_type maps|blocking purchase|issuance|Express Dashboard|session|headers already sent|404|checkout|stripe|commerce|cron|exception|fatal|error" || true
```

## Remaining Open P1/P2

- Open blocker: investigate current PHP `AssertionError` watchdog entries for
  `myeventlane_vendor_settings.info.yml`.
- Open blocker: investigate abandoned cart scheduler
  `Order::getType()` watchdog errors before staging smoke deploy.
- Resolved by Task 14 reconciliation: abandoned cart `Order::isEmpty()` invalid
  method call.
- Resolved by Task 14 reconciliation: `OnboardingState::getOwnerId()` return
  type mismatch.

## Recommendation

Keep `cursor/onboard-storage-fix-128b4` as the source-of-truth branch. Do not
merge the full `cursor/audit-doc-update-61d60` branch. Before staging smoke
deploy, triage the current watchdog errors and rerun the watchdog sample so the
deploy starts from a clean error baseline.
