# Vendor Studio Visual Language — Component Examples

**Sprint:** VS1  
**Status:** Design recipes (documentation only) — no CSS  
**Date:** 2026-07-25  
**Authority:** [03-option-b5.md](03-option-b5.md) · PDS `05` · brand tokens  
**Note:** Examples describe **visual intent**. Class names below are illustrative of existing MEL patterns where known; implementers verify in theme/module before changing runtime.

---

## How to read these recipes

Each example lists: **purpose · surface · hierarchy · do · don’t**.  
Architecture (routes, eligibility, CTA ownership) is unchanged.

---

## 1. Page canvas

| | |
| --- | --- |
| **Purpose** | Warm session environment |
| **Surface** | Warm Cream full-bleed under shell |
| **Hierarchy** | Canvas quieter than panels quieter than primary CTA |
| **Do** | Keep cream continuous; allow Soft Sky only as intentional bands |
| **Don’t** | Switch to cool grey admin `#f5f5f5` or pure white clinical canvas |

---

## 2. Soft panel

| | |
| --- | --- |
| **Purpose** | Group operational content (Mission Control, checklist, settings group) |
| **Surface** | Lifted cream/white · radius-lg · shadow-sm or soft 1px warm border |
| **Hierarchy** | One panel = one job |
| **Do** | Internal headings + spacing; Soft Sky strip inside for next-action |
| **Don’t** | Nest a soft panel inside another soft panel |

**ASCII**

```text
┌─ Soft panel ─────────────────────────────────────────┐
│  Title (H3)                                          │
│  Supporting sentence                                 │
│  ┌ Soft Sky strip ─────────────────────────────────┐ │
│  │ Next action line                                │ │
│  └─────────────────────────────────────────────────┘ │
│  Content rows…                                       │
└──────────────────────────────────────────────────────┘
```

---

## 3. Flat narrative band

| | |
| --- | --- |
| **Purpose** | Launch “Ready to launch”, help intros, aftercare prose |
| **Surface** | Flat on canvas — **no** card chrome |
| **Hierarchy** | Editorial title → one sentence → optional links |
| **Do** | Use Reading width; generous top/bottom space |
| **Don’t** | Wrap every paragraph in a panel |

---

## 4. Hero chrome (visual only)

| | |
| --- | --- |
| **Purpose** | Where am I · status · **one** primary CTA · View |
| **Surface** | Calm chrome on cream; status chip quiet; primary purple button |
| **Hierarchy** | Event name strong; meta quieter; CTA strongest interactive |
| **Do** | Soften borders; increase title clarity; keep single primary |
| **Don’t** | Add gradient theatre, badge stickers on media, or second Publish |

Frozen: CTA resolver behaviour, Share/Publish/Continue modes, layout role.

---

## 5. Mission Control panel

| | |
| --- | --- |
| **Purpose** | What needs me · next useful action |
| **Surface** | One soft panel |
| **Hierarchy** | Next-action strip → blockers → done items quieter |
| **Do** | Human copy; fix links; scannable rows |
| **Don’t** | Redesign into multi-card dashboard; add Guide illustration by default |

---

## 6. Launch checklist row

| | |
| --- | --- |
| **Purpose** | Honest readiness item + fix path |
| **Surface** | Row inside checklist panel or flat list |
| **Hierarchy** | Incomplete first; complete muted |
| **Do** | Status text + optional icon; link “Fix” to section |
| **Don’t** | Raw Drupal error strings as the only UI; colour-only status |

```text
○  Payments need connecting          Fix →
✔  Event title
```

---

## 7. Primary / secondary / danger buttons

| Kind | Visual | When |
| --- | --- | --- |
| Primary | Purple fill, white label | Hero authoritative only for go-live verbs |
| Secondary | Outline or soft | View, Preview, Marketing |
| Tertiary | Text / quiet | Progressive disclosure |
| Danger | Ghost + danger text | Unpublish — confirm first |

Touch ≥44px. Loading: visible label change (“Publishing…”), not spinner alone.

---

## 8. Status chip

| | |
| --- | --- |
| **Purpose** | Draft · Needs attention · Ready · Live · Past |
| **Surface** | radius-sm/pill-spare; soft fill + label |
| **Do** | Pair with plain language in nearby sentence |
| **Don’t** | Rainbow chip clusters; Discovery Gold as “warning” |

---

## 9. Form field group

| | |
| --- | --- |
| **Purpose** | Edit event data without CMS feel |
| **Surface** | Flat group under heading; soft panel only for long Settings |
| **Do** | Label above; helper sentence; coral focus |
| **Don’t** | Fieldset boxes with heavy borders; admin vertical tabs aesthetic |

---

## 10. Data table / list (orders, attendees)

| | |
| --- | --- |
| **Purpose** | Operational scanning |
| **Surface** | Flat table or soft-panel wrapped table — one wrapper max |
| **Do** | Tabular numbers; clear row hover/focus; sticky header if long |
| **Don’t** | Each row as its own elevated card on desktop |

Mobile: stacked row cards **allowed** for touch scanning — still one elevation level.

---

## 11. Metric tile (dashboard)

| | |
| --- | --- |
| **Purpose** | Decision support (≤4) |
| **Surface** | Flat or shadow-sm; large number + quiet label |
| **Do** | Answer a question (“Tickets sold tonight”) |
| **Don’t** | Decorative chart walls; >4 equal tiles |

---

## 12. Info / aftercare band

| | |
| --- | --- |
| **Purpose** | “What happens when you publish” / live share guidance |
| **Surface** | Soft Sky wash — flat or single soft panel |
| **Do** | Short prose + 1–2 links |
| **Don’t** | Boost as primary; FOMO urgency |

---

## 13. Success handoff

| | |
| --- | --- |
| **Purpose** | Post-publish confidence |
| **Surface** | Flat editorial title + optional small Celebrating illustration + action row |
| **Do** | `aria-live` announcement; Share path clear |
| **Don’t** | Full-screen confetti blocking ops; dual primary CTAs |

---

## 14. Empty state

| | |
| --- | --- |
| **Purpose** | First-run or zero-data honesty |
| **Surface** | Flat or one soft panel |
| **Composition** | Optional illustration · title · sentence · one CTA |
| **Do** | Helper Guide tone |
| **Don’t** | Three upsell cards; empty grey Drupal placeholder |

---

## 15. Confirm modal (unpublish)

| | |
| --- | --- |
| **Purpose** | Sober consequence check |
| **Surface** | Elevated modal (shadow-lg); calm, not red panic wallpaper |
| **Do** | State consequences for guests; confirm + cancel |
| **Don’t** | One-click unpublish from primary Hero |

---

## 16. Section navigation

| | |
| --- | --- |
| **Purpose** | Where am I in Event Workspace |
| **Surface** | Quiet underline or soft pill for active — lavender/purple scarce |
| **Do** | Publishing emphasised when on Launch Centre |
| **Don’t** | Heavy tab bars that feel like Drupal vertical tabs |

---

## Component budget cheat-sheet

| Screen | Soft panels (target) | Flat bands | Illustrations |
| --- | --- | --- | --- |
| Home + Mission Control | 1 (MC) + ≤3 body | Hero chrome | 0 |
| Launch Centre Ready | 0–1 (checklist) | 3 narrative | 0 |
| Launch Centre Live | 0–1 | Aftercare Sky | 0–1 success |
| Orders list | 0–1 wrapper | — | 0 |
| Empty events | 0–1 | — | 0–1 |
| Settings long form | 1–3 groups | Intro flat | 0 |

---

**Next:** [05-before-after.md](05-before-after.md)
