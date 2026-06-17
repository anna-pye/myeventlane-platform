# Community Favourites Governance Audit (Phase 7A)

**Date:** 2026-06-17  
**Scope:** Governance definition only — no code, config, or copy changes  
**Method:** Repository evidence first; brand docs second; gaps flagged explicitly  
**Related:** `docs/audits/community-favourites-governance.md` (CF-7F ownership authority) — this document answers **product governance questions** Phase 7A requires; CF-7F remains the technical ownership map.

---

## Executive summary

In MEL, **Community Favourite** is **not an editorial flag or badge** — it is a **popularity-driven discovery surface** backed by `PopularEventsService`. An event qualifies when it has **real engagement** (paid ticket sales and/or confirmed RSVPs) within a **rolling lookback window** (default **7 days**), passes **public listability** checks, and ranks highly enough to appear after homepage dedup and diversity filters.

There is **no minimum score threshold** beyond “at least one counted engagement in the window.” There is **no staff override field or permission**. Promoted (Spotlight) and Hidden Gem events **can** become Community Favourites if they have engagement and are not excluded by homepage dedup.

**Decay is built in** via the rolling window — engagement older than the lookback drops out automatically. There is no separate time-decay curve within the window.

---

## 1. What Community Favourite means in MEL

### Brand definition

From `docs/brand/event-card-system.md`:

| Aspect | Definition |
|---|---|
| **Meaning** | Strong repeat attendance or local sentiment |
| **When to use** | Evidence-based; **not paid placement** |
| **Badge on card** | Approved badge in brand system — but **not implemented as an image badge** in runtime (see §8) |

From `docs/brand/mel-brand-system-v1.md`:

- “Social proof without pressure” — Community Favourite is acceptable; fake scarcity is not.

From `docs/brand/homepage-system.md`:

- Community Favourites sit at **priority #6** in homepage hierarchy (medium — social proof without pressure).
- Content priority **P1** alongside Hidden Gems.

### Technical definition

| Item | Evidence |
|---|---|
| **Engine** | `PopularEventsService` (`myeventlane_analytics.popular_events`) |
| **Formula** | `score = (tickets_sold × 3) + (rsvps × 1)` — locked in service docblock |
| **Ticket source** | Commerce paid order items; excludes boost/donation order item types via `OrderItemClassifier` |
| **RSVP source** | `rsvp_submission` (confirmed) + legacy `myeventlane_rsvp` merge |
| **Eligibility** | `PublicEventVisibility::isPubliclyListable()` — published, public visibility, not draft/cancelled/archived/ended |
| **Not editorial** | No `field_community_favourite` or staff boolean exists |
| **Public label** | Section: **Community Favourites**; card discovery reason: **Popular with the community** |

### Distinction from other signals

| Signal | Mechanism | Paid? | Staff-controlled? |
|---|---|---|---|
| **Community Favourites** | Engagement ranking (`PopularEventsService`) | No — real tickets/RSVPs only | **No** |
| **Spotlight** | `field_promoted` (Boost) | Yes (vendor Boost) | Vendor/staff promotion |
| **Hidden Gem** | `field_hidden_gem` | No | Staff editorial flag |
| **Editor's Pick** | Brand-approved badge; field not confirmed for CF | — | Staff-curated (separate programme) |

Community Favourites is the **only major homepage rail driven purely by measured engagement**, not editorial assignment.

---

## 2. Minimum threshold?

### Governance answer (repository-confirmed)

| Question | Answer |
|---|---|
| **Minimum score to enter pool?** | **None coded.** Any event with ≥1 counted ticket or RSVP in the window gets score ≥1 and enters the ranked pool. |
| **Minimum tickets?** | **0** — RSVPs alone qualify (score = rsvp count). |
| **Minimum RSVPs?** | **0** — ticket sales alone qualify (score = tickets × 3). |
| **Minimum combined “going”?** | **1** — `going = tickets_sold + rsvps`; events with zero engagement in the window are not in the pool at all. |
| **Minimum to show “X going” on CF cards?** | **> 0** when event social proof mode is `show` (`myeventlane_event_should_show_block_going()`). Hidden when mode is `hide`. |
| **Minimum to render homepage section?** | **≥1 eligible event** after post-dedup pool (`HomepageSectionVisibility::hasCommunityFavouritesEvents()`). |

### Effective threshold in practice

The practical floor is **one real booking action** (paid ticket quantity or confirmed RSVP) within the lookback window, on a **publicly listable upcoming event**.

### Not defined in repository

| Gap | Notes |
|---|---|
| **Minimum score for “meaningful” CF** | e.g. “score ≥ 5” — not implemented |
| **Minimum unique buyers** | Not tracked; quantity sum only |
| **Anti-gaming floor** | No fraud/spike detection in `PopularEventsService` |
| **Geographic threshold** | No location filter on CF engine |

### Recommended governance (not implemented)

Product may wish to document an **ops floor** (e.g. score ≥ 3 or going ≥ 5) for quality — but **today any single RSVP qualifies**. If a floor is desired, it must be added to `PopularEventsService` or the block filter with explicit sign-off (would be a future phase, not 7A).

---

## 3. Time window?

### Governance answer (repository-confirmed)

| Setting | Value | Evidence |
|---|---|---|
| **Default lookback** | **7 days** | `PopularEventsService::DEFAULT_DAYS = 7` |
| **Homepage block config** | **7 days** | `block.block.myeventlane_theme_homepage_community_favourites.yml` → `days: 7` |
| **Homepage dedup pool fetch** | **7 days, top 24** | `HomepageMerchandising::getCommunityFavouritesEventIds()` → `getPopularEventIds(7, 24)` |
| **Browse page ranking** | **7 days** | `HomepageMerchandisingQueryAlter` — comment and call use 7-day lookback |
| **Configurable range** | **1–60 days** | `PopularEventsBlock` block form `#min => 1, #max => 60` |
| **Engagement timestamp** | Order item `created`; RSVP `created` | `PopularEventsService` — not order completed time |

### What the window measures

**New engagement in the last N days** — not lifetime totals, not event-start proximity (except via listability filter removing past events).

### Homepage vs browse

Both surfaces use the **same 7-day engine output** today. Browse (`/events/popular`, display `page_popular`) constrains the View to engine-ranked NIDs via query alter; homepage renders via `PopularEventsBlock` with merchandising/diversity on top.

### Cache refresh

Block render cache `max-age: 900` (15 minutes) — ranking can lag up to 15 minutes between engagement changes and homepage display even though the window is rolling.

---

## 4. Can promoted events become Community Favourites?

### Governance answer

**Yes.** `PopularEventsService` does **not** filter on `field_promoted`. Boost order **items** are excluded from ticket counting, but **promoted events with genuine ticket/RSVP engagement are ranked like any other event.**

### Homepage interaction

Promoted events may be **deduped out** of the Community Favourites rail if they already appear in higher-priority surfaces:

```text
Hero → Tonight → Hidden Gems → Discover → Community Spotlight → Community Favourites → …
```

Community Spotlight uses promoted events (`front_featured_events:block_featured`). A heavily promoted event may **win Spotlight** and **not appear** on the CF homepage rail — but it can still appear on `/events/popular` browse if ranked and listable.

### Card presentation

If a promoted event appears on the CF rail:

- **Image badge:** **Spotlight** (promoted wins over Hidden Gem in `EventMerchandisingPresenter`)
- **Discovery reason:** **Popular with the community** (from `mel_source`, not from promotion)

So an event can simultaneously show **Spotlight badge + CF discovery reason** — brand says CF is “not paid placement”; runtime allows promoted events with real engagement. **Governance tension:** copy implies organic social proof; Boosted events can appear if engagement qualifies.

### Recommended policy (not enforced)

| Policy option | Recommendation |
|---|---|
| Exclude promoted events from CF | Would require engine change — not current behaviour |
| Allow but cap promoted share | Not implemented |
| **Current honest framing** | Homepage subtitle already says *“from real tickets and RSVPs this week”* — accurate; does not claim “non-promoted only” |

Product should decide whether **paid promotion + CF placement** is acceptable social proof or whether promoted NIDs should be excluded from the CF pool (future phase).

---

## 5. Can Hidden Gems become Community Favourites?

### Governance answer

**Yes, at the data level.** Hidden Gem (`field_hidden_gem`) and Community Favourites (engagement rank) are independent. An event can be editorially flagged **and** have ticket/RSVP engagement in the window.

### Homepage surface interaction

**Mutually exclusive on homepage rails when both would claim the same slot.** Dedup priority:

| Priority | Surface |
|---|---|
| 3 | Hidden Gems |
| 6 | Community Favourites |

An event that **wins the Hidden Gems rail** is excluded from the CF candidate pool via `getCommunityFavouritesExclusionNids()`.

**Edge case:** An event flagged Hidden Gem but **deduped out** of the Hidden Gems rail (e.g. already in Hero or Tonight) **can still appear** as Community Favourite if it ranks in the popularity pool.

### Browse

`/events/hidden-gems` and `/events/popular` are separate routes — the same event can appear on **both browse pages** if it meets each display's criteria.

### Card presentation on CF rail

- **Image badge:** Hidden Gem **only if not promoted**; Spotlight if promoted
- **Discovery reason on CF rail:** **Popular with the community** (from attribution source, not Hidden Gem copy)

### Recommended policy (not enforced)

| Scenario | Guidance |
|---|---|
| HG + high engagement | Acceptable — editorial discovery and social proof can coexist on different surfaces |
| Same event on HG and CF homepage rails | **Prevented by dedup** — HG wins |
| Staff assigns HG to CF-dominant event | Allowed; may reduce CF visibility if HG rail claims the NID |

---

## 6. Should Community Favourites decay?

### What exists today (repository-confirmed)

| Decay mechanism | Present? | How |
|---|---|---|
| **Rolling window drop-off** | **Yes** | Engagement older than N days (default 7) stops counting |
| **Past event removal** | **Yes** | `PublicEventVisibility::isPubliclyListable()` removes ended events; engine deprioritises `is_past` in sort then filters |
| **Score time-decay within window** | **No** | A ticket sold 7 days ago counts the same as one sold today |
| **Automatic rank decay cron** | **No** | No scheduled rank decay beyond window boundary |
| **Cache TTL** | **15 min** | Block `max-age: 900` |

### Governance answer

| Question | Answer |
|---|---|
| **Should CF decay?** | **Yes — and it already does** via the 7-day rolling window and listability lifecycle |
| **Is decay sufficient?** | **Partially.** Window boundary provides step decay; no gradual fade for aging engagement within the week |
| **Should flag-based expiry exist?** | **N/A** — there is no CF flag to expire |

### Recommended governance (not implemented)

| Option | Trade-off |
|---|---|
| **Keep 7-day window** | Simple, matches homepage copy (“this week”); current default |
| **Shorter window (3 days)** | More “right now” social proof; sparser rails |
| **Longer window (14 days)** | Fuller rails; staler signal |
| **Within-window decay** | e.g. weight recent days higher — requires engine change |
| **Document window in Help Centre** | Transparency for vendors wondering why CF status changed |

**Recommendation:** Treat **rolling window = canonical decay**. Any change to window length is a **product decision** applied via block config / service default — not a separate “expiry” system. Do not add staff TTL without overriding evidence-based integrity.

---

## 7. Should staff override exist?

### What exists today (repository-confirmed)

| Mechanism | Exists? |
|---|---|
| `field_community_favourite` or pin boolean | **No** |
| Permission to force-include event in CF | **No** |
| Permission to exclude event from CF | **No** |
| Per-event staff boost in ranker | **No** |
| Block-level config (`days`, `limit`) | **Yes** — Drupal admin block settings only |
| Views manual selection | **No** — browse uses engine ordering via query alter |

**I cannot confirm any staff override path from the repository.**

### Governance answer

| Question | Answer |
|---|---|
| **Should staff override exist?** | **Not in current architecture** — CF is intentionally algorithmic |
| **Should it exist in future?** | **Generally no** for the CF surface itself — override would undermine “evidence-based; not paid placement” (`event-card-system.md`) |

### If editorial placement is needed

Use **separate surfaces**, not CF override:

| Need | Approved alternative |
|---|---|
| Staff pick | Editor's Pick programme / Hidden Gem flag |
| Paid prominence | Spotlight / Boost (`field_promoted`) |
| Temporary pin | **Not implemented** — would be new product scope |

### Exception cases (ops, not product)

| Case | Current workaround |
|---|---|
| Fraudulent engagement spike | Manual unpublish / cancel event — not CF-specific |
| Test/staging events in pool | Internal title exclusion patterns in `PublicEventVisibility` |
| Empty CF rail locally | Seed real ticket/RSVP fixtures — not staff pin |

### Recommended policy

1. **Do not add staff override** to Community Favourites without explicit product sign-off and copy review (“Popular with the community” must remain truthful).
2. If curators need control, use **Hidden Gem** or **Editor's Pick** — not CF manipulation.
3. Block `days` / `limit` changes remain **platform admin** configuration, not per-event editorial tools.

---

## 8. Surfaces, capacity, and presentation

| Surface | Route / placement | Capacity | Ranking owner |
|---|---|---|---|
| Homepage rail | `<front>` / `homepage_community_favourites` | **8 cards** (block `limit: 8`; over-fetch ×3 for dedup/diversity) | `PopularEventsBlock` + `PopularEventsService` |
| Browse page | `/events/popular` / `page_popular` | **12 per page** (paginated) | `HomepageMerchandisingQueryAlter` + View |
| Card discovery reason | CF attribution sources only | — | `mel-event-card.html.twig` |
| Image badge | — | — | **No CF badge** — `EventMerchandisingPresenter` |

### Badge status

Community Favourite **image badge is intentionally disabled**. CF identity is:

- section title **Community Favourites**
- discovery reason **Popular with the community**
- optional **X going** under card (engagement transparency)

See `docs/audits/community-favourites-governance.md` § Badge Ownership and `docs/audits/badge-ownership-map.md`.

---

## 9. Analytics that exist today

| Layer | What is measured |
|---|---|
| **Click capture** | `event_click` rows with `mel_source` |
| **Homepage source** | `homepage_community_favourites` |
| **Browse source** | `browse_community_favourites` |
| **Reporting surface** | Both collapse to `community_favourites` in `DiscoverySurfaceAnalyticsService` |
| **Vendor visibility** | `HomepageVisibilityReportService` includes CF surface when event is in merchandising pool |

### What analytics do not include

- Impressions
- Score or rank history
- Window boundary transitions (“fell off CF” events)
- Staff override audit (N/A)

---

## 10. Governance gaps and open decisions

| # | Gap | Risk | Decision needed |
|---|---|---|---|
| 1 | No minimum quality threshold | Single RSVP can surface an event | Define ops floor or accept “any engagement” |
| 2 | Promoted events eligible | CF may show Boosted events — tension with “not paid placement” | Exclude `field_promoted` from CF pool? |
| 3 | No staff override | Curators cannot rescue empty rails | Accept algorithmic emptiness vs use HG/Editor's Pick |
| 4 | 7-day window fixed in multiple callers | Changing window requires config + code alignment | Single config source for lookback days |
| 5 | No within-window decay | Day-1 and day-7 engagement weighted equally | Accept or add decay curve |
| 6 | CF badge disabled vs brand badge list | Brand docs mention badge; runtime uses discovery reason only | Align brand doc or enable badge with criteria |
| 7 | Empty rail on thin engagement | Homepage section hidden — no CF visibility | Seed engagement or lower threshold (product) |

---

## 11. Recommended governance policy (proposed — not in repository)

### Definition (public)

**Community Favourites** = upcoming public events ranked by **real ticket sales and RSVPs in the last 7 days**, surfaced as social proof — not editorial picks, not paid placement labels.

### Eligibility rules

1. ≥1 counted ticket or RSVP in lookback window (current runtime).
2. Pass `isPubliclyListable()`.
3. On homepage: not already shown in higher-priority rails (dedup).
4. Promoted and Hidden Gem events **allowed** unless product bans promoted (decision §4).

### Decay rules

1. **Rolling 7-day window** is canonical decay — no separate expiry flag.
2. Engagement outside window does not count.
3. Ended/cancelled/archived events drop off via lifecycle filter.

### Override rules

1. **No per-event staff override** for CF.
2. Editorial needs → Hidden Gem, Editor's Pick, or Spotlight — not CF pin.
3. Platform admins may adjust block `days` (1–60) and `limit` (1–24) only.

### Transparency

1. Homepage subtitle references **real tickets and RSVPs this week** — keep aligned with window.
2. Vendors see CF in visibility report when event is in pool; clicks via analytics surface.

---

## 12. Quick reference — Phase 7A questions

| Question | Answer |
|---|---|
| **Minimum threshold?** | **1 engagement action** in window (1 RSVP or 1 ticket); no score floor coded |
| **Time window?** | **7 days** default; configurable 1–60 via block admin |
| **Promoted → CF?** | **Yes**, if engagement qualifies; may be homepage-deduped if already in Spotlight |
| **Hidden Gem → CF?** | **Yes** at node level; homepage rails dedup — HG wins over CF |
| **Should CF decay?** | **Yes** — via rolling window + lifecycle; no within-window decay |
| **Staff override?** | **No** today; **not recommended** without breaking evidence-based contract |

---

## 13. Evidence index

| Topic | Primary paths |
|---|---|
| Ranking engine | `web/modules/custom/myeventlane_analytics/src/Service/PopularEventsService.php` |
| Homepage block | `web/modules/custom/myeventlane_front/src/Plugin/Block/PopularEventsBlock.php` |
| Block placement | `config/sync/block.block.myeventlane_theme_homepage_community_favourites.yml` |
| Homepage dedup pool | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` (`getCommunityFavouritesEventIds`) |
| Browse ranking bridge | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandisingQueryAlter.php` |
| Listability | `web/modules/custom/myeventlane_event/src/Service/PublicEventVisibility.php` |
| Card copy | `web/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig` |
| Badge priority | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` |
| Analytics | `web/modules/custom/myeventlane_core/src/Service/DiscoverySurfaceAnalyticsService.php` |
| Technical ownership (CF-7F) | `docs/audits/community-favourites-governance.md` |
| Brand | `docs/brand/event-card-system.md`, `docs/brand/homepage-system.md` |
| Hidden Gem overlap | `docs/audits/governance/hidden-gems-governance.md` §5 |

---

## 14. Residual risks

- **Promoted + CF** may read as paid social proof despite honest RSVP/ticket requirement.
- **Single-RSVP threshold** can surface low-signal events in thin markets.
- **Dedup emptiness** — popular events consumed by Hero/Spotlight/HG may leave CF rail empty despite market activity.
- **No override** means ops cannot curate CF during cold-start — relies on real bookings or fixture seeding.

---

**Phase 7A complete.** No code, config, View, SCSS, Commerce, or RSVP changes made.
