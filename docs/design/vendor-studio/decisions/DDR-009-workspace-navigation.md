# DDR-009 — Workspace Navigation

**Status:** Accepted  
**Date accepted:** 2026-07-25  
**Version:** 1.0  
**Owners:** Design Authority · Product Owner · Technical Authority  
**Sprint origin:** Vendor Workspace v2 — Foundation (Phase A / Phase 2A)  
**Related:** [DDR-001](DDR-001-shell-navigation.md) · [DDR-002](DDR-002-event-workspace.md) · [DDR-008](DDR-008-canonical-event-workspace.md) · [02](../02-information-architecture.md) · [13](../13-event-workspace-philosophy.md) · [07-workspace-unification.md](../../vendor-workspace-v2/07-workspace-unification.md)

---

## Decision (accepted)

1. **Single nav source** for Event Workspace section membership and order: `EventStudioSectionManager` + `Plugin/EventStudioSection/*`.
2. **Event Workspace section order** (organiser product — labels as shipped):

```text
Home → Details → Schedule → Venue → Images → Tickets
→ Attendees → Messages → Marketing → Orders
→ Analytics → Publishing → Settings
```

3. **Door Mode** is presented as a mode/entry under **Attendees**, not as a global shell peer and not as a competing top-level Workspace product. (Entry UX nesting remains follow-on; route may stay stable initially.)
4. **Global shell order is unchanged by this DDR:** global Orders may remain before global Attendees (PDS 02). Workspace order may differ because live ops are event-primary.
5. **PDS 13** section order text should be amended under governance to match this accepted order (queued — not a silent edit outside CHANGELOG/INDEX).
6. Duplicate authorities (`VendorEventTabsService`, shortened console preprocess lists, Manager local tasks for organisers) are retired or staff-gated over time — they must not redefine organiser IA.

---

## Shipped Foundation navigation (authority)

### Studio section weights (organiser truth)

| Weight | Machine id | Organiser label | Route pattern |
| --- | --- | --- | --- |
| 0 | `overview` | Home | `…/studio` |
| 10 | `information` | Details | `…/studio/information` |
| 20 | `schedule` | Schedule | `…/studio/schedule` |
| 30 | `venue` | Venue | `…/studio/venue` |
| 40 | `branding` | Images | `…/studio/branding` |
| 50 | `tickets` | Tickets | `…/studio/tickets` |
| 60 | `attendees` | Attendees | `…/studio/attendees` |
| 70 | `messaging` | Messages | `…/studio/messaging` |
| 80 | `marketing` | Marketing | `…/studio/marketing` |
| 90 | `orders` | Orders | `…/studio/orders` |
| 100 | `analytics` | Analytics | `…/studio/analytics` |
| 110 | `publishing` | Publishing | `…/studio/publishing` |
| 120 | `settings` | Settings | `…/studio/settings` |

Hidden / nested plugins (examples): Questions, Capacity, Extras, Content, Fulfilment — `navigationVisible: FALSE`.

Paths remain on transitional `/studio` per [DDR-008](DDR-008-canonical-event-workspace.md).

### Event chrome (constant)

```text
[← Events]  Event name · Status badge · Primary CTA · Secondary (View / Share)
```

Hero owns the visual primary CTA. Mission Control mirrors the same authoritative next action (`resolveAuthoritativePrimaryCta`), except the approved Stripe Connect exception.

### Door Mode (accepted direction)

```text
Attendees
  └── Door Mode  (mode / full-stress UI)
```

- ≤2 taps from Home when state is Live / door-imminent (product target).
- Same event identity visible; no “you left Workspace” feeling.
- Check-in access and mutations remain server-authoritative.

### Lifecycle emphasis (not membership)

Nav membership is stable. Visual emphasis may highlight Attendees/Door when Live, Publishing when Ready, Orders when Completed — see `08-event-lifecycle-model.md` and `11-operational-state-model.md`.

---

## Operational reasoning

| Choice | Why |
| --- | --- |
| Attendees before Orders in Workspace | Door and guest prep dominate live stress; money trail remains one tap away; matches shipped weights |
| Orders before Attendees in **global** nav | Cross-event reconciliation is a business job (PDS 02) — unchanged |
| Marketing before Orders | Growth actions often precede deep order inspection while Selling |
| Publishing near end | Deliberate gate after build/ops sections; Hero still surfaces publish CTA when Ready |
| Single nav source | Stops triple-definition drift; one place for a11y labels and mobile priority |

---

## Migration remaining

| Phase | Work | Foundation status |
| --- | --- | --- |
| **1** | Declare `EventStudioSectionManager` sole organiser nav authority | **Shipped** (runtime + this DDR) |
| **2** | Align / retire `VendorEventTabsService` + console preprocess organiser use | **Not started** |
| **3** | Door Mode entry UX under Attendees (presentation); keep route stable initially | **Not started** |
| **4** | Staff-gate Manager tabs for organisers | **Not started** |

---

## Risks

| Risk | Severity | Mitigation |
| --- | --- | --- |
| Help/docs still teach Orders-before-Attendees inside Workspace | Medium | Update copy with PDS 13 amendment |
| Reordering visible nav without weight/tests | Medium | Keep weights; snapshot tests on section order |
| Door Mode access regression | High | No permission changes in nav-only / Foundation PRs |
| Mobile drawer hiding Orders | Medium | Preserve mobile_priority metadata; audit 390px |
| Global vs Workspace order confusion for support | Low | Document in glossary / staff playbook |

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Force PDS 13 Orders-before-Attendees in runtime | Fights live-ops priority and shipped weights |
| Make Door Mode a Workspace top-level section | Inflates nav; contradicts DDR-002 |
| Keep multiple nav builders forever | Permanent drift |
| Different section sets per lifecycle | Breaks shell constancy |

---

## Approval checklist

- [x] Design Authority
- [x] Product Owner (IA weight — Attendees before Orders)
- [x] Technical Authority (nav builders, Door Mode access adjacency)
- [x] PDS 13 amendment queued under governance
- [x] Status **Accepted** + CHANGELOG
