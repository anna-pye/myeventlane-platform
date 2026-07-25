# VL-3 remediation re-review

**Date:** 2026-07-25  
**VL-4:** closed  
**Scope:** vendor-theme presentation only (no Twig / resolver / Boost logic)

## Blockers addressed

1. **Hero purple** — theme selectors include shell + workspace + `__actionsitions` + `.mel-btn.mel-btn--primary` so they beat module coral workspace primary (0,6,0 → 0,7,0). No `!important`.
2. **Boost quiet** — new `_mel-event-studio-boost-quiet.scss`: banner soft cream strip; “Boost my event” transparent secondary; closed sidebar overlay no longer paints coral bar.
3. **390 sticky** — below 768px, Hero + action row `position: static` (no large margin hack).

## Computed styles (CDP)

| Control | Event | Result |
| --- | --- | --- |
| Hero primary Share | 1583 | `rgb(107, 70, 255)` purple |
| Hero primary Continue setup | 1761 | `rgb(107, 70, 255)` purple |
| Boost my event | 1583 | `rgba(0,0,0,0)` transparent |
| Boost Active banner | 1583 | `rgb(255, 251, 246)` · shadow none |
| Topbar position @390 | 1583 | `static` · overlap with MC **false** |
| Sidebar overlay[hidden] | 1583 | `display: none` |

## Screenshots

| File | What |
| --- | --- |
| `vl3-rereview-1583-desktop.png` | Live event desktop |
| `vl3-rereview-1583-768.png` | Live event 768 |
| `vl3-rereview-1583-390-top.png` | Live event 390 Identity + quiet Boost |
| `vl3-rereview-1583-390-mc.png` | Live event 390 MC after scroll (no Hero cover) |
| `vl3-rereview-1761-desktop.png` | Draft blockers — purple Continue setup |
| `vl3-rereview-1761-checklist.png` | Checklist blockers + completed |
| `vl3-rereview-1761-focus.png` | Hero primary with forced `:focus-visible` |

## Gate table (remediation)

| Gate | Verdict |
| --- | --- |
| Hero hierarchy | Pass — one purple filled primary |
| Boost hierarchy | Pass — no competing filled coral CTA; banner recessed |
| Zone order | Pass — Identity → quiet Boost strip → Guidance → Work |
| Mobile | Pass — sticky disabled &lt;768; no Hero/MC overlap |
| Checklist | Pass — attention blockers + success rows (1761) |
| Focus | Pass — coral outline via `:focus-visible` (CSS.forcePseudoState) |
| CSS | Pass — specificity win; no `!important` |

**VL-3 remains on hold for PO visual lock** — remediation evidence ready.
