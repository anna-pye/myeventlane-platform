# Vendor Workspace v2 — Organiser Journey

**Status:** Discovery / product model (no implementation)  
**Date:** 2026-07-25  
**Aligned to:** PDS 13 lifecycle · runtime Studio Home next-action rules  
**Lifecycle used (sprint):** Draft → Ready → Published → Selling → Upcoming → Live → Completed  

PDS 13 uses Draft → Ready → Published/Live → Door → Aftermath → Archive. This map expands selling/upcoming for operational clarity; Archive remains the calm end-state of Completed.

---

## Journey overview

```text
Draft → Ready → Published → Selling → Upcoming → Live → Completed
  ↑ setup/readiness          ↑ growth/sales     ↑ door ops   ↑ aftermath
```

Shell stays constant (DDR-002). Emphasis and primary CTA change by state.

---

## 1. Draft

| | |
| --- | --- |
| **Goals** | Capture the event’s identity; leave every session knowing the next setup step |
| **Questions** | What is still missing? Can I come back later without losing work? |
| **Stress** | Blank-page anxiety; fear of “doing Drupal wrong” |
| **Primary task** | Complete the next readiness blocker |
| **Information required** | Checklist with reasons + deep links; autosave status; draft badge |
| **Primary CTA** | Continue setup → first failing checklist item / Publishing review |
| **Ideal Workspace response** | Home next-action = setup error or “Continue setup”; builder sections emphasised; sales cards quiet |

**Runtime today:** `resolveNextRecommendedAction()` elevates readiness errors and “Continue setup” / Publishing when not ready. Autosave on capable sections. **Good fit.**

---

## 2. Ready

| | |
| --- | --- |
| **Goals** | Gain confidence to publish; verify payments if paid tickets |
| **Questions** | Am I truly ready? Will guests find this? Is Stripe connected? |
| **Stress** | Publish regret; public visibility fear |
| **Primary task** | Review Publishing and publish deliberately |
| **Information required** | Green readiness only when real; Stripe attention; preview public page |
| **Primary CTA** | Go to publishing / Publish |
| **Ideal Workspace response** | Home celebrates readiness calmly; primary CTA = Publishing; share secondary |

**Runtime today:** When readiness OK and unpublished → “Ready when you are” + “Go to publishing”; Stripe attention can precede publish CTA. **Good fit** (Stripe vs publish priority is intentional in code comments).

---

## 3. Published

| | |
| --- | --- |
| **Goals** | Confirm the event is findable; first share |
| **Questions** | Is it live on MEL? What’s the link? |
| **Stress** | “Did publish actually work?” |
| **Primary task** | Share / verify public page |
| **Information required** | Published status unmistakable; public URL; early sales zero-state that is calm |
| **Primary CTA** | Share (Marketing) |
| **Ideal Workspace response** | Status = Published; next-action = Share; sales cards show empty-but-ready |

**Runtime today:** Published + ready → Share → `workspace_marketing`. **Good fit.**

---

## 4. Selling

| | |
| --- | --- |
| **Goals** | Watch tickets move; fix capacity/pricing issues; light promotion |
| **Questions** | Are tickets selling? Which type? Any refunds? |
| **Stress** | Slow sales; inventory mistakes |
| **Primary task** | Monitor sales + adjust tickets/marketing |
| **Information required** | Sales snapshot, ticket breakdown, orders entry, Boost/share state |
| **Primary CTA** | Context: View orders / Adjust tickets / Share |
| **Ideal Workspace response** | Sales + Orders rise in hierarchy; Marketing available; setup chrome quieter |

**Runtime today:** Home sales/tickets/analytics cards exist; next-action still defaults to Share when published unless VM injects error/info. **Average** — selling emphasis is card-level, not shell reweight.

---

## 5. Upcoming (near-term, not yet door)

| | |
| --- | --- |
| **Goals** | Prep guest list, messages, door plan |
| **Questions** | Who’s coming? What do I message? Is check-in ready? |
| **Stress** | Last-minute incomplete guest data |
| **Primary task** | Attendee readiness + message plan |
| **Information required** | Attendee counts, RSVPs if used, message entry, Door Mode entry |
| **Primary CTA** | Review attendees / Send message |
| **Ideal Workspace response** | Attendees + Messages emphasised; Door Mode promoted but not yet full-screen stress mode |

**Runtime today:** Attendees before Orders in Studio nav helps. Dedicated “upcoming mode” not confirmed as automated shell emphasis. **Average.**

---

## 6. Live (door / in-progress)

| | |
| --- | --- |
| **Goals** | Check guests in quickly; handle exceptions calmly |
| **Questions** | Who is next? Did that scan work? What if offline? |
| **Stress** | Queue pressure; lighting; one-handed phone use |
| **Primary task** | Door Mode check-in |
| **Information required** | Searchable list, large targets, failure messaging, event identity |
| **Primary CTA** | Open Door Mode / Check in |
| **Ideal Workspace response** | Status unmistakable Live; Attendees/Door Mode dominate; builder sections de-emphasised |

**Runtime today:** Door Mode at `/vendor/events/{node}/operations/door` on **Manager** theme shell — split from Studio. PDS wants Door under Attendees. **Average / Poor for continuity** (capability exists; shell continuity weak).

---

## 7. Completed (aftermath → archive)

| | |
| --- | --- |
| **Goals** | Reconcile orders/refunds; learn from analytics; archive calmly |
| **Questions** | What sold? What’s owed? Can I close this out? |
| **Stress** | Refund disputes; payout timing |
| **Primary task** | Orders / refunds / analytics review |
| **Information required** | Order truth, refund entry, payout links to Payments hub, archive affordance |
| **Primary CTA** | Review orders / View analytics |
| **Ideal Workspace response** | Builder quiet; Orders + Analytics + Settings/archive calm; no fake “boost now” pressure |

**Runtime today:** Orders/analytics sections exist; archive routes on Manager remain. Lifecycle “aftermath mode” not automated. **Average.**

---

## Cross-state constants

- One Workspace application (DDR-002) — **product intent**; runtime still dual-shell.
- Honest money and publish (01, 07, 13).
- Help copy Australian English, organiser voice (15).
- Global Dashboard answers portfolio attention; Workspace answers **this event**.
