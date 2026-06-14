# Event Page Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based.

---

## 1. Public event detail templates (evidence)

Render chain for a public event node:

| Layer | Template | Role |
|---|---|---|
| Page wrapper | `templates/page--node--event.html.twig` | Strips container; full-width `mel-content--event-full` |
| Node (canonical) | `templates/node/node--event--full.html.twig` | Full detail page (richest variant: organiser card, related events) |
| Node (default) | `templates/node--event--default.html.twig` | Hero + detail-grid (main + sidebar) variant |
| Node base | `templates/node--event.html.twig` | base |
| Teasers / cards | `node--event--card.html.twig`, `node--event--event-card.html.twig`, `node--event--event-card-poster.html.twig`, `node--event--teaser.html.twig`, `node--event--teaser-featured.html.twig`, `node--event--teaser-tonight.html.twig` | discovery cards |

Detail partials (`templates/event/`): `event-hero`, `_event-meta`, `_event-cta` / `event-cta`, `event-about`, `event-location`, `event-organiser`, `event-tickets`, `event-vendor-card`, `event-sidebar`.

**Cinematic sidebar carousel** (`templates/event/sidebar/`): `mel-event-sidebar-slide-booking`, `-extras`, `-gallery`, `-organiser`, `-social`, `-trust` (SCSS: `_event-sidebar-carousel.scss`, `_event-cinematic-convergence.scss`).

---

## 2. Anatomy & current state

### Hero (`node--event--default.html.twig` / `event-hero.html.twig`)
`event-hero` with background image + overlay, **event state badge** (`event-hero__state-badge--{state}`), `h1` title, meta (`_event-meta`), and primary CTA group. **NEEDS EVOLUTION** — re-skin to Bright Edition; strong canvas for a "Hidden Gem" badge.

### CTA hierarchy
`_event-cta.html.twig` rendered **twice** — in hero (`event-hero__cta`) and in sticky sidebar (`event-cta`). Single CTA source, two placements. Clean. **SAFE TO REUSE** (re-skin only).

### Sidebar
Sticky desktop sidebar with: CTA, Date & Time + calendar links (Google/Outlook/ICS), location, capacity. Plus the **cinematic carousel** of slides (booking / extras / gallery / organiser / social / trust). **SAFE TO REUSE** — rich, on-brand; re-skin.

### Related content — **already exists**
`node--event--full.html.twig` line ~582: `{% if mel_related_events is not empty %}` renders a **related-events rail**. **SAFE TO REUSE** — this is a ready-made Guide recommendation slot.

### Organiser trust — **already exists**
`event-organiser.html.twig` + `mel-organiser-card` (in `node--event--full`): "Presented by" / "Hosted by", logo/avatar, tagline, and **"Verified organiser"** trust line; links to public vendor profile. Trust sidebar slide (`mel-event-sidebar-slide-trust`). **SAFE TO REUSE**.

### Recommendation areas — **engine already exists**
`myeventlane_event.module` (≈ line 856+) attaches **recommendation reason labels** to *all* event "card" view builds (search, lists) via service **`myeventlane_core.event_recommendation`** (`EventRecommendationService::attachListCardContextToBuild`), keyed off `#recommendation_context`. Supporting: `myeventlane_core/src/Service/DiscoveryAttributionSources.php`.

> **This is the single most important event-page finding for The Guide:** MEL already has a **recommendation service that produces human-readable "why we're showing you this" reasons** on event cards. The Guide's "I picked this for you because…" voice can be driven by an **existing service**, not new ML.

---

## 3. Where Guide recommendations could live (evidence-mapped)

| Slot | Existing hook | Effort |
|---|---|---|
| **Related-events rail** on event detail | `mel_related_events` already rendered in `node--event--full` | Re-voice header → "The Guide also loves…"; ensure populated. Low. |
| **Recommendation reason labels** on cards | `EventRecommendationService` + `#recommendation_context` already attach reasons | Re-voice labels in the Guide's tone. Low. |
| **Sidebar carousel — new slide** | carousel slide system is modular (`event/sidebar/slide-*`) | Add a "More gems like this" slide reusing related-events data. Medium. |
| **Post-CTA "discover more"** | `mel-section-shell` + card-carousel components | Add a Guide rail below the detail grid. Low–medium. |
| **Organiser → "more from this host"** | organiser card links to vendor profile; `mel_related_events` can filter by organiser | Add "More from {organiser}" rail. Medium. |

---

## 4. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | Hero structure, dual-placement CTA, sticky sidebar + cinematic carousel, organiser trust card, `mel_related_events` rail, **`EventRecommendationService` + reason labels**, calendar/ICS links |
| **NEEDS EVOLUTION** | Token re-skin across hero/cards/sidebar; re-voice related/recommendation headers + reason labels to Guide tone; optional "Hidden Gem" badge on hero |
| **ADD (reuse existing patterns)** | "More gems like this" carousel slide; "More from this host" rail |
| **DON'T TOUCH** | Ticket/CTA commerce wiring, state-badge logic, capacity/availability, access |

**Bottom line:** The event page is the **best-positioned surface** for The Guide. A recommendation engine with reason labels, a related-events rail, and a modular sidebar carousel already exist. Bright Edition work is **re-skin + re-voice + optionally one new rail/slide** — all reusing live services and components. No Commerce/access changes.
