# MEL Homepage System

**Version:** 1.0  
**Purpose:** Define homepage hierarchy, hero structure, discovery patterns, guide placement, and mobile-first behaviour.

---

## Homepage goals

1. **Immediate discovery** — User understands what MEL offers within five seconds.
2. **Local relevance** — Content feels tied to place (city, suburb, or detected region).
3. **Clear next step** — One primary action: explore events near the user.
4. **Hidden Gem surfacing** — Editorial and algorithmic rails highlight worthwhile local experiences.
5. **Welcoming tone** — Host and Explorer guide energy without clutter.

---

## Hero structure

### Required elements (mobile-first order)

1. **Headline** — Discovery-oriented; states the brand promise in plain language.
2. **Supporting line** — One sentence reinforcing local exploration.
3. **Location / date quick filters** — Low-friction way to personalise the feed.
4. **Primary CTA** — Single action, e.g. “Explore events near you”.
5. **Hero media** — Photography or restrained illustration showing participation and community (not empty venues).

### Hero copy direction

| Element | Example |
|---------|---------|
| Headline | “Discover what’s on near you” |
| Supporting line | “Markets, gigs, workshops, and community events — all in one place.” |
| Primary CTA | “Explore events” or “See what’s on this week” |

### Hero constraints

- **One locked hero variant** on the public theme per `DESIGN_SYSTEM.md` — brand refreshes work within the existing `.mel-event-hero--featured-style` contract unless a formal hero change is approved.
- No rotating carousels with competing CTAs in the hero.
- Hero must not use exclusivity or urgency language.

---

## Homepage hierarchy

Top to bottom on mobile:

| Section | Priority | Purpose |
|---------|----------|---------|
| 1. Hero | Critical | Promise, filters, primary CTA |
| 2. Tonight / This week near you | Critical | Immediate discovery |
| 3. Hidden Gems | High | Brand differentiator |
| 4. Browse by category | High | Wayfinding |
| 5. Editor’s Pick / Curator rail | Medium | Editorial trust |
| 6. Community Favourites | Medium | Social proof without pressure |
| 7. Just Added | Medium | Freshness signal |
| 8. Blog / stories (if present) | Lower | Inspiration, SEO |
| 9. Footer | Standard | Trust, help, legal |

On desktop, rails may sit in two columns where grid contracts allow, but **mobile order is the canonical priority**.

---

## Recommended sections

### Tonight near you

- Time-scoped events within the user’s selected or detected area.
- Cards use standard event card system — see [event-card-system.md](event-card-system.md).
- Badge: `Nearby` or `Trending Tonight` where criteria are met.

### Hidden Gems

- Curated or scored list of high-value, low-visibility local events.
- Badge: `Hidden Gem` (Discovery Gold accent).
- Explorer Guide placement: optional single illustration or copy line — “Come look at this.”

### Browse by category

- Scannable category chips or cards (Music, Markets, Workshops, Sport, etc.).
- Links to filtered browse views — same card patterns on destination pages.

### Editor’s Pick

- Human-curated selection; limited count (e.g. 4–6 items).
- Badge: `Editor’s Pick`.
- Curator Guide tone in section intro.

---

## Guide placement

| Section | Guide | Max presence |
|---------|-------|--------------|
| Hero | Explorer (optional) | Illustration or one line only |
| Hidden Gems | Explorer | Section intro copy |
| Editor’s Pick | Curator | Section intro copy |
| Empty location state | Helper | “Let’s get started — choose your area.” |

**Do not** place guides in every rail. Maximum **two guide moments** on the full homepage.

---

## Discovery patterns

1. **Rails over walls** — Horizontal scroll or stacked cards on mobile; avoid endless undifferentiated grids above the fold.
2. **Consistent cards** — Every event uses the canonical event card; no homepage-only card chrome.
3. **Badge discipline** — Maximum one badge per card; see approved badge list in event card system.
4. **See all links** — Each rail links to a filtered browse page with the same sort and filters applied.
5. **Location awareness** — When location is unknown, prompt to select suburb or city before showing “Near you” rails.

---

## Content priorities

| Priority | Content type |
|----------|--------------|
| P0 | Events happening soon near the user |
| P1 | Hidden Gems and Community Favourites |
| P2 | Categories and Editor’s Pick |
| P3 | Blog teasers and static promo blocks |

Paid promotion, if introduced later, must be labelled clearly and must not use Hidden Gem or Editor’s Pick badges.

---

## Mobile-first behaviour

1. **Single column** default; no side-by-side hero + rails on narrow viewports.
2. **Sticky optional** — Location or date filter may stick on scroll; primary nav remains theme-standard.
3. **Touch targets** — Category chips and CTAs meet 44×44px minimum.
4. **Image aspect ratios** — Card images consistent with browse to prevent layout shift when navigating.
5. **Performance** — Hero image optimised; lazy-load below-the-fold rails.
6. **Reduced motion** — Rail scroll and hero animations respect `prefers-reduced-motion`.

---

## Governance

Homepage implementation spans Drupal blocks, views, and theme templates. Map technical ownership in [drupal-design-governance.md](drupal-design-governance.md).

Brand rollout audits: `docs/audits/brand-rollout/homepage-audit.md`.
