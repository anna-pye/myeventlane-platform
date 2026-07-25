# Vendor Workspace v2 — Publishing State Model

**Status:** Product design (Sprint 3B) — documentation only  
**Date:** 2026-07-25  
**Authority:** Runtime discovery `15` · audit `16` · lifecycle `08` · CTA resolver `EventWorkspaceOverviewBuilder::resolveAuthoritativePrimaryCta`  
**Frozen:** Mission Control

---

## Principles

1. Shell membership constant; Publishing section emphasis changes by state.
2. **One authoritative primary CTA** (Hero). Publishing page never introduces a second primary of the same verb.
3. Readiness (display) ≠ Eligibility (enforcement) — organisers see one honest story.
4. Australian English; no CMS vocabulary (“node”, “moderation”) in UI.

---

## Authoritative CTA model (Stage 7)

| State key | Primary CTA label | Mode | Destination / behaviour |
| --- | --- | --- | --- |
| Draft / Needs attention | **Continue setup** | link | Deep-link first blocker (prefer section URL; Publishing checklist item) |
| Ready | **Publish event** | publish | Hero AJAX publish only — card becomes status, not second Publish |
| Publishing (transient) | **Publishing…** | disabled | Same control; loading |
| Published / Live | **Share event** | link | Marketing workspace (existing) |
| Past | **View attendees** | link | Attendees workspace |
| Closed | **Duplicate event** | link | Duplicate flow **if** route exists; else **View event** until confirmed |
| Unpublished again | **Continue setup** or **Publish event** | per readiness | Same matrix as Draft/Ready |

**Runtime today:** labels are `Continue setup` / `Publish` / `Share` — product design lengthens to **Publish event** / **Share event** for clarity (implementation may keep short labels if chrome width demands; meaning must match).

**Rules:**

- No competing Publish buttons on Launch Centre.
- When Ready, Mission Control (Home) may keep “Use Publish in the header” hint — **do not** add a second Publish on Publishing page.
- Stripe Connect exception remains Mission Control / Home only (approved Foundation behaviour) — Launch Centre surfaces Stripe as a checklist blocker with Connect link, not a second Hero.

---

## State catalogue

### 1. Draft

| Dimension | Spec |
| --- | --- |
| **Meaning** | Event exists; unpublished; setup incomplete or not yet reviewed |
| **User expectation** | “I can leave and come back; nothing is public” |
| **Allowed actions** | Edit sections; preview if meaningful; open Launch Centre; cannot successfully publish |
| **Primary CTA** | Continue setup |
| **Secondary** | Preview · View Launch checklist |
| **Transitions** | → Needs attention (same, with errors) · → Ready when `readiness.ready` |
| **Evidence** | `node->isPublished() === FALSE`; `EventReadinessResult->ready === FALSE` |

### 2. Needs attention

| Dimension | Spec |
| --- | --- |
| **Meaning** | Explicit incomplete readiness — blockers present |
| **User expectation** | Clear list of what’s wrong + how to fix |
| **Allowed actions** | Fix via deep links; Launch Centre checklist interactive |
| **Primary CTA** | Continue setup (to first blocker) |
| **Secondary** | Open any incomplete item |
| **Transitions** | → Ready when errors empty; stays if eligibility still fails (vendor/Stripe) |
| **Evidence** | `readiness->errors !== []`; eligibility `reason: readiness|vendor_denied|stripe` |

### 3. Ready

| Dimension | Spec |
| --- | --- |
| **Meaning** | Eligibility would allow publish; still unpublished |
| **User expectation** | Calm confidence — “ready when you are”; understand public consequences |
| **Allowed actions** | Publish (Hero); preview public page; review visibility (secondary) |
| **Primary CTA** | Publish event |
| **Secondary** | Preview · Who can find this? (disclosure) |
| **Transitions** | → Publishing (transient) → Published; or leave as Ready |
| **Evidence** | `readiness->ready === TRUE` && unpublished; eligibility `allowed: true` |

### 4. Publishing

| Dimension | Spec |
| --- | --- |
| **Meaning** | Transient UI/network state during POST |
| **User expectation** | Something is happening; no double-submit |
| **Allowed actions** | None until response |
| **Primary CTA** | Publishing… (disabled) |
| **Secondary** | None |
| **Transitions** | → Published (200) · → Needs attention / Ready (422/409) · → Failure with retry |
| **Evidence** | Shell `is-publishing`; no durable Drupal state |

### 5. Published (Live)

| Dimension | Spec |
| --- | --- |
| **Meaning** | Node published; discoverable per visibility rules |
| **User expectation** | Guests can find/RSVP/buy per configuration; edits update live |
| **Allowed actions** | Share; view public; edit sections; unpublish (secondary, confirmed) |
| **Primary CTA** | Share event |
| **Secondary** | View public page · Marketing · Unpublish |
| **Transitions** | → Needs attention if readiness regresses while live · → Draft via unpublish · → Past when event ended |
| **Evidence** | `node->isPublished() === TRUE`; moderation `published` when field present |

### 6. Past

| Dimension | Spec |
| --- | --- |
| **Meaning** | Event end datetime in the past; still published or unpublished |
| **User expectation** | Ops shift to attendees/aftermath — not “launch” |
| **Allowed actions** | View attendees; messages; analytics; unpublish/archive later |
| **Primary CTA** | View attendees |
| **Secondary** | View public page · Duplicate (if available) |
| **Transitions** | → Closed (product language) when organiser marks complete / archive future |
| **Evidence** | Lifecycle `08`; date fields — Launch Centre must detect past for copy (confirm field usage at implementation from `field_event_end`) |

### 7. Closed

| Dimension | Spec |
| --- | --- |
| **Meaning** | Event intentionally wound down (product state; may map to unpublished + past or future archive flag) |
| **User expectation** | No new guests; historical record preserved |
| **Allowed actions** | View attendees; duplicate; limited edit |
| **Primary CTA** | Duplicate event (or View attendees if duplicate unavailable) |
| **Secondary** | Analytics · Support |
| **Transitions** | Terminal for this occurrence |
| **Evidence** | **Partial today** — no first-class “closed” flag confirmed in Studio publish path. Product state for Launch Centre; implementation must not invent fields — map to unpublished+past until archive exists |

### 8. Future (scheduled event date)

| Dimension | Spec |
| --- | --- |
| **Meaning** | Published (or Ready) with start date in the future |
| **User expectation** | “Live page now; event happens later” — **not** “will auto-publish later” |
| **Allowed actions** | Share; edit; unpublish |
| **Primary CTA** | Share event (if published) or Publish event (if Ready) |
| **Secondary** | Preview · Calendar |
| **Transitions** | Same as Published/Ready |
| **Evidence** | Start date future; **no** deferred node publish |

### 9. Archived (future)

| Dimension | Spec |
| --- | --- |
| **Meaning** | Soft-removed from active portfolio; not primary ops |
| **User expectation** | Recoverable history |
| **Allowed actions** | View-only / restore (TBD) |
| **Primary CTA** | View event |
| **Secondary** | Restore (future) |
| **Transitions** | Out of Launch Centre scope for Sprint 3 |
| **Evidence** | Vendor archive routes exist elsewhere — **do not** fold into Launch Centre without separate discovery |

---

## Transition diagram

```text
                    ┌──────────────┐
                    │    Draft     │
                    └──────┬───────┘
                           │ blockers
                    ┌──────▼───────┐
              ┌─────│ Needs attention│────┐
              │     └──────┬───────┘     │
              │            │ ready        │
              │     ┌──────▼───────┐     │
              │     │    Ready     │     │
              │     └──────┬───────┘     │
              │            │ publish     │
              │     ┌──────▼───────┐     │
              │     │  Publishing  │     │
              │     └──────┬───────┘     │
              │         ok │             │
              │     ┌──────▼───────┐     │
              └─────│  Published   │◄────┘ (fix → may still be live)
                    │   (Live)     │
                    └──────┬───────┘
                           │ end date / wind-down
                    ┌──────▼───────┐
                    │ Past → Closed│
                    └──────────────┘

  Published ──unpublish──► Draft / Needs attention
```

---

## Launch Centre band emphasis by state

| State | Ready to Launch band | Checklist | Controls | Aftercare |
| --- | --- | --- | --- | --- |
| Needs attention | “Almost there” | Open, blockers first | Hidden / disabled publish | Soft tips |
| Ready | “Ready to launch” | Collapsed summary ✔ | Hero owns Publish | Preview consequences |
| Publishing | “Going live…” | Frozen | Disabled | — |
| Live | “Your event is live” | Collapsed | Share primary; Unpublish secondary | Share guidance |
| Past | “This event has ended” | Hidden | Attendees primary | Aftermath |

---

## Mapping to runtime keys

| Product state | `published` | `readiness.ready` | CTA `key` (today) |
| --- | --- | --- | --- |
| Draft / Needs attention | false | false | `continue_setup` |
| Ready | false | true | `publish` |
| Published / Live / Future-dated live | true | true | `share` |
| Live + regression | true | false | `continue_setup` (fix) |
| Past | true/false | * | Product override → attendees (design; **not** in resolver today) |

**Gap:** Past/Closed CTA override is **design-new** relative to `resolveAuthoritativePrimaryCta` — implementation strategy (`21`) must extend resolver carefully or keep Share until Past detection approved.

---

## Unpublish contract

- Allowed from Live (and Past if still published).
- Always **confirm** (consequences: public page unavailable; tickets/RSVP implications stated honestly).
- No eligibility check.
- After success: state = Draft or Needs attention; CTA returns to Continue setup / Publish event.
- Primary never remains “Unpublish”.
