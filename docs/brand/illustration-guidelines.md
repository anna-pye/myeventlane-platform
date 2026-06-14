# MEL Illustration Guidelines

**Version:** 1.0  
**Purpose:** Direct illustration style for guides, empty states, discovery moments, and marketing supporting the MEL brand.

---

## Strategic intent

Illustration fills gaps where photography is unavailable and reinforces **optimism, curiosity, and community** — especially in guide moments and discovery UI.

Illustration is **supporting**, not dominant. It must not become a cartoon mascot universe.

---

## Focus on

### Optimism

Light palettes, open compositions, upward or forward movement.

**MEL example:** Figures walking toward a market strip under Soft Sky background — open, bright scene.

### Curiosity

Characters or abstract guides looking toward something interesting — a map pin, a small stage, a market stall.

**MEL example:** Explorer Guide gesturing toward a cluster of event pins on a simplified neighbourhood map.

### Discovery

Visual metaphors for finding the unexpected nearby — paths, doors opening, map reveals — without “treasure chest” clichés.

**MEL example:** Turning a corner to reveal a small outdoor gig — subtle, not dramatic.

### Community

Groups in casual interaction; shared tables, lawns, workshops.

**MEL example:** Diverse figures at a community picnic illustration for an empty-state “no events yet in this suburb”.

### Participation

Hands making, clapping, planting, cooking — aligned with photography pillars.

**MEL example:** Workshop illustration with hands on materials, not passive silhouettes.

---

## Avoid

| Avoid | Why |
|-------|-----|
| **Isolation** | Single figure alone in empty space contradicts belonging |
| **Mystery** | Hooded figures, fog, locked doors — wrong emotional outcome |
| **Darkness** | Heavy shadows, night-only scenes, neon noir |
| **Exclusivity** | Velvet ropes, VIP badges, crown icons, gated doors |
| **Childish clipart** | Thick outlines, emoji style, mascot costumes |
| **Corporate stock flat art** | Generic office people at whiteboards |

---

## Style attributes

| Attribute | Guidance |
|-----------|----------|
| **Line** | Soft, rounded; not sharp corporate geometric |
| **Colour** | Brand tokens: Primary Purple, Lavender, Coral, Discovery Gold, Warm Cream, Soft Sky |
| **Detail** | Moderate — readable at small sizes on mobile |
| **Characters** | Simplified human forms; inclusive; no branded mascot names |
| **Background** | Minimal or Warm Cream / Soft Sky; avoid busy patterns behind text |

---

## Usage by context

| Context | Illustration role |
|---------|-------------------|
| Guide moments | Small figure or abstract guide per [guide-character-system.md](guide-character-system.md) |
| Empty states | One illustration + helpful copy + CTA |
| Onboarding | Step-by-step Helper Guide scenes |
| Marketing email | Header illustration; keep file size modest |
| Event card fallback | When no photo uploaded — venue category iconography or neutral participation scene |

**Do not** illustrate every card — photography is preferred when available.

---

## Motion

- Optional gentle loop for marketing pages only.
- UI core flows: prefer static SVG or PNG unless performance-tested.
- Respect `prefers-reduced-motion` — see [design-tokens.md](design-tokens.md).

---

## Relationship to guides

Illustration implements the five guide archetypes (Explorer, Host, Curator, Helper, Celebrating) without giving them names, backstories, or merchandise.

---

## Asset location

Illustration files are organised under:

- `docs/brand/assets/guide/` — guide archetype artwork
- `docs/brand/assets/discovery/` — Hidden Gem and map/discovery motifs
- `docs/brand/assets/ui/` — empty states and UI-specific scenes

Produced in Phases 2–3 of [implementation-roadmap.md](implementation-roadmap.md).
