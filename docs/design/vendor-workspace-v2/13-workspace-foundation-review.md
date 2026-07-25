# Vendor Workspace v2 — Foundation Review (Slices 1–3)

**Status:** Implementation complete — awaiting Product Owner review  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-foundation`  
**Base:** `origin/main` @ `37fcdc449` (Dashboard Foundation PR #716)  
**Scope:** Slice 1 Shell · Slice 2 Hero · Slice 3 Readiness presentation only  

No commit / push in this sprint.

---

## Repository summary

| Check | Result |
| --- | --- |
| Branch | `feature/vendor-workspace-foundation` tracking `origin/main` |
| Dashboard Foundation | Merged (`37fcdc449`) |
| Discovery + Architecture docs | Present under `docs/design/vendor-workspace-v2/` (untracked from Sprint 1A/1B) |
| Runtime health | Drupal 11.4.4 bootstrap OK |
| Config drift | **Known** (Klaro, payments, `user.role.vendor`, RSVP view) — unchanged by this sprint |
| DDR-008 / DDR-009 | Still **Prepared** (not Accepted) — composition used transitional `/studio` |

---

## Files changed

### Runtime

| Path | Slice | Why |
| --- | --- | --- |
| `…/templates/mel-event-studio-workspace.html.twig` | 1 | Shell comment / Home ownership clarity |
| `…/templates/mel-event-studio-sidebar.html.twig` | 1 | “Event Workspace” labelling |
| `…/templates/mel-event-studio-topbar.html.twig` | 2 | Workspace Hero: identity, status, date/venue, one primary CTA, View/Share |
| `…/templates/mel-event-studio-overview.html.twig` | 1–3 | Mission Control hierarchy: Focus → Health → Readiness → quieter ops |
| `…/css/mel-event-studio-shell.css` | 1–3 | Calmer hero + Home spacing; Reading-width Focus; mobile sticky primary |
| `…/js/mel-event-studio-shell.js` | 2–3 | Hero primary sync after publish AJAX; health class sync |
| `…/src/Controller/EventStudioController.php` | 2 | Topbar date/venue/share + `resolveHeroPrimaryCta()` from readiness |
| `…/src/Controller/EventStudioPublishController.php` | 2 | AJAX topbar carries `primary_cta` + date/venue |
| `…/src/Service/EventStudioWorkspacePresentation.php` | 2–3 | `buildTopbarDateLabel` / `buildTopbarVenueLabel`; clearer strip copy |

### Tests

| Path | Why |
| --- | --- |
| `EventStudioWorkspacePresentationContractTest.php` | Hero contract assertions |
| `EventStudioWorkspaceStateMatrixTest.php` | Strip title expectations |
| `EventStudioWorkspaceUxConsolidationTest.php` | JS fallback copy |

### Docs

| Path | Why |
| --- | --- |
| `13-workspace-foundation-review.md` | This review |

---

## Architecture followed

- Studio remains organiser truth (`mel_event_studio_workspace`, `/studio`)
- No route rename, no Manager retirement, no nav collapse, no path unification
- Dashboard vs Workspace scope unchanged
- Mission Control: one narrative above the fold; one primary action
- Lifecycle CTA rules from Sprint 1B (Continue setup → Publish → Share) using existing readiness/published signals
- Stripe Connect attention remains Home Focus authority (not duplicated in Hero)

---

## Reused

| Kind | Assets |
| --- | --- |
| **Components / patterns** | `.mel-btn`, existing Studio shell BEM, readiness checklist marks |
| **Builders** | `EventWorkspaceOverviewBuilder` (unchanged business logic), `EventStudioSectionManager` |
| **ViewModels** | `VendorEventWorkspaceViewModelBuilder` still feeds Home next-action; Hero date uses same `field_event_start` as VM |
| **Readiness** | `EventReadinessFacade`, `PublishEligibilityEvaluator` (via facade), presentation-only strip/Home |
| **Libraries** | `mel_event_studio_shell_only` |

**Not created:** new builders, new themes, parallel kits, new routes.

---

## Accessibility review

| Requirement | Status |
| --- | --- |
| Status not colour-only | Health strip + text headlines + SR labels on checklist |
| Focus / keyboard | Existing shell drawer + focus patterns preserved; primary CTAs `min-height: 44px` |
| ARIA | Hero banner; health labelled heading; checklist SR prefixes |
| Reduced motion | No new motion introduced |
| 390px | Topbar stacks; sticky primary action band; Focus first |

Residual: full axe pass in browser not run in this sprint — recommend Product visual + keyboard pass.

---

## Performance review

| Concern | Status |
| --- | --- |
| Duplicate ViewModel on every section | **Avoided** — Hero date/venue from presentation field reads, not full VM |
| Duplicate builders | None |
| Cache | Workspace `#cache` tags/contexts unchanged on controller return |
| Extra queries | Date/venue use fields already loaded on `$node` |

---

## Validation run

| Command | Result |
| --- | --- |
| `ddev drush cr` | Success |
| `ddev drush config:status` | Known drift only (pre-existing) |
| `composer validate` | Valid |
| `npm run mel:lint` | Passed |
| `npm run mel:build` | Passed |
| PHPUnit (presentation, state matrix, UX, next-action) | 36 tests OK |
| Smoke: readiness + date/venue on event 1094 | Published+ready; strip “Live and ready” |
| Smoke: render `mel_event_studio_topbar` | Hero labels + primary key OK |

---

## Known limitations

1. DDR-008/009 not Accepted — `/studio` remains transitional.
2. Config drift (`user.role.vendor`, RSVP ownership) still open — not touched here.
3. Ops/business cards still present (muted) — Slice 6 will redesign pulse rows.
4. Hero primary does not re-run Stripe evaluation (by design — Home Focus owns Connect).
5. After in-place publish, Share becomes primary via JS; full page refresh remains authoritative.

---

## Technical debt (unchanged / residual)

- Dual Manager shell still in codebase  
- Triple nav sources  
- Door Mode chrome split  
- ADR-0002 missing  

---

## Future slices

4 Publishing · 5 Tickets · 6 Operations (Door continuity) · 7 Polish · later path unification (DDR-008)

---

## Design compliance

| Mission Control question | Foundation answer |
| --- | --- |
| Where am I? | Hero identity + Event Workspace eyebrow + status |
| How healthy? | Health strip + readiness progress |
| What needs attention? | Today’s Focus |
| What next? | One primary (Hero + Focus) |
| How close to success? | `@done of @total complete` |

Avoided: KPI walls as hero, nested card competition above fold, dual primary Publish+Focus of equal weight when setup incomplete.

---

## Recommendation

**Ready for Product Review?** → see sprint final report.
