# Vendor Workspace v2 — Implementation Readiness

**Status:** Discovery readiness (no implementation)  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-v2-discovery` @ `37fcdc449`

---

## Strengths

- Studio Workspace is a real per-event application with section plugins, Home builder, readiness facade, autosave, and publish APIs.
- Home already encodes next-action priority (setup → blockers → Stripe → publish → share).
- Shared `VendorEventWorkspaceViewModelBuilder` gives Mission Control seeds (focus, metrics, actions).
- Organiser funnel via `VendorLegacyWizardRedirectSubscriber` reduces dual-nav exposure in practice.
- Dashboard Foundation merged — clear split: portfolio vs event.
- Door Mode and Commerce-aware Orders surfaces exist.
- PDS pack (01–21 + DDR-001–005) gives design authority for Workspace.

---

## Weaknesses

- Dual shells remain (`mel_event_studio_workspace` vs `mel_event_workspace`).
- DDR-002 canonical paths omit `/studio`; runtime requires it for organisers.
- Triple/quadruple nav sources drift.
- PDS 13 Orders-before-Attendees vs runtime Attendees-before-Orders.
- Door Mode still on Manager theme — continuity break at highest stress moment.
- Manager workspace root cache metadata weak.
- Local config drift: vendor role permission + RSVP `organiser_owned` filter.
- ADR-0002 referenced by Cursor rule but **absent** from pack; version label 1.0 vs 1.0.1 mismatch.

---

## Architecture opportunities

1. **Declare one canonical shell** (Studio) and staff-gate or delete Manager event chrome after path strategy DDR.  
2. **Unify nav assembly** — one builder feeding sidebar/tabs.  
3. **State-aware presentation layer** on existing builders (03 state model) without new modules.  
4. **Door Mode chrome continuity** — same app feeling under Attendees.  
5. **Align Home “Today’s focus” and `next_action`** into one narrative.  
6. **Harden cache** on any remaining Manager surfaces.

---

## Reusable inventory

| Kind | Assets |
| --- | --- |
| **Components** | Workspace hero/topbar patterns, readiness checklist, ops cards, empty states, alerts, metric patterns from vendor theme + Studio Home |
| **Builders** | `EventWorkspaceOverviewBuilder`, `VendorEventWorkspaceViewModelBuilder`, readiness facade, section manager/renderer, sales summaries |
| **Twig** | `mel-event-studio-workspace|overview|sidebar|topbar|section`, manager workspace partials for reference/staff |
| **SCSS/CSS** | `mel-event-studio-shell.css`, nav CSS, mission-control / workspace SCSS, layout intent classes |
| **APIs** | Autosave + publish POST endpoints with CSRF |

---

## Potential risks

| Risk | Severity |
| --- | --- |
| Path/shell change without redirect map | High — bookmarks, emails, help links |
| Touching publish/eligibility/Stripe gate | High — money + trust |
| Orders/refunds UX changes | High — Commerce state |
| RSVP view / vendor role config drift ignored | High — access/isolation |
| Door Mode access/mutation changes | High — live ops |
| Cache regressions on workspace | Medium |
| Duplicating Dashboard queues on Home | Medium — product confusion |

---

## Technical debt

- Dual Workspace themes and route trees  
- Legacy Manager local tasks vs Studio plugin nav  
- `VendorEventTabsService` vs section manager duplication  
- Manager `EventWorkspaceController` without solid `#cache`  
- `/studio` prefix as permanent product history leak  
- Hidden section plugins still routable — document IA carefully  

---

## Product debt

- Organisers may still learn “Studio” as a word (path, old docs)  
- Home density vs One primary action tension (topbar publish + next-action)  
- Lifecycle emphasis not formalised — Selling/Live feel under-weighted  
- Door Mode IA promise (under Attendees) vs peer-tab presentation  
- Section order conflict with frozen PDS 13  

---

## Potential DDRs

| ID (proposed) | Topic |
| --- | --- |
| **DDR-008** (recommended) | Canonical Event Workspace path & shell — resolve `/studio` vs DDR-002 paths; retirement plan for Manager `mel_event_workspace` organiser entry |
| **DDR-009** (recommended) | Workspace section order — Attendees vs Orders; Door Mode placement in nav chrome |
| **ADR-0002** (missing) | Author “implementation follows PDS” or remove rule reference |
| **PDS 1.0.1 patch** | Version label alignment + CHANGELOG if governance docs added |
| Amend **13** | Only after DDR-009 if runtime order is intentional |

Do **not** silently redesign paths in a theme PR.

---

## Recommended implementation slices (after wireframes + DDRs)

Order assumes DDRs accepted first.

0. **Hygiene (blocker):** Reconcile `user.role.vendor` + `views.view.myeventlane_vendor_rsvps` active vs sync; document payment/Klaro drift ownership.  
1. **DDR-008/009 + ADR-0002** documentation PRs (no UX code).  
2. **Wireframes** — Home + chrome by lifecycle state (this pack’s models).  
3. **Slice A — Home Mission Control composition** — extend `EventWorkspaceOverviewBuilder` + overview Twig only; no route renames.  
4. **Slice B — State emphasis** — presentation/topbar primary CTA by lifecycle; reuse VM.  
5. **Slice C — Nav single source** — section manager as sole nav; retire duplicate tab builders gradually.  
6. **Slice D — Door Mode continuity** — Attendees entry + shared chrome; keep check-in access logic.  
7. **Slice E — Path unification** (if DDR-008 requires) — redirects, tests, help URL updates; highest risk last.

---

## Stage 10 — Success criteria answers

| Question | Answer |
| --- | --- |
| **What already exists?** | Full Studio Workspace app, Home mission-control builder, readiness/publish/autosave, Manager residual shell, Door Mode, shared VM, Dashboard Foundation. |
| **What should stay?** | Studio section architecture, readiness facade, publish/autosave safety, ownership access, Commerce order truth, Door Mode capability, layout intents/tokens. |
| **What should change?** | Dual-shell story → one organiser shell; nav single source; lifecycle emphasis; Home primary CTA clarity; Door Mode continuity; path strategy per DDR. |
| **What should be removed?** | Organiser-facing Manager event chrome (after DDR); duplicate tab builders; competing “Studio vs Manager” language in product UI. |
| **What should become more prominent?** | Next action, honest status, Live/Door entry when relevant, sales pulse while Selling. |
| **What should become quieter?** | Builder density when Live; Boost pressure after Completed; governance panels; duplicate metrics. |
| **Can organisers confidently run an event from this Workspace?** | **Partially yes** for setup → publish → share; **weaker** for Live door continuity and IA purity. |
| **If not, why not?** | Shell/path duality, Door Mode theme split, nav fragmentation, config drift on RSVP isolation, incomplete lifecycle emphasis. |

---

## Recommendation: Proceed to Workspace Wireframes?

### **YES — conditional**

**YES** to wireframes **provided**:

1. Wireframes target **Studio Workspace** as organiser truth (`/vendor/events/{node}/studio*` until DDR-008).  
2. Open **DDR-008** (path/shell) and **DDR-009** (section order / Door chrome) before freezing IA in pixels.  
3. Author or drop **ADR-0002** reference.  
4. Treat config drift reconciliation as a **parallel blocker** before any implementation slice.  
5. Do **not** wireframe a greenfield shell or new Drupal architecture.

**NO** if Product Owner insists wireframes must show DDR-002 literal paths `/vendor/events/{id}/{section}` without an accepted transition DDR — that would invent a runtime that does not exist yet.

**Repository evidence:** PR #716 on `main`; Studio routes + overview builder; legacy redirect subscriber; DDR-002 path text vs `/studio` prefix; missing ADR-0002 file under `docs/design/vendor-studio/decisions/`.
