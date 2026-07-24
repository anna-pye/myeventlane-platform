# MEL Microcopy Audit

**Status:** Complete  
**Date:** 2026-07-24  
**Locale:** Australian English  
**Type:** Documentation only  
**Authority:** Language Guide · Brand copy guidelines · VX2 hub notes

---

## Standards checklist

Every organiser-facing string should be:

| Criterion | Pass means |
| --- | --- |
| Australian English | organise, favour, cancelled (as applicable) |
| Warm | Encouraging, not corporate-cold |
| Professional | Clear, adult, not childish or meme-y |
| Community-first | Grassroots organiser respect |
| Consistent | Matches Language Guide master table |

---

## Terminology compliance (summary)

| Preferred | Avoid in organiser UI | Status after VX2 |
| --- | --- | --- |
| Organiser | Vendor (customer-facing) | Mostly renamed; residual titles possible |
| Event Workspace | Event Studio / Manager as dual products | Soft rename shipped; docs/Home label drift |
| Tickets / Ticket type | Product, Variation, Ticket product | Sprint 1–3 purge on critical paths |
| Attendees | Ticket holders | Renamed |
| Door Mode | QR validate / multi check-in apps | Sprint 4 |
| Messages | Vendor comms / Attendee Messaging | VX2-06 |
| Payments | Store, Gateway, Commerce | VX2-07 hub |
| Analytics | Insights (as product name) | Language; surface merge open |
| Marketing | Grow event / Promote soup | Partial |
| Get paid / Connect Stripe | Configure payment gateway | Hub + onboard direction |

---

## Preferred microcopy patterns (authoritative)

| Situation | Pattern |
| --- | --- |
| Blocked publish | You can’t publish yet because {reason}. {Fix CTA}. |
| Stripe incomplete | Connect Stripe to get paid for tickets. |
| Pro gate | {Capability} is included in Pro. See what’s included → |
| Empty attendees | No guests yet. Share your event to get your first booking. |
| Success publish | You’re live. Share your event link. |
| Refund | Refund {name}? This can’t be undone from here. |
| Empty tickets | Add a ticket so people can register. |
| Empty payments | Connect Stripe to get paid. |
| Message failed | Message didn’t send. Try again or contact support. |
| Needs attention | Needs attention — {why}. Fix issue → |

Tone: warm, specific, blame-free.

---

## Surface audit

### Dashboard

| Finding | Severity | Action |
| --- | --- | --- |
| Action queue verbs should match Language Guide | Low | Keep Create / Continue / Fix |
| Stripe chip must mirror Payments health language | Medium | Align to Ready / Needs attention / Incomplete |
| Avoid “Vendor dashboard” in titles | Medium | Organiser / Dashboard |

### Workspace Home

| Finding | Severity | Action |
| --- | --- | --- |
| “Event Ready” locked (not Event Status) | — | Keep |
| “Home” vs “Overview” doc drift | Medium | Align Convergence pack wording to Home |
| Next action copy must be specific | High | Never “Manage entity” |

### Tickets

| Finding | Severity | Action |
| --- | --- | --- |
| Advanced Ticket Tools label | — | Keep progressive disclosure name |
| Archive vs Delete clarity | Medium | Prefer Archive for organisers; delete reserved |
| Free RSVP language | — | Keep human Free / RSVP |

### Attendees

| Finding | Severity | Action |
| --- | --- | --- |
| AU empty states shipped Sprint 4 | — | Keep |
| Door Mode labelling on entry CTAs | Low | Unify Check in → Door Mode where live ops |
| Refund entry copy | High | Always confirm irreversibility |

### Payments

| Finding | Severity | Action |
| --- | --- | --- |
| Hub AU trust copy shipped | — | Keep |
| Residual Settings stored-status phase strings | Medium | Purge/translate per VX2-07 backlog |
| Payout delayed / verification pending | — | Keep honest status + Fix CTA |

### Messages

| Finding | Severity | Action |
| --- | --- | --- |
| No Vendor Comms / queue / plugin jargon in hub | — | Keep |
| Failed / Needs attention when zero deliveries | — | Keep (post follow-up fix) |
| “Soon” on audience filters | Low | Acceptable if not fake-enabled |
| Soften residual admin messaging outside organiser UI | Low | Backlog |

---

## Banned phrase spot-check (organiser UI)

Do not show:

- Commerce linked / partial / invalid (raw chips)
- Ticket product · Product variation · Select a store
- Entity · Node ID · Bundle
- Env vars · PHP · Drush · gateway plugin IDs in errors
- Escalation (to organisers) — say Support

---

## Dual vocabulary policy (unchanged)

| Layer | Allowed |
| --- | --- |
| Organiser UI, emails, help (customer-facing) | Language Guide only |
| Admin / staff tools | Technical terms OK |
| Code, routes, config | `vendor`, Commerce APIs OK |
| Support macros to organisers | Language Guide only |

---

## Microcopy score (organiser critical path)

| Area | Score /10 | Notes |
| --- | --- | --- |
| Create → Tickets → Publish | 8.5 | Strong after Sprint 1–3 |
| Attendees + Door Mode | 8.5 | Empty + labels good; QA pending |
| Payments | 9 | Hub trust copy strong |
| Messages | 8.5 | Hub strong; residual admin edges |
| Analytics naming | 7 | Language incomplete vs Insights surfaces |
| Settings / Onboarding | 8 | Stripe “get paid” direction; residual strings |

**Overall microcopy maturity:** **8.3 / 10**
