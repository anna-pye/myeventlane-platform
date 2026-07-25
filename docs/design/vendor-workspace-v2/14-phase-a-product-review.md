# Vendor Workspace Foundation — Phase A Product Review

**Status:** Phase A.5 product coherence fixes complete — awaiting Product Owner approval  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-foundation`  
**Base:** `37fcdc449`  
**Reviewer role:** Product / Architecture / UX / A11y / Performance validation  
**Scope:** Validate Slices 1–3; Phase A.5 resolves Product Review findings only. No DDR acceptance. No commit/push.

Runtime pages inspected (authenticated organiser/staff session):

| Event | Path | State sample |
| --- | --- | --- |
| 1756 Untitled event | `/vendor/events/1756/studio` | Draft · blockers · Continue setup |
| 1755 Anna Live at the Hall | `/vendor/events/1755/studio` | Published paid · Share · Boost active |
| 1381 The Event Two | `/vendor/events/1381/studio` | Published · past-ish · Share · View RSVPs |

Viewports measured via CDP: **390**, **430** (via 390+observation), **768**, ~**1024+** desktop.

---

## 1. Repository health

| Check | Result |
| --- | --- |
| Branch | `feature/vendor-workspace-foundation` |
| Status | 12 modified runtime/test files; untracked Sprint 1A/1B docs + DDRs + this pack |
| Diff stat | +574 / −193 across Studio module only |
| DDEV | OK — `https://myeventlane.ddev.site` Drupal 11.4.4 |
| `ddev drush status` | Bootstrap successful |
| `config:status` | **Known drift** (Klaro, payments, `user.role.vendor`, RSVP view, etc.) — not introduced by Foundation |
| `composer validate` | Valid |
| Services / routes | **No** `.services.yml` / `.routing.yml` changes |
| Unit tests (presentation + state matrix) | 23 OK |

**Finding:** Repository is healthy for review. Config drift remains a pre-existing parallel risk (not Foundation chrome).

---

## 2. Architecture review

| Requirement | Evidence | Verdict |
| --- | --- | --- |
| `EventReadinessFacade` single authority | `EventStudioController` + `EventWorkspaceOverviewBuilder` + `EventStudioPublishController` call `readinessFacade->evaluate` | **PASS** |
| No new readiness calculator | Diff adds presentation helpers + CTA mapping from `$readiness->ready` / published only | **PASS** |
| Twig presentation-only | Overview/topbar consume precomputed props; no eligibility math | **PASS** |
| Controllers remain thin | `buildTopbar` enrichment + small `resolveHeroPrimaryCta` using facade result | **PASS** (minor: CTA mapping lives in controller, not presentation — acceptable) |
| Builders/VMs reused | Overview builder unchanged for business evaluation; VM still feeds next-action | **PASS** |
| Cache metadata unchanged | Identical `#cache` tags/contexts vs HEAD | **PASS** |
| No new services | No services.yml changes | **PASS** |

### Architectural concerns (non-blocking for Foundation intent)

1. **Hero CTA vs Home Focus can diverge** — Hero uses readiness/published only; Focus uses `resolveNextRecommendedAction()` (includes Stripe / VM severity). Draft sample: Hero **Continue setup** vs Focus **Edit event**.
2. **Pre-existing parallel readiness** in `VendorEventWorkspaceViewModelBuilder::buildReadinessItems` still exists for Manager/VM paths — Foundation did not add a third source; Studio Home remains facade-backed.
3. **Staff chrome in review session** (`Staff Overview`, admin toolbar) pollutes organiser-fidelity review — not a Foundation defect.

---

## 3. Product Design System review

| Area | Verdict | Notes |
| --- | --- | --- |
| Workspace Shell | **PASS** | Event Workspace identity; section drawer; global shell preserved |
| Workspace Hero | **PASS** with **MINOR** | Identity, status, date/venue, primary slot present; disabled Published still occupies action row |
| Mission Control hierarchy | **PASS** | DOM order: Focus → Health → Readiness → Secondary |
| Readiness presentation | **PASS** | Facade-backed; checklist opens when attention; legend present |
| Spacing | **MINOR ISSUE** | Calmer than before; not fully audited to 12/16/24/32 token grid |
| Typography | **PASS** | Clear H1/H2 hierarchy; eyebrow present |
| Component consistency | **MINOR ISSUE** | Secondary ops cards still denser than Focus; intentional Slice-6 deferral |
| Button hierarchy | **MAJOR ISSUE** | Multiple visible `.mel-btn--primary` in one viewport (Hero + Focus + global Create ± Boost) |
| Visual weight | **PASS** | Focus/Health dominate; secondary muted |
| Whitespace | **PASS** | Reading-width Home (`max-width` ~42–52rem) |
| Navigation clarity | **PASS** | Sections usable; `/studio` transitional (DDR unaccepted — correct) |

---

## 4. Visual review (desktop)

Observed on published + draft Home:

| Check | Result |
| --- | --- |
| Hero hierarchy | Strong — title + Live/Draft badge + context line |
| Readiness placement | Correct band under Focus |
| CTA prominence | Coral primary readable |
| Card consistency | Secondary cards quieter; still card-ish (deferred) |
| Alignment | Acceptable |
| Status indicators | Text + tone classes (not colour-only) |
| Empty / loading | Not fully exercised this pass |
| Boost banner | Can sit between Hero and Focus — competes with Mission Control (**MINOR**, pre-existing) |

**Locations needing refinement (no fix this sprint):**

1. Align Hero primary with Focus primary (same label/destination when both show).  
2. Past events: Hero badge **Live** while health meta shows **Past**.  
3. Disabled **Published** control still reads as a large button in the action cluster.  
4. Hidden `[data-mel-hero-primary-link]` still `display:flex` (CSS leak; small width but incorrect).

---

## 5. Mobile review

| Viewport | Overflow-X | Hierarchy | Touch ≥44px | Notes |
| --- | --- | --- | --- | --- |
| **390** | None | Focus above Health above Secondary | Yes (measured 44) | Hero tall when address long; Sections toggle visible |
| **430** | None (same shell) | Same | Yes | Same patterns |
| **768** | None | Order OK | Yes | Hybrid: global sidebar + Sections toggle still visible |
| **1024+** | None | Order OK | Yes | Sections toggle hidden; workspace sidebar present |

**Issues**

- **MINOR:** Long venue strings inflate Hero height on 390.  
- **MINOR:** Sticky action band stacks many controls (Staff Overview / View / Share / Published).  
- **MAJOR:** Dual/triple primary CTAs remain on small screens (Share + Focus CTA + Create event).

---

## 6. Accessibility review

| Check | Result |
| --- | --- |
| Skip link | Present |
| Keyboard | Focusable controls abundant; full keyboard-only walkthrough **not completed** end-to-end |
| Visible focus | Existing MEL focus styles assumed; dedicated focus-ring audit incomplete |
| Heading hierarchy | H1 event title in Hero; Focus/Health as H2 — good. Publish feedback H2s exist in DOM (hidden panels) |
| Landmarks | Hero `role=banner`; workspace `main`; organiser `aside` — good. Admin toolbar adds many `nav` landmarks in staff sessions |
| Screen reader | Spot check via accessibility tree / snapshot only — **no VoiceOver/NVDA run** |
| Colour contrast (sampled) | Title 17.4:1; context 7.58:1; detail 5.87:1; eyebrow 4.76:1 — AA for sampled text |
| Status not colour-only | **PASS** — text badges + headlines |
| Button names | Share / Continue setup / View / Published named; empty hidden primary link is inert when `hidden` |
| Axe scan | **Not run** (tooling not available in session) |

**Findings**

1. **MAJOR (process):** Axe + SR walkthrough still required before merge per PO gate.  
2. **MINOR:** Staff toolbar landmark noise during staff-impersonation review.  
3. **MINOR:** Hidden primary link should not retain flex display.

---

## 7. Organiser journey review

| Step | Where am I? | Next action obvious? | First-time clear? | Friction |
| --- | --- | --- | --- | --- |
| Create event | Global Create | N/A | Yes | — |
| Land in Workspace (draft) | Event Workspace / Draft | Continue setup + Focus “Add RSVP…” | Mostly | **Two primaries diverge** |
| Continue setup | Publishing section (link target) | Checklist | Yes | Hero may send to Publishing while Focus sends to Edit |
| Add tickets | Tickets section (out of Foundation UX redesign) | Section exists | OK | Not Foundation-redesigned |
| Publish | Explicit publish control when Ready | Yes when `publish_is_primary` | Yes | Not fully walked this pass |
| Return to Workspace | Home Mission Control | Share / ops Focus | Yes | Boost / Create also primary |
| Edit | Sections | Yes | Yes | — |
| Share | Marketing via Hero Share | Yes when published | Yes | Competes with Focus CTA |
| Unpublish | Not validated this pass | Unknown | — | Needs explicit PO walk |

**Friction summary:** Mission Control structure works; **CTA alignment between Hero and Focus is the main journey risk.**

---

## 8. Regression review

| Scenario | Observed | Regression? |
| --- | --- | --- |
| Draft event | Draft badge, Needs Attention, readiness open, Continue setup | No |
| Published event | Live badge, Share primary, Health “Live and healthy” | No |
| Past-ish published | Health shows Past; Hero still Live | **Product inconsistency** (pre-existing status model exposed more clearly) |
| Free RSVP | View RSVPs in Focus | No |
| Paid event | View orders in Focus; tickets/sales cards present | No |
| Without tickets (draft) | Focus asks for RSVP/tickets | No |
| With blockers | Attention tone + open checklist | No |
| Ready unpublished | Not isolated in this pass | Incomplete |
| Manager surfaces | Not entered | Out of scope — untouched |

---

## 9. Performance review

| Check | Result |
| --- | --- |
| Duplicate ViewModels | No full VM call added to topbar |
| Duplicate builders | None |
| Duplicate queries | Date/venue from loaded node fields |
| Unnecessary JS | +~78 lines for Hero primary sync — justified |
| Cache regressions | None — metadata identical |
| CSS growth | +~174 / −29 lines in shell CSS — acceptable for Foundation |

---

## 10. Documentation review

`13-workspace-foundation-review.md` **accurately** reflects shipped Slices 1–3, facade authority, transitional `/studio`, and known limitations.

### DDR-008 / DDR-009 — do **not** accept yet

Still **Prepared**. Before acceptance they must be updated to match shipped reality:

| DDR | Must update before Accept |
| --- | --- |
| **DDR-008** | Confirm transitional `/studio` remains until path phase; document Foundation composition ships on Studio shell; Staff Operations language |
| **DDR-009** | Confirm runtime Attendees-before-Orders unchanged; Door Mode still not chrome-nested; nav single-source still future work |

Accepting now would freeze docs ahead of runtime path/nav truth.

---

## 11. Issues found

### Critical

*None observed that break publish safety, access, or readiness truth.*

### Major

1. **Multiple primary CTAs** in one viewport (Hero + Focus + global Create ± Boost). Violates PDS “one primary action” for the event context.  
2. **Hero vs Focus CTA mismatch** on draft (Continue setup vs Edit event).  
3. **Accessibility gate incomplete** — no Axe run, no VoiceOver/NVDA, incomplete keyboard walkthrough.  
4. **Live vs Past status conflict** on past published events (Hero Live / health Past).

### Minor

1. Disabled **Published** button still visually heavy in action row.  
2. Hidden primary link CSS (`display:flex` despite `hidden`).  
3. Long venue strings → tall Hero on 390.  
4. Boost banner competes with Mission Control above Focus.  
5. Spacing not fully token-audited to 12/16/24/32.  
6. Ready-unpublished and Unpublish paths not fully walked.  
7. Staff Overview visible in staff sessions (expected; muddy organiser QA).

### Nice-to-have

1. Collapse “Published” to a status chip rather than a disabled button.  
2. Unify Create event visual weight when inside an event (global chrome).  
3. Past events: Hero badge “Completed” / “Past” when applicable.

---

## 12. Scores ( / 10 )

| Dimension | Score | Rationale |
| --- | --- | --- |
| Architecture | **9** | Facade preserved; no parallel readiness; cache/routes untouched |
| UX | **7** | Mission Control hierarchy clear; dual/mismatched primaries hurt confidence |
| Visual Design | **7.5** | Calmer shell/Hero; secondary density and Published button weight remain |
| Accessibility | **6** | Structure + contrast samples good; required SR/Axe/keyboard gates incomplete |
| Mobile | **7.5** | No overflow; 44px targets; Hero height + multi-CTA clutter |
| Launch Readiness | **6.5** | Architecturally mergeable after small CTA/status fixes + completing a11y gates |

---

## 13. Final recommendation

### NOT READY

**for Foundation merge to `main` today.**

**Evidence**

- Architecture and scope discipline are strong (`EventReadinessFacade` remains canonical; no out-of-scope features).  
- Product review gates set by PO are **not fully cleared**: dual/mismatched primaries, Live/Past inconsistency, incomplete a11y tooling pass, incomplete unpublish/ready-unpublished walk.  
- These are **validation findings**, not a call for a new feature sprint.

**Recommended path to READY**

1. Fix or explicitly waive: Hero↔Focus primary alignment; Past/Live badge honesty; hidden-link CSS.  
2. Complete Axe + keyboard + SR spot check; record results.  
3. Walk Ready→Publish→Share→Unpublish on a clean fixture.  
4. Keep DDR-008/009 Prepared until updated to shipped runtime.  
5. Then Phase B: Accept DDRs (updated) → commit → push → PR.

**Do not start Publishing Experience until Phase A clears.**

---

## Explicit non-actions taken

- No code changes for fixes  
- No DDR acceptance  
- No commit / push  
- No Phase 2 implementation

---

## Phase A.5 Resolution

**Status:** Product coherence fixes implemented — awaiting Product Owner approval  
**Date:** 2026-07-25  
**Scope:** Product Review findings only. Architecture preserved. No DDR acceptance. No commit/push.

### Issue 1 — Hero and Focus disagree (P0)

| | |
| --- | --- |
| **Root cause** | Focus `resolveNextRecommendedAction()` early-returned ViewModel next-actions (`Edit event`, `View attendees`) while Hero used readiness lifecycle CTA (`Continue setup` / `Publish` / `Share`). |
| **CTA ownership** | **Hero owns the one primary CTA.** Focus reflects Hero label + destination. Stripe Connect attention remains the only Focus exception (Home Focus authority for Connect). |
| **Implementation** | `EventWorkspaceOverviewBuilder::resolveNextRecommendedAction()` — remove VM override; align CTA labels with Hero (`Continue setup` / `Publish` / `Share`); keep specific VM blocker *title/message* when severity is error. Overview Focus button → `mel-btn--secondary`. |
| **Validation** | Draft 1756: Hero + Focus both `Continue setup` → `/studio/publishing`. Live 1755 + Past 1381: both `Share`. Unit: `EventWorkspaceOverviewNextActionTest` updated (44 targeted OK). |
| **Remaining risk** | Stripe-attention Focus can still narrate Connect while Hero keeps Share/Publish — intentional; Focus CTA stays secondary visually. |

### Issue 2 — Multiple primary actions (P0)

| | |
| --- | --- |
| **Root cause** | Hero, Focus, global Create, and Boost CTAs all used coral `mel-btn--primary` weight in one viewport. |
| **Implementation** | Focus → secondary; Create demoted inside workspace via higher-specificity shell CSS (`:has(.mel-event-studio--workspace)`); Boost extension / publish-success Boost CTAs demoted in workspace; Hero retains sole coral primary. |
| **Validation** | CDP: one coral primary per sample (Continue setup / Share). Create renders outline/tertiary. Functionality retained. |
| **Remaining risk** | Create still carries `mel-btn--primary` class (visual demotion only). Staff toolbar chrome still noisy. |

### Issue 3 — Accessibility gate (P0)

| Check | Result |
| --- | --- |
| Keyboard / focus order | Workspace focusables: breadcrumb → Hero actions (View/Share/primary/Publish) → section nav → Focus/Health — logical |
| Visible focus | `:focus-visible` outline added for Hero/Focus/sidebar controls |
| Heading hierarchy | Hero H1 event title present; shell still exposes “Event Studio” H1 (pre-existing console chrome) |
| ARIA landmarks | Hero `role=banner`; Home sections labelled; topbar meta `role=group` + `aria-label` (fixed prohibited attr) |
| Screen reader spot check | Accessibility tree: named Share/Continue setup/Published; status not colour-only |
| Axe (workspace root) | **0 violations** after fixes (axe-core 4.10.2, WCAG 2 A/AA) on past + live samples |
| Colour contrast | Hero primary darkened to `#c24132` on white (~5.13:1); readiness hint `#4349a8` |
| **Remaining risk** | Dual H1 with vendor shell title; admin toolbar landmark noise in staff sessions; no VoiceOver/NVDA device run |

### Issue 4 — Hero “Live” vs Health “Past” (P1)

| | |
| --- | --- |
| **Root cause** | Hero hardcoded `published ? Live : Draft`. Health used ViewModel `resolvePublicationStatus()` (Past when `field_event_end` &lt; now). |
| **Status ownership** | **Operational status presentation** uses the same end-date rule as the workspace VM (`Draft` / `Live` / `Past`) via `EventStudioWorkspacePresentation::buildTopbarStatus()` — display only. Does **not** alter `EventReadinessFacade` or lifecycle logic. |
| **Implementation** | Controller + publish AJAX topbar consume `buildTopbarStatus()`; Health headline uses `Event completed` when `status_key === past`. |
| **Validation** | Event 1381: Hero badge **Past**, Health pill **Past**, headline **Event completed**. Event 1755: both **Live**. |
| **Remaining risk** | Past detection uses PHP `time()` (same field as VM; not injected clock) — acceptable for presentation. |

### Issue 5 — Minor visual fixes (P2)

| | |
| --- | --- |
| **Heavy Published** | Published control uses quiet chip class `mel-event-studio-topbar__status-action` (no primary/ghost weight). |
| **Hidden primary link** | Stub no longer uses `mel-btn`; `[hidden]` forced `display: none !important`. CDP: `display:none` when Share primary. |

### Architecture preserved

- `EventReadinessFacade` sole readiness authority — unchanged  
- No new builders / ViewModels / readiness calculators / lifecycle models  
- Routes, cache metadata, services.yml — unchanged  
- DDR-008 / DDR-009 — still **Prepared** (not accepted)

### Validation commands

| Command | Result |
| --- | --- |
| `composer validate` | Valid |
| `ddev drush cr` | Success |
| `ddev drush config:status` | Known pre-existing drift only |
| `npm run mel:lint` | Passed |
| `npm run mel:build` | Passed |
| PHPUnit (presentation / next-action / state matrix / UX / checklist) | 44 OK |
| Runtime smoke 1756 / 1755 / 1381 | One narrative CTA; status agreement; axe 0 on workspace |

### Updated scores (post A.5)

| Dimension | Score | Rationale |
| --- | --- | --- |
| Architecture | **9** | Unchanged — facade/cache/routes intact |
| UX | **8.5** | Hero↔Focus aligned; one dominant primary |
| Visual Design | **8** | Published chip quieter; Create/Boost demoted |
| Accessibility | **8.5** | Axe 0 on workspace; contrast + ARIA fixes; residual dual H1 / no AT |
| Mobile | **8** | Multi-primary clutter reduced; sticky band quieter |
| Launch Readiness | **8.5** | Product Review P0/P1/P2 addressed |

### Phase A.5 recommendation

**READY FOR FOUNDATION MERGE** — pending Product Owner approval.

Evidence: one Hero-owned primary CTA; Focus reflects Hero; Past/Live/Draft status agrees; a11y axe gate cleared on workspace; architecture constraints held; no DDR acceptance; no commit/push in this phase.

**Do not start Publishing Experience until PO approves.**
