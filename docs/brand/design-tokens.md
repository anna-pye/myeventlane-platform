# MEL Design Tokens

**Version:** 1.0  
**Purpose:** Document the MEL brand token system for colour, typography, spacing, radius, shadow, and motion.

This document defines **brand tokens**. Runtime SCSS and CSS custom properties in `web/themes/custom/myeventlane_theme/` implement these values. Do not introduce a second token system in theme code — align implementation to this document and `DESIGN_SYSTEM.md`.

---

## Colour palette

### Primary colours

| Token name | Hex | Role |
|------------|-----|------|
| **Primary Purple** | `#6B46FF` | Primary actions, key brand moments, active states |
| **Lavender** | `#CDBDFF` | Secondary surfaces, soft highlights, badge backgrounds |
| **Coral** | `#FF6B4A` | Warm accent, energy, secondary CTAs where appropriate |
| **Discovery Gold** | `#FFC83D` | Discovery highlights, Hidden Gem accents, editorial emphasis |
| **Warm Cream** | `#FFF7EE` | Page background, card surfaces, warm neutral base |
| **Soft Sky** | `#EAF4FF` | Informational panels, calm secondary backgrounds |

### Colour usage principles

- **Warm Cream** is the default page canvas — the brand should feel light and welcoming, not clinical white or dark nightlife.
- **Primary Purple** anchors primary buttons and key links; use sparingly on large text fields to maintain contrast.
- **Discovery Gold** is reserved for discovery moments (Hidden Gem, editorial picks) — not generic warnings or errors.
- **Coral** adds warmth; avoid overuse alongside Discovery Gold on the same component.
- **Lavender** and **Soft Sky** support hierarchy without competing with primary actions.

### Semantic pairings (documentation reference)

| Pairing | Use |
|---------|-----|
| Primary Purple on Warm Cream | Primary buttons, key headings (verify contrast for small text) |
| White on Primary Purple | Primary button labels, inverse chips |
| Discovery Gold on Warm Cream | Hidden Gem badge, discovery callouts |
| Coral on Warm Cream | Secondary promotional accents |
| Dark neutral on Warm Cream | Body copy (implementation uses theme neutral tokens) |

---

## Typography scale

MEL uses a clear, readable sans-serif stack in implementation. Brand documentation defines the **scale**, not font files.

| Token | Size (mobile) | Size (desktop) | Weight | Use |
|-------|---------------|----------------|--------|-----|
| **Display** | 2rem (32px) | 2.75rem (44px) | Bold (700) | Homepage hero headline |
| **H1** | 1.75rem (28px) | 2.25rem (36px) | Bold (700) | Page titles |
| **H2** | 1.5rem (24px) | 1.875rem (30px) | Semibold (600) | Section headings |
| **H3** | 1.25rem (20px) | 1.5rem (24px) | Semibold (600) | Card titles, subsections |
| **Body** | 1rem (16px) | 1rem (16px) | Regular (400) | Default copy |
| **Body small** | 0.875rem (14px) | 0.875rem (14px) | Regular (400) | Meta, captions, badges |
| **Label** | 0.75rem (12px) | 0.8125rem (13px) | Medium (500) | Uppercase labels sparingly; prefer sentence case |

### Typography principles

- Minimum **16px body** on mobile for readability.
- **Line height:** 1.5 for body; 1.2–1.3 for headings.
- **Sentence case** for UI labels and badges (not ALL CAPS except where space is severely constrained).
- Limit display size to one hero per page to preserve hierarchy.

---

## Spacing scale

Based on a **4px base unit**. Use consistent steps across components.

| Token | Value | Typical use |
|-------|-------|-------------|
| `space-1` | 4px | Tight inline gaps, icon padding |
| `space-2` | 8px | Badge padding, compact stacks |
| `space-3` | 12px | Form field internal padding |
| `space-4` | 16px | Card internal padding (mobile) |
| `space-5` | 20px | Between card elements |
| `space-6` | 24px | Section gaps (mobile) |
| `space-8` | 32px | Card padding (desktop), rail gaps |
| `space-10` | 40px | Section padding (mobile) |
| `space-12` | 48px | Large section breaks |
| `space-16` | 64px | Hero vertical rhythm |
| `space-20` | 80px | Major section separation (desktop) |

### Spacing principles

- Mobile card padding: **space-4** minimum; **space-8** on desktop.
- Between discovery rails: **space-10** mobile, **space-12** desktop.
- Maintain consistent gutter alignment with `.mel-container` in theme implementation.

---

## Organiser layout widths (VX2-02A)

Vendor console (`myeventlane_vendor_theme`) centres **content** inside a full-width shell. Do not hardcode max-widths in Twig — use layout classes / Sass tokens.

| Token | Value | Intent | Typical surfaces |
|-------|-------|--------|------------------|
| `$mel-layout-form` / `--mel-layout-form` | 800px | Editable forms | Settings, Stripe connect, messaging brand, wizard steps |
| `$mel-layout-reading` / `--mel-layout-reading` | 800px | Prose & status | Support, help, next-action banners, status cards |
| `$mel-layout-workspace` / `--mel-layout-workspace` | 1200px | Event operations | Event Workspace, tickets/attendees/analytics tabs, builder, events list |
| `$mel-layout-dashboard` / `--mel-layout-dashboard` | 1280px | Organiser home / hubs | Dashboard, global analytics/payments hubs |
| `$mel-layout-wide` / `$mel-layout-marketing` | 1400px | Marketing grids | Boost hubs, placement grids |

### Page gutters

| Token | Value |
|-------|-------|
| `$mel-page-gutter-mobile` | 16px |
| `$mel-page-gutter-tablet` | 24px |
| `$mel-page-gutter-desktop` | 32px |

### Layout classes

- `.mel-layout--form` · `.mel-layout--reading` · `.mel-layout--workspace` · `.mel-layout--dashboard` · `.mel-layout--wide` / `--marketing`
- Hierarchy helpers: `.mel-layout__status` · `.mel-layout__readiness` · `.mel-layout__next` · `.mel-layout__split`

Runtime: `web/themes/custom/myeventlane_vendor_theme/src/scss/tokens/_spacing.scss` and `layout/_container.scss`.  
Authority detail: [`docs/implementation/vx2-02a-workspace-layout-convergence.md`](../implementation/vx2-02a-workspace-layout-convergence.md).

---

## Radius scale

| Token | Value | Use |
|-------|-------|-----|
| `radius-sm` | 6px | Badges, small chips |
| `radius-md` | 12px | Buttons, inputs |
| `radius-lg` | 16px | Cards, panels |
| `radius-xl` | 24px | Hero media containers, featured modules |
| `radius-full` | 9999px | Pills, avatars |

### Radius principles

- Cards and buttons share the **rounded, friendly** MEL shape — avoid sharp 0px corners on public surfaces.
- Hero media may use **radius-xl** on bottom corners or full container per locked hero contract in `DESIGN_SYSTEM.md`.

---

## Shadow scale

| Token | Description | Use |
|-------|-------------|-----|
| `shadow-sm` | Subtle lift | Badges, hover hint |
| `shadow-md` | Standard card | Event cards, panels |
| `shadow-lg` | Elevated | Modals, dropdowns, sticky mobile bars |
| `shadow-none` | Flat | On Warm Cream sections where separation is by spacing alone |

### Shadow principles

- Prefer **shadow-md** for event cards on Warm Cream backgrounds.
- Avoid heavy shadows that suggest dark or exclusive nightlife aesthetics.
- Sticky mobile CTAs may use **shadow-lg** for separation from content.

---

## Motion guidance

### Duration tokens

| Token | Duration | Use |
|-------|----------|-----|
| `motion-fast` | 120ms | Hover, focus colour transitions |
| `motion-base` | 200ms | Card hover lift, accordion |
| `motion-slow` | 320ms | Page section reveal (optional) |
| `motion-enter` | 400ms | Modal enter (max) |

### Easing

- **Standard:** ease-out for entrances; ease-in-out for toggles.
- **Avoid:** Bouncy or playful overshoot — brand is warm, not childish.

### Motion principles

1. **Respect `prefers-reduced-motion`** — disable non-essential transitions when requested.
2. **No essential information in animation alone** — badges, errors, and CTAs must be visible without motion.
3. **Subtle card hover** — slight lift or shadow change; no large scale transforms on mobile.
4. **Guide illustrations** — may use gentle looped motion in marketing; static in core UI flows unless performance-tested.

### What not to animate

- Checkout payment fields
- Ticket quantity changes that affect price (instant update preferred)
- Accessibility focus rings

---

## Token governance

| Layer | Authority |
|-------|-----------|
| Brand definition | This document (`docs/brand/design-tokens.md`) |
| Runtime implementation | `web/themes/custom/myeventlane_theme/src/scss/base/_tokens.scss` and related token partials |
| Locked contracts | `DESIGN_SYSTEM.md` at repository root |

Changes to brand colours or scale require updating this document first, then aligning theme tokens in a dedicated implementation task.
