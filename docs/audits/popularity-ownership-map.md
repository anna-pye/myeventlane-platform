# Popularity Ownership Map

**Audit date:** 2026-06-15  
**Task:** CF-2C.1 — consolidate popularity ownership  
**Method:** Repository-wide trace of service registration, DI, callers, tests, config, plugins, subscribers, cron, and queue usage.

---

## Canonical owner

| Layer | Owner | Service id | Evidence |
|-------|-------|------------|----------|
| **Engine** | `Drupal\myeventlane_analytics\Service\PopularEventsService` | `myeventlane_analytics.popular_events` | `web/modules/custom/myeventlane_analytics/src/Service/PopularEventsService.php`; `myeventlane_analytics.services.yml` |
| **Excluded-type policy** | `OrderItemClassifier` (boost + donation order items excluded from ticket scoring) | `myeventlane_analytics.order_item_classifier` | Injected into `PopularEventsService` |

Scoring is owned exclusively by `PopularEventsService`. Do not add parallel ranking logic or a new popularity service.

---

## Active consumers

| Consumer | Module | Injects | Role |
|----------|--------|---------|------|
| `PopularEventsBlock` | `myeventlane_front` | `@myeventlane_analytics.popular_events` | Renders ranked event cards (`PopularEventsBlock.php`) |
| `HomepageMerchandising` | `myeventlane_front` | `@myeventlane_analytics.popular_events` | Community Favourites homepage rail nid selection (`HomepageMerchandising.php`) |
| `HomepageSectionVisibility` | `myeventlane_front` | `@myeventlane_analytics.popular_events` | Hides Community Favourites rail when engine returns no rows (`HomepageSectionVisibility.php`) |
| `HomepageMerchandisingQueryAlter` | `myeventlane_front` | `@myeventlane_analytics.popular_events` | Applies engine ranking to `upcoming_events:page_popular` browse (`HomepageMerchandisingQueryAlter.php`) |
| `TrendingCategoriesService` | `myeventlane_analytics` | `@myeventlane_analytics.popular_events` | Aggregates trending categories from popular event rows (`TrendingCategoriesService.php`) |

Service wiring: `web/modules/custom/myeventlane_front/myeventlane_front.services.yml` (lines 9–34); `myeventlane_analytics.services.yml` (lines 74–90).

Related audit: `docs/audits/brand-rollout/popular-events-service-audit.md` (engine behaviour and gaps).

---

## Deprecated duplicate (retained, do not use)

| Layer | Class | Service id | Status |
|-------|-------|------------|--------|
| Orphan | `Drupal\myeventlane_core\Service\HomepagePopularityService` | `myeventlane_core.homepage_popularity` | **Deprecated** — no active runtime consumers |

Registration only: `web/modules/custom/myeventlane_core/myeventlane_core.services.yml` (lines 287–292).

Class retained temporarily for backwards compatibility. Service definition retained so container compilation is unchanged. Removal is out of scope for CF-2C.1.

---

## HomepagePopularityService audit (CF-2C.1 Phase 1)

| Check | Finding | Active? |
|-------|---------|---------|
| PHP callers / DI injections | None outside class + `myeventlane_core.services.yml` | **No** |
| Dynamic `\Drupal::service('myeventlane_core.homepage_popularity')` | None | **No** |
| Tests | None (`rg HomepagePopularityService tests/` → no matches) | **No** |
| Config (`config/sync`) | None | **No** |
| Block / plugin references | None | **No** |
| Event subscribers | None | **No** |
| Cron | None | **No** |
| Queue workers | None | **No** |
| Documentation | Historical audits only (`community-favourites-audit.md`, `popular-events-service-audit.md`, `PHASE2_BOOST_REFACTOR_COMPLETE.md`) | N/A |
| Registry | Listed in `mel-services.json` (inventory only, not a runtime caller) | N/A |

### Caller evidence table

| Caller | Location | Active? |
|--------|----------|---------|
| *(none)* | — | **No active runtime consumers** |
| Service definition | `myeventlane_core.services.yml:287–292` | Registered only (not injected elsewhere) |
| Class definition | `HomepagePopularityService.php` | Self-reference only |

**Conclusion:** Safe to deprecate in PHPDoc only. No behaviour, ranking, route, View, or config changes in CF-2C.1.

---

## Out of scope (CF-2C.1)

- Badge eligibility and visual treatments
- Browse route copy / SEO / redirects
- Removing `HomepagePopularityService` class or service definition
- Changing `PopularEventsService` scoring or sort order
