# Vendor Workspace v2 — Mission Control (Ideal Workspace)

**Status:** Product design philosophy (Sprint 1B) — documentation only  
**Date:** 2026-07-25  
**Authority:** PDS 01 · 12 · 13 · 14 · 18 · `04-mission-control-model.md` · wireframes `09`  
**Role:** Ideal experience definition for Event Workspace Home  

This document describes the **ideal** Workspace organisers should feel. It does not invent a parallel builder. Runtime seeds remain in `EventWorkspaceOverviewBuilder` and `VendorEventWorkspaceViewModelBuilder`.

---

## The five-second test

An organiser opens an event. Within **five seconds**, without scrolling past the first meaningful band, they can answer:

| Question | Ideal answer source |
| --- | --- |
| **Where am I?** | Event name · status · “Event Workspace” context (not Drupal, not “Studio vs Manager”) |
| **How healthy is my event?** | Health strip: status · readiness · payments/Stripe honesty |
| **What needs attention?** | Today’s Focus narrative (one story) |
| **What should I do next?** | Single primary CTA (chrome + Focus aligned) |
| **How close am I to success?** | Lifecycle-appropriate progress (setup % **or** sales/door pulse **or** closure metrics) |
| **How do I run this event?** | Obvious path to Tickets · Attendees/Door · Orders · Messages |

If any answer requires hunting through equal-weight cards, Mission Control has failed.

---

## Design around confidence — not administration

| Confidence looks like | Administration looks like |
| --- | --- |
| “You’re ready — publish when you are” | Form dump of every field |
| “42 sold — Early nearly full” | Raw Commerce variation tables on Home |
| “Open Door Mode” | Nested local tasks and dual shells |
| Calm empty sales | Red error empty states for zero |
| One next step | Toolbars of peer actions |

**Emotional target (PDS 14):** warm, capable, local, calm, honest — premium through restraint.

---

## Ideal Workspace anatomy

```text
Identity (chrome)
    ↓
Today’s Focus (narrative + primary)
    ↓
Health (status · readiness · money capability)
    ↓
Run this event (pulse rows)
    ↓
Quiet growth + activity
    ↓
Setup / publishing / settings (muted unless Draft/Ready)
```

### Block contracts

#### 1. Identity

- Event name, date clarity, venue short, status badge  
- One primary CTA slot; Share/View secondary  
- Answers **Where am I?**

#### 2. Today’s Focus

- Single sentence in organiser language  
- One button matching chrome primary (or explaining why chrome differs briefly)  
- Unifies today’s dual mental models: VM `todays_focus` + Studio `next_action`  
- Answers **What needs attention?** and **What next?**

#### 3. Health

- Status · Readiness (honest) · Stripe/payments signal when relevant  
- Never decorative green  
- Answers **How healthy?**

#### 4. Success proximity

| Lifecycle | Proximity cue |
| --- | --- |
| Draft / Ready | “3 of 8 ready” |
| Published / Selling | Sales / capacity pulse |
| Upcoming | Guest prep completeness |
| Live | Checked-in / remaining |
| Completed | Final totals + open refunds |

#### 5. Run this event

Operational pulse rows — Tickets, Attendees, Orders, Messages — depth on click. Not six nested marketing cards.

#### 6. Growth (quiet)

Marketing/Analytics present but visually secondary while Selling; demoted when Completed; primary only when Published (first share).

#### 7. Activity

Grouped, factual, scannable — not a social feed.

#### 8. Help

Contextual, Australian English, organiser audience — appears beside blockers, not as a tour modal wall.

---

## Relationship to Dashboard

| Dashboard Foundation | Event Mission Control |
| --- | --- |
| Portfolio attention | This event attention |
| Action queue across events | Today’s Focus for one event |
| Business health strip | Event health strip |
| “Today’s event / Next up” | Deep link **into** Workspace Focus |

**Rule:** Never clone the Dashboard queue onto Workspace Home. Cross-link with clear scope labels (“Across your events” vs “This event”).

---

## Confidence principles (normative for Home PRs)

1. **One story above the fold** — Focus band owns the narrative.  
2. **One primary action** — Chrome and Focus agree.  
3. **Honesty over cheerleading** — Readiness and money tell the truth.  
4. **Lifecycle reweight, don’t rebuild** — Same shell.  
5. **Stress path privilege** — Live/Door gets ruthlessly simple UI.  
6. **Empty is success when appropriate** — Zero sales after publish is calm, not failure.  
7. **Hide MEL history** — No Studio/Manager fork in copy or chrome.  
8. **Accessibility is confidence** — Focus order, contrast, non-colour severity, reduced motion (PDS 07/08).

---

## Success criteria (Mission Control)

Measurable intent (aligns PDS 18; exact instrumentation later):

1. Time-to-identify next step on Home &lt; 5s (moderated usability).  
2. Publish blockers always show recovery.  
3. Live → Door Mode ≤ 2 taps.  
4. No dual-product navigation in organiser path.  
5. Dashboard item and Workspace Focus never contradict without scoped explanation.

---

## Runtime extension map

| Ideal block | Extend |
| --- | --- |
| Identity | Topbar + VM `event` |
| Focus | Presentation unify `todays_focus` + `resolveNextRecommendedAction()` |
| Health | Readiness facade + Stripe gate + status |
| Pulse | Flatten overview cards to rows |
| Activity | Overview activity |
| Door | Attendees entry + Door Mode route (chrome continuity) |

**Do not** create `VendorWorkspaceV2MissionControlBuilder` as a parallel system.

---

## Ideal day-in-the-life vignette

> Jordan opens **Summer Night Market**. In one glance: On sale, Ready, Stripe OK. Focus says tickets are moving and Early is nearly full. Primary: View orders. Pulse shows attendees expected and a message draft. Jordan adjusts capacity in Tickets, shares once from Marketing, and on the night taps Open Door Mode from the same Workspace — never learning that MEL once had two products.

That vignette is the design bar for Foundation implementation.
