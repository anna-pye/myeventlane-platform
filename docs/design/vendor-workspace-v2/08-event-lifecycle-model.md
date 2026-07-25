# Vendor Workspace v2 — Event Lifecycle Model

**Status:** Architecture / product design (Sprint 1B) — documentation only  
**Date:** 2026-07-25  
**Authority:** PDS 01 · 13 · 15 · DDR-002 · prepared DDR-008/009  
**Extends:** `02-organiser-journey.md` · `03-workspace-state-model.md`  

Shell membership is constant. Only emphasis, CTA content, warnings, and metrics prominence change.

Lifecycle used:

```text
Draft → Ready → Published → Selling → Upcoming → Live → Completed
```

PDS 13’s Draft → Ready → Published/Live → Door → Aftermath → Archive maps here: Door ⊂ Live; Aftermath/Archive ⊂ Completed.

---

## Cross-state invariants

| Invariant | Rule |
| --- | --- |
| Shell | Global nav + event chrome + section nav always present |
| One primary CTA | Event chrome holds exactly one primary action |
| Honesty | Readiness never green without publish capability; money never invented |
| Scope | Workspace = this event; Dashboard = portfolio |
| Language | Australian English; no Commerce/CMS vocabulary in organiser UI |
| Help | Available, non-blocking; audience boundaries preserved |
| Autosave | Never publishes; never money-commits |

---

## 1. Draft

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Capture identity and close every session knowing the next setup step |
| **Hero** | Event name · **Draft** badge · subtitle = first incomplete readiness item |
| **Workspace emphasis** | Readiness checklist + builder sections (Details → Images → Tickets) |
| **Primary CTA** | Continue setup → deep link to first blocker |
| **Secondary CTA** | Preview (if meaningful) · Save status (“Saved”) |
| **Warnings** | Blockers with reasons + recovery links; never shame |
| **Metrics** | Quiet / calm empty states (no fake sales) |
| **Help** | “What makes an event ready?” · progressive field guidance |
| **Navigation emphasis** | Details, Schedule, Venue, Images, Tickets highlighted; ops quieter |
| **Operational tools** | Autosave on capable sections; Publishing review available but not primary |
| **Success criteria** | Organiser can answer “what’s missing?” in &lt;5s; work resumes without CMS fear |

**Runtime seed:** `resolveNextRecommendedAction()` elevates setup errors / Continue setup.

---

## 2. Ready

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Confidence to publish deliberately (including Stripe if paid) |
| **Hero** | Event name · **Ready** · calm “Ready when you are” |
| **Workspace emphasis** | Readiness green (honest) + Publishing confidence + Stripe attention if needed |
| **Primary CTA** | Go to Publishing / Publish |
| **Secondary CTA** | Preview public page |
| **Warnings** | Stripe connect / payout readiness; visibility consequences of publish |
| **Metrics** | Still quiet |
| **Help** | What guests will see after publish |
| **Navigation emphasis** | Publishing elevated; builder complete-state |
| **Operational tools** | Publish eligibility + Stripe gate; explicit confirm |
| **Success criteria** | No false green; publish path obvious; regret risk acknowledged calmly |

**Runtime seed:** Unpublished + ready → “Go to publishing”; Stripe may precede publish CTA.

---

## 3. Published

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Confirm findability; complete first share |
| **Hero** | Event name · **Published** · public URL affordance |
| **Workspace emphasis** | Share / Marketing; readiness collapsed unless regression |
| **Primary CTA** | Share |
| **Secondary CTA** | View public page · Open Marketing |
| **Warnings** | Only if capability regresses (tickets broken, unpublished accidentally) |
| **Metrics** | Zero sales OK — empty-success copy, not failure |
| **Help** | Sharing tips; embed if available |
| **Navigation emphasis** | Marketing; soft pulse on Tickets/Orders |
| **Operational tools** | Public link copy; Boost available but not pressuring |
| **Success criteria** | Organiser trusts “it’s live”; can share in one action |

**Runtime seed:** Published + ready → Share → `workspace_marketing`.

---

## 4. Selling

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Watch inventory and revenue; fix issues; light promotion |
| **Hero** | Event name · **On sale** / Published · sales pulse headline |
| **Workspace emphasis** | Sales snapshot · Tickets · Orders · Marketing |
| **Primary CTA** | Context-sensitive: View orders **or** Adjust tickets **or** Share (from next-action / alerts) |
| **Secondary CTA** | Boost · Messages · Analytics |
| **Warnings** | Low capacity · Stripe issues · refund spikes |
| **Metrics** | Tickets sold, revenue (Commerce-truth), conversion cues if honest |
| **Help** | Capacity / pricing tips — non-blocking |
| **Navigation emphasis** | Tickets, Orders, Marketing; builder quieter |
| **Operational tools** | Ticket manager; readonly-safe order inspection; Boost with spend clarity |
| **Success criteria** | Organiser sees movement or calm zero; knows which lever to pull |

**Runtime gap:** Emphasis is card-level today, not formal shell reweight — target for presentation layer.

---

## 5. Upcoming

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Guest-list readiness, messaging plan, door prep |
| **Hero** | Event name · **Upcoming** · date/countdown clarity |
| **Workspace emphasis** | Attendees · Messages · door prep checklist |
| **Primary CTA** | Review attendees |
| **Secondary CTA** | Message guests · Preview Door Mode |
| **Warnings** | Incomplete guest questions · missing door staff plan |
| **Metrics** | Attendee / RSVP counts; checked-in = 0 expected |
| **Help** | Door prep checklist |
| **Navigation emphasis** | Attendees, Messages; Door entry visible |
| **Operational tools** | Guest list; messaging; Door Mode preview (not full stress mode) |
| **Success criteria** | Organiser knows who’s coming and how door will run |

**Runtime gap:** No automated “upcoming mode” shell emphasis yet.

---

## 6. Live

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Check guests in quickly; handle exceptions calmly |
| **Hero** | Unmistakable **Live** / Door status · event identity always visible |
| **Workspace emphasis** | Door Mode + Attendees exceptions; builder de-emphasised |
| **Primary CTA** | Open Door Mode / Check in |
| **Secondary CTA** | Order exceptions · Message · View orders |
| **Warnings** | Scan failure · access issues · (future) offline |
| **Metrics** | Checked-in / remaining; exceptions count |
| **Help** | Minimal chrome; large targets; reduced motion respected |
| **Navigation emphasis** | Attendees (+ Door); everything else secondary |
| **Operational tools** | Door Mode stress UI; searchable list; server-side check-in |
| **Success criteria** | ≤2 taps from Home to Door; one-handed usable at 390px; confirmation brief |

**Runtime debt:** Door Mode on Manager shell — continuity must be fixed (DDR-008/009).

---

## 7. Completed

| Dimension | Specification |
| --- | --- |
| **Primary goal** | Reconcile money; learn; close calmly |
| **Hero** | Event name · **Completed** · thank-you / closure tone |
| **Workspace emphasis** | Orders · Analytics · Settings/archive; no growth pressure |
| **Primary CTA** | Review orders **or** View analytics (context) |
| **Secondary CTA** | Refunds (deliberate) · Archive · Duplicate event (if product supports later) |
| **Warnings** | Open refunds · payout holds — link to Payments hub |
| **Metrics** | Final sales, attendance, (honest) outcomes |
| **Help** | Payouts → Payments; refund expectations |
| **Navigation emphasis** | Orders, Analytics, Settings; Marketing demoted |
| **Operational tools** | Order truth; refund entry; archive affordance |
| **Success criteria** | Organiser can close the loop without FOMO “boost now” |

**Runtime gap:** Aftermath mode not automated; archive routes residual on Manager — map via DDR-008 Phase 3.

---

## State detection (design contract — not inventing fields)

Presentation layer should derive emphasis from **existing** signals already used by builders/VM where possible:

| Signal class | Examples already in stack |
| --- | --- |
| Publish state | Node published flag / status labels on VM |
| Readiness | `EventReadinessFacade` / eligibility |
| Sales activity | Sales snapshot / Home cards |
| Temporal | Event date/time fields already feeding VM date labels |
| Door proximity | Dashboard Foundation patterns for “today / doors open”; reuse carefully for Workspace |
| Completion | Past end + published/unpublished archive patterns |

**Do not invent** new entity fields in this sprint. If a signal cannot be confirmed in repository at implementation time — **stop and ask**.

---

## CTA conflict resolution

When multiple CTAs could apply:

1. Safety / setup errors  
2. Publish blockers / Stripe gate  
3. Lifecycle primary (table above)  
4. Growth (Share / Boost)  

Event chrome shows **one** primary. Home may explain why. Topbar must not permanently compete with Home next-action as a second primary of equal weight.
