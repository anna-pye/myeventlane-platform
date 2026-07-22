# Vendor Experience Convergence — Success Metrics

**Status:** Measurement plan (documentation only)  
**Date:** 2026-07-22  
**Related:** [`vendor-experience-convergence.md`](vendor-experience-convergence.md)

Metrics prove whether Convergence helps organisers create successful events — not whether Drupal entities were rearranged.

---

## 1. North-star outcomes

| North star | Why it matters |
| --- | --- |
| **Successful events published** | Core mission |
| **Organiser retention (30 / 90 day)** | Platform health |
| **Gross ticket volume (GMV)** | Revenue for organisers and MEL |
| **Support contacts per active organiser** | Friction inverse |

---

## 2. Funnel metrics

| Metric | Definition | Target direction |
| --- | --- | --- |
| **Registration → organiser profile complete** | % completing required onboard profile/terms | ↑ |
| **Stripe start → Stripe complete** | % Connect flows reaching payouts-ready | ↑ |
| **First event created** | % new organisers with ≥1 event draft | ↑ |
| **First event published** | % with ≥1 published event within 7 / 30 days | ↑ |
| **Time to first publish** | Median minutes/hours from register → first publish | ↓ |
| **Time to first ticket type** | Median from event create → first ticket saved | ↓ |
| **Draft abandon rate** | Drafts never edited again after 7 days | ↓ |
| **Draft-choice clarity** | % Create clicks that hit explicit resume/new when draft exists | ↑ (instrument) |

---

## 3. Money & growth metrics

| Metric | Definition | Target |
| --- | --- | --- |
| **Paid publish unlock latency** | Time from first paid ticket to Stripe-ready | ↓ |
| **Boost start → Boost purchase** | Wizard completion / conversion | ↑ |
| **Pro conversion** | Free → Pro after Analytics/Messages exposure | ↑ without ↑ churn |
| **Refund completion time** | Request → resolved | ↓ |
| **Payout failures** | Failed or delayed payouts needing support | ↓ |
| **Revenue per active organiser** | GMV / active organisers | ↑ |

---

## 4. Operations & support metrics

| Metric | Definition | Target |
| --- | --- | --- |
| **Attendee export time** | Click → file ready (p50/p95) | ↓ |
| **Door Mode successful check-ins** | Check-ins / attempts | ↑ |
| **Check-in related support tickets** | Tag volume | ↓ |
| **“Where is X?” tickets** | Nav/IA confusion tags | ↓ |
| **403 / access dead-end tickets** | Permission confusion | ↓ → ~0 on known P0 paths |
| **Messaging send failures** | Failed sends / attempts | ↓ |
| **Legacy URL hits** | Requests to singular `/vendor/event/*` stubs | ↓ after redirects |

---

## 5. Product quality metrics

| Metric | Definition | Target |
| --- | --- | --- |
| **Commerce jargon impressions** | Spot audits / automated string scans in organiser theme | → 0 |
| **Nav item count (shell)** | Top-level items | ≤10 |
| **Duplicate surfaces live** | Count of parallel Analytics/Messages/Check-in UIs | ↓ to 1 canonical each |
| **Mobile Door Mode task success** | Moderated test: check in 5 guests | ↑ |
| **Accessibility regressions** | Critical a11y issues on shell + Door Mode | 0 critical |

---

## 6. Retention & engagement

| Metric | Definition | Target |
| --- | --- | --- |
| **Organiser 30-day retention** | Return within 30 days of first publish | ↑ |
| **Events created per organiser** | Mean/median per quarter | ↑ |
| **Events published per organiser** | Mean/median | ↑ |
| **Repeat publish rate** | Organisers with ≥2 publishes | ↑ |
| **Create from previous usage** | When feature ships | Instrument adoption |

---

## 7. Instrumentation requirements (product)

Minimum events to log (names illustrative):

- `organiser_onboard_step_completed`
- `stripe_connect_started` / `stripe_connect_completed` / `stripe_connect_needs_attention`
- `event_create_started` / `event_draft_resumed` / `event_draft_started_new`
- `ticket_type_created`
- `event_publish_succeeded` / `event_publish_blocked` (+ reason codes)
- `boost_wizard_step` / `boost_purchased`
- `message_sent` / `message_failed`
- `door_checkin_succeeded` / `door_checkin_failed`
- `attendee_export_requested` / `attendee_export_ready`
- `refund_requested` / `refund_resolved`
- `analytics_viewed` / `pro_upgrade_clicked` / `pro_upgrade_completed`
- `legacy_route_redirected` (path family)

Reason codes on publish blocks enable support reduction analysis.

---

## 8. Baselines & review cadence

1. **Capture baselines** for 2–4 weeks before P1 marketing.  
2. **Review weekly** during P0/P1 (trust + spine).  
3. **Review biweekly** during P2 merges.  
4. **Quarterly** north-star board for leadership.  

Do not declare Convergence “successful” on ship day — declare **launch-ready** on exit criteria, **successful** on metric movement.

---

## 9. Qualitative success signals

- Organiser usability tests: complete create → ticket → publish without mentor help  
- Support macros for “Studio vs Manager” and “Insights vs Analytics” retire  
- Sales/success teams demo one Workspace story only  

---

## 10. Anti-metrics (do not optimise blindly)

- Raw pageviews of admin-like screens  
- Number of routes added (prefer redirects)  
- Feature count over task success  
- Pro conversion via deceptive gates (trust damage)

---

## Success statement

Convergence succeeds when a new Australian community organiser can go from **sign-up → publish → first sale → door check-in** thinking only about their event, attendees, tickets, and revenue — and when MEL sees rising publish rates, Stripe completion, retention, and GMV with falling support load.
