# MELGovernanceTestingSystem

Testing and verification layer for the **canonical** MEL governance stack (Surface, Form, Component, Workflow, Data Presentation, Interaction, State, Experience, Intelligence, Operational Policy, Observability). **No additional runtime governance** is introduced here—only automated proof and documentation.

## 1. Test architecture

| Layer | Role |
| --- | --- |
| **Kernel (`tests/src/Kernel`)** | Boots Drupal services for `myeventlane_surface` + `myeventlane_core`; pushes synthetic `Request` + `Route` onto the stack; asserts payloads (`#cache` contexts, IDs, tiers, suppression maps). |
| **Functional (`tests/src/Functional`)** | `BrowserTestBase`: HTTP responses, `drupalSettings`, and HTML `data-mel-*` hints. |
| **Docs (this file)** | Coverage boundaries, security expectations, manual smoke, commands. |

**Base class:** `Drupal\Tests\myeventlane_surface\Kernel\MelSurfaceGovernanceKernelTestBase`

## 2. Coverage map

| Concern | Primary tests |
| --- | --- |
| Observability permission + restricted payload | `MelObservabilityAccessKernelTest` |
| Trace redaction | `MelObservabilitySanitizerKernelTest` |
| Deterministic keys / ordering contract | `MelObservabilityDeterministicKernelTest` |
| Operational policy (registry-driven) | `MelOperationalPolicyKernelTest` |
| Intelligence prioritisation + policy coupling | `MelIntelligenceDeterminismKernelTest` |
| State conservatism + trust visibility | `MelStateGovernanceKernelTest` |
| Experience continuity + workflow deferral | `MelExperienceContinuityKernelTest` |
| Cross-surface route resolution | `MelSurfaceRouteGovernanceKernelTest` |
| Governance snapshot regression | `MelGovernanceSnapshotKernelTest` |
| Staff governance debug (not observability) | `MelGovernanceDebugKernelTest` |
| Vendor shell privacy envelopes | `MelVendorDashboardGovernanceKernelTest` |
| HTTP observability visibility | `MelObservabilityVisibilityTest` |
| HTTP surface hints | `MelSurfaceRouteGovernanceTest` |
| HTTP vendor dashboard gate | `MelVendorDashboardGovernanceTest` |

## 3. Security map

| Expectation | How it is tested |
| --- | --- |
| Staff diagnostics require **Staff surface +** `access mel observability diagnostics` | `MelObservabilityDiagnosticsAccess`, `MelObservabilityAccessKernelTest`, `MelObservabilityVisibilityTest` |
| Vendor/Customer surfaces never emit diagnostic traces | `MelObservabilityAccessKernelTest`, `MelVendorDashboardGovernanceKernelTest` |
| `drupalSettings.melObservability` only when gate passes **and** traces exist | `MelObservabilityAccessKernelTest` (negotiator) |
| Sensitive strings redacted in trace copy | `MelObservabilitySanitizerKernelTest` |
| Permission-sensitive cache contexts | Assertions on `user.permissions` in payloads (`MelObservabilityAccessKernelTest`, `MelIntelligenceDeterminismKernelTest`) |
| Vendor interpretation omits staff registry snapshot | `MelOperationalPolicyKernelTest`, `MelVendorDashboardGovernanceKernelTest` |
| Staff governance debug requires **Staff surface +** `access mel governance debug` | `MelGovernanceDebugAccess`, `MelGovernanceDebugKernelTest` |
| Vendor never receives governance debug assets | `MelGovernanceDebugKernelTest::testVendorSurfaceNeverAttachesGovernanceDebug` |
| Intelligence suppression lists respected | `MelIntelligenceDeterminismKernelTest` |
| Public trust indicators respect `publicSafe` | `MelStateGovernanceKernelTest::testTrustHelperNeverExposesNonPublicSafeTrustOnPublicSurface` |

## 4. What is intentionally **not** covered

- **Drupal entity/route access matrix** end-to-end for every vendor event (use existing `myeventlane_vendor`, `myeventlane_analytics`, checkout modules).
- **Translated marketing copy** as assertions (avoid brittle strings).
- **Commerce payment flows** (charges, Stripe identifiers beyond sanitizer patterns)—Commerce remains authoritative.
- **Every Kernel test in `myeventlane_surface`** in CI — the pipeline runs a **minimal slice** via `web/phpunit-governance.xml` (see [`mel-governance-devtooling-and-ci.md`](mel-governance-devtooling-and-ci.md)); the full directory may still have legacy failures.

## 5. Manual smoke checklist

1. **Staff + diagnostics permission:** open any `/admin/*` page → developer tools → confirm `drupalSettings.melObservability` **present only** when traces render; observability library attached.
2. **Staff without permission:** same page → **no** `melObservability` in settings; no observability panel.
3. **Vendor:** `/vendor/dashboard` (trusted vendor) → governance variables may render; **no** staff observability panel; **no** `melObservability` settings.
4. **Customer:** `/my-account` → **no** observability diagnostics.
5. **Public:** `/` and event discovery → **no** observability internals; HTML contains `data-mel-surface`.
6. **Checkout:** `/checkout/*` → workflow **next-step** panel must not appear; policy profile tends toward checkout-minimal (see operational policy interpretation).
7. **Vendor isolation:** log in as Vendor A; confirm no Vendor B data in dashboards (existing vendor/analytics tests).

## 6. Commands

**Governance CI slice (preferred — matches GitHub Actions)**

```bash
composer run-script governance:test
composer run-script governance:audit
```

**PHPUnit — full directories locally**

From project root:

```bash
vendor/bin/phpunit web/modules/custom/myeventlane_surface/tests/src/Kernel
vendor/bin/phpunit web/modules/custom/myeventlane_surface/tests/src/Functional
```

With DDEV:

```bash
ddev exec vendor/bin/phpunit web/modules/custom/myeventlane_surface/tests/src/Kernel
ddev exec vendor/bin/phpunit web/modules/custom/myeventlane_surface/tests/src/Functional
```

`drupal/core-dev` in `require-dev` supplies `vendor/bin/phpunit`.

**Project checks (task hygiene)**

```bash
php -l web/modules/custom/myeventlane_surface/tests/src/Kernel/*.php
composer validate --no-check-publish
npm run mel:lint
npm run mel:build
ddev drush cr
```

## 7. Known follow-ups

| Item | Notes |
| --- | --- |
| **CI Kernel slice vs full suite** | `.github/workflows/php-composer.yml` runs `composer run-script governance:test`, which uses `web/phpunit-governance.xml` (four Kernel files). Expand the suite as remaining Kernel tests are fixed for Drupal 11. Details: [`mel-governance-devtooling-and-ci.md`](mel-governance-devtooling-and-ci.md). |
| **Kernel uid 1** | Drupal `SuperUserAccessPolicy` grants uid 1 all permissions. `MelSurfaceGovernanceKernelTestBase` creates a placeholder uid 1 so `UserCreationTrait::createUser()` yields uid ≥ 2 for realistic permission assertions. |
| **Functional vendor module weight** | `MelVendorDashboardGovernanceTest` enables `myeventlane_vendor`; full dependency resolution may require a longer install—monitor CI time. |
| **HTTP observability positive case** | `MelObservabilityVisibilityTest` assumes admin routes produce non-empty observability traces for staff with permission; if a route yields zero traces, `melObservability` will correctly be omitted—pick another admin route if needed. |

## 8. Deliverables summary (request checklist)

1. **Architecture map** — §1–2.  
2. **Coverage map** — §2.  
3. **Security map** — §3.  
4. **Observability permission report** — `MelObservabilityAccessKernelTest` + `MelObservabilityVisibilityTest`.  
5. **Policy suppression report** — `MelOperationalPolicyKernelTest`.  
6. **Intelligence determinism report** — `MelIntelligenceDeterminismKernelTest`.  
7. **Vendor dashboard governance report** — `MelVendorDashboardGovernanceKernelTest` + `MelVendorDashboardGovernanceTest`.  
8. **Cacheability report** — Assertions on `#cache.contexts` (`user.permissions`, `route`, `user`) in Kernel tests; negotiator merges bubbleable metadata.  
9. **Manual smoke checklist** — §5.  
10. **File-by-file summary** — see table in §2.
