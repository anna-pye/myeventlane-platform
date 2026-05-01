# MyEventLane Watchdog Triage Before Staging Smoke

Date: 2026-05-01

Branch: `cursor/onboard-storage-fix-128b4`

## Scope

Task 16 triaged the two known watchdog blockers before staging smoke:

- PHP `AssertionError` referencing `web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.info.yml`.
- Repeated abandoned cart scheduler errors for `Call to undefined method Drupal\commerce_order\Entity\Order::getType()`.

No deploy, push, merge to `main`, config export, secret edit, or Stripe config change was performed.

## Preflight

Commands:

```bash
git branch --show-current
git status --short
git log -8 --oneline
composer validate
ddev drush cr
```

Result:

- Branch: `cursor/onboard-storage-fix-128b4`.
- Dirty files before edits: none.
- Latest local commit at preflight: `571f252d fix(launch): resolve staging P1 watchdog issues`.
- Task 14 cherry-pick: present as `571f252d`.
- Composer validation: passed.
- Cache rebuild: passed.

## Issue 1: Missing Vendor Settings Module Metadata

Latest details:

- Watchdog IDs: `383875` through `383879`.
- Latest timestamp: `1777601568` / 01 May 12:12 local display.
- Severity: Error.
- Channel/type: `php`.
- Route/location: request bootstrap; watchdog SQL rows did not expose a route path for these AssertionError rows.
- Message: `AssertionError: The file specified by the given app root, relative path and file name (/var/www/html/web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.info.yml) do not exist.`
- Stack/file: `Drupal\Core\Extension\Extension->__construct()` from `web/core/lib/Drupal/Core/Extension/Extension.php`, line 75, while constructing the module handler during kernel pre-handle.

Root cause:

The active site configuration still had `myeventlane_vendor_settings` enabled, but the module directory and `.info.yml` file were absent from the working tree. Drupal could not construct the enabled extension during bootstrap.

Fix:

Restored a narrow compatibility metadata file at `web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.info.yml`. The current `/vendor/settings` route remains owned by `myeventlane_vendor`; no route, form, config export, uninstall, or broad vendor settings refactor was performed.

Verification:

- YAML decode via Drupal serialization: passed.
- `ddev drush cr`: passed.
- `ddev drush pm:list --type=module`: lists `MEL Vendor Settings (myeventlane_vendor_settings)` as enabled.
- No new PHP AssertionError rows after the fix. Latest error watchdog ID remained `383879`.

## Issue 2: Abandoned Cart `Order::getType()`

Latest details:

- Latest watchdog ID: `383846`.
- Latest timestamp: `1777598002` / 01 May 11:13 local display.
- Severity: Error.
- Channel/type: `myeventlane_pro`.
- Route/location: internal notifications unread endpoint was captured in watchdog; personal referer details were not copied into this audit.
- Message: `Abandoned cart scheduler failed: Call to undefined method Drupal\commerce_order\Entity\Order::getType()`.
- Stack/file: watchdog row recorded the scheduler failure message, not a PHP stack trace.

Root cause:

`AbandonedCartScheduler` and `ProAbandonedCartJob` called `$order->getType()` on Commerce order entities while determining terminal workflow states. Commerce order entities do not expose that method. The correct order type id comes from `$order->bundle()`, and the order type entity can then be loaded from `commerce_order_type` storage.

Fix:

Updated both abandoned cart terminal-state helpers to load the order type entity with:

```php
$this->entityTypeManager
  ->getStorage('commerce_order_type')
  ->load($order->bundle());
```

No abandoned cart behavior was otherwise changed.

Verification:

- Exact search: no `getType()` calls remain under `web/modules/custom/myeventlane_pro`.
- `ddev drush cron`: passed after the change and did not reproduce the abandoned cart error.
- AdvancedQueue inspection: no `pro_abandoned_cart` / `pro_abandoned_cart_job` rows were queued.
- Latest error watchdog ID remained `383879`; no new `Order::getType()` rows appeared after cron.

## Commands Run

Diagnostic and triage:

```bash
ddev drush ws --count=200 --severity=Error
ddev drush ws --count=200 --severity=Warning
ddev drush ws --count=250 | rg -i -A6 -B3 'AssertionError|myeventlane_vendor_settings\.info\.yml|Order::getType|abandoned|cart|cron|exception|fatal|error'
ddev drush ws --extended --count=20 --severity=Error
ddev drush sqlq "SELECT wid, type, severity, message, variables, location, referer, timestamp FROM watchdog WHERE variables LIKE '%myeventlane_vendor_settings.info.yml%' OR variables LIKE '%Order::getType%' OR variables LIKE '%AssertionError%' OR message LIKE '%Order::getType%' ORDER BY wid DESC LIMIT 20"
ddev drush cget core.extension module.myeventlane_vendor_settings
ddev drush cget core.extension module
ddev drush pm:list --type=module
ddev drush queue:list
ddev drush sqlq "SELECT job_id, queue_id, type, state, num_retries, available, processed FROM advancedqueue WHERE queue_id='pro_abandoned_cart' OR type='pro_abandoned_cart_job' ORDER BY job_id DESC LIMIT 20"
```

Verification:

```bash
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_pro/src/Service/AbandonedCartScheduler.php
php -l web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php
ddev drush cron
ddev drush ws --count=120 --severity=Error
ddev drush ws --count=120 --severity=Warning
ddev drush sqlq "SELECT severity, MAX(wid) AS latest_wid, MAX(timestamp) AS latest_timestamp FROM watchdog WHERE severity IN (3,4) GROUP BY severity ORDER BY severity"
ddev drush sqlq "SELECT wid, type, severity, message, timestamp FROM watchdog WHERE severity=3 AND wid > 383879 ORDER BY wid DESC LIMIT 20"
git grep -nE 'sk_(test|live)_[A-Za-z0-9]{20,}|pk_(test|live)_[A-Za-z0-9]{20,}|rk_live_[A-Za-z0-9]{20,}|whsec_[A-Za-z0-9]{20,}' -- . || true
```

## Verification Result

Passed:

- `composer validate`
- `ddev drush cr`
- PHP syntax checks for changed PHP files
- `ddev drush cron`
- Drupal YAML decode for restored `.info.yml`
- IDE lint check for changed files
- Stripe secret grep: no tracked full Stripe keys or webhook secrets matched

Watchdog status:

- No new `myeventlane_vendor_settings.info.yml` AssertionError after the metadata fix.
- No new `Order::getType()` abandoned cart error after cron.
- Latest error row remained historical ID `383879`.
- Latest warning row remained historical ID `383810`.

## Remaining Watchdog Errors

The error watchdog sample still displays historical rows because dblog retains prior entries:

- `383875`-`383879`: pre-fix missing `myeventlane_vendor_settings.info.yml` AssertionErrors.
- `383053`-`383846`: pre-fix abandoned cart `Order::getType()` scheduler errors.

The warning sample still contains historical access-denied, page-not-found, and cron re-run warnings. These were not part of Task 16 and were not changed.

## Push and Smoke Recommendation

The two named pre-smoke watchdog blockers are cleared by code verification. The branch is safe to push after Anna approves.

Staging smoke is ready from the perspective of these two Task 16 watchdog blockers, with the residual risk that dblog still contains historical rows until retention or manual clearing removes them.
