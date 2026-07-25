# Vendor Studio — Design Language

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Explain how Vendor Studio **extends MEL’s public brand** for operations — high-level visual language without owning the numeric token tables.

## Scope

Relationship to public MEL, colour/type philosophy summaries, whitespace, borders, radius, elevation, motion, dark mode strategy overview. **Definitive token values:** [11-design-tokens.md](11-design-tokens.md). **Emotional identity:** [14-visual-identity.md](14-visual-identity.md).

## Audience

Designers, brand contributors, theme architects.

## Related documents

- [11-design-tokens.md](11-design-tokens.md)
- [14-visual-identity.md](14-visual-identity.md)
- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [`docs/brand/design-tokens.md`](../../brand/design-tokens.md)
- [`docs/brand/mel-brand-system-v1.md`](../../brand/mel-brand-system-v1.md)

---

## Why extend, not fork

Organisers move between public MEL and Vendor Studio. A parallel palette or type system would feel like a different company. Extension preserves brand DNA while changing hierarchy and chrome for ops jobs.

---

## 1. Relationship to MEL public theme

| Layer | Authority | Vendor Studio role |
| --- | --- | --- |
| Brand strategy / copy | `docs/brand/` | Same warmth, Australian English, no exclusivity language |
| Public discovery UI | `myeventlane_theme` + `DESIGN_SYSTEM.md` | Do not fork event cards / public heroes into the console |
| Organiser console | `myeventlane_vendor_theme` | Implements this OS; scoped under `.mel-vendor` |
| Shared tokens | Brand + [11](11-design-tokens.md) | Console may tune density and focus colour for ops clarity |

**Rule:** Shared MEL DNA (warm cream canvas, purple/coral accents, friendly radius). Different job (ops vs discovery) means different hierarchy and chrome — not a different brand.

---

## 2. Typography

Philosophy: organisers scan under time pressure. Expressive marketing typography belongs on public MEL, not in Door Mode.

Scale and roles: [11-design-tokens.md](11-design-tokens.md). Personality of type: [14](14-visual-identity.md).

---

## 3. Colour system

Brand palette source: `docs/brand/design-tokens.md`. Studio application and semantic rules: [11](11-design-tokens.md).

Summary: warm cream canvas · white surfaces · scarce purple primary · coral focus/accent · rare discovery gold · semantic severity with text/icon.

---

## 4. Vendor colour usage

| Do | Don’t |
| --- | --- |
| Use purple for the one primary CTA per region | Paint entire sidebars purple |
| Use coral as focus / warm accent consistently | Mix coral + gold on the same control |
| Keep surfaces light for long sessions | Default the console to dark “pro tool” aesthetics before Phase 12 |
| Align `.mel-vendor` CSS variables with public tokens | Invent one-off hex in component SCSS |

Organiser branding (logos, event colours) may appear in **content previews**, not as a re-skin of the entire shell.

---

## 5. White space rules

1. Prefer spacing scale steps over arbitrary pixel values ([11](11-design-tokens.md)).
2. Separate competing panels with space before adding borders or shadows.
3. Compress decorative empty heroes; expand space around primary attention lists.
4. Forms stay at reading/form width — unused side space on ultra-wide is intentional calm.
5. Dense tables may tighten row padding; surrounding page chrome stays airy.

---

## 6. Borders

| Use | Guidance |
| --- | --- |
| Default panel edge | 1px soft border token (`--mel-border` family) |
| Dividers | Prefer subtle horizontal rules inside lists |
| Emphasis | Do not double-border + heavy shadow |
| Destructive zones | Stronger border or left accent + clear heading |

---

## 7. Radius

Align to brand radius scale; intents in [11](11-design-tokens.md). Avoid sharp 0px admin aesthetics and avoid overusing full pills on every chip (reduces hierarchy).

---

## 8. Elevation

Levels and uses in [11](11-design-tokens.md). **Why restraint:** Heavy multi-layer shadows read as “dashboard fashion” and fight calm ops UI ([19](19-anti-patterns.md)).

---

## 9. Motion

Duration bands in [11](11-design-tokens.md). Behaviour rules in [07](07-interaction-guidelines.md). Motion explains state — it does not entertain. Always honour `prefers-reduced-motion`.

---

## 10. Dark mode strategy

**Phase 12 capability** — not a near-term default. Token-first remap under `.mel-vendor`; full principles in [11](11-design-tokens.md). Until Phase 12, do not partially dark-style individual pages.

---

## Design implications

- Visual PRs cite [11](11-design-tokens.md) for values and this file for brand-extension rationale
- Do not invent seasonal accent trends without accessibility + Three Questions justification

## Future considerations

- Public discovery gold remains rare in Studio to protect semantic clarity
- Phase 12 dark mode QA across tables, severity, money
- Illustration guidance owned by [14](14-visual-identity.md)

## Related references

- [11](11-design-tokens.md) · [14](14-visual-identity.md) · [03](03-layout-system.md) · [07](07-interaction-guidelines.md) · [10](10-roadmap.md) · `docs/brand/`
