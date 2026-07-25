# Vendor Workspace v2 — Drupal Mapping

**Status:** Discovery mapping (no new architecture)  
**Date:** 2026-07-25  
**Commit:** `37fcdc449`  
**Rule:** Reuse existing homes. Do not invent parallel modules, themes, or route trees.

Companion: PDS [09-drupal-mapping.md](../vendor-studio/09-drupal-mapping.md).

---

## 1. Concept → runtime home

| Workspace concept | Runtime home |
| --- | --- |
| Organiser theme | `web/themes/custom/myeventlane_vendor_theme` |
| Global shell / nav | `VendorNavBuilder` + vendor theme layout regions |
| Event Studio Workspace (organiser product) | `myeventlane_event_studio` — theme `mel_event_studio_workspace` |
| Manager Workspace (staff / residual) | `myeventlane_vendor` — theme `mel_event_workspace` |
| Home / Mission Control body | `EventWorkspaceOverviewBuilder` → `mel_event_studio_overview` |
| Shared event VM | `VendorEventWorkspaceViewModelBuilder` |
| Section nav | `EventStudioSectionManager` + `Plugin/EventStudioSection/*` |
| Section bodies | `EventStudioSectionRenderer` + section forms |
| Readiness | `EventReadinessFacade` / `EventReadinessService` |
| Publish eligibility | `PublishEligibilityEvaluator` (+ Stripe gate) |
| Autosave | `EventStudioAutosaveController` + `EventStudioAutosaveService` |
| Publish API | `EventStudioPublishController` |
| Organiser redirect funnel | `VendorLegacyWizardRedirectSubscriber` |
| Door Mode | `myeventlane_event_attendees` — `vendor_operations_door` |
| Access / ownership | `EventStudioAccess`, `VendorConsoleAccess`, `accountHasWorkspaceParityForEvent` |
| Config sync | `config/sync` — reconcile before implementation |

---

## 2. Twig

| Concept | Template / hook |
| --- | --- |
| Studio shell | `mel-event-studio-workspace.html.twig` |
| Home | `mel-event-studio-overview.html.twig` |
| Sidebar / topbar / section | `mel-event-studio-sidebar|topbar|section.html.twig` |
| Attendees stack | `mel-event-studio-attendees-workspace.html.twig` |
| Manager shell | `mel-event/mel-event-workspace.html.twig` |
| Manager tabs / header | `components/workspace/workspace-*.html.twig` |

---

## 3. Preprocess & theme hooks

| Need | Mechanism |
| --- | --- |
| Studio variables | `EventStudioPreprocess` / `template_preprocess_mel_event_studio` |
| Manager workspace vars | `myeventlane_vendor_theme_preprocess_mel_event_workspace` |
| Console tab shortening | `VendorConsolePagePreprocess` |
| Theme negotiation | `VendorThemeNegotiator` → `myeventlane_vendor_theme` |
| Hook registration | `myeventlane_event_studio.module` `hook_theme`; vendor theme `hook_theme` for `mel_event_workspace` |

---

## 4. Builders & view models

| Concept | Builder |
| --- | --- |
| Home cards + next action | `EventWorkspaceOverviewBuilder` |
| Event mission VM | `VendorEventWorkspaceViewModelBuilder` |
| Tabs (legacy/alternate) | `VendorEventTabsService` |
| Presentation strip | `EventStudioWorkspacePresentation` |
| Sales panels | `EventStudioCommerceSalesSummaryBuilder`, extras builders |
| Dashboard (do not merge) | `VendorDashboardViewModelBuilder` — portfolio only |

---

## 5. Libraries & SCSS

| Concept | Library / SCSS |
| --- | --- |
| Studio shell | `myeventlane_event_studio/mel_event_studio_shell_only` |
| Home styles | `css/mel-event-studio-shell.css` (`.mel-event-workspace-home*`) |
| Manager workspace | `myeventlane_vendor_theme/vendor-workspace`, `event_mission_control` |
| Layout intents | `.mel-layout--*` + tokens (DDR-003 · 11) |
| Section apps | tickets_app, attendees_app, branding, extras libs as attached today |

Extend existing partials (DDR-004). Do not create `vendor-workspace-v2-*` parallel kits.

---

## 6. Routes & access

| Concept | Route pattern | Access |
| --- | --- | --- |
| Studio Home | `myeventlane_event_studio.workspace` | `EventStudioAccess` |
| Studio sections | `…workspace_*` | same |
| Autosave / publish | POST autosave / publish | CSRF header + access |
| Manager event root | `myeventlane_vendor.console.event_workspace` | Vendor console access + ownership; redirected for organisers |
| Door Mode | `…vendor_operations_door` | Check-in capability |

**Entity ownership:** Always server-side workspace parity / vendor ownership. UI absence is not security.

---

## 7. Cache

| Surface | Tags / contexts (confirmed) |
| --- | --- |
| Studio workspace render | Node tags; `route`, `user`, `user.permissions`, `url.query_args:mel_celebrate` |
| Access results | Event/vendor deps + user contexts |
| Manager workspace root | Incomplete `#cache` — risk if still rendered |
| Home builder | Inherits parent; no separate `#cache` array |

Future slices must preserve/improve cache metadata — especially if Manager surfaces remain.

---

## 8. Commerce boundaries

| Organiser language | Backstage |
| --- | --- |
| Ticket | Product variation / MEL ticket type abstractions |
| Order | Commerce order |
| Payment / payout | Gateway + Stripe Connect |
| Refund | Deliberate Commerce/Stripe flows |

Workspace mapping must not collapse event node and ticket products into one unclear model.

---

## 9. What mapping forbids

- New theme for Workspace v2
- New global nav item “Workspace”
- Parallel overview builder that ignores readiness facade
- Client-only publish/ready flags
- Moving Door Mode to a global shell peer
- Dashboard action-queue duplication inside event Home

---

## 10. Preferred extension points for later slices

1. `EventWorkspaceOverviewBuilder` + overview Twig — Home composition  
2. `EventStudioWorkspacePresentation` — state emphasis / strips  
3. Section plugin weights/titles — nav order after DDR  
4. Topbar variables — primary CTA slot by state  
5. Door Mode entry presentation under Attendees — path may stay; chrome should feel same app  
6. Retire or staff-gate Manager shell after path DDR  

Path unification (`/studio` removal) is a **DDR + Technical Authority** change — not a silent Twig edit.
