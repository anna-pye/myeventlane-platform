# Hidden Gems Governance Audit (Phase 6A)

**Date:** 2026-06-17  
**Scope:** Governance definition only — no code, config, or copy changes  
**Method:** Repository evidence first; brand docs second; gaps flagged explicitly

---

## Executive summary

In MEL, **Hidden Gem** is a **staff editorial programme**, not a vendor or algorithmic signal. An event becomes a Hidden Gem when a privileged staff user sets `field_hidden_gem = 1` on an upcoming, public event. That flag drives:

- the **homepage Hidden Gems rail** (`upcoming_events:homepage_hidden_gems`, max 6 cards),
- the **browse page** at `/events/hidden-gems` (12 per page),
- the **Hidden Gem card badge** (when not superseded by Spotlight),
- **click analytics** attributed to `homepage_hidden_gems` and `browse_hidden_gems` sources,
- a modest **recommendation score boost** (+8) in `EventRecommendationService`.

The programme is **independent of Boost / paid promotion** (`field_promoted`). Brand docs position Hidden Gems as MEL’s discovery differentiator — surfacing high-value, low-visibility local experiences without exclusivity or pay-to-play framing.

**Critical governance gap:** the `administer hidden gem flag` permission exists but is **not granted to any non-admin role in `config/sync`**. In practice, only Drupal administrators can assign Hidden Gems today. There is **no moderator role**, no editorial workflow, no pool-size cap, and no flag auto-expiry beyond standard event lifecycle rules.

---

## 1. What Hidden Gems means in MEL

### Brand definition

From `docs/brand/mel-brand-system-v1.md` § Hidden Gem Framework:

| Aspect | Definition |
|---|---|
| **Purpose** | Identify experiences that are high value but low visibility — events people are glad they found |
| **Qualifies when** | Strong community/creative merit with limited mainstream promotion; local relevance; genuine discovery moment |
| **Appears as** | `Hidden Gem` badge; discovery rails; copy like “A local favourite you might not have found yet” |
| **Is not** | Pay-to-play; synonym for “obscure”; exclusivity signal |

From `docs/brand/homepage-system.md`:

- Hidden Gems are **priority #3** on the homepage hierarchy (after Hero and Tonight / This week near you).
- Badge uses **Discovery Gold** accent (`docs/brand/design-tokens.md`).
- Explorer Guide may appear in the section intro (max two guide moments on full homepage).

From `docs/brand/event-card-system.md`:

- **One badge per card** — Hidden Gem is an approved editorial/scored badge.
- Must not stack with other badges (e.g. Hidden Gem + Editor’s Pick).

### Technical definition

| Item | Evidence |
|---|---|
| **Data model** | Boolean field `field_hidden_gem` on `node:event` |
| **Field label** | “Hidden Gem” |
| **Field description** | “Staff editorial flag for the Hidden Gems discovery programme. Independent of Boost or paid promotion.” — `config/sync/field.field.node.event.field_hidden_gem.yml` |
| **Discovery filter** | Views displays filter `field_hidden_gem_value = 1` plus standard upcoming/hygiene filters |
| **Badge renderer** | `EventMerchandisingPresenter` — label `'Hidden Gem'`, modifier `discovery-gold` |
| **Not vendor-controlled** | `myeventlane_event_entity_field_access()` forbids editing without dedicated permission; field hidden on all Event Studio wizard form modes |

**Distinction from Spotlight (Boost):**

| Signal | Field | Who sets | Meaning on card |
|---|---|---|---|
| **Spotlight** | `field_promoted` | Vendor Boost / staff promotion | Paid/editorial spotlight placement |
| **Hidden Gem** | `field_hidden_gem` | Staff with `administer hidden gem flag` | Editorial discovery programme |

These are explicitly documented as independent (`field_hidden_gem` description; permission description in `myeventlane_event.permissions.yml`).

---

## 2. Who can assign Hidden Gem?

### Permission model (repository-confirmed)

| Item | Value |
|---|---|
| **Permission ID** | `administer hidden gem flag` |
| **Title** | “Administer Hidden Gem editorial flag” |
| **Restrict access** | `true` |
| **Enforcement** | `myeventlane_event_entity_field_access()` — edit on `field_hidden_gem` forbidden without permission |
| **Administrator bypass** | Users with `is_admin: true` roles hold all permissions implicitly |
| **View access** | Not restricted — badge is public |

### Role assignments in `config/sync`

| Role | Has permission? |
|---|---|
| `administrator` (`is_admin: true`) | Yes (implicit) |
| `content_editor` | **No** |
| `vendor` | **No** |
| `mel_pro` | **No** |
| `authenticated` / `anonymous` | **No** |

**I cannot confirm a “moderator” role from the repository** — no role with that name exists in `config/sync/user.role.*.yml`.

### Where staff assign the flag

| Form mode | `field_hidden_gem` visible? |
|---|---|
| `node.event.default` | **Yes** — boolean checkbox |
| Event Studio wizard steps (`wizard_step_*`, `studio_branding`, `wizard_step_details`) | **Hidden** (`field_hidden_gem: true`) |
| Vendor-facing forms | **Hidden** + access forbidden |

### Governance answer

| Question | Answer |
|---|---|
| **Who can assign Hidden Gem today?** | **Drupal administrators only** (no other role has the permission in sync config) |
| **Staff only or moderators too?** | **Staff administrators only** — there is no moderator role or delegated editorial permission in the repository |
| **Can vendors self-assign?** | **No** — field access hook + wizard hiding prevent vendor assignment |
| **Can content editors assign?** | **No** — unless manually granted `administer hidden gem flag` outside sync |

### Recommended governance (not implemented)

Product/ops should decide whether to grant `administer hidden gem flag` to a future **Editorial** or **Discovery curator** role without full administrator access. Until then, assignment is centralised on administrators.

---

## 3. How many Hidden Gems should exist at once?

### Runtime capacity (repository-confirmed)

| Surface | Capacity | Evidence |
|---|---|---|
| **Homepage rail** | **6 cards max** | `views.view.upcoming_events` → `homepage_hidden_gems` pager `items_per_page: 6` |
| **Browse page** | **12 per page** (paginated) | `page_hidden_gems` pager `items_per_page: 12` |
| **System-wide flagged events** | **No hard cap** | Any number of nodes may have `field_hidden_gem = 1`; only upcoming/public events surface |

### Homepage visibility gate

The homepage section renders only when `HomepageSectionVisibility::hasHiddenGemEvents()` returns true — i.e. the `homepage_hidden_gems` View display returns at least one row **after** merchandising dedup and hygiene filters. If zero eligible events, the entire rail is hidden (`mel_home_show_hidden_gems` + `block_hide_empty: true`).

### Brand guidance (not enforced in code)

| Source | Guidance |
|---|---|
| `docs/brand/homepage-system.md` § Editor’s Pick | “Limited count (e.g. 4–6 items)” for human-curated rails — analogous editorial pattern |
| `docs/brand/mel-brand-system-v1.md` | No numeric pool cap for Hidden Gems programme |
| Phase 5 local validation | **0 Hidden Gems** on local DDEV — rail correctly hidden |

### Governance answer

| Question | Answer |
|---|---|
| **How many on homepage?** | **Up to 6** (View pager limit; may be fewer after dedup/diversity) |
| **How many in programme total?** | **Unbounded** — no repository-enforced maximum |
| **Recommended editorial target?** | **Not defined in repository.** Brand docs suggest curated rails work best at 4–6 visible items; ops should define an editorial target pool (e.g. 8–12 active flagged upcoming events) separately |

---

## 4. Can an event be both Hidden Gem and Spotlight?

### At the data layer

**Yes.** `field_hidden_gem` and `field_promoted` are independent booleans. Nothing in the repository prevents both being `1` on the same event.

### On the event card (badge)

**No — Spotlight wins.** `EventMerchandisingPresenter` image badge priority:

```text
Sold out → Spotlight (promoted) → Hidden Gem
```

If an event is both promoted and flagged Hidden Gem, the card shows **Spotlight** only. The Hidden Gem badge is suppressed.

Evidence: `EventMerchandisingPresenter.php` lines 114–124; unit tests in `EventMerchandisingPresenterTest.php`.

### On the homepage (surface placement)

**Mutually exclusive on rails, by dedup priority.** `HomepageMerchandising` cascade:

```text
Hero → Tonight → Hidden Gems → Discover → Community Spotlight → …
```

- **Community Spotlight** uses `front_featured_events:block_featured` (promoted events).
- **Hidden Gems** uses `upcoming_events:homepage_hidden_gems` (`field_hidden_gem = 1`).
- Higher-priority surfaces exclude NIDs from lower rails.

An event that wins **Hero** or **Community Spotlight** will not also appear in the **Hidden Gems homepage rail**, even if flagged. It may still appear on `/events/hidden-gems` browse (browse display does not apply homepage merchandising dedup).

### Governance answer

| Question | Answer |
|---|---|
| **Can both flags coexist on one event?** | **Yes** (data model allows it) |
| **Can both badges show?** | **No** — Spotlight takes precedence |
| **Can both homepage rails show the same event?** | **No** — dedup cascade prevents duplicate surface ownership |
| **Should ops assign both?** | **Discouraged.** Brand intent treats Hidden Gem as discovery merit independent of Boost; dual assignment creates confusing vendor expectations and suppresses the Hidden Gem badge |

---

## 5. Can an event be Hidden Gem and Community Favourite?

### At the data layer

**Yes.** Hidden Gem is editorial (`field_hidden_gem`); Community Favourites is engagement-ranked (`PopularEventsService`, 7-day lookback). No repository rule forbids both on the same node.

### On the homepage (surface placement)

**Mutually exclusive when both would appear on homepage rails.** Dedup order places **Hidden Gems (#3)** before **Community Favourites (#6)**:

- `getCommunityFavouritesExclusionNids()` excludes everything in `getCascadeThroughSpotlight()`, which includes Hidden Gem NIDs.
- An event that wins the Hidden Gems rail is excluded from the Community Favourites candidate pool.

**Edge case:** An event may be flagged Hidden Gem but **deduped out** of the Hidden Gems rail (because it already won Hero, Tonight, or Discover). If it remains in the popularity pool, it **can** appear as Community Favourite.

### On the card (badge)

Community Favourite is a separate approved badge in brand docs. `EventMerchandisingPresenter` does not render Community Favourite as an image badge today — only Sold out / Spotlight / Hidden Gem. I cannot confirm a Community Favourite card badge implementation from the presenter file alone.

### Governance answer

| Question | Answer |
|---|---|
| **Can one event be both?** | **Yes** at node level |
| **Can one event appear on both homepage rails?** | **No** — Hidden Gems wins over Community Favourites in dedup |
| **Operational guidance** | Prefer Hidden Gem for editorial discovery picks; let Community Favourites remain organic engagement signal. Avoid flagging events already dominant in CF unless intentional editorial override |

---

## 6. When should Hidden Gem expire?

### What exists today (repository-confirmed)

There is **no dedicated Hidden Gem expiry field**, cron job, or scheduled unset for `field_hidden_gem`.

An event **falls off Hidden Gem surfaces** when it fails public discovery eligibility:

| Condition | Mechanism |
|---|---|
| Event start in the past / ended | View upcoming filters + `PublicEventDiscoveryQueryAlter` (excludes `ended` state) |
| Lifecycle state draft / cancelled / archived | View filters on `homepage_hidden_gems` and `page_hidden_gems` |
| Unpublished | `status = 1` filter |
| Non-public visibility | `PublicEventDiscoveryQueryAlter` visibility exclusion |
| Homepage dedup | Removed from homepage rail if claimed by higher-priority surface — **flag remains on node** |

The **boolean flag persists** on the node after the event ends unless staff manually clears it.

### What does not exist

- Auto-clear `field_hidden_gem` on event end
- Time-boxed Hidden Gem assignment (e.g. “7 days before start”)
- Review/renewal workflow
- Audit log of who set/cleared the flag

### Governance answer

| Question | Answer |
|---|---|
| **When does Hidden Gem stop appearing?** | When the event is no longer upcoming/public/discoverable — **not** when the flag is cleared |
| **When should the flag be cleared?** | **Not defined in repository.** Recommended ops policy: clear flag after event ends or within 7 days of end date to keep editorial pool accurate |
| **Should expiry be automated?** | **Not implemented.** Phase 6B+ decision if product wants cron-based unset |

---

## 7. Homepage capacity and placement

### Rail configuration

| Item | Value |
|---|---|
| **Theme region** | `homepage_hidden_gems` |
| **Block** | `block.block.myeventlane_theme_homepage_hidden_gems.yml` |
| **View display** | `upcoming_events:homepage_hidden_gems` |
| **Cards shown** | 6 (pager type `some`) |
| **Sort** | `field_event_start` ASC (soonest first) |
| **Hide when empty** | Yes (`block_hide_empty: true`) |
| **Section visibility** | `mel_home_show_hidden_gems` (front page only) |
| **Template position** | After Tonight, before Discover — `page--front.html.twig` |
| **Diversity filter** | Yes — `HomepageRailDiversityFilter` applies to `homepage_hidden_gems` (category/venue/organiser de-duplication within the 6 slots) |

### Copy vs query mismatch

Prior audits note the rail subtitle/copy may imply locality (“Near You”), but **no geo filter** exists on `homepage_hidden_gems` in View config. Hidden Gems are **editorially flagged upcoming events**, not location-scored. Governance should either align copy with behaviour or add a geo filter in a future phase (out of 6A scope).

### Merchandising interaction

Hidden Gems sit at **priority 3** in the homepage dedup cascade (after Hero and Tonight). Events in Hero or Tonight are excluded from the Hidden Gems rail even if flagged.

---

## 8. Analytics that exist today

### Click attribution

| Source key | Surface | When recorded |
|---|---|---|
| `homepage_hidden_gems` | Homepage Hidden Gems rail | Card click with discovery attribution on homepage |
| `browse_hidden_gems` | `/events/hidden-gems` browse | Card click on browse page |

**Service chain:**

1. `DiscoveryAttributionSources` — maps View displays to source keys
2. `AnalyticsService::countEventClicksGroupedBySource()` — counts `event_click` rows by source
3. `DiscoverySurfaceAnalyticsService` — maps sources to surface keys `hidden_gems` and `hidden_gems_browse`
4. `HomepageVisibilityReportService` — reports `hidden_gems` surface in vendor visibility report
5. Vendor dashboard (`VendorDashboardViewModelBuilder`) — shows surface labels and click counts via `buildReport()` / `buildSurfaceClickSummary()`

### What analytics include

- **Clicks** per surface (homepage rail, browse page)
- **Aggregate** homepage surface performance across boosted events
- **Vendor-facing labels** e.g. “Hidden Gems — N clicks”

### What analytics do not include

| Gap | Notes |
|---|---|
| **Impressions** | `DiscoverySurfaceAnalyticsService` docblock: “Uses stored event_click rows only — no impressions” |
| **Assignment audit** | No log of who set/cleared `field_hidden_gem` or when |
| **Editorial justification** | No structured reason field on assignment |
| **Conversion funnel** | Clicks only — no ticket/RSVP attribution split by Hidden Gem surface in this service |
| **Flagged-but-not-shown** | Events deduped out of homepage rail still flagged; no metric for “eligible but suppressed” |

### Recommendation scoring (non-analytics)

`EventRecommendationService` adds **+8 score** when `field_hidden_gem = 1`, influencing recommended-event ordering. This is not surfaced as analytics to vendors.

---

## 9. Surfaces summary

| Surface | Route / display | Shown when |
|---|---|---|
| Homepage rail | `<front>` / `homepage_hidden_gems` | Flagged + upcoming + public + passes dedup + ≥1 result |
| Browse page | `/events/hidden-gems` / `page_hidden_gems` | Flagged + upcoming + public |
| Card badge | Any card context | Flagged + not sold out + not promoted (Spotlight wins) |
| Search fallback | Search empty state | `SearchController::buildHiddenGemsFallback()` |
| Customer continuity | Post-booking recovery links | `MelCustomerContinuityPresenter` → “Explore Hidden Gems” |
| Vendor visibility report | Vendor dashboard | `HomepageVisibilityReportService::getSurfacesForEvent()` |

---

## 10. Governance gaps and open decisions

| # | Gap | Risk | Decision needed |
|---|---|---|---|
| 1 | Permission only on administrators | Editorial bottleneck; no delegated curators | Create Editorial role with `administer hidden gem flag`? |
| 2 | No moderator role | “Moderators” cannot assign — term undefined in MEL | Define role taxonomy (admin vs editor vs curator) |
| 3 | No pool size policy | Unbounded flags; homepage shows max 6 | Set editorial target (e.g. maintain 8–12 active gems) |
| 4 | No flag expiry | Stale flags on past events clutter admin | Manual review cadence vs automated unset on end |
| 5 | Dual Spotlight + Hidden Gem allowed | Badge confusion; vendor perception of pay-to-play | Policy: disallow dual assignment or auto-clear one |
| 6 | “Near You” copy without geo | Over-promises locality | Copy alignment or geo filter (separate phase) |
| 7 | No assignment audit trail | Accountability gap for editorial programme | Log flag changes (future ops tooling) |
| 8 | Zero local fixtures (Phase 5) | Programme untested end-to-end | Seed editorial Hidden Gems on staging/local |

---

## 11. Recommended governance policy (proposed — not in repository)

These recommendations synthesise brand docs and runtime behaviour. **They are not enforced in code.**

### Assignment

1. **Who:** Discovery/editorial staff with `administer hidden gem flag` (not vendors, not content editors unless granted).
2. **Criteria:** Apply brand framework § What qualifies — local merit, low mainstream visibility, genuine discovery moment.
3. **Exclusions:** Do not assign to events primarily surfaced via Boost/Spotlight unless intentionally replacing paid framing with editorial discovery story.
4. **Dual flags:** Avoid `field_promoted = 1` and `field_hidden_gem = 1` on the same event.

### Pool management

1. **Active pool target:** Maintain **8–12** flagged upcoming events organisation-wide (supports homepage 6-pack + browse depth).
2. **Homepage visible:** Expect **4–6** on rail after dedup (aligns with brand curated-rail pattern).
3. **Review cadence:** Weekly editorial review of flagged pool.

### Expiry

1. **Surface expiry:** Automatic — event drops off rails when no longer upcoming/public (already true).
2. **Flag expiry:** Manual clear after event end, or within **7 days** of end date (ops policy).
3. **Future automation:** Consider cron unset of `field_hidden_gem` when `field_event_state = ended` (Phase 6B+).

### Overlap rules

| Combination | Policy |
|---|---|
| Hidden Gem + Spotlight | Discouraged; Spotlight badge wins |
| Hidden Gem + Community Favourite | Allowed; homepage rails dedup — Hidden Gems wins |
| Hidden Gem + Hero | Possible; Hero wins homepage dedup; flag may remain for browse |

---

## 12. Evidence index

| Topic | Primary paths |
|---|---|
| Field definition | `config/sync/field.field.node.event.field_hidden_gem.yml` |
| Permission | `web/modules/custom/myeventlane_event/myeventlane_event.permissions.yml` |
| Field access hook | `web/modules/custom/myeventlane_event/myeventlane_event.module` (lines 73–90) |
| Access tests | `web/modules/custom/myeventlane_event/tests/src/Unit/HiddenGemFieldAccessTest.php` |
| Homepage View | `config/sync/views.view.upcoming_events.yml` (`homepage_hidden_gems`, `page_hidden_gems`) |
| Merchandising dedup | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` |
| Badge priority | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` |
| Section visibility | `web/modules/custom/myeventlane_front/src/Service/HomepageSectionVisibility.php` |
| Click analytics | `web/modules/custom/myeventlane_core/src/Service/DiscoverySurfaceAnalyticsService.php` |
| Attribution sources | `web/modules/custom/myeventlane_core/src/Service/DiscoveryAttributionSources.php` |
| Vendor report | `web/modules/custom/myeventlane_front/src/Service/HomepageVisibilityReportService.php` |
| Brand framework | `docs/brand/mel-brand-system-v1.md`, `docs/brand/homepage-system.md`, `docs/brand/event-card-system.md` |
| Phase 5 validation | `docs/audits/brand-rollout/discovery-validation-audit.md` |

---

## 13. Residual risks

- **Governance vs runtime mismatch:** Brand promises Hidden Gems as a differentiator, but local/staging pools may be empty — rail hidden with no user-facing explanation beyond absence.
- **Administrator-only assignment** does not scale for a curated discovery programme.
- **No expiry** allows stale editorial state on archived events.
- **Analytics are click-only** — cannot measure editorial programme ROI without impressions or conversion attribution.

---

**Phase 6A complete.** No code, config, View, SCSS, Commerce, or RSVP changes made.
