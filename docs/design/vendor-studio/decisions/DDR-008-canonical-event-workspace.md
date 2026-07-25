# DDR-008 — Canonical Event Workspace (path & shell)

**Status:** Accepted  
**Date accepted:** 2026-07-25  
**Version:** 1.0  
**Owners:** Design Authority · Product Owner · Technical Authority  
**Sprint origin:** Vendor Workspace v2 — Foundation (Phase A / Phase 2A)  
**Related:** [DDR-002](DDR-002-event-workspace.md) · [DDR-009](DDR-009-workspace-navigation.md) · [13](../13-event-workspace-philosophy.md) · [07-workspace-unification.md](../../vendor-workspace-v2/07-workspace-unification.md)

---

## Decision (accepted)

1. **Organiser Event Workspace** is a single application implemented by `myeventlane_event_studio` (`mel_event_studio_workspace`).
2. **Shipped organiser runtime paths (Foundation):** `/vendor/events/{node}/studio` and `/vendor/events/{node}/studio/{section}`.
3. **Canonical target paths (path-unification phase):** `/vendor/events/{id}` and `/vendor/events/{id}/{section}` — aligns DDR-002; not renamed in Foundation.
4. **`/studio` prefix** is transitional product truth until path unification ships; permanent redirects will preserve bookmarks and help links.
5. **`mel_event_workspace` (Manager)** is **Staff Operations** intent — organisers must not land there for ordinary event work (staff-gate remains phased work).
6. **Door Mode** remains an event-scoped capability; chrome continuity with Event Workspace is mandatory even if the route path migrates later.
7. **Home Mission Control** is the shared operational card for Home and non-Home chrome (`mel_event_studio_mission_control`), fed by `EventWorkspaceOverviewBuilder` / `EventReadinessFacade` — not a second readiness engine.

---

## Shipped Foundation runtime (authority)

| Surface | Runtime truth |
| --- | --- |
| Organiser shell theme | `mel_event_studio_workspace` |
| Controller | `EventStudioController` |
| Routes | `myeventlane_event_studio.routing.yml` — `/vendor/events/{node}/studio*` |
| Workspace Hero | `mel-event-studio-topbar.html.twig` — identity · status · one primary CTA |
| Mission Control | `mel-event-studio-mission-control.html.twig` — next step · improvements · Event Quality |
| Primary CTA contract | `EventWorkspaceOverviewBuilder::resolveAuthoritativePrimaryCta()` — Hero + Mission Control share lifecycle CTA; Stripe Connect is the only Mission Control exception |
| Readiness authority | `EventReadinessFacade` (unchanged) |
| Homepage quality score | Existing `FeaturedEventReadinessService` presentation `score` — Event Quality section only |

Foundation does **not** invent a third shell or a parallel scoring system.

---

## Problem (historical)

Accepted **DDR-002** decided “one Event Workspace” and listed paths without `/studio`. Pre-Foundation runtime used `/studio` and dual Studio/Manager themes. Organisers were mostly protected by `VendorLegacyWizardRedirectSubscriber`, but dual shells created support confusion and Door Mode chrome breaks.

---

## Migration remaining (post-Foundation)

| Phase | Work | Foundation status |
| --- | --- | --- |
| **1 — Product clarity** | Organiser UI says **Event Workspace**; Manager = Staff Operations | **Shipped** (organiser chrome/copy) |
| **2 — Path unification** | Routes → `/vendor/events/{id}[/{section}]`; redirects from `/studio` | **Not started** |
| **3 — Staff Operations** | Staff-gate Manager organiser entry; Door Mode chrome continuity | **Not started** |

---

## Alternatives considered

| Alternative | Assessment |
| --- | --- |
| **A. Path-unify to DDR-002** (accepted direction) | Rename organiser routes after Foundation; keep Studio theme; redirect `/studio/*`; staff-gate Manager |
| **B. Amend DDR-002 to `/studio` forever** | Rejected as permanent product history in URLs |
| **C. Keep dual shells indefinitely** | Rejected — fails PDS 13 / DDR-001 spirit |
| **D. Rebuild on Manager theme** | Rejected — abandons Studio section/plugin stack |
| **E. New third theme for v2** | Forbidden |

---

## Consequences

- Organiser product extension point is `mel_event_studio_workspace` only.
- Implementation and help may cite `/studio` until Phase 2 completes; docs must call it transitional.
- Path rename, redirect inventory, and Door Mode chrome continuity remain explicit follow-on work — not Foundation blockers.
- Publishing Experience (Phase 3) proceeds only after this Foundation merge.

---

## Future review triggers

- Multi-event bulk ops requiring different URL shapes
- Evidence that `/vendor/events/{id}` collides with non-Workspace console routes
- Door Mode offline / PWA productisation
- Staff Operations surfaces needing a dedicated staff theme

---

## Approval checklist

- [x] Design Authority — product clarity & IA (Foundation merge preparation)
- [x] Technical Authority — shell, routes (transitional), access, cache, no third readiness engine
- [x] Product Owner — Foundation merge approved; path unification deferred
- [x] Status **Accepted** + CHANGELOG entry
- [x] INDEX decision table updated
