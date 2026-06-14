# MEL Event Card System

**Version:** 1.0  
**Purpose:** Define event card hierarchy, structure, badge system, discovery language, and mobile-first requirements.

Event cards are the **primary discovery unit** across homepage, browse, search results, maps, and related-event rails.

---

## Card hierarchy

Information priority on every card:

1. **Event title** — Scannable, truncated with ellipsis if needed.
2. **Date and time** — Human-readable (e.g. “Sat 14 Jun · 7:00 pm”).
3. **Location** — Venue or suburb; distance when “near you” context applies.
4. **Badge** (optional) — One badge maximum.
5. **Price or entry type** — “Free”, “From $25”, or “RSVP”.
6. **Primary action** — Entire card clickable to event detail; optional explicit “View event” on desktop.

---

## Card structure

### Anatomy

```
┌─────────────────────────────┐
│  Image (16:9 or 4:3)        │
│  [Badge]                    │
├─────────────────────────────┤
│  Title (H3)                 │
│  Date · time                │
│  Location · distance        │
│  Price / RSVP               │
└─────────────────────────────┘
```

### Regions

| Region | Requirements |
|--------|--------------|
| **Media** | Photography showing participation or venue character; fallback illustration per illustration guidelines |
| **Badge** | Top-left or top-right overlay; contrast-safe background |
| **Body** | Warm Cream or white card surface; `radius-lg`; `shadow-md` |
| **Meta** | Body small typography; neutral text colour |
| **Interaction** | Full-card link; visible focus ring; hover lift on desktop only |

### Theme alignment

Public cards extend the canonical `.mel-card` contract in `DESIGN_SYSTEM.md` and `web/themes/custom/myeventlane_theme/src/scss/components/_event-card.scss`. Do not create parallel card systems.

---

## Badge system

### Approved badges

| Badge | Meaning | When to use |
|-------|---------|-------------|
| **Hidden Gem** | High-value local experience with low mainstream visibility | Editorial or scored discovery programmes only |
| **Community Favourite** | Strong repeat attendance or local sentiment | Evidence-based; not paid placement |
| **Trending Tonight** | High interest for events happening today/tonight | Time-bound; auto or editorial with clear criteria |
| **Editor’s Pick** | Human editorial selection | Staff-curated lists with documented criteria |
| **Just Added** | Published within a defined recent window | Typically 7 days; configurable in implementation |
| **Nearby** | Within proximity threshold of user location | Requires location context |

### Badge rules

1. **One badge per card** — Never stack `Hidden Gem` + `Editor’s Pick` on the same card.
2. **Honest labels** — Badges must reflect real criteria; document criteria in Drupal governance.
3. **No exclusivity badges** — “VIP”, “Exclusive”, “Members Only” are not approved.
4. **Colour** — Hidden Gem uses Discovery Gold accent; others use Lavender or neutral chips per design tokens.
5. **Accessible text** — Badge text in sentence case; sufficient contrast on overlay.

---

## Discovery language

### On-card copy patterns

| Field | Preferred | Avoid |
|-------|-----------|-------|
| Title | Event name as published | Clickbait prefixes (“SHOCKING:”) |
| Time | “Sat 14 Jun · 7:00 pm” | Unix timestamps, ambiguous “soon” |
| Location | “Newtown Community Hall · 2 km away” | Vague “Sydney area” when suburb known |
| Price | “Free”, “From $25”, “Donation” | “Unlock price” |
| CTA (implicit) | Card links to detail | “Get exclusive access” |

### Section headers paired with cards

- “Hidden Gems near you”
- “On this week in Brisbane”
- “Community favourites in your area”
- “Just added”

Avoid: “Exclusive picks”, “VIP events”, “Secret listings”.

---

## Mobile-first requirements

1. **Full-width cards** in single-column browse on viewports under 768px.
2. **Minimum tap target** — Entire card is tappable; no micro-buttons-only navigation on mobile.
3. **Image height cap** — Prevent oversized images pushing meta below the fold on small screens.
4. **Text truncation** — Title max two lines; location one line; ellipsis with full text on detail page.
5. **Badge size** — Readable at 14px; does not obscure critical image content.
6. **Grid** — Use `.mel-grid--events` and container contracts from theme layout files.
7. **Performance** — Lazy-load images below the fold; responsive image styles when configured.

---

## States

| State | Behaviour |
|-------|-----------|
| **Default** | Standard card |
| **Hover (desktop)** | Subtle shadow lift |
| **Focus** | Visible focus outline for keyboard users |
| **Sold out** | Meta line “Sold out”; card may remain for discovery but CTA on detail handles waitlist |
| **Cancelled** | Remove from discovery rails; detail page shows status |
| **Past event** | Exclude from discovery rails; archive behaviour per product rules |

---

## Related documents

- [homepage-system.md](homepage-system.md) — Where cards appear on homepage
- [copy-guidelines.md](copy-guidelines.md) — Vocabulary
- [design-tokens.md](design-tokens.md) — Visual tokens
- [drupal-design-governance.md](drupal-design-governance.md) — Drupal views and fields
