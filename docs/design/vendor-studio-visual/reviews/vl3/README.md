# VL-3 visual review evidence

**Page:** `https://vendor.myeventlane.ddev.site/vendor/events/1583/studio`  
**State:** Live / published event (Mission Control success · next action “Share your event”)  
**Date:** 2026-07-25  
**VL-4:** not opened

## Screenshots

| Viewport | File |
| --- | --- |
| Desktop | `vl3-workspace-home-desktop.png` |
| 768px | `vl3-workspace-home-768.png` |
| 390px (top) | `vl3-workspace-home-390-top.png` |
| 390px (scrolled) | `vl3-workspace-home-390-scrolled.png` |
| Desktop (earlier) | `vl3-workspace-home-desktop-1440.png` |

## CDP measurements (authoritative for colour/structure)

| Surface | Result |
| --- | --- |
| Canvas | `rgb(255, 247, 238)` Warm Cream |
| MC panel | white · elevation-1 · 20px radius · collapsed **~217–238px** |
| Soft Sky `__next` | `rgb(234, 244, 255)` · **no shadow** · inside panel |
| MC CTA | secondary · white fill |
| Hero primary Share | **`rgb(194, 65, 50)` coral** (not `#6B46FF`) |
| Boost my event | **`rgb(194, 65, 50)` coral** |
| Nested `.mel-card` in MC | **0** |
| VL-3 `!important` | **none** |
| 390 overflow-x | **false** |
| 390 sticky Hero | `position:sticky; z-index:20; height ~405px` — **overlaps MC** while scrolled |

## Specificity root cause

Module (`mel-event-studio-shell.css`):

`.mel-vendor .mel-vendor-console.mel-vendor-shell .mel-event-studio--workspace .mel-btn.mel-btn--primary` → `#c24132`

beats theme VL-2:

`.mel-vendor .mel-event-studio-topbar__actions .mel-btn--primary` → purple

and also beats module Boost demotion rules (lower specificity).

## Gate summary

| Gate | Verdict |
| --- | --- |
| Single surface | Pass (structure) |
| Zone hierarchy | Fail / weak — Boost Active + coral rail between Identity and Guidance; sticky Hero covers MC at 390 |
| Next-action strip | Pass (Soft Sky inside panel) |
| CTA weight | Fail — Hero not purple; coral Boost competes |
| Checklist | Pass (structure; success state only on this event) |
| Density | Pass collapsed MC; page still feels tall from Boost + sticky Hero |
| Mobile | Mixed — no overflow; sticky Hero overlap |
| Accessibility | Incomplete — coral `:focus-visible` in CSS; not proven via keyboard capture |
| CSS stability | Fail — theme loses to module without `!important` |

**Recommendation:** Hold VL-3 product lock. Do not open VL-4 until Hero purple wins on specificity and competing coral-filled Workspace primaries are demoted, then re-shot.
