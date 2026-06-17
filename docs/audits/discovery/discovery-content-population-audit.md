# Discovery Content Population Audit (Phase 8A)

**Date:** 2026-06-17  
**Environment:** Local DDEV (`myeventlane.ddev.site`)  
**Scope:** Why discovery rails are under-populated — inventory and eligibility audit only  
**Method:** Drush inventory queries + front-page merchandising simulation (`HttpKernel SUB_REQUEST`)  
**No code changes**

Related governance: [`docs/audits/governance/hidden-gems-governance.md`](../governance/hidden-gems-governance.md), [`docs/audits/governance/community-favourites-governance.md`](../governance/community-favourites-governance.md)

---

## Executive summary

Discovery rails are under-populated **not because event inventory is empty**, but because **rail-specific eligibility filters are unmet**:

| Rail | Phase 5 evidence | Current DDEV (2026-06-17) | Primary bottleneck |
|---|---|---|---|
| **Hidden Gems** | 0 | **0** | **Zero editorial flags** (`field_hidden_gem = 0` on all events) |
| **Community Favourites** | 1 | **2** | **Almost no real engagement in 7-day window** (2 confirmed RSVPs total; 0 ticket sales) |
| **Tonight** | 1 | **3** | **Calendar-day scarcity** — only events starting *today* (site TZ) qualify; 1 organic + 2 staging fixtures |

**Overall inventory is healthy:** 71 event nodes, 41 publicly listable upcoming events across 8 categories. Discover (6), Upcoming Highlights (12), and Free RSVP (6) rails are well supplied. The gap is concentrated in **editorial assignment (Hidden Gems)**, **live engagement data (Community Favourites)**, and **same-day scheduling (Tonight)**.

---

## 1. Event inventory

### Totals

| Metric | Count |
|---|---|
| Total event nodes | **71** |
| Published | **67** |
| Unpublished | **4** |
| Published past (start &lt; now) | **22** |
| Published upcoming (start ≥ now) | **44** |
| Publicly listable upcoming | **41** |
| Non-listable upcoming | **3** (internal `[MEL TEST]` events) |

### Lifecycle and type (published upcoming, n=44)

| Dimension | Breakdown |
|---|---|
| **State** | live: 28 · scheduled: 16 |
| **Type** | rsvp: 32 · paid: 12 |
| **Visibility** | public: 44 (no unlisted/private in upcoming set) |

### Promotion and editorial flags

| Flag | Published (all) | Upcoming |
|---|---|---|
| `field_promoted = 1` | 12 | 11 |
| `field_hidden_gem = 1` | **0** | **0** |

### MEL-STAGING fixtures

**11** staging events present (seeded after Phase 5 via `homepage-real-world-validation.drush.php`):

| Fixture | NID | Starts | Promoted | Hidden Gem |
|---|---|---|---|---|
| HERO-001 | 1744 | 2026-06-21 | yes | no |
| SPOTLIGHT-001/002 | 1745, 1746 | Jun 25, 29 | yes | no |
| GATE-FAIL-001 | 1747 | Jun 23 | yes | no |
| TONIGHT-001/002 | 1748, 1749 | **2026-06-17** (today) | no | no |
| DISCOVER-001/002 | 1750, 1751 | Jul 2, 5 | no | no |
| RSVP-001 | 1752 | Jun 27 | no | no |
| LATEST-001 | 1753 | Jul 9 | no | no |
| RECOMMENDED-001 | 1754 | Jul 12 | no | no |

**None of the staging fixtures have `field_hidden_gem = 1`.** Staging improves Tonight/Discover/Latest pools but does **not** populate Hidden Gems.

---

## 2. Category distribution

### Publicly listable upcoming events (n=41)

| Category | Count |
|---|---|
| Community | 15 |
| Music | 8 |
| Markets | 4 |
| *(none)* | 3 |
| LGBTQI+ | 3 |
| Arts | 3 |
| Workshop | 2 |
| Family | 2 |
| Food & Drink | 1 |

**Finding:** Inventory is category-diverse. Under-population is not caused by missing categories — it is caused by per-rail filters (editorial flag, engagement window, calendar day).

### Start-date horizon (listable upcoming)

| Window (Sydney calendar) | Events |
|---|---|
| Today | **1** organic (+ 2 staging tonight) |
| Within 7 days | 6 |
| Within 30 days | 19 |
| 90+ days out | 15 |

**Finding:** Most upcoming events are **weeks away**. Same-day / “tonight” pool is inherently thin on any given audit day.

---

## 3. Homepage rail population (front-page context)

Captured via `HttpKernel SUB_REQUEST` to `/home` on 2026-06-17:

| Rail | Count | Capacity | Fill % |
|---|---|---|---|
| Hero | 1 | 1 | 100% |
| Tonight | **3** | 6 | 50% |
| Hidden Gems | **0** | 6 | **0%** |
| Discover | 6 | 6 | 100% |
| Community Spotlight | 2 | 3 | 67% |
| Community Favourites | **2** | 8 | 25% |
| Upcoming Highlights | 12 | 12+ | 100% |
| Free RSVP | 6 | 6 | 100% |
| More Events To Explore | 4 | — | — |

### Phase 5 vs current (Tonight / CF)

| Rail | Phase 5 audit | Current | Change driver |
|---|---|---|---|
| Tonight | 1 | 3 | +2 MEL-STAGING-TONIGHT fixtures starting today |
| Community Favourites | 1 | 2 | +1 event with recent RSVP (NID 1681) |
| Hidden Gems | 0 | 0 | Unchanged — no flags assigned |

---

## 4. Hidden Gem candidates

### Why Hidden Gems = 0

**Root cause: editorial.** The `homepage_hidden_gems` View requires `field_hidden_gem = 1`. Repository count:

```text
field_hidden_gem = 1 (published): 0
field_hidden_gem = 1 (upcoming):  0
```

`HomepageSectionVisibility::hasHiddenGemEvents()` returns false → homepage section hidden.

### Potential editorial pool (heuristic)

| Pool | Count | Notes |
|---|---|---|
| Unpromoted + listable + upcoming | **30** | Could be flagged by staff — none are |
| Staging fixtures | 11 | None flagged Hidden Gem |
| Promoted upcoming | 11 | Could still be flagged; Spotlight badge would win on card if also promoted |

### Hidden Gem is not automatic

No scoring service assigns Hidden Gem. Assignment requires staff with `administer hidden gem flag` (administrators only in sync config). See Phase 6A governance.

### Dedup impact on Hidden Gems

Not applicable at count zero — no candidates to dedup.

---

## 5. Community Favourite candidates

### Engine rules (reminder)

`PopularEventsService`:

- Window: **7 days**
- Score: `(tickets_sold × 3) + (rsvps × 1)`
- Minimum: **≥1 ticket or RSVP** in window (no higher floor)
- Source: **live** `commerce_order_item` + `rsvp_submission` tables — **not** `myeventlane_projection_event_metrics`

### Actual engagement (7-day window)

| Source | Count |
|---|---|
| Confirmed RSVPs (7d) | **2** (across **2** events) |
| Ticket sales (7d) | **0** |
| Events in PopularEventsService pool | **2** |

| Rank | NID | Title | Score | Going | Tickets | RSVPs |
|---|---|---|---|---|---|---|
| 1 | 1696 | Volunteer Meetup | 1 | 1 | 0 | 1 |
| 2 | 1681 | Jazz in the Park | 1 | 1 | 0 | 1 |

### Projection metrics vs engine (critical mismatch)

**51 events** have non-zero rows in `myeventlane_projection_event_metrics`, including high “going” counts:

| NID | Title | Metrics tickets | Metrics RSVP |
|---|---|---|---|
| 1714 | Outdoor Cinema | 23 | 29 |
| 1696 | Volunteer Meetup | 20 | 24 |
| 1591 | Winter Festival of Lights | 20 | 24 |
| 1713 | Drag Bingo | 14 | 17 |

**These metrics are not used by `PopularEventsService`.** Only **2** events have **recent** confirmed RSVPs in the 7-day window. This explains why CF shows 1–2 cards despite a seemingly rich metrics table.

### CF after homepage dedup

| Stage | NIDs |
|---|---|
| Raw top-24 popular | 1696, 1681 |
| Excluded by dedup | **none** |
| CF rail (after dedup + diversity) | **1696, 1681** |

**Dedup is not the bottleneck** for CF at current engagement levels — the popularity pool itself is nearly empty.

### Theoretical CF candidates

All **41** listable upcoming events would qualify **if** they received ≥1 ticket/RSVP in the last 7 days. Today only **2** do.

---

## 6. Events eligible for Tonight

### View definition (repository-confirmed)

Display: `upcoming_events:homepage_tonight`  
Capacity: **6 cards**

Base View filters (group 2 OR): event **has not ended** (`field_event_end >= now`) OR no end date with start ≥ now.

**Additional constraint** via `myeventlane_views_views_query_alter()`:

```php
// Start must fall on today's calendar day (site timezone)
field_event_start >= today 00:00:00
field_event_start <  tomorrow 00:00:00
```

Evidence: `web/modules/custom/myeventlane_views/myeventlane_views.module` lines 37–62.

**“Tonight” = events starting today**, not all upcoming events, not “this evening” only.

### Tonight candidates (2026-06-17, Australia/Sydney)

| NID | Title | Start | Source |
|---|---|---|---|
| 1692 | Community Picnic | 2026-06-17T11:00:00 | **Organic** |
| 1748 | MEL-STAGING-TONIGHT-001 | 2026-06-17T19:30:00 | Staging fixture |
| 1749 | MEL-STAGING-TONIGHT-002 | 2026-06-17T20:30:00 | Staging fixture |

| Metric | Count |
|---|---|
| Events starting today (Sydney day) | **1 organic** (+ 2 staging) |
| Homepage Tonight rail | **3** |
| Dedup exclusions from Tonight | Hero NID excluded from lower rails only; Tonight is priority #2 |

### Why Phase 5 showed Tonight = 1

On Phase 5 audit date, **only one organic event** had `field_event_start` on the current calendar day. Staging fixtures were not yet seeded. **Tonight = 1 was correct behaviour**, not a bug.

### Calendar scarcity pattern

With 41 listable upcoming events but only **~1 starting per typical day**, Tonight will often show **1–3 cards** unless:

- more events are scheduled for “today”, or
- staging/seed scripts create same-day fixtures, or
- the calendar-day filter is relaxed (product decision — out of 8A scope)

---

## 7. Root cause matrix

| Rail | Inventory sufficient? | Eligibility met? | Root cause | Fix category |
|---|---|---|---|---|
| **Hidden Gems** | Yes (30+ unpromoted upcoming) | **No** | Zero `field_hidden_gem` flags | **Editorial ops** — staff must assign flags |
| **Community Favourites** | Yes (41 listable) | **Barely** | Only 2 real RSVPs in 7d; 0 ticket sales; projection metrics ignored | **Engagement seeding** or real bookings; not dedup/config |
| **Tonight** | Yes (41 upcoming) | **Calendar-limited** | Query restricts to **starts today**; typically 1 organic/day | **Scheduling** + optional staging; not inventory |
| Discover | Yes | Yes | — | — |
| Upcoming Highlights | Yes | Yes | — | — |
| Free RSVP | Yes | Yes | — | — |

---

## 8. Why other rails are populated (contrast)

Understanding populated rails confirms the system works when eligibility is met:

| Rail | Source | Why populated |
|---|---|---|
| Discover | `mel_home_events:embed_discover` | Any upcoming public event; no editorial/engagement gate |
| Upcoming Highlights | `upcoming_events:homepage_latest` | Broad upcoming sort; 12 slots filled |
| Free RSVP | `mel_home_events:under_20` | RSVP-type events; 6 slots filled |
| Hero | Top promoted marketplace-ready event | 11 promoted upcoming; quality gate excludes 2 |
| Community Spotlight | Promoted block roster | Promoted pool after dedup |

Under-populated rails have **extra gates** (editorial flag, 7-day engagement, calendar day) that broad rails do not.

---

## 9. Data quality notes

### Non-listable upcoming events

| NID | Title | Issue |
|---|---|---|
| 1663 | [MEL TEST] Event 5 - Paid | Fails `isPubliclyListable()` |
| 1665 | [MEL TEST] Event 7 - Paid | Fails `isPubliclyListable()` |
| 1666 | [MEL TEST] Event 8 - Paid | Fails `isPubliclyListable()` |

These are correctly excluded from discovery but inflate raw “upcoming” counts (44 vs 41 listable).

### Ineligible promoted events (quality gate)

NIDs **1714**, **1716** — promoted but fail marketplace media quality gate; excluded from hero/spotlight merchandising.

### Projection metrics vs live engagement

`myeventlane_projection_event_metrics` holds cumulative/historical-style counts for **51 events**. `PopularEventsService` reads **transaction timestamps** from Commerce and `rsvp_submission`. **Do not use projection metrics alone to predict CF rail fill** — they measure different things.

---

## 10. Recommendations (ops — no code in Phase 8A)

### Hidden Gems (0 → target 4–6 on homepage)

1. **Assign `field_hidden_gem = 1`** to 8–12 listable upcoming events (staff/admin with permission).
2. Optionally flag 1–2 **MEL-STAGING** fixtures in seed script for deterministic validation — seed script currently sets `field_hidden_gem` nowhere.
3. Prioritise unpromoted, category-diverse events (30 candidates exist).

### Community Favourites (1–2 → target 6–8 on homepage)

1. **Create real or staging RSVPs/ticket purchases within 7 days** — engine requires live rows in `rsvp_submission` / Commerce order items.
2. Projection metrics seeding (`mel_staging_set_metrics`) **does not feed CF** — do not rely on it for CF validation.
3. Accept that CF will stay thin until marketplace activity exists; dedup is not the blocker today.

### Tonight (1 organic → variable by calendar day)

1. **Expect 1–2 organic cards** on most days given current scheduling spread (only 1 event/day starts today on average).
2. Use **MEL-STAGING-TONIGHT** fixtures for validation when testing same-day rail (already adds +2 today).
3. Product decision: if “Tonight” should mean “this evening” or “next 12 hours” rather than “calendar today”, that requires a **query change** — document separately; current behaviour is calendar-day by design (`myeventlane_views.module`).

### General

1. Run population audit after fixture seeding: `ddev drush scr web/scripts/audit-homepage-gate.drush.php`
2. Track **listable upcoming** (41) as health metric; track **per-rail eligibility** separately.

---

## 11. Validation commands used

```bash
ddev drush php:eval '…'   # inventory, engagement, rail counts (see audit session)
ddev drush scr web/scripts/audit-homepage-gate.drush.php
```

Audit date: **2026-06-17**. Counts will change as events are scheduled, bookings occur, and editorial flags are assigned.

---

## 12. Evidence index

| Topic | Path |
|---|---|
| Tonight calendar-day alter | `web/modules/custom/myeventlane_views/myeventlane_views.module` |
| Hidden Gems View filter | `config/sync/views.view.upcoming_events.yml` (`homepage_hidden_gems`) |
| Popularity engine | `web/modules/custom/myeventlane_analytics/src/Service/PopularEventsService.php` |
| CF block | `web/modules/custom/myeventlane_front/src/Plugin/Block/PopularEventsBlock.php` |
| Homepage dedup | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` |
| Staging fixtures | `web/scripts/homepage-real-world-validation.drush.php` |
| Phase 5 rail counts | `docs/audits/brand-rollout/discovery-validation-audit.md` |
| HG governance | `docs/audits/governance/hidden-gems-governance.md` |
| CF governance | `docs/audits/governance/community-favourites-governance.md` |

---

## 13. Residual risks

- **Hidden Gems rail hidden entirely** until editorial programme starts — brand differentiator invisible locally.
- **CF misread from projection metrics** — ops may think engagement exists when 7-day live pool is empty.
- **Tonight variance by audit day** — population swings with calendar; comparing audits on different dates requires noting “starts today” count.
- **Staging fixtures** improve some rails but **do not** populate Hidden Gems or CF without flag assignment / live RSVPs.

---

**Phase 8A complete.** No code, config, View, SCSS, Commerce, or RSVP changes made.
