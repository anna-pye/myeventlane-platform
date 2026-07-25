# Feature Implementation Record — Vendor Dashboard v2 Foundation

**Note:** Canonical `FEATURE_IMPLEMENTATION_TEMPLATE.md` is **missing** from the frozen PDS pack. This record follows CONTRIBUTING + PR template structure for this feature only.

| Field | Value |
| --- | --- |
| Feature | Vendor Dashboard v2 Foundation (Slices 1 + 2) |
| Surface | `/vendor/dashboard` · `myeventlane_vendor.console.dashboard` |
| Branch | `feature/vendor-dashboard-v2-slice2` |
| PDS version | 1.0.1 (philosophy v1.0 FROZEN) |
| Author | Agent (merge prep) |
| Date | 2026-07-25 |
| Status | Implementation complete — await commit / PR / Design Authority |

---

## Organiser outcome

Organisers open the Dashboard and immediately see what needs attention, what is happening soon, and calm business health — without marketing wallpaper or duplicate metrics.

---

## Three Question Framework

| Question | Answer on this surface |
| --- | --- |
| Where am I? | Organiser Dashboard home (H1 identity + shell nav) |
| What needs me? | Action Queue (or calm caught-up) |
| What next? | Queue CTA, Create event, or Open Workspace / Door Mode on today’s focus |

**Golden Rule:** Action Queue is first content after identity (+ optional factual Daily Brief).

---

## Runtime mapping

| Concern | Home |
| --- | --- |
| View model | `VendorDashboardViewModelBuilder` |
| Action queue | `VendorActionQueueBuilder` |
| Activity rows | `VendorDashboardController::buildDashboardActivity` |
| Presentation | `dashboard.html.twig` + `_dashboard-live-ops.scss` |
| Brief / grouping | Theme preprocess |
| Cache | Controller `#cache` |

---

## Explicit non-goals

- Event Workspace redesign  
- Unread message inventing  
- AI Daily Brief  
- Config export  
- New libraries / routes / permissions  

---

## Risks called out

| Risk | Mitigation |
| --- | --- |
| Relative-time stale cache | `max-age` 300 + `timezone` context |
| Misleading “today” label | Panel gated to live / doors / &lt;1 day |
| Access | No access changes; Door Mode URL remains access-checked |
| Commerce | KPI/activity reuse existing completed-order / RSVP signals only |

---

## Validation

See [09-merge-readiness.md](09-merge-readiness.md) §8–§10.
