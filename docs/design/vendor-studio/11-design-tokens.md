# Vendor Studio — Design Tokens

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

The definitive source of truth for every **visual decision** in Vendor Studio.

If a spacing step, type size, radius, elevation level, or semantic colour is disputed, this document wins for Studio. Brand strategy and public discovery tokens remain in `docs/brand/`; this pack **extends** them for operations and records Studio-specific contracts.

## Scope

- Design specification only — no SCSS, Twig, or PHP implementation in this pack
- Covers typography, spacing, grid, containers, radius, elevation, colour, motion, focus, icons, touch, and surface-level component token intents
- Does not redefine navigation IA ([02](02-information-architecture.md)) or component behaviour contracts ([05](05-component-library.md))

## Audience

Designers, theme architects, frontend engineers, accessibility reviewers.

## Related documents

- [04-design-language.md](04-design-language.md) — philosophy of MEL extension
- [03-layout-system.md](03-layout-system.md) — structural use of containers
- [14-visual-identity.md](14-visual-identity.md) — emotional use of tokens
- [docs/brand/design-tokens.md](../../brand/design-tokens.md) — brand token source
- [09-drupal-mapping.md](09-drupal-mapping.md) — future SCSS mapping homes
- [DDR-003](decisions/DDR-003-layout-intents.md)

---

## Why tokens first

Organisers spend long sessions in Studio. Inconsistent spacing, competing max-widths, and ad-hoc colours create cognitive tax and theme debt. A single token system:

- Improves **organiser experience** through predictable rhythm
- Protects **accessibility** (contrast, focus, targets)
- Enables **long-term maintainability** (one remap for dark mode)
- Aligns **Drupal theme** work under `.mel-vendor` without parallel hex forks

---

## 1. Naming conventions

| Pattern | Example | Use |
| --- | --- | --- |
| `--mel-color-{role}` | `--mel-color-primary` | Brand-aligned colour |
| `--mel-space-{n}` | `--mel-space-4` | Spacing scale (4px base) |
| `--mel-radius-{size}` | `--mel-radius-md` | Corner radius |
| `--mel-shadow-{level}` | `--mel-shadow-sm` | Elevation |
| `--mel-font-size-{role}` | `--mel-font-size-body` | Type scale |
| `--mel-duration-{band}` | `--mel-duration-base` | Motion |
| `--mel-layout-{intent}` | `--mel-layout-workspace` | Container max-width |
| `--mel-vendor-*` | Scoped under `.mel-vendor` | Console overrides |

**Rules**

- Prefer role names over raw values in documentation and future SCSS
- Do not invent parallel systems (`studio-purple`, hardcoded `1120px` in Twig)
- Semantic colour tokens (`danger`, `warning`, `success`, `info`) stay separate from brand accents

---

## 2. Typography

Aligned with brand scale; Studio emphasises scanability over marketing display.

| Role | Mobile | Desktop | Weight | Line-height | Use |
| --- | --- | --- | --- | --- | --- |
| Display | 32px | 44px | 700 | 1.2 | Rare — avoid in ops chrome |
| H1 | 28px | 36px | 700 | 1.2 | One page title |
| H2 | 24px | 30px | 600 | 1.25 | Section headings |
| H3 | 20px | 24px | 600 | 1.3 | Panel / card titles |
| Body | 16px | 16px | 400 | 1.5 | Default copy |
| Body small | 14px | 14px | 400 | 1.45 | Meta, table secondary |
| Label | 12–13px | 13px | 500 | 1.4 | Field labels, badges |

**Rules**

- Minimum **16px** body on mobile
- Sentence case for UI labels
- Tabular lining figures for KPIs where available
- No novelty display faces in Door Mode or money surfaces

---

## 3. Spacing

**Base unit:** 4px

| Token | Value | Typical use |
| --- | --- | --- |
| space-1 | 4px | Icon padding, tight gaps |
| space-2 | 8px | Compact stacks, target gaps |
| space-3 | 12px | Form internals |
| space-4 | 16px | Mobile card padding, gutters mobile |
| space-5 | 20px | Between related elements |
| space-6 | 24px | Tablet gutters, section gaps mobile |
| space-8 | 32px | Desktop gutters, card padding desktop |
| space-10 | 40px | Major section breaks |
| space-12 | 48px | Large breaks |

**Whitespace philosophy (summary):** Separate competing panels with space before borders/shadows. Full emotional guidance: [14](14-visual-identity.md).

---

## 4. Grid

| Rule | Value |
| --- | --- |
| Conceptual columns | 12 inside active container |
| Metric cards | 2 mobile · 2–3 tablet · ≤4 desktop |
| Base alignment | Multiples of 4px |
| Split layouts | Only documented split helpers (status / next / board) |

Do not introduce a second grid alongside vendor theme layout partials.

---

## 5. Containers (layout intents)

| Intent | Max width | Gutters | Use |
| --- | --- | --- | --- |
| Form | 800px | 16 / 24 / 32 | Settings, Stripe, wizards |
| Reading | 800px | 16 / 24 / 32 | Help, readiness, next-action |
| Workspace | 1200px | 16 / 24 / 32 | Event Workspace, tickets, lists |
| Dashboard | 1280px | 16 / 24 / 32 | Home, global hubs |
| Wide / Marketing | 1400px | 16 / 24 / 32 | Boost grids, boards |

Shell remains full width. Next-action blocks inside wide workspaces still prefer **Reading** width.

Authority for structural behaviour: [03-layout-system.md](03-layout-system.md) · [DDR-003](decisions/DDR-003-layout-intents.md).

---

## 6. Radius

| Token | Intent | Typical use |
| --- | --- | --- |
| radius-sm | Small | Badges, chips |
| radius-md | Medium | Inputs, buttons |
| radius-lg | Large | Cards, panels, modals |
| radius-pill | Pill | Sparse use — density of pills kills hierarchy |

Runtime alignment examples: ~8 / 14 / 20 / pill. Prefer brand radius scale; avoid 0px admin sharpness.

---

## 7. Elevation

| Level | Use |
| --- | --- |
| 0 Flat | Default on warm canvas |
| 1 shadow-sm | Light cards, hover hint |
| 2 shadow-md | Panels, dropdowns |
| 3 shadow-lg | Modals, sticky mobile action bars |

Restraint is mandatory — multi-layer fashion shadows fight calm ops UI ([19](19-anti-patterns.md)).

---

## 8. Colour philosophy

Vendor Studio shares MEL DNA: warm cream canvas, purple primary, coral accent, soft supporting lavenders/skies.

| Principle | Why |
| --- | --- |
| Warm, not clinical | Long sessions feel human |
| Purple is scarce | Protects primary-action hierarchy |
| Coral for focus / energy | Distinct from filled primary buttons |
| Discovery gold is rare | Preserves semantic clarity; not a warning colour |
| Neutrals carry reading | Body and borders stay sober |

Organiser branding may tint **content previews**, never the whole shell.

---

## 9. Semantic colours

| Meaning | Rule |
| --- | --- |
| Danger / error | Distinct danger token + text + icon |
| Warning | Amber/warning + label |
| Success | Green/success + label; use sparingly |
| Info | Soft sky / info token |
| Money caution | Precise copy + sober treatment; no playful colour on refunds |

**Never** convey severity by colour alone.

---

## 10. Motion tokens

| Band | Duration | Use |
| --- | --- | --- |
| Fast | ~120ms | Hover / focus colour |
| Base | ~200ms | Panels, accordions, card lift |
| Slow | ~320ms | Optional section reveal |
| Enter max | ≤400ms | Dialog enter |

Easing: ease-out for entrances; ease-in-out for toggles.  
Always honour `prefers-reduced-motion` — instant state, no meaning that depends on motion.

Behaviour detail: [07-interaction-guidelines.md](07-interaction-guidelines.md).

---

## 11. Focus styles

| Spec | Rule |
| --- | --- |
| Visibility | Always visible on interactive elements |
| Colour | Vendor focus token (coral/accent family) — distinct from purple fill |
| Offset | Clear separation from control edge |
| Never | Remove outlines for aesthetics |

---

## 12. Icon sizing

| Context | Size |
| --- | --- |
| Inline with body | 16–20px |
| Button / input adornment | 20px |
| Nav item | 20–24px |
| Empty state illustration container | Modest; not hero theatre |
| Door Mode primary control icon | Larger, paired with text label |

Icons require accessible names when standalone.

---

## 13. Touch targets

| Spec | Value |
| --- | --- |
| Minimum | 44×44px |
| Spacing between targets | ≥8px |
| Actionable list rows | ≥48px height preferred |

---

## 14. Buttons (token intents)

| Variant | Visual weight |
| --- | --- |
| Primary | Filled purple; one per region |
| Secondary | Outline / soft surface |
| Quiet | Text or ghost |
| Destructive | Danger emphasis; confirm for irreversible |

Min height meets touch target. Full-width primary on mobile when sticky.

---

## 15. Forms (token intents)

| Spec | Value |
| --- | --- |
| Container | Form / Reading 800px |
| Label | Visible, above field |
| Input text | ≥16px |
| Error text | Adjacent; danger semantic |
| Section gap | space-6 to space-8 |

---

## 16. Tables (token intents)

| Spec | Rule |
| --- | --- |
| Header | Distinct surface or weight; sticky optional |
| Row padding | May tighten vs page chrome |
| Mobile | Card-row **or** horizontal scroll — one pattern per surface |
| Actions | Explicit control; not hover-only |

---

## 17. Charts (token intents)

| Spec | Rule |
| --- | --- |
| Series colours | Colour-blind safe; not severity-critical alone |
| Critical numbers | Always available as text/KPI |
| Empty | Honest empty — no fake trend lines |
| Mobile | Simplify series; prefer one chart per view |

---

## 18. Navigation (token intents)

| Spec | Rule |
| --- | --- |
| Active state | Clear; not colour-only |
| Badge counts | Sparse; attention not FOMO |
| Support / Settings | Visually separated (footer zone of nav) |

Structure authority: [02](02-information-architecture.md).

---

## 19. Empty states (token intents)

| Spec | Rule |
| --- | --- |
| Composition | Heading + reason + primary CTA |
| Width | Reading / form |
| Illustration | Optional, quiet; never replaces copy |

---

## 20. Loading states (token intents)

| Spec | Rule |
| --- | --- |
| Skeleton | Mirrors final layout; no fake metrics |
| Busy buttons | Prevent double-submit |
| `aria-busy` | On loading containers |

---

## 21. Dark mode token strategy

**Phase 12** capability ([10-roadmap.md](10-roadmap.md)) — not a near-term default.

| Principle | Detail |
| --- | --- |
| Tokens first | Remap under `.mel-vendor`; no per-component hex forks |
| Re-QA | Tables, badges, severity, money surfaces |
| Brand | Warm neutrals, not neon admin |
| Opt-in | Product decision at Phase 12 |
| Partial dark | Forbidden until the remap exists |

---

## 22. Future SCSS mapping

Implementation homes (when coding begins) — confirm in repository:

| Concern | Likely home under `myeventlane_vendor_theme` |
| --- | --- |
| Root / vendor vars | `_root-tokens.scss` · `.mel-vendor` |
| Colour | `tokens/_colors.scss` |
| Type | `tokens/_typography.scss` |
| Space | `tokens/_spacing.scss` |
| Shadow | `tokens/_shadows.scss` |
| Layout intents | `layout/_container.scss` · `--mel-layout-*` |

**Rule:** Twig applies intent classes; SCSS owns widths. See [09-drupal-mapping.md](09-drupal-mapping.md).

---

## Design implications

- New visual values must be proposed as tokens here before appearing in theme PRs
- Competing max-widths and one-off hex values are regressions ([19](19-anti-patterns.md))
- Public discovery tokens remain brand-owned; Studio documents operational extension only

## Future considerations

- Dark mode remap table (Phase 12)
- Optional density token (`comfortable` / `compact`) only if organiser research demands it
- Chart palette formalisation when Analytics phase lands

## Related references

- [README.md](README.md) · [03](03-layout-system.md) · [04](04-design-language.md) · [07](07-interaction-guidelines.md) · [09](09-drupal-mapping.md) · [14](14-visual-identity.md) · [DDR-003](decisions/DDR-003-layout-intents.md) · [docs/brand/design-tokens.md](../../brand/design-tokens.md)
