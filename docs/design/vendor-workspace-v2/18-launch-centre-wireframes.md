# Vendor Workspace v2 — Launch Centre Wireframes

**Status:** High-fidelity product design (Sprint 3B) — documentation only  
**Date:** 2026-07-25  
**Surface:** Publishing section = **Launch Centre**  
**URL (transitional):** `/vendor/events/{id}/studio/publishing`  
**Authority:** PDS 03 · 05 · 06 · 08 · 13 · 14 · state model `17` · audit `16`  
**Frozen:** Mission Control (Home). This doc does **not** redesign Overview.

---

## Design intent

| Feel | Means |
| --- | --- |
| Calm | One column narrative; generous space |
| Confident | Honest readiness; one launch control |
| Focused | Launch question only — not Settings dump |
| Guided | Checklist with fix links; aftercare after live |
| Premium | Restraint; no FOMO badges |

**Avoid:** Dual Publish buttons · full Settings form on Launch · nested card walls · competing Boost primary.

---

## Page hierarchy (locked)

```text
Workspace chrome (Hero — FROZEN contract)
    ↓
Section: Publishing (Launch Centre)
    ↓
1. Ready to Launch          (status narrative)
    ↓
2. Launch checklist         (readiness items + fix links)
    ↓
3. Publishing controls      (status / confirm — NOT second primary Publish)
    ↓
4. After publishing guidance (only when live, or preview of “what happens”)
```

Hero remains the **only** primary Publish / Share / Continue setup control (`17`).

---

## Shared chrome (all breakpoints)

Identical to Workspace Foundation chrome (`09`):

```text
← Events    {Event name}     [Status]    [ Primary CTA ]  [ View ]
            {Date · Venue}               (one only)       [ Share* ]
```

\* Share secondary when primary is not already Share.

Section nav: Publishing emphasised; Settings available but not the launch path.

---

## A. Desktop (≥1200px) — Ready state

```text
┌─ GLOBAL + EVENT CHROME ─────────────────────────────────────────────────────┐
│ …  Summer Summer Night Market     Ready      [ Publish event ]  [ View ]
│                           Sat 18 Oct · Fitzroy
├─ SECTIONS … Publishing ● … ─────────────────────────────────────────────────┤
│                                                                             │
│  READY TO LAUNCH                                                            │
│  ───────────────                                                            │
│  You’re ready to go live.                                                   │
│  Guests will be able to discover this event and RSVP or buy tickets         │
│  according to your setup.                                                   │
│                                                                             │
│  LAUNCH CHECKLIST                                                    All ✔  │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ ✔ Event title     ✔ Schedule     ✔ Booking mode     ✔ Tickets       │   │
│  │ ✔ Payments        ✔ Organiser profile     ✔ Cover (optional done)   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│  (collapsed by default when Ready — expand to review)                       │
│                                                                             │
│  LAUNCH CONTROL                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ Status: Ready · not live yet                                        │   │
│  │ Use Publish event in the header when you are ready.                 │   │
│  │                                                                     │   │
│  │ Who can find this?  Public ▾     [Preview public page]              │   │
│  │ (progressive — not a full settings form)                            │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  AFTER YOU LAUNCH                                                           │
│  You’ll be able to share your link, track RSVPs or sales, and edit         │
│  anytime. Updates appear on the public page when you save.                 │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## B. Desktop — Needs attention

```text
│  READY TO LAUNCH                                                            │
│  Almost there — 2 things left before you can launch.                        │
│                                                                             │
│  LAUNCH CHECKLIST                                                           │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ ○ Add at least one active paid ticket          [ Fix → Tickets ]    │   │
│  │ ○ Connect Stripe before publishing             [ Connect Stripe ]   │   │
│  │ ✔ Event title   ✔ Schedule   ✔ Booking mode                         │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  LAUNCH CONTROL                                                             │
│  Publish is unavailable until the checklist is clear.                       │
│  Header shows Continue setup → first blocker.                               │
```

---

## C. Desktop — Live (post-publish)

```text
│  … Status: Live …     [ Share event ]  [ View ]                             │
│                                                                             │
│  READY TO LAUNCH → becomes YOUR EVENT IS LIVE                               │
│  People can discover, RSVP or buy, and share — per your visibility.         │
│                                                                             │
│  LAUNCH CHECKLIST — collapsed “Setup complete”                              │
│                                                                             │
│  NEXT                                                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ Recommended: Share your event                                       │   │
│  │ [ Copy link ]  [ Share on Facebook ]  [ LinkedIn ]  [ Post ]        │   │
│  │                                              [ Open Marketing → ]   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  SECONDARY                                                                    │
│  [ Boost visibility ] (quiet) · [ Unpublish… ] (ghost, confirm)             │
```

---

## D. Tablet (~768px)

```text
┌─ CHROME (primary CTA remains in header) ────────────────┐
│ Event name · Ready · [ Publish event ] · ⋮              │
├─ Section drawer / horizontal scroll ─ Publishing ● ─────┤
│                                                         │
│ Ready to Launch                                         │
│ Checklist (full width cards, one item per row)          │
│ Launch control (hint to header — no second Publish)     │
│ After guidance                                          │
└─────────────────────────────────────────────────────────┘
```

- Checklist items become stacked rows with trailing fix buttons (≥44px).
- Visibility disclosure as single select row.
- No sticky duplicate Publish unless header scrolls away — then sticky bar mirrors **same** Hero CTA only.

---

## E. Mobile 390px

```text
┌────────────────────────────┐
│ ← Events                   │
│ Summer Night Market        │
│ Ready                      │
│ Sat 18 Oct · Fitzroy       │
├────────────────────────────┤
│ Ready to launch            │
│ You’re ready to go live.   │
│ Guests can discover…       │
├────────────────────────────┤
│ Checklist            All ✔ │
│ (tap to expand)            │
├────────────────────────────┤
│ Launch                     │
│ Use Publish in the bar →   │
│ Who can find this? Public  │
│ Preview public page        │
├────────────────────────────┤
│ After you launch           │
│ Share · track · edit…      │
├────────────────────────────┤
│ ┌────────────────────────┐ │
│ │   Publish event        │ │  ← sticky bottom = Hero primary only
│ └────────────────────────┘ │
└────────────────────────────┘
```

**Rules @390:**

- One sticky primary matching Hero key.
- Checklist collapsed when Ready; open when Needs attention.
- No Boost above Share after live.
- Unpublish behind “More” / secondary text link — not sticky.

---

## F. Publishing in progress (all breakpoints)

```text
Primary CTA: Publishing…
Checklist frozen
Optional inline: “Going live — this usually takes a moment.”
No other actions
```

---

## G. Component inventory (reuse, don’t invent)

| Band | Reuse |
| --- | --- |
| Chrome / Hero CTA | Existing topbar + `resolveAuthoritativePrimaryCta` |
| Ready narrative | New presentation props from existing readiness/publish flags |
| Checklist | Same readiness items as Mission Control / hub — **presentation only** |
| Launch control hint | Align with MC publish hint pattern (“Use Publish in the header”) |
| Visibility | Slim subset of `EventSettingsForm` visibility — or link to Settings |
| Success / share | Extend `buildPublishSuccessHandoff` presentation (`20`) |
| Buttons | `.mel-btn` / `.mel-btn--primary` / `--ghost` |

**Do not** add a new eligibility service or parallel checklist calculator.

---

## H. CTA ownership on this page

| State | Page body primary? | Body copy |
| --- | --- | --- |
| Needs attention | No — Hero Continue setup | Fix links only |
| Ready | No second Publish | “Use Publish event in the header…” |
| Live | Share actions OK as **recommended next** but Hero owns Share | Match labels |
| Past | Link to Attendees | No Publish |

---

## I. Anti-patterns (explicit)

1. Embedding full `EventSettingsForm` on Launch Centre.
2. Card button labelled Publish now beside Hero Publish.
3. Equal-weight Boost + Share.
4. Green “ready” while eligibility would fail.
5. Redesigning Mission Control to compensate for Launch Centre.

---

## J. Wireframe alternatives (Ready band copy)

**A (calm):** “You’re ready to go live.”  
**B (action):** “Launch when you’re ready.”  
**C (guest-centred):** “Guests can find this event as soon as you publish.”

**Recommendation:** A + one guest-centred supporting sentence (shown in desktop wireframe).
