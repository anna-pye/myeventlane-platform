# Vendor Studio Visual Language — Before / After

**Sprint:** VS1  
**Status:** Documentation only  
**Date:** 2026-07-25  
**Authority:** [03-option-b5.md](03-option-b5.md)  
**Note:** “Before” describes the **felt** admin/SaaS presentation problem — not a claim that architecture is wrong. “After” is Option B.5 visual expression only.

---

## Framing

| Unchanged | Changes |
| --- | --- |
| Routes, eligibility, CTA resolver | Colour atmosphere, spacing, elevation |
| Mission Control structure | Panel chrome, typography, next-action strip |
| Launch Centre band order | Flat narrative vs form dump feel |
| Hero CTA ownership | Softened chrome, clearer type, single primary emphasis |

---

## 1. Overall Studio atmosphere

### Before

```text
Cool/neutral admin canvas feel
Equal-weight bordered boxes
Forms and panels compete
Purple + borders + chips everywhere
“I am configuring a CMS node”
```

### After (B.5)

```text
Warm Cream continuous canvas
Editorial titles + soft panels only where needed
One primary purple action
“I am running my event”
```

---

## 2. Hero

### Before (felt)

- Chrome reads like a toolbar strip.
- Status + multiple similar-weight controls.
- Publish presence can feel duplicated with body cards.

### After (visual only)

- Event name typographically clear; meta quiet.
- One filled primary; View/Share secondary quieter.
- Soft separation from body — not a heavy admin bar.
- **Still:** same CTA modes and resolver authority.

---

## 3. Mission Control

### Before (felt)

- Checklist as admin task list inside generic card stack.
- Next action not visually privileged.
- Can blend into dashboard noise on Home.

### After (visual only)

- Single soft panel.
- Soft Sky next-action strip inside panel.
- Blockers first; human copy; fix links.
- **Still:** readiness truth; frozen structure; no redesign of Home IA.

---

## 4. Launch Centre / Publishing

### Before (felt) — from product audit `16`

```text
Headline
+ raw checklist
+ full Settings form
+ Publish card
= admin publish page
```

Competing Publish affordances; density at 390; weak success journey.

### After (B.5 visual on locked bands)

```text
Ready to launch          (flat editorial)
Launch checklist         (one soft panel / list)
Controls                 (status — Hero owns Publish)
Aftercare                (Soft Sky when live)
```

Settings visibility progressive or Settings-only (per PO).  
**Still:** no second primary Publish; eligibility server-side.

---

## 5. Forms

### Before

- Fieldset-heavy / settings-dump aesthetic.
- Launch path polluted by visibility + danger zone.

### After

- Narrative headings; soft inputs; coral focus.
- Long settings grouped in soft panels on Settings section.
- Launch stays launch.

---

## 6. Cards

### Before

- Cards everywhere; nested surfaces; metric wallpaper.

### After

- Default flat; soft panel = one job; ≤4 metrics; no card-in-card.
- Mobile list rows may card-stack once — not double-wrapped.

---

## 7. Success

### Before

- Thin messenger / handoff; easy to miss “what next.”

### After

- Editorial “Your event is now live” + Share path + optional Celebrating Guide.
- `aria-live`; Boost secondary.

---

## 8. Empty

### Before

- Blank admin empty or cold “No entities.”

### After

- Helper tone + one CTA + optional illustration.
- Honest about what unlocks next.

---

## 9. 390px Publishing — side by side

### Before (compressed)

```text
[Hero Publish]
Title
Checklist strings
Visibility fields…………
Publish now (again)
Danger zone…
(scroll forever)
```

### After

```text
[Hero Publish event]
Ready to launch…
Checklist (one panel)
Status: use header to publish
Aftercare preview
(Settings elsewhere)
```

---

## 10. Desktop Home — side by side

### Before

```text
Hero toolbar
MC card
KPI card  KPI card  KPI card  KPI card
More cards…
```

### After

```text
Hero (calm, one primary)
Mission Control (one soft panel + next-action strip)
≤2–3 ops snapshots with air between
```

---

## Success test (five seconds)

| Question | Before risk | After target |
| --- | --- | --- |
| Where am I? | Toolbar soup | Clear event title + section |
| What needs me? | Buried checklist | MC next-action strip |
| What next? | Competing CTAs | Hero primary only |

---

## Residual non-visual gaps (out of VS1)

Documented elsewhere — not fixed by visual language alone:

- Legacy redirects to Settings vs Publishing (`15` / `16`)
- Past/Closed CTA override not in resolver yet (`17`)
- Scheduled publish deferred

Visual Language v1 still applies when those product items ship.

---

**STOP.** For PO review with [03-option-b5.md](03-option-b5.md).
