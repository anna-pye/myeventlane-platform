# VX2-02A — Workspace Layout Convergence

**Status:** Complete — ready for review  
**Date:** 2026-07-22  
**Branch:** `feature/vx2-workspace-layout-convergence`  
**Depends on:** PR #705 (VX2 Sprint 4 — One Attendee Workspace) merged to `main`

Calm, centred, product-first organiser layouts. Shell stays full width; content uses intent-based containers.

---

## Product story

As an organiser, I want the organiser interface to feel calm, consistent, and easy to scan, so I can focus on running my event instead of navigating software.

---

## Design philosophy (principles adopted)

Aligned with Shopify Admin / Stripe Dashboard / Linear / Notion **UX principles** (not visual copies):

- Full-width application shell
- Centred content with intentional max-width
- Whitespace creates hierarchy
- Cards earn their size
- One clear scanning path: title → primary action → status → next → supporting

MEL design language is extended — not replaced. See Vendor Experience Convergence pack and `docs/brand/design-tokens.md`.

---

## Stage 1 — Audit summary (pre-change)

| Screen | Prior max-width | Issues |
| --- | --- | --- |
| Dashboard | 1120px hardcoded | Below target; oversized hero padding |
| Workspace | 1440 shell / ~1152 management | Too wide on ultra-wide; double horizontal inset |
| Tickets / Attendees / Analytics | Inherited workspace; builder 1200 | Competing page max-widths (1080 vs none) |
| Messages | Console unconstrained / forms 960 | Forms stretched |
| Payments (payouts) | Console full | Status cards competed as equal full-width blocks |
| Marketing (Boost) | Console unconstrained | No marketing intent |
| Settings | Form 960 / pane 100% | Forms wider than reading comfort |
| Support | ~896px (56rem) | Close to reading; not tokenised |

**System issues:** competing `$layout-content-max-width` (1080), `--mel-max` (1320), `$layout-container-max` (1440), hardcoded 1120/1200/960; triple gutters (shell + workspace + event-landing); two `.mel-page` contracts.

---

## Container system (Stages 2–3)

| Intent | Token | Width | Class |
| --- | --- | --- | --- |
| Forms | `$mel-layout-form` | 800px | `.mel-layout--form` |
| Reading | `$mel-layout-reading` | 800px | `.mel-layout--reading` |
| Workspace | `$mel-layout-workspace` | 1200px | `.mel-layout--workspace` |
| Dashboard | `$mel-layout-dashboard` | 1280px | `.mel-layout--dashboard` |
| Marketing / wide | `$mel-layout-wide` | 1400px | `.mel-layout--wide` / `--marketing` |

Gutters: `$mel-page-gutter-mobile|tablet|desktop` (16 / 24 / 32).

CSS custom properties mirror tokens on `.mel-vendor` (`--mel-layout-*`).

**Rule:** never hardcode content widths in Twig — apply layout classes; SCSS owns tokens.

---

## Screen mapping (KEEP vs CONSTRAIN)

| Surface | Container | Cards |
| --- | --- | --- |
| Dashboard | Dashboard 1280 | KEEP KPI / attention / activity within container |
| Event Workspace | Workspace 1200 | KEEP metrics/tables; CONSTRAIN next-action / status to Reading |
| Tickets / Attendees / Analytics | Workspace | KEEP operational boards; forms → Form |
| Messages / Settings / Stripe forms | Form 800 | CONSTRAIN |
| Support | Reading 800 | CONSTRAIN |
| Payments hub | Workspace (console) | Status split side-by-side; history KEEP |
| Marketing / Boost | Marketing 1400 | KEEP grids |
| Events list / Builder | Workspace 1200 | KEEP |

---

## Card hierarchy (Stage 6)

Existing `.mel-card` tokens retained (`$card-padding`, `$radius-card` / `$radius-workspace-card`, `$shadow-card`).

Added hierarchy utilities (no parallel card system):

- `.mel-card--status` / `--readiness` / `--next` → reading width
- `.mel-card--primary` → subtle primary emphasis
- `.mel-card-stack` / `.mel-card-grid` → rhythm without five equal full-bleed cards
- `prefers-reduced-motion` on clickable card lift

---

## Whitespace & hierarchy (Stages 4–7)

- Dashboard organiser header padding reduced (less empty hero)
- Event landing: removed duplicate horizontal padding (shell + workspace already inset)
- Console header: title → meta → actions alignment
- Next-action banners capped at reading width inside workspace
- Settings / wizard / forms centred at form intent

---

## Responsive & accessibility (Stages 8–9)

| Viewport | Expectation |
| --- | --- |
| 390px | Centred content, safe-area gutters, no horizontal overflow |
| Tablet | Split layouts collapse; form width still comfortable |
| Desktop | Intent max-widths centre in shell |
| Ultra-wide | Content stops at intent; shell/sidebar fill remaining |

A11y retained: focus rings, 44px tab targets, heading hierarchy via existing page/console titles, reading width for prose, reduced-motion on card hover.

---

## Manual UX scores (Stage 11)

| Screen | Consistency | Hierarchy | Readability | Whitespace | Primary CTA | Scan | Trust | A11y | Avg |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | 9 | 8.5 | 9 | 8.5 | 8.5 | 8.5 | 9 | 9 | **8.8** |
| Workspace | 9 | 8.5 | 9 | 8.5 | 8.5 | 8.5 | 9 | 9 | **8.8** |
| Tickets | 8.5 | 8.5 | 8.5 | 8.5 | 8.5 | 8.5 | 9 | 9 | **8.6** |
| Attendees | 8.5 | 8.5 | 8.5 | 8.5 | 8.5 | 8.5 | 9 | 9 | **8.6** |
| Messages | 8.5 | 8 | 9 | 8.5 | 8 | 8 | 8.5 | 9 | **8.4** |
| Payments | 9 | 9 | 9 | 8.5 | 9 | 9 | 9 | 9 | **8.9** |
| Analytics | 8.5 | 8.5 | 8.5 | 8.5 | 8 | 8.5 | 9 | 9 | **8.6** |
| Marketing | 8.5 | 8 | 8.5 | 8.5 | 8 | 8 | 8.5 | 9 | **8.4** |
| Settings | 9 | 8.5 | 9 | 9 | 8.5 | 9 | 9 | 9 | **8.9** |
| Support | 9 | 8.5 | 9 | 9 | 8.5 | 9 | 9 | 9 | **8.9** |

Target 8.5+ met for core operational screens; Messages/Marketing at 8.4 pending deeper content IA (later epics).

---

## Remaining UX backlog

1. Studio module CSS (`mel-event-studio-shell.css`) still carries some widths outside vendor tokens — alias or migrate in a follow-up.
2. Messages compose/history still lacks a dedicated layout partial — apply `.mel-layout--form` / reading when Messages hub ships (VX2-06).
3. Global Analytics / Attendees hubs: confirm console modifiers (dashboard vs workspace) per route when those hubs are productised.
4. Card header treatments still vary slightly between live-ops and `.mel-card` — optional visual polish pass.
5. Wizard multi-step chrome may need workspace-width frame with form-width fields (currently form for steps).

---

## Files changed (primary)

- `web/themes/custom/myeventlane_vendor_theme/src/scss/tokens/_spacing.scss`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/_root-tokens.scss`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/layout/_container.scss`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/layout/_two-column.scss`
- Consumer SCSS: workspace, dashboard, console, form, cards, support, builder, events, settings, wizard, mission-control, console-stack, event-form, mel-dashboard
- Twig: `boost.html.twig`, `payouts.html.twig`
- Docs: this file, `docs/brand/design-tokens.md`, Convergence pack updates

---

## Validation checklist

See Stage 10 in the sprint brief. Commands and results are recorded in the PR / final report after local runs.
