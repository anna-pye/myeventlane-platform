# Vendor Studio Visual Language v1 — Option B.5

**Sprint:** VS1  
**Status:** ✅ **APPROVED · FROZEN** (Product Owner 2026-07-25) — Vendor Studio Visual Language v1 complete with Workspace Zones  
**Date:** 2026-07-25  
**Codename:** Soft Command · Editorial Breath · Guided Heart  
**Authority:** [01-philosophy.md](01-philosophy.md) · [02-visual-directions.md](02-visual-directions.md) · [07-workspace-zones.md](07-workspace-zones.md) (first-class) · PDS · `docs/brand/`  
**Next:** Implementation VL-1…VL-6 only — no further design expansion

---

## One-line definition

**Vendor Studio Visual Language v1** is a soft command centre with editorial whitespace and human warmth — operational enough for door night, calm enough for Sunday setup, warm enough to feel like MyEventLane.

```text
Layout & structure     ← Option B (Soft Command Centre)
Typography & air       ← Option A (Editorial Workspace)
Warmth & Guide moments ← Option C (Guided Studio)
```

---

## Design philosophy

1. **Operate with clarity** — Zones, next action, status, and lists are scannable under pressure (B).
2. **Breathe like an editorial tool** — Large titles, quiet meta, cards only when earned (A).
3. **Feel human at the edges** — Empty, success, and first-run moments carry Helper / Celebrating Guide tone — not a mascot on every panel (C).
4. **Never look like admin** — Warm cream canvas, soft radius, scarce purple, no nested card walls.
5. **Never look like toy SaaS** — Money, publish, refunds, and door stay sober.

Personality remains: **Warm · Capable · Local · Calm · Honest**.

---

## Colour use (v1)

Align to brand + PDS Studio tokens. No parallel palette.

| Token | Hex (brand) | Studio use in B.5 |
| --- | --- | --- |
| Warm Cream | `#FFF7EE` | Default page canvas |
| White / lifted cream | — | Soft panel surfaces on cream |
| Primary Purple | `#6B46FF` | Primary CTA, active nav, key links — **scarce** |
| Lavender | `#CDBDFF` | Selected rows, subtle washes, secondary soft fills (rare) |
| Soft Sky | `#EAF4FF` | Info / next-action / aftercare bands |
| Coral | `#FF6B4A` | Focus rings, accent energy, secondary emphasis |
| Discovery Gold | `#FFC83D` | **Rare** in Studio — success spark only if contrast-safe; never warnings |
| Neutrals | theme | Body, borders, meta — warm, not cool grey admin |

**Rules:**

- Purple fill = primary action only (usually Hero).
- Semantic danger / warning / success remain distinct + labelled.
- Organiser brand colours tint **previews**, never the shell.

---

## Card system (v1)

**Principle:** Cards earn their size (PDS principle 9).

| Surface type | Treatment |
| --- | --- |
| Narrative bands (Launch headlines, help prose) | **Flat on canvas** — typography + space |
| Mission Control | **One soft panel** — internal sections, not nested cards |
| Launch Centre | **One column**; prefer internal dividers over 4 stacked cards |
| Action items / orders / ticket rows | Soft row or single interactive card |
| Metric tiles | ≤4 decision metrics; flat or shadow-sm |
| Modals / menus | shadow-md |
| Card inside card | **Forbidden** |

Elevation budget: 0 flat default · 1 soft panels · 2 overlays · 3 sticky mobile bars / modals.

---

## Spacing (v1)

| Context | Rhythm |
| --- | --- |
| Page section breaks | space-10–12 mobile · space-12–16 desktop (A breath) |
| Inside soft panels | space-4–6 mobile · space-6–8 desktop |
| Between checklist rows | space-3–4 |
| Reading / Launch narrative | Prefer **Reading 800** width |
| Ops lists / tables | **Workspace 1200** |
| Gutters | 16 / 24 / 32 by breakpoint |

Anti-pattern: edge-to-edge panel packing with 8px gaps everywhere.

---

## Typography (v1)

| Role | Intent |
| --- | --- |
| Page / band titles | Editorial scale — confident H1/H2 (A) |
| Panel titles | H3 semibold — clear ops (B) |
| Body | 16px · 1.5 line-height |
| Meta | 14px quiet |
| Money / counts | Tabular figures where available |
| Case | Sentence case; sparse uppercase |

One display-level title per screen region. Hero event name remains chrome hierarchy — section titles must not compete with brand/event identity.

---

## Buttons (v1)

| Kind | Use |
| --- | --- |
| Primary filled (purple) | Hero authoritative CTA only for publish/share/continue |
| Secondary soft / outline | View, Preview, secondary nav actions |
| Tertiary text | Inline fixes, “Who can find this?” |
| Danger ghost | Unpublish / destructive — always confirm |
| Soft lavender fill | Optional for non-primary encouragement — **never** compete with Hero primary |

Labels: **Publish event** / **Share event** / **Continue setup** (product may shorten for chrome width; meaning fixed).

---

## Forms (v1)

- Labels above; helper text in human language.
- Soft inputs on cream/white; coral focus ring.
- Group by narrative headings on canvas; soft panel only for long settings groups.
- Launch Centre: **no full Settings dump** — visibility as progressive disclosure if PO allows.
- Validation: icon + text; fail loud.
- Sticky save on long mobile forms (B ops).

---

## Mission Control (visual expression only)

**Structure frozen.** Visual recipe:

1. Single soft panel on Warm Cream.
2. Header in editorial weight: short title + one sentence.
3. Next-action strip: Soft Sky wash **inside** the panel (not a nested card).
4. Checklist as clean rows — blocker first; ✔ / ○ or status text; fix links.
5. No illustration inside Mission Control by default (keep ops sober).
6. Guide tone in **copy** (“Here’s what needs you”) — Helper Guide art reserved for empty/first-run elsewhere.

---

## Launch Centre (visual expression only)

**Band order frozen** (`18`). Visual recipe:

| Band | B.5 expression |
| --- | --- |
| Ready to Launch | Flat editorial headline + one sentence (A) |
| Launch checklist | Soft panel **or** flat list with hairline separators — blockers first, fix links (B) |
| Publishing controls | Status narrative; **no second Publish**; unpublish secondary (frozen rule) |
| Aftercare | Soft Sky band when live; Share guidance; Boost secondary (C warmth in copy) |

Ready state: checklist collapsed summary. Needs attention: checklist open. Live: “Your event is live” + aftercare.

---

## Information pages

- Reading width; editorial titles; Soft Sky callouts for tips.
- Tables for operational data; not card grids of paragraphs.
- Help Centre audience boundaries preserved (staff vs vendor).

---

## Publishing

Visual language follows Launch Centre + Hero. Enforcement unchanged (`PublishEligibilityEvaluator`). Organisers see one honest story: readiness checklist + Hero publish.

---

## Success states

| Element | Spec |
| --- | --- |
| Title | Editorial: “Your event is now live” |
| Body | One calm sentence (consequences + next) |
| Actions | Share primary path; View public; Boost secondary |
| Illustration | Optional small Celebrating Guide — max one |
| Motion | Brief enter ≤320ms; reduced-motion = instant |
| Announcement | `aria-live` on feedback region |

---

## Empty states

| Element | Spec |
| --- | --- |
| Composition | Illustration (optional) + title + sentence + one CTA |
| Tone | Helper Guide: “Let’s get started.” |
| Surface | Flat or one soft panel — never three promo cards |
| Honesty | Say what’s empty and what unlocks next |

---

## Illustrations

| Allowed | Not allowed |
| --- | --- |
| Empty states, first-run, publish success | Every Mission Control visit |
| Soft brand style; inclusive figures | Mascot universe, VIP, FOMO, clipart |
| Decorative with text equivalent | Information only in the image |

Max **one** guide moment per screen.

---

## Motion

| Band | Use |
| --- | --- |
| Fast ~120ms | Hover / focus |
| Base ~200ms | Panel expand, row highlight |
| Slow ≤320ms | Success enter, celebrate |
| Reduced motion | Instant state; no meaning-only animation |

Publishing: button `Publishing…` disabled — no decorative spinner-only feedback without text.

---

## 390px example — Launch Centre Ready (B.5)

```text
┌─────────────────────────────────┐
│ ← Events                        │
│ Summer Night Market             │
│ Sat 18 Oct · Fitzroy            │
│ Ready           [ Publish event]│
│ View · Share                    │
├─────────────────────────────────┤
│ Publishing                      │
│                                 │
│ Ready to launch                 │  ← editorial flat
│ You’re ready to go live.        │
│ Guests can discover this event  │
│ and RSVP or buy tickets.        │
│                                 │
│ ┌ Launch checklist ──── All ✔ ┐ │  ← one soft panel
│ │ ✔ Title · ✔ Schedule · …    │ │
│ │ (tap to expand)             │ │
│ └─────────────────────────────┘ │
│                                 │
│ Status                          │  ← flat
│ Ready when you are.             │
│ Use Publish in the header.      │
│                                 │
│ After you publish               │  ← Soft Sky wash ok
│ Share your page · Invite list   │
└─────────────────────────────────┘
```

---

## Desktop example — Home + Mission Control (B.5)

```text
┌─ Event chrome ─────────────────────────────────────────────┐
│ ← Events  Summer Night Market  Live  [ Share event ]  View │
├─ Mission Control (one soft panel) ─────────────────────────┤
│  Here’s what needs you                                     │
│  ┌ Soft Sky next-action ─────────────────────────────────┐ │
│  │ Next: Share with your community                       │ │
│  └───────────────────────────────────────────────────────┘ │
│  ✔ Setup complete · Live · 12 sold                         │
│  ○ Optional: stronger cover for discovery                  │
├─ Body (editorial air + soft ops tiles ≤2–3) ───────────────┤
│  Orders snapshot          Attendees snapshot               │
└────────────────────────────────────────────────────────────┘
```

---

## Accessibility considerations (v1)

- WCAG AA for text and essential UI.
- Focus: coral-family ring, never removed.
- Touch: ≥44×44px.
- Status: colour + text + icon.
- Checklists: semantic lists / links with names.
- Illustrations decorative or dual-encoded in text.
- `prefers-reduced-motion` respected.
- Contrast on pastel Soft Sky / Lavender washes verified for body text.

---

## What B.5 deliberately rejects

| Reject | Why |
| --- | --- |
| Pure A sparsity on order tables | Door night needs command clarity |
| Pure B panel walls | Recreates admin dashboard |
| Pure C illustration-forward ops | Undermines money/publish gravity |
| Dark mode as default | Conflicts with MEL warm cream brand |
| Purple gradient SaaS cliché | Overused; not MEL community warmth |
| Card wallpaper | Explicit PO failure mode |

---

## Adoption rule

**B.5 is Vendor Studio Visual Language v1** (approved). New Workspace pages: **Zone map first** ([07](07-workspace-zones.md)) → reuse catalogue components → apply B.5 → implement. Divergences require DDR / PO — not silent PR restyles.

Implementation mapping: [06-implementation-guide.md](06-implementation-guide.md)  
Component recipes: [04-component-examples.md](04-component-examples.md)  
Before/after: [05-before-after.md](05-before-after.md)  
Composition: [07-workspace-zones.md](07-workspace-zones.md)

---

**Design frozen.** Start code only when PO opens **VL-1**.
