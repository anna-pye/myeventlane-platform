# MEL governance dev tooling and CI

This document describes how MyEventLane **operationalises** the existing governance architecture (Surface, negotiator, operational policy, observability, staff-only debug). It does **not** define a new runtime governance layer.

## 1. CI architecture

| Piece | Role |
| --- | --- |
| [`.github/workflows/php-composer.yml`](../../.github/workflows/php-composer.yml) | After `composer install`, runs `composer run-script governance:audit` then `composer run-script governance:test`. |
| [`composer.json`](../../composer.json) scripts | `governance:test`, `governance:audit`, `governance:snapshot:update`. |
| [`web/phpunit-governance.xml`](../../web/phpunit-governance.xml) | Kernel **slice** only: four governance-focused tests, `SIMPLETEST_DB=sqlite://localhost/:memory:` declared in the file. |
| [`scripts/governance/architecture-audit.php`](../../scripts/governance/architecture-audit.php) | Narrow static scan (vendor-facing Twig suppression heuristics). |
| [`scripts/governance/surface-audit.php`](../../scripts/governance/surface-audit.php) | JSON report from `mel-routes.json` + resolver/permission references (no runtime duplication). |

Broader Kernel tests under `web/modules/custom/myeventlane_surface/tests/src/Kernel/` may be run locally by path; they are **not** all in the CI slice until the wider suite is stabilised on Drupal 11.

## 2. Governance enforcement flow

1. **Composer validate** (existing workflow step).  
2. **`governance:audit`** — fails the job if architecture-audit finds drift signals; surface-audit always prints JSON (informational pass).  
3. **`governance:test`** — runs the PHPUnit config above; snapshot tests compare fixtures unless `MEL_UPDATE_GOVERNANCE_SNAPSHOTS=1`.

Runtime gates remain in PHP services (`MelGovernanceDebugAccess`, observability diagnostics access, `SurfaceNegotiator`, etc.). Tests and scripts only **observe** them.

## 3. Snapshot strategy

| Rule | Rationale |
| --- | --- |
| Normalised JSON fixtures under `tests/fixtures/governance_snapshots/` | Stable ordering; no HTML; no translated UI strings. |
| No PII, timestamps, or environment paths | Regressions are structural, not environmental. |
| Update via `composer run-script governance:snapshot:update` | Sets env and runs the snapshot test filter only. |

See `MelGovernanceSnapshotKernelTest` for the exact fields under test.

## 4. Debug tooling usage (staff-only)

| Item | Detail |
| --- | --- |
| Permission | `access mel governance debug` ([`myeventlane_surface.permissions.yml`](../../web/modules/custom/myeventlane_surface/myeventlane_surface.permissions.yml)). |
| Gate | [`MelGovernanceDebugAccess`](../../web/modules/custom/myeventlane_surface/src/MelGovernanceDebugAccess.php) — Staff surface **and** authenticated **and** permission. |
| Attachment | [`SurfaceNegotiator`](../../web/modules/custom/myeventlane_surface/src/SurfaceNegotiator.php) attaches `myeventlane_surface/governance_debug` and explainability-safe `drupalSettings.melGovernanceDebug` when allowed. |
| Privacy | [`MelGovernancePayloadInspector`](../../web/modules/custom/myeventlane_surface/src/MelGovernancePayloadInspector.php) normalises summaries for staff; vendors/customers/public must never receive this library (covered by `MelGovernanceDebugKernelTest`). |

Default: **off**. Do not grant the permission to authenticated, vendor, or customer roles in shipped config.

## 5. Architecture audit tooling

`architecture-audit.php` flags vendor-facing Twig that matches direct `suppress_*` naming patterns so teams route behaviour through **operational policy interpretation** instead of ad hoc templates.

This is intentionally small; it does not replace PHPCS or PHPStan.

## 6. Cacheability and security checks

| Concern | Where enforced |
| --- | --- |
| Staff-only debug / diagnostics | Kernel tests on negotiator attachments; permissions in PHP. |
| No leakage of governance debug to vendor surface | `MelGovernanceDebugKernelTest::testVendorSurfaceNeverAttachesGovernanceDebug`. |
| Drupal super-user (uid 1) skewing permission tests | `MelSurfaceGovernanceKernelTestBase::ensureDrupalSuperUserPlaceholderExists()` reserves uid 1 so `createUser()` reflects real role permissions. |
| Observability cache contexts | Broader tests documented in [`mel-governance-testing-system.md`](mel-governance-testing-system.md) §3 and §8. |

Future improvement: a dedicated kernel test asserting negotiator `#cache` contexts (`user.permissions`, `route`, surface) if not already covered elsewhere.

## 7. Developer workflows

**Run governance tests (same as CI):**

```bash
composer run-script governance:test
```

**Run audits:**

```bash
composer run-script governance:audit
```

**Refresh a governance snapshot after an intentional contract change:**

```bash
composer run-script governance:snapshot:update
```

**Run the full surface Kernel directory locally (may include failing legacy tests):**

```bash
cd web && ../vendor/bin/phpunit modules/custom/myeventlane_surface/tests/src/Kernel
```

## 8. Local test commands

| Command | Purpose |
| --- | --- |
| `composer run-script governance:test` | CI-equivalent kernel slice. |
| `cd web && ../vendor/bin/phpunit -c phpunit-governance.xml --filter <Name>` | Focused debugging. |
| `composer validate --no-check-publish` | Lockfile and schema sanity. |
| `npm run mel:lint` / `npm run mel:build` | Theme/tooling when SCSS/JS changed alongside governance UI. |
| `ddev drush cr` | Local Drupal cache rebuild after module permission or library changes. |

Dev dependencies: `drupal/core-dev` provides PHPUnit (see root `composer.json` `require-dev`).

## 9. CI troubleshooting

| Symptom | Check |
| --- | --- |
| Snapshot failure after intentional payload change | Run `governance:snapshot:update` and commit updated JSON under `tests/fixtures/governance_snapshots/`. |
| Architecture audit failure | Inspect listed Twig files; move suppression to operational policy / PHP builders. |
| PHPUnit sqlite errors | Ensure workflow sets `SIMPLETEST_DB` (workflow duplicates config env for clarity) and runs from project root so `vendor/bin/phpunit` resolves. |
| Permission tests pass locally but seem “wrong” | Confirm tests do not use **uid 1** for negative permission cases — base class now reserves uid 1. |

## 10. Follow-up recommendations

1. Gradually fix remaining Kernel tests outside the four-file slice and add them to `phpunit-governance.xml` or a second testsuite.  
2. Add an explicit kernel assertion for negotiator `#cache` contexts on representative routes (public, vendor, staff).  
3. Extend architecture-audit with optional scans for duplicate CTA blocks if patterns stabilise (keep scripts grep-based, not a new framework).  
4. Wire surface-audit JSON to artifacts or nightly dashboards if teams need historical route inventory diffs.

## Related documentation

- [`mel-governance-testing-system.md`](mel-governance-testing-system.md) — coverage matrix, security map, manual smoke.
