# Homepage Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based.

---

## 1. Render path (evidence)

| Step | Evidence |
|---|---|
| Front page = `/home` | `config/sync/system.site.yml` → `page.front: /home` |
| Template | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` — header comment: *"MEL homepage — region-driven discovery."* Extends `page.html.twig`. |
| Section gating | `myeventlane_theme.theme` preprocess (≈ line 1933+) calls service `myeventlane_front.homepage_section_visibility` (`HomepageSectionVisibility`) to set `mel_home_show_*` flags + hero `popular_tags`. |
| Region fill | Blocks placed in `homepage_*` / `home_*` theme regions (config `block.block.*.yml`). |
| Data | Each block is a **Views block** (or custom plugin) — see §3. |

> The homepage is **not** a single controller or node. It is a **region + block + View** composition, gated per-section by a visibility service. This is a flexible, low-risk surface to evolve.

---

## 2. Homepage section stack (top → bottom, from `page--front.html.twig`)

| # | Section | Region | Heading (current copy) | Subtitle | "See all" target |
|---|---|---|---|---|---|
| 1 | **Hero** | `homepage_hero` (fallback `hero` → SDC `hero.twig`) | rotating hero + search | — | search |
| 2 | Featured / Community spotlight | `homepage_featured` | "Community spotlight" | "Local events and creators worth showing up for" | `view.upcoming_events.page_events` |
| 3 | **Discover (chips)** | `home_discover` | "Discover events" | "Filter by what you feel like tonight" | events |
| 4 | **Happening tonight** | `homepage_tonight` | "Happening tonight" | "Don't miss these events starting soon" | `page_today` |
| 5 | **Free & RSVP** | `homepage_free` | "Easy ways to get involved" | "Great community events with no ticket fee" | `page_free` |
| 6 | Freshly added | `homepage_latest` | "Freshly added" | "Recently published on MyEventLane" | events |
| 7 | **Recommended for you** | `home_recommended` | "Recommended for you" | "Events you may enjoy" | events |
| 8 | Nearby | `homepage_nearby` | "Nearby events" | "Find experiences close to you." | `/events/nearby` |
| 9 | Online | `homepage_online` | "Online events" | "Join from wherever you are." | `/events/online` |
| 10 | Blog / Guides | `homepage_blog` | "Guides for better nights out" | "Lightweight ideas for discovering, hosting, and growing events." | `/blog` |
| 11 | Host CTA | `homepage_host_cta` (fallback `includes/mel-host-cta-default.html.twig`) | host-your-event CTA | — | vendor onboarding |
| 12 | Newsletter | `homepage_newsletter` | newsletter form | — | — |
| (—) | Categories pill bar | `homepage_categories` | `myeventlane_category_pills` plugin | — | category pages |

All sections wrap in the shared `components/layout/mel-section-shell.html.twig` (consistent title/subtitle/link chrome).

---

## 3. Block → View mapping (evidence: `config/sync/block.block.*.yml`)

| Section | Block plugin | View / display |
|---|---|---|
| Hero | `myeventlane_home_hero` (custom block plugin) | — |
| Categories | `myeventlane_category_pills` (custom block plugin) | (`front_category_pills` view exists) |
| Featured | `views_block:front_featured_events-block_featured` | `front_featured_events` |
| Featured carousel (`home_featured`) | `views_block:featured_events_carousel-…` | `featured_events_carousel` |
| Discover (chips) | `views_block:mel_home_events-embed_discover` | `mel_home_events` |
| Tonight | `views_block:upcoming_events-homepage_tonight` | `upcoming_events` |
| Free & RSVP | `views_block:mel_home_events-under_20` | `mel_home_events` |
| Latest | `views_block:upcoming_events-homepage_latest` | `upcoming_events` |
| **Recommended** | `views_block:front_recommended_events-block_1` | `front_recommended_events` |
| Blog | `views_block:mel_blog-homepage_preview` | `mel_blog` |

Related discovery views also present: `front_discover_events`, `featured_discover_recommended`, `all_events`, `events_calendar`, `frontpage`.

---

## 4. Existing discovery / recommendation / featured / category surfaces

| Capability | Status | Evidence |
|---|---|---|
| **Discovery surfaces** | ✅ Strong | tonight / free / latest / nearby / online / discover-chips sections, all View-backed |
| **Recommendation surface** | ✅ Exists | `front_recommended_events` View + `home_recommended` region + `views-view-unformatted--front-recommended-events--block-1.html.twig` template |
| **Featured / editorial surface** | ✅ Exists | `front_featured_events` + `featured_events_carousel` + SDC `featured-events.twig` with **"Curated by MyEventLane"** curator line |
| **Category navigation** | ✅ Exists | `myeventlane_category_pills` block + `front_category_pills` view + `homepage_categories` region |
| **Vibe-based discovery** | ⚠️ Component exists, placement unconfirmed | `components/vibe-mixer/vibe-mixer.twig`. **Repository evidence not found** that the Vibe Mixer is currently placed in a homepage region/block. |

---

## 5. How close is the homepage to *"Find your next favourite experience"*?

**Very close — structurally already there.** Evidence:

- The template's own header reads *"region-driven discovery"*.
- Sections are organised around **discovery intent** (tonight / free / nearby / online / recommended / featured), not around ticketing or a database list.
- A **recommendation** surface and an **editorial "Curated by MyEventLane"** surface already exist.
- Per-section visibility (`HomepageSectionVisibility`) means empty sections hide gracefully — supports an optimistic, never-empty feel.

**Gaps vs. the new emotional target ("What wonderful thing can I discover this weekend?"):**

| Gap | Evidence | Bright Edition action |
|---|---|---|
| Copy is utilitarian / nightlife-leaning ("better nights out", "what you feel like tonight") | §2 headings | Rewrite to discovery-wonder voice. No structural change. |
| No "Hidden Gem" surface | no `hidden_gem`/`gem` view or block found | Add a curated "Hidden Gems" View display (reuses featured/recommended pattern). |
| Vibe Mixer not wired into homepage | §4 | Place Vibe Mixer block in a homepage region — pure config + block. |
| "Recommended" subtitle generic ("Events you may enjoy") | §2 | Re-voice as the Guide ("The Guide picked these for you"). |
| Visual brand = current coral/cream tokens | `theme-architecture.md` | Token re-skin (Bright Edition palette). |

---

## 6. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | Region/block/View homepage architecture; `HomepageSectionVisibility` gating; featured/recommended/category surfaces; `mel-section-shell` wrapper |
| **NEEDS EVOLUTION** | All section headings/subtitles (voice); hero copy; "Recommended" framing → Guide voice; token re-skin |
| **ADD (config + existing components only)** | "Hidden Gems" curated View display; Vibe Mixer block placement |
| **DON'T TOUCH** | Section visibility service logic, View query layer (data correctness) |

**Bottom line:** the homepage is already a discovery surface. The Bright Edition rollout here is **copy + tokens + 2 block placements**, not a rebuild.
