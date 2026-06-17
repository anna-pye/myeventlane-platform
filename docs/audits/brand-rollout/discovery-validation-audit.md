# MEL Phase 5 — Discovery Validation Audit

**Date:** 2026-06-17  
**Branch:** `feature/homepage-copy-phase-1a`  
**Scope:** Homepage only (`/home`) — validation only, no architecture/copy/merchandising changes  
**Phase 4B commit:** `1636b6a30` — *Homepage: align discovery architecture and merchandising*

---

## Safety check

| Check | Result |
|---|---|
| Branch | `feature/homepage-copy-phase-1a` |
| Phase 4B committed | Yes (`1636b6a30`) |
| Working tree at audit start | **Not clean** — `web/scripts/audit-homepage-gate.drush.php` modified; two untracked prior audit docs |
| Config drift | None (`ddev drush config:status` — no differences) |
| Commerce/checkout touched | No |

Audit proceeded because the modified file was audit tooling (Phase 5B scope), not homepage production code.

---

## Component inventory

| Component | Path | Status |
|---|---|---|
| HomepageMerchandising | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` | Present |
| HomepageMerchandisingQueryAlter | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandisingQueryAlter.php` | Present |
| HomepageVisibilityReportService | `web/modules/custom/myeventlane_front/src/Service/HomepageVisibilityReportService.php` | Present |
| HomepageSectionVisibility | `web/modules/custom/myeventlane_front/src/Service/HomepageSectionVisibility.php` | Present |
| audit-homepage-gate.drush.php | `web/scripts/audit-homepage-gate.drush.php` | Present (extended in Phase 5B) |
| homepage-real-world-validation.drush.php | `web/scripts/homepage-real-world-validation.drush.php` | Present |

---

## 1. Tooling audit (Phase 5A)

### Problem

Under plain Drush CLI, audit output reported:

```text
isFrontPage: no
applies(block_featured): no
```

Because `HomepageMerchandising::applies()` gates on `PathMatcherInterface::isFrontPage()`:

```132:138:web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php
  public function applies(string $viewId, string $displayId): bool {
    if (!$this->pathMatcher->isFrontPage()) {
      return FALSE;
    }

    return in_array($displayId, self::MERCHANDISED_DISPLAYS[$viewId] ?? [], TRUE);
  }
```

`PathMatcher::isFrontPage()` requires an active **RouteMatch** whose internal path equals `system.site:page.front` (`/home`). Pushing a `Request` onto `RequestStack` alone does **not** set RouteMatch:

```91:102:web/core/lib/Drupal/Core/Path/PathMatcher.php
  public function isFrontPage() {
    if (!isset($this->isCurrentFrontPage)) {
      $this->isCurrentFrontPage = FALSE;
      if ($this->routeMatch->getRouteName()) {
        $url = Url::fromRouteMatch($this->routeMatch);
        $this->isCurrentFrontPage = ($url->getRouteName() && '/' . $url->getInternalPath() === $this->getFrontPagePath());
      }
    }
    return $this->isCurrentFrontPage;
  }
```

### Existing simulation?

**No.** Repository evidence shows no prior front-page simulation in audit tooling. The previous script pushed a request but documented the limitation in a comment. No other Drush script sets RouteMatch for homepage validation.

### Phase 5B — tooling improvement

Extended `audit-homepage-gate.drush.php` to bootstrap front-page context via:

```php
$kernel->handle(Request::create('/home'), HttpKernelInterface::SUB_REQUEST);
```

This sets RouteMatch, enables `isFrontPage: yes`, applies `HomepageMerchandisingQueryAlter` exclusions, and aligns View execution with real `/home` behaviour.

**Usage:**

```bash
# Local
ddev drush scr web/scripts/audit-homepage-gate.drush.php

# Staging
php -d memory_limit=1024M vendor/bin/drush.php scr \
  web/scripts/audit-homepage-gate.drush.php --uri=https://staging.myeventlane.com.au
```

**Optional fixtures** (additive MEL-STAGING content only):

```bash
ddev drush scr web/scripts/homepage-real-world-validation.drush.php
ddev drush scr web/scripts/audit-homepage-gate.drush.php
```

### Before vs after simulation

| Metric | Without SUB_REQUEST | With SUB_REQUEST |
|---|---|---|
| `isFrontPage` | no | **yes** |
| `applies(block_featured)` | no | **yes** |
| Dedup violations | 22 false positives | **0** |
| Featured view NIDs | 1662, 1591, 1712, 1713, 1715 | **1591** (deduped) |
| View vs merchandising mismatch | yes | **NONE** |

---

## 2. Fixture audit

Local DDEV content counts under front-page simulation (2026-06-17):

| Surface | Count | Notes |
|---|---|---|
| Hero | 1 | NID 1662 |
| Tonight | 1 | NID 1692 |
| Hidden Gems | **0** | Rail hidden by `HomepageSectionVisibility::hasHiddenGemEvents()` |
| Discover | 6 | |
| Community Spotlight | 1 | NID 1591 (single promoted event after hero/discover exclusions) |
| Community Favourites | 1 | NID 1696 |
| Upcoming Highlights | 12 | |
| Free RSVP | 6 | |
| More Events To Explore | 4 | |
| MEL-STAGING fixtures | **0** | Run `homepage-real-world-validation.drush.php` to seed |

### Fixture gaps

| Gap | Impact |
|---|---|
| **Hidden Gems: 0** | Cannot validate Hidden Gems rail rendering, dedup from Hidden Gems tier, or vendor visibility reporting for that surface |
| **MEL-STAGING fixtures absent** | Cannot validate deterministic cross-rail scenarios (hero rotation, quality-gate exclusion, CF diversity) without seeding |
| **Community Favourites: 1** | Minimal — dedup and visibility work, but diversity/filtering under-tested |

**Recommendation:** Run `homepage-real-world-validation.drush.php` on staging/local before the next merchandising phase. Do **not** fabricate editorial Hidden Gem events in this audit.

---

## 3. Visibility report audit

Service: `HomepageVisibilityReportService` — used by vendor dashboard via `VendorDashboardViewModelBuilder`.

### Surfaces reported correctly (front-page context)

| Surface key | Service label | Verified |
|---|---|---|
| `hero` | Hero | Yes — hero NID 1662 reports Hero only |
| `community_favourites` | Community Favourites | Yes — NID 1696 reports Community Favourites only |
| `recommended` | More Events To Explore | Yes — recommended NIDs report correctly |
| `latest` | Upcoming Highlights | Yes |

### Label mismatches (service vs rendered homepage Twig)

These are **copy presentation differences**, not dedup/ownership bugs:

| Surface key | Vendor report label | Homepage Twig title |
|---|---|---|
| `tonight` | Happening Tonight | Happening tonight |
| `discover` | Discover Events | Discover events |
| `spotlight` | Community Spotlight | Community spotlight |
| `free` | Free & RSVP | Easy ways to join in |
| `recommended` | More Events To Explore | More Events to Explore |
| `more_to_discover` | Worth checking out | (overflow carousel — no separate section heading) |

Vendor-facing labels use title case and canonical surface names; homepage Twig uses marketing-specific copy per `page--front.html.twig` header comment. This is documented behaviour, not a Phase 5 defect.

### Hidden Gems reporting

With 0 Hidden Gem events locally, no event reports `hidden_gems` surface — expected. Reporting logic exists and would activate when `getHiddenGemEventIds()` returns rows.

### Ineligible promoted events

NIDs 1714, 1716 are ineligible (quality gate). NID 1714 correctly reports **no surfaces** in vendor report despite being promoted.

---

## 4. Dedup validation

Priority order validated:

```text
Hero → Tonight → Hidden Gems → Discover → Community Spotlight →
Community Favourites → Upcoming Highlights → Free RSVP → More Events To Explore
```

### Result (front-page simulation)

**No duplicate ownership across rails.**  
**No priority violations.**

Sample ownership table:

| NID | Winning Surface | Other Eligible |
|---|---|---|
| 1662 | hero | — |
| 1692 | tonight | — |
| 1712–1715, 1661, 1693, 1681 | discover | — |
| 1591 | spotlight | — |
| 1696 | community_favourites | — |
| 1694–1702 (12) | latest | — |
| 1703–1710 (6) | free_rsvp | — |
| 1666, 1705, 1707, 1711 | recommended | — |

Featured view (`block_featured`) result NIDs match merchandising featured block NIDs with **NONE** mismatch.

---

## 5. Mobile validation

Homepage rail order extracted from live `/home` at three viewports (390px mobile, 768px tablet, 1280px desktop):

```text
1. Happening tonight
2. Discover events          ← Hidden Gems absent (0 events, section gated)
3. Community spotlight
4. Community Favourites
5. Upcoming Highlights
6. Easy ways to join in
7. More Events to Explore
8. Guides for better nights out
```

| Check | Result |
|---|---|
| Same rail order across viewports | **Yes** — identical heading sequence |
| CSS `order` reordering | **None** — all sections `order: 0` |
| Responsive alternate ordering | **None found** in `_front-page.scss` |
| Hidden Gems section | Correctly absent (visibility gate) |

Hero renders above content rails in the `homepage_hero` region (outside `.mel-page--home` content block) — consistent across viewports.

---

## 6. Risks

| Risk | Severity | Notes |
|---|---|---|
| Audit tooling previously validated raw View results | **Resolved** | SUB_REQUEST simulation now matches `/home` |
| Hidden Gems rail untested locally | Medium | 0 editorial Hidden Gem events; recommend fixture seeding |
| Vendor visibility labels differ from homepage marketing copy | Low | Intentional per Twig comment; may confuse vendors comparing dashboard to public page |
| `HomepageVisibilityReportService` uses same `PathMatcher` gate as merchandising | Low | Vendor report built during HTTP request (front page or vendor dashboard sub-context) — CLI audit must use SUB_REQUEST for accurate vendor-surface parity checks |
| Session/header errors in watchdog during SUB_REQUEST | Low | `myeventlane_event` paid-booking resolution logged session errors during kernel handle — audit-only side effect, not production |
| MEL-STAGING fixtures not seeded | Medium | Deterministic regression scenarios unavailable until seed script run |

---

## 7. Recommendations

1. **Use updated audit script** as the standard homepage discovery validation command before any future merchandising work.
2. **Seed MEL-STAGING fixtures** on staging/local via `homepage-real-world-validation.drush.php` to unlock deterministic hero/tonight/discover/CF scenarios.
3. **Create or assign Hidden Gem editorial events** locally to validate the Hidden Gems rail end-to-end (rendering, dedup tier, visibility reporting).
4. **Do not start another homepage implementation phase** until fixtures cover Hidden Gems and Community Favourites diversity (≥3 CF candidates recommended).
5. **Optional follow-up (copy-only, out of Phase 5 scope):** Align vendor report surface labels with homepage marketing titles if vendor confusion is reported — e.g. "Easy ways to join in" vs "Free & RSVP".

---

## Validation commands

```bash
git diff --stat
ddev drush cr
ddev drush config:status          # No differences between DB and sync directory
ddev drush scr web/scripts/audit-homepage-gate.drush.php
ddev drush watchdog:show --severity=Error --count=5
```

### Results (2026-06-17)

| Command | Result |
|---|---|
| `config:status` | No differences |
| `audit-homepage-gate.drush.php` | `isFrontPage: yes`, 0 dedup violations, view/merchandising match |
| Watchdog errors | Pre-existing messaging/Postmark and SUB_REQUEST session noise — no homepage render fatals |

---

## Files changed (Phase 5)

| File | Change |
|---|---|
| `web/scripts/audit-homepage-gate.drush.php` | Front-page SUB_REQUEST simulation, surface counts, dedup table, view/merchandising parity check |
| `docs/audits/brand-rollout/discovery-validation-audit.md` | This audit deliverable |

**Not changed:** homepage architecture, copy, merchandising logic, Views, config, SCSS, Commerce, RSVP.
