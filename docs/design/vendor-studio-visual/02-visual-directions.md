# Vendor Studio Visual Language — Three Directions

**Sprint:** VS1  
**Status:** Product design (documentation only) — awaiting PO approval  
**Date:** 2026-07-25  
**Authority:** [01-philosophy.md](01-philosophy.md) · PDS · `docs/brand/` · Launch Centre `17`–`20`  
**Constraint:** Existing architecture only. Hero / Mission Control / Launch Centre structure frozen — visual expression only.

---

## Shared constraints (all options)

- Canvas rooted in **Warm Cream** (`#FFF7EE`) or a controlled variant — not clinical white, not dark mode.
- Primary action colour: **Primary Purple** (`#6B46FF`) — scarce.
- Focus ring: coral/accent family — never remove outlines.
- Layout intents unchanged: Form 800 · Reading 800 · Workspace 1200 · Dashboard 1280.
- Hero remains sole primary Publish / Share / Continue setup control.
- Mission Control checklist truth from readiness facade — no second calculator.
- Launch Centre band order locked: Ready to Launch → Checklist → Controls → Aftercare.
- No CSS in this pack — design system language only.

---

# OPTION A — Editorial Workspace

**Inspired by:** Apple · Notion · Linear · Medium  
**Codename:** Editorial Workspace

### Design philosophy

Treat Vendor Studio like a calm editorial workspace for running events. Hierarchy comes from **typography and whitespace**, not from bordered boxes. The organiser reads a clear story: where they are, what needs them, what to do next. Surfaces stay mostly flat. Cards appear only when the user must act on a discrete unit (order row, ticket type, action item).

Premium here means **restraint** — large titles, quiet meta, almost no chrome.

### Colour use

| Role | Treatment |
| --- | --- |
| Page canvas | Near-white cream or pure Warm Cream; very light |
| Text | Near-black neutrals; purple only for primary CTA and key links |
| Borders | Rare hairlines at ~8–12% opacity; prefer spacing separation |
| Accents | Lavender/Soft Sky used as **full-bleed section washes**, not card fills |
| Discovery Gold | Almost never in Studio ops (reserve for public discovery) |
| Semantic | Text labels + icons; colour as support only |

### Card system

- **Default: no card.** Content sits on canvas.
- Elevate only: Action Cards, confirm dialogs, sticky mobile bars, interactive list rows on hover.
- No nested cards. No metric card walls.
- Borders preferred over shadows when separation is needed; elevation 0–1 only.

### Spacing

- Generous vertical rhythm: section breaks at space-12–16; desktop space-16–20.
- Reading/Launch bands prefer Reading width (800px) even inside Workspace shell.
- Dense tables allowed, but surround them with air — do not pack panels edge-to-edge.

### Typography

- **Large page titles** (H1 scale); section titles nearly editorial.
- Body 16px minimum; meta 14px quiet grey.
- Strong contrast between display and body — Medium/Notion-like.
- Sentence case; sparse uppercase labels.

### Buttons

- One filled primary (purple) in Hero.
- Ghost / text secondary elsewhere.
- Publish never duplicated as a second filled primary on Launch Centre.
- Pill radius avoided for primary — soft `radius-md` / `radius-lg`.

### Forms

- Labels above fields; generous field spacing (space-5–6).
- Inputs with soft borders; focus coral ring.
- Group fields by narrative headings, not fieldset boxes.
- Settings remain Settings — Launch Centre does not dump full settings chrome.

### Mission Control (visual only)

- Feels like an editorial “briefing” — title + short paragraph + checklist as a clean list.
- Minimal panel chrome; optional Soft Sky wash behind the block.
- Progress expressed as typography (“3 of 7 complete”), not a loud gauge.
- Next-action line in semibold; secondary items quieter.

### Launch Centre (visual only)

- “Ready to Launch” as a large editorial headline + one supporting sentence.
- Checklist as typographic list with ✔ / ○ — expandable, not a heavy card stack.
- Controls band: status sentence + secondary unpublish — Hero owns Publish.
- Aftercare: prose + quiet link row (Share, View public).

### Information pages

- Help / support / analytics explanations: Reading width, long-line comfort.
- Side notes as italic or soft callout text — not bordered tip cards unless interactive.

### Publishing

- Same Launch Centre expression; celebrate success with typography first (“Your event is live”), then quiet share links.
- No confetti-first composition.

### Success states

- Large affirmative title; one sentence; 1–2 links.
- Celebrating Guide tone in copy — illustration optional and small.

### Empty states

- Short headline + one sentence + one CTA.
- Optional abstract line illustration — never a mascot occupying half the viewport.

### Illustrations

- Minimal. Prefer none on operational screens.
- Empty/success only; soft line style per brand illustration guidelines.

### Motion

- Near-instant. Subtle fade (~120–200ms) on panel expand.
- No parallax, no decorative float.

### 390px example (Ready — Launch Centre)

```text
┌─────────────────────────────┐
│ ← Events                    │
│ Summer Night Market         │
│ Ready                       │
│ [ Publish event ]           │
│ View · Share                │
├─────────────────────────────┤
│ Publishing · · ·            │
│                             │
│ Ready to launch             │
│ You’re ready to go live.    │
│ Guests can discover this…   │
│                             │
│ Launch checklist      All ✔ │
│  ✔ Title  ✔ Schedule        │
│  ✔ Tickets  ✔ Payments …    │
│                             │
│ Status: Ready to publish    │
│ Unpublish is unavailable    │
│ until live.                 │
│                             │
│ After you publish           │
│ Share your event page…      │
└─────────────────────────────┘
```

Air between bands; no nested boxes.

### Desktop example (≥1200)

```text
┌─ Chrome ──────────────────────────────────────────────┐
│ ← Events   Summer Night Market  Ready  [Publish] View │
├─ Sections ────────────────────────────────────────────┤
│                                                       │
│   Ready to launch                                     │
│   You’re ready to go live. Guests will…               │
│                                                       │
│   Launch checklist                              All ✔ │
│   ✔ Title · ✔ Schedule · ✔ Mode · ✔ Tickets · …       │
│                                                       │
│   Ready when you are. Preview the public page.        │
│                                                       │
│   After publishing — Share, then invite your list.    │
└───────────────────────────────────────────────────────┘
```

Single column narrative; Hero owns the only filled Publish.

### Accessibility considerations

- Large type helps readability; verify purple-on-cream for small link text.
- Hairline borders may fail for low-vision users — ensure focus rings and text hierarchy carry structure.
- Checklist must remain a real list with accessible names, not decorative glyphs alone.

### Option A — fit / risk

| Fit | Risk |
| --- | --- |
| Premium calm; kills “admin” feel | Can feel sparse for dense ops (orders, attendees) |
| Excellent for Launch Centre narrative | Tables need careful density rules or they clash |
| Reinforces one primary CTA | Over-minimalism can hide secondary affordances |

---

# OPTION B — Soft Command Centre

**Inspired by:** Arc Browser · Stripe Dashboard · Raycast  
**Codename:** Soft Command Centre

### Design philosophy

Vendor Studio is a **soft command centre** for event operations. Structure is visible and trusted: clear zones, soft panels, high-contrast status, scannable lists. Warmth comes from colour and radius, not from illustration. Organisers feel capable — “I can run tonight’s door from here” — without CRM coldness.

Premium here means **clarity under pressure**.

### Colour use

| Role | Treatment |
| --- | --- |
| Canvas | Warm Cream |
| Panels | White / slightly lifted cream with soft lavender edge |
| Borders | Soft 1px warm neutrals — present but not admin-grey |
| Primary | Purple filled buttons; purple for active nav |
| Support | Soft Sky for info bands; Lavender for selected rows |
| Semantic | Strong: danger / warning / success chips with labels |

### Card system

- **Soft panels** are the default for operational groups (not every paragraph).
- One elevation language: shadow-sm panels on cream; shadow-md for menus/modals.
- Cards group related controls (ticket types, payout status) — still no card-in-card.
- Metric tiles allowed **≤4** and only when they answer a decision.

### Spacing

- Consistent 8px grid; section gaps space-8–12 (tighter than A).
- Workspace width used fully for lists/tables; Reading width for next-action and Launch narrative.
- Dense but breathable — Stripe-like rhythm.

### Typography

- Clear UI scale; H1 present but not magazine-large.
- Semibold section labels; tabular figures for money and counts.
- Meta 13–14px; high clarity over editorial flourish.

### Buttons

- Primary filled; secondary outline/soft; tertiary text.
- Destructive ghost with danger text — confirmed.
- Icon+label for frequent ops (Check in, Message) — Raycast-like affordance clarity.

### Forms

- Structured field groups inside soft panels.
- Inline validation with icon + text.
- Sticky save on long settings (mobile) — operational, not editorial.
- Compact but ≥44px controls.

### Mission Control (visual only)

- Soft panel with clear header “Mission Control”.
- Checklist as interactive rows with status chips (blocker / done).
- Next action emphasised in a Soft Sky or Lavender strip inside the panel (not a second card).
- Feels like a command briefing — structured, scannable.

### Launch Centre (visual only)

- Four bands as soft stacked panels **or** one panel with internal dividers (prefer internal dividers to avoid card wall).
- Checklist rows with fix links as primary row action.
- Controls band shows status + secondary actions clearly.
- Aftercare as Soft Sky info panel when live.

### Information pages

- Definition lists and tables preferred.
- Callouts as Soft Sky banners with icon — operational help, not marketing.

### Publishing

- High clarity on Ready / Needs attention / Live states via status chip + headline.
- Unpublish secondary, confirmed modal (soft elevated).

### Success states

- Success banner (semantic green + label) + next actions as button row.
- Celebrate without illustration overload — optional small mark.

### Empty states

- Soft panel with icon, title, CTA — Command Centre empty, not sad blank page.
- Honest: “No orders yet” + what happens when first sale arrives.

### Illustrations

- Sparse. Prefer icons (consistent set) over scenes.
- Empty states may use one small illustration; Mission Control uses icons only.

### Motion

- Fast feedback: 120ms hover, 200ms panel.
- Publishing button → “Publishing…” disabled state.
- Row highlight on focus for keyboard ops.

### 390px example (Needs attention — Launch Centre)

```text
┌─────────────────────────────┐
│ ← Events                    │
│ Summer Night Market         │
│ Needs attention             │
│ [ Continue setup ]          │
├─────────────────────────────┤
│ ┌ Soft panel ─────────────┐ │
│ │ Almost there            │ │
│ │ 2 items need you before │ │
│ │ guests can find this.   │ │
│ └─────────────────────────┘ │
│ ┌ Soft panel ─────────────┐ │
│ │ ○ Cover image     Fix → │ │
│ │ ○ Stripe charges  Fix → │ │
│ │ ✔ Title                 │ │
│ │ ✔ Schedule              │ │
│ └─────────────────────────┘ │
│ ┌ Soft panel ─────────────┐ │
│ │ Publish unavailable     │ │
│ │ Finish the items above. │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘
```

### Desktop example (Live — Mission Control on Home)

```text
┌─ Hero: Live · [ Share event ] · View ─────────────────┐
├─ Mission Control (soft panel) ────────────────────────┤
│  Next: Share with your community                      │
│  ✔ Setup complete · Live · 12 tickets sold            │
│  ○ Add cover for stronger discovery (optional)        │
├─ Workspace body ──────────────────────────────────────┤
│  [ Orders 2-up soft tiles ]  [ Attendees snapshot ]   │
└───────────────────────────────────────────────────────┘
```

### Accessibility considerations

- Soft borders must still meet 3:1 for UI component contrast where required.
- Status chips need text, not colour alone.
- Dense rows: ensure focus visible and hit targets ≥44px on mobile.

### Option B — fit / risk

| Fit | Risk |
| --- | --- |
| Best for daily ops clarity | Can slide toward “dashboard cards” if panel count rises |
| Matches Stripe/Arc operational trust | Less “delight” than C; may feel cooler than MEL public brand |
| Natural for lists, money, door | Over-structuring Launch Centre into admin panels |

---

# OPTION C — Guided Studio

**Inspired by:** Canva · Apple Setup Assistant · Calm AI assistants  
**Codename:** Guided Studio

### Design philosophy

Vendor Studio feels like a **friendly guided studio**. A Helper / Celebrating Guide presence (tone + light illustration) walks organisers through setup and celebrate moments. Optimistic colour washes, rounded soft shapes, human copy. Operational screens still work, but the emotional centre is “someone is with me.”

Premium here means **human warmth** — never childish clipart.

### Colour use

| Role | Treatment |
| --- | --- |
| Canvas | Warm Cream with soft gradient washes (Lavender → Cream, Sky → Cream) at key moments |
| Accent | Coral for encouragement moments; purple for primary |
| Panels | Very rounded (`radius-lg`), pastel fills |
| Gold | Allowed sparingly on success/celebration — still not FOMO |

### Card system

- Friendly soft cards for steps and empty states.
- Risk: card proliferation — must cap illustration cards to empty/success/onboarding only.
- Interactive steps as large tappable tiles on mobile (Canva-like), with care not to fight Hero CTA.

### Spacing

- Comfortable; slightly playful vertical rhythm.
- More padding inside step cards (space-6–8).
- Avoid cramming illustration + form + CTA in one fold on 390.

### Typography

- Friendly, rounded feel in hierarchy (weight and size — not a display novelty font that breaks brand).
- Conversational headings: “Let’s finish your tickets.”
- Shorter paragraphs; more second-person.

### Buttons

- Large, rounded primaries; encouraging labels (“Publish event”, “Continue setup”).
- Secondary often soft-filled lavender instead of outline.
- Risk: too many soft primaries — must still enforce one Hero primary.

### Forms

- Wizard-like grouping even on Settings (progressive disclosure).
- Helper text under fields (“Guests see this on your public page”).
- Inline coach marks sparingly.

### Mission Control (visual only)

- Feels like a coach: illustration corner + “Here’s what needs you.”
- Checklist as friendly steps with encouraging microcopy.
- Risk: undermines sober publish/money tone if too playful — keep Stripe/blocker language honest.

### Launch Centre (visual only)

- Setup-assistant narrative: “Ready when you are” with Helper Guide.
- Checklist as stepped journey.
- Success: Celebrating Guide + share prompts.
- Must not add a second Publish button “for friendliness.”

### Information pages

- Illustrated explainers; short sections.
- Good for first-run; may frustrate power users on dense ops pages.

### Publishing

- Optimistic Ready state; honest blockers with warm fix links (“Let’s connect Stripe”).
- Unpublish: still confirmed and sober — warmth ≠ casual about taking a page down.

### Success states

- Strongest of three options: illustration + “Your event is live” + share path.
- Keep Boost secondary.

### Empty states

- Illustrated scene + CTA — brand illustration guidelines (optimism, community).
- Avoid isolation / mystery / VIP tropes.

### Illustrations

- Central to the identity. Helper Guide on onboarding; Celebrating on success; Host tone for welcome.
- Not a named mascot; not on every panel.
- Max one guide moment per screen.

### Motion

- Soft entrances (≤320ms); gentle checklist item complete animation.
- Honour reduced motion → instant state.
- No continuous animation loops.

### 390px example (Draft empty — Events list)

```text
┌─────────────────────────────┐
│ Your events                 │
│                             │
│   [ soft illustration ]     │
│                             │
│ Nothing here yet            │
│ Let’s create your first     │
│ event — we’ll guide you.    │
│                             │
│ [ Create event ]            │
└─────────────────────────────┘
```

### Desktop example (Ready — Launch Centre)

```text
┌─ Hero [ Publish event ] ────────────────────────────────┐
│  Ready to launch                    [ small Helper art ]│
│  You’re ready to go live…                               │
│  Checklist (friendly rows) · Controls · Aftercare preview│
└─────────────────────────────────────────────────────────┘
```

### Accessibility considerations

- Pastel fills need contrast checks for text overlays.
- Illustration must be decorative (`alt=""`) or have text equivalent — never info-only in image.
- Motion optional; meaning in text.

### Option C — fit / risk

| Fit | Risk |
| --- | --- |
| Strong MEL public-brand continuity | Can feel non-serious for money/door ops |
| Best empty/success delight | Illustration cost + “Canva toy” perception |
| Helps first-time organisers | Power users may find coach tone noisy |

---

## Comparative matrix

| Dimension | A Editorial | B Soft Command | C Guided |
| --- | --- | --- | --- |
| Admin detox | ★★★★★ | ★★★★☆ | ★★★★☆ |
| Daily ops clarity | ★★★☆☆ | ★★★★★ | ★★★☆☆ |
| MEL warmth | ★★★☆☆ | ★★★☆☆ | ★★★★★ |
| Launch Centre fit | ★★★★★ | ★★★★☆ | ★★★★☆ |
| Card restraint | ★★★★★ | ★★★☆☆ | ★★☆☆☆ |
| Illustration load | Low | Low | High |
| 390 density risk | Low | Medium | Medium–High |
| Implementation risk | Type/spacing discipline | Panel budget discipline | Asset + tone discipline |

---

## Recommendation pointer

None of A, B, or C alone fully hits modern · premium · calm · community-first · operational · enjoyable.

**Recommended synthesis: Option B.5** — see [03-option-b5.md](03-option-b5.md).

| Take from | Ingredient |
| --- | --- |
| **B** | Soft Command layout, panel discipline, ops clarity |
| **C** | Warmth, Guide tone at empty/success, optimistic microcopy |
| **A** | Typography scale, whitespace, minimal borders, anti-card-wall |

---

**STOP.** Directions for PO review — no implementation.
