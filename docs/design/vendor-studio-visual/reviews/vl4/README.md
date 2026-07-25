# VL-4 — Launch Centre visual review

**Date:** 2026-07-25  
**Status:** 🔒 **APPROVED AND LOCKED** (Product Owner 2026-07-25)  
**Scope:** Vendor-theme presentation only (`_mel-event-studio-launch-centre.scss`)  
**Next:** VL-5 may open — Launch Success Alternative A + shared outcome-state first

---

## Zone map

```text
Identity:   Hero — unchanged and frozen (VL-2)
Guidance:   Mission Control — unchanged and frozen (VL-3)
Work:       Launch Centre — visual refresh (this sprint)
Outcome:    Not implemented in VL-4 (Launch Success → 3C.2 / VL-5)
```

---

## Events used

| State | Event | Notes |
| --- | --- | --- |
| Needs attention | **1761** | Checklist open; blockers + completed rows |
| Ready | **1594** | Unpublished + ready; checklist collapsed |
| Live | **1583** | Published; aftercare “While your event is live” |

No event data was mutated for screenshots.

---

## Screenshots

| File | What |
| --- | --- |
| `vl4-needs-desktop-full.png` | Needs attention — desktop full page |
| `vl4-needs-desktop.png` / `vl4-needs-desktop-launch.png` | Needs attention — desktop crops |
| `vl4-needs-390-top.png` | Needs attention — 390 Identity |
| `vl4-needs-390-launch.png` | Needs attention — 390 Launch Centre |
| `vl4-ready-desktop.png` | Ready — desktop |
| `vl4-ready-768.png` | Ready — 768 |
| `vl4-ready-390.png` | Ready — 390 |
| `vl4-live-desktop.png` | Live — desktop |
| `vl4-live-390.png` | Live — 390 |
| `vl4-live-visibility-expanded.png` | Live — visibility disclosure open |
| `vl4-focus-visibility-summary.png` | Coral `:focus-visible` on visibility summary |
| `vl4-focus-fix-link.png` | Coral `:focus-visible` on checklist Fix link |

---

## Computed styles (CDP)

| Control | Event | Result |
| --- | --- | --- |
| Page / shell canvas | 1761 | `rgb(255, 247, 238)` Warm Cream |
| Hero primary Continue setup | 1761 | `rgb(107, 70, 255)` purple fill |
| Hero primary Publish | 1594 | `rgb(107, 70, 255)` purple fill |
| Hero primary Share | 1583 | `rgb(107, 70, 255)` purple fill |
| Checklist surface (open) | 1761 | white · soft border · `shadow-sm` |
| Checklist (collapsed ready/live) | 1594 / 1583 | transparent · no shadow |
| Visibility root | all | transparent · hairline top · no shadow |
| Visibility summary height | all | ≥57px |
| Aftercare background | all | `rgb(234, 244, 255)` Soft Sky |
| Fix action | 1761 | transparent bg · purple text · not filled primary |
| Focus outline (forced `:focus-visible`) | 1761 / 1583 | coral `#FF6B4A` + 3px coral ring |
| Publishing section `.mel-es-card` | publishing | border 0 · shadow none (flattened) |
| Wizard steps nav in visibility | 1583 | `display: none` |

Approximate Launch Centre heights: needs ~1175px (open checklist + aftercare preview); ready ~584px; live ~564px.

---

## CSS authority notes

1. Module shell (`mel-event-studio-shell.css`) still owns baseline Launch Centre rules; vendor theme overrides under `.mel-vendor` / `.mel-event-studio--workspace` without `!important`.
2. Publishing section wrapper `.mel-event-studio-section.mel-es-card` was painting cool admin card chrome around Work — flattened for `data-current-section-id='publishing'` only (mirrors branding flatten pattern).
3. Visibility form reuses wizard form classes and injected `mel-event-studio-wizard-nav` (including a coral **Publish** step link). Hidden inside Launch Centre visibility body only — form submit / radios unchanged.

---

## Accessibility (Axe on `[data-mel-launch-centre]`)

| Result | Detail |
| --- | --- |
| Passes | 21 |
| Color contrast | Cleared after removing completed-row opacity fade |
| Remaining | `landmark-complementary-is-top-level` (aftercare `<aside>` inside region) · `landmark-no-duplicate-banner` — **pre-existing Twig/landmarks**; out of VL-4 Twig freeze |

Touch / focus: summaries ≥44px; Fix ≥44px; coral focus confirmed via `CSS.forcePseudoState`.

---

## Gate table

| Gate | Verdict |
| --- | --- |
| Zone order Identity → Guidance → Work | Pass |
| Flat Ready narrative | Pass |
| One checklist soft surface | Pass |
| Visibility quiet / tertiary | Pass |
| Soft Sky aftercare (not Launch Success) | Pass |
| One purple Hero primary | Pass |
| No LC action matching Hero primary | Pass |
| Wizard Publish nav suppressed in LC | Pass |
| Card budget | Pass (section chrome flattened; one checklist panel) |
| 390 no overflow · sticky off | Pass |
| Axe contrast on completed rows | Pass |

**PO decision:** VL-4 **READY TO LOCK** → **LOCKED**. VL-5 may open under the published boundary (Launch Success first).

### Non-blocking follow-ups (do not reopen VL-4)

1. Nested/duplicate landmarks — later Twig a11y.
2. `EventLaunchVisibilityForm` should stop inheriting wizard presentation classes (tech debt).
3. Product: whether aftercare preview helps while blocked (ViewModel/content).
4. Before commit/PR: reconcile branch vs `origin/main` (3 merge commits behind); do not blind rebase/merge over cumulative uncommitted VL work.
