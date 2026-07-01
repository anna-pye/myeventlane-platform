# Launch Validation Results

All commands run in the live DDEV environment on 2026-06-26. No "PASS" is claimed without output.

## Release hygiene (Phase 6)

| Command | Result |
| --- | --- |
| `ddev composer validate` | `./composer.json is valid` |
| `ddev drush status` (bootstrap) | Successful |
| `ddev drush config:status` | `No differences between DB and sync directory` (mel_pro role change exported) |
| `ddev drush cr` | Rebuilt clean after each change |
| `phpunit MelReadinessHelperCustomerTest` (Unit) | **OK** — 3 tests, 22 assertions |
| `phpcs` new subscriber `ProUpgradeRedirectSubscriber.php` | **Clean** (0 errors/0 warnings) |
| `phpcs` `EventInsightsController.php` | 1 pre-existing `\Drupal`-call warning (line 79, not introduced by this change); my edits clean |
| Theme build / lint | **Not required** — no SCSS/JS/Twig changed (only PHP/YAML) |

## OB-1 functional validation (authenticated Pro vendor, event 1755)

| Route | Before | After |
| --- | --- | --- |
| `/vendor/events/1755/insights` | 500 | **200** |
| `/vendor/events/1755/insights/sales` | 500 | **200** |
| `/vendor/events/1755/insights/attendees` | 500 | **200** |
| `/vendor/events/1755/insights/checkins` | 500 | **200** |
| `/vendor/events/1755/insights/traffic` | 500 | **200** |
| `/vendor/insights` | 403 | **200** ("Vendor Insights") |
| `/vendor/exports` | 403 | **200** ("Export Centre") |
| admin (uid 1) `/insights/sales` | 500 | **200** (326 KB rendered) |

## OB-3 functional validation

| Actor → route | Result |
| --- | --- |
| **non-Pro** → `/vendor/analytics` | **302** → `/vendor/pro?return_to=/vendor/analytics` |
| **non-Pro** → `/vendor/settings/branding` | **302** → `/vendor/pro?return_to=/vendor/settings/branding` |
| non-Pro → landing page content | shows Pro value prop + "Upgrade to unlock" warning + `return_to` preserved |
| **Pro** → `/vendor/analytics` | **200** (no redirect — unaffected) |
| **anonymous** → `/vendor/analytics` | **403** (normal flow — not redirected) |
| global 403 page | unchanged |

## OB-2 validation

| Route | Result |
| --- | --- |
| `/vendor/events/1755/studio/messaging` | **200** (Studio section, `MessagingSection` plugin) |
| `/vendor/event/1755/comms` (singular) | 302 → Studio |
| `/vendor/events/1755/comms` (legacy plural) | 404 — unlinked; not used by the UI |

## Performance (Phase 5) — warm, authenticated, full HTML

| Route | time_total |
| --- | --- |
| `/vendor/dashboard` | 1.23 s |
| `/vendor/settings` | 1.03 s |
| `/vendor/events/1755/studio/tickets` | 0.96 s |
| `/vendor/events/1755/studio` | 0.92 s |
| `/vendor/events/1755/check-in` | 0.89 s |
| `/vendor/payouts` | 0.86 s |
| `/vendor/events` | 0.57 s |
| `/vendor/analytics` | 0.52 s |
| `/vendor/events/1755/insights/sales` | 0.51 s |

No route > 1.3 s. No obvious bottleneck.

## Accessibility primitives (Phase 2) — per page

All pages: skip link present, `lang` set, `<h1>` present, abundant `aria-*`. Forms carry `<label>`s
(tickets 41, settings 25). Counts captured per route (see `launch-evidence.md`).

## Regression checks

| Check | Result |
| --- | --- |
| `/vendor/dashboard` (Pro) | 200 |
| `/vendor/events/1755/insights/attendees` (Pro) | 200 |
| `/home` (anon) | 200 |
| `/search` (anon) | 200 |
| Fresh 500s on organiser routes post-change | none (historical entries pre-date the fix) |
