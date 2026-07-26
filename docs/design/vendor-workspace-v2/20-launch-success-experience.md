# Vendor Workspace v2 — Launch Success Experience

**Status:** Approved design direction — Alternative A; implementation completion and final freeze not confirmed

**Date:** 2026-07-25  

**Governance clarification:** Product Owner 2026-07-26; see [PDR-001](../../product-decisions/PDR-001-governance-baseline-authority.md) and [Component Catalogue](23-vendor-component-catalogue.md).
**Goal:** After publish, organiser feels confident, excited, guided — knows what happened and what next.  
**Runtime seed:** `EventStudioPreprocess::buildPublishSuccessHandoff` · shell `renderPublishSuccessFeedback` · `?mel_celebrate=1`

---

## Emotional target

| Feel | Design move |
| --- | --- |
| Confident | Clear “now live” + what guests can do |
| Excited | Warm, brief celebration — not carnival |
| Guided | One recommended next step (Share) |
| Calm | Secondary actions quiet; no pressure Boost |

---

## Non‑negotiables

1. Reuse handoff payload fields: `title`, `message`, `view_url`, `share`, `boost_url`, `calendar_url`.
2. Hero CTA becomes **Share event** immediately.
3. Focus moves to success heading (a11y `19`).
4. Reduced motion: no confetti; static success panel.
5. Boost is **never** the primary next step.

---

## Alternative A — Recommended (calm Launch success)

```text
┌─────────────────────────────────────────────────────────────┐
│  Your event is now live                                     │
│                                                             │
│  People can now:                                            │
│  ✓ discover your event                                      │
│  ✓ RSVP or buy tickets                                      │
│  ✓ share your event                                         │
│                                                             │
│  Recommended next step                                      │
│  [ Share your event → ]                                     │
│                                                             │
│  [ Copy link ]   [ View public page ]                       │
│                                                             │
│  Grow later                                                 │
│  Boost visibility (optional)                                │
└─────────────────────────────────────────────────────────────┘
```

**Why recommended:** Matches sprint example; one narrative; Share primacy; Boost demoted.

---

## Alternative B — Compact toast + inline strip

```text
Toast: Event is live · Share →

Inline strip under Ready band:
Live · Public link ready · [ Copy ] [ Share ]
```

**Use when:** Organiser already on Marketing intent; less interruption.  
**Risk:** Easier to miss next step on mobile.

---

## Alternative C — Full celebrate panel (legacy-aligned)

```text
Large celebrate panel with social icons + calendar + Boost card
(existing mel_publish_celebrate_* / boost CTA patterns)
```

**Use when:** First-ever publish for organiser (onboarding delight).  
**Risk:** Feels heavy on return publishes; Boost competition.

**Rule:** If C used, auto-collapse after first dismiss; subsequent publishes use A.

---

## Alternative D — Guest preview first

```text
Your event is now live
[ Open public page ] as recommended
Share as secondary
```

**Use when:** Visibility was private/passcode — organiser should verify guest view.  
**Not default** for public events.

---

## State variants

| Context | Success treatment |
| --- | --- |
| First publish | Alternative A (+ optional one-time soft motion) |
| Re-publish after unpublish | A, shorter: “You’re live again” |
| Paid + Stripe just connected | A + quiet “Payments ready” note |
| Passcode/private | D hybrid: View public/passcode path + Share |
| Reduced motion | A static |

---

## Copy bank

| Element | Copy |
| --- | --- |
| Title | Your event is now live |
| Re-publish | You’re live again |
| People can | discover your event · RSVP or buy tickets · share your event |
| Next | Share your event |
| Secondary | Copy link · View public page |
| Boost | Optional — Grow visibility later |
| Dismiss | Continue to workspace |

RSVP-only events: “RSVP” not “buy tickets”. Paid-only: “buy tickets”. Both: keep both.

---

## Social / share mechanics

Reuse handoff URLs:

- Facebook / LinkedIn / Twitter(X) intent links from `share`
- Copy uses `view_url` (public absolute URL)
- Calendar optional if `calendar_url` present

Do not invent new social networks in v1 Launch success.

---

## Failure-to-success edge

If publish returns 200 but handoff null (shouldn’t when published): still update Hero + show “Your event is live” with View link from canonical route — fail soft, log already exists server-side patterns.

---

## Measurement (product, not analytics build)

Success of this experience:

- Time-to-first-share after publish
- % who unpublish within 10 minutes (regret signal)
- Support contacts tagged “can’t find publish / not live”

---

## Recommendation

**Ship Alternative A** as Launch Centre default; keep handoff API; demote legacy full celebrate (C) to first-publish optional. Document in implementation strategy `21`.
