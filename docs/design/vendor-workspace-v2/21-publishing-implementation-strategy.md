# Vendor Workspace v2 — Publishing Implementation Strategy

**Status:** Implementation readiness (Sprint 3B) — documentation only  
**Date:** 2026-07-25  
**Do not implement until Product Owner review of docs `15`–`20`.**  
**Frozen:** Mission Control · Workspace Foundation chrome contracts  
**No DDR acceptance in this sprint.**

---

## 1. Strategy summary

Build **Launch Centre** as a presentation refactor of the existing Publishing section — not a new app, route family, or eligibility engine.

```text
KEEP     PublishEligibilityEvaluator · EventReadinessService · Facade
KEEP     EventStudioPublishController · setNodePublishedState
KEEP     resolveAuthoritativePrimaryCta (extend carefully for Past)
KEEP     buildPublishSuccessHandoff · shell AJAX publish
CHANGE   EventStudioSectionRenderer::buildPublishingHub composition
CHANGE   Twig/SCSS presentation for Launch Centre bands
CHANGE   Shell JS feedback/success rendering toward Alternative A
AVOID    New publish architecture · new readiness calculator · MC redesign
```

---

## 2. Reuse map

| Need | Reuse |
| --- | --- |
| Eligibility | `myeventlane_event_studio.publish_eligibility` |
| Readiness checklist data | `EventReadinessService` / `EventReadinessFacade` |
| Publish mutation | `EventStudioPublishController` + `EventStudioSaveService::setNodePublishedState` |
| Hero CTA | `EventWorkspaceOverviewBuilder::resolveAuthoritativePrimaryCta` |
| Success URLs/copy seed | `EventStudioPreprocess::buildPublishSuccessHandoff` |
| Section plug-in | `PublishingSection` (keep id `publishing`) |
| Buttons / tokens | Vendor theme `.mel-btn`, existing Studio SCSS |
| Access | `EventStudioAccess` — no weakening |

---

## 3. Files likely to change (implementation sprint)

### PHP (presentation / light orchestration)

| File | Change type |
| --- | --- |
| `.../Service/EventStudioSectionRenderer.php` | Rebuild `buildPublishingHub()` into Launch Centre bands; stop embedding full `EventSettingsForm` by default |
| `.../Service/EventWorkspaceOverviewBuilder.php` | Optional Past CTA extension in `resolveAuthoritativePrimaryCta` **only if PO approves** |
| `.../EventStudioPreprocess.php` | Enrich handoff copy for Alternative A (people can…); keep structure |
| `.../Form/EventSettingsForm.php` | Extract slim visibility fragment **or** link out — do not delete form |
| `.../Controller/EventStudioController.php` | Pass Launch Centre presentation vars if needed (keep thin) |

### Twig

| File | Change type |
| --- | --- |
| New theme suggestion e.g. `mel-event-studio-launch-centre.html.twig` **or** structured render arrays only | Prefer one dedicated template for clarity |
| `mel-event-studio-workspace.html.twig` | Slot success region if required |
| `mel-publish-boost-cta.html.twig` | Demote placement / conditional |

### JS / CSS

| File | Change type |
| --- | --- |
| `js/mel-event-studio-shell.js` | Success feedback → Alternative A; ensure focus + aria-live; **remove/ignore card Publish as primary** |
| Vendor / Studio SCSS (`_mel-builder.scss`, workspace publishing) | Launch Centre layout; 390 sticky already in chrome |

### Tests

| File | Change type |
| --- | --- |
| `PublishEligibilityEvaluatorTest` | Unchanged unless gates change (should not) |
| `EventStudioWorkspacePresentationContractTest` | Assert Launch Centre props / CTA |
| `EventWorkspaceOverviewNextActionTest` | If CTA resolver extended |
| New unit test for hub builder presentation | Checklist bands without Settings dump |

---

## 4. Files that must NOT change

| File / area | Why |
| --- | --- |
| Mission Control template/SCSS contracts (Phase 2 frozen) | Explicit freeze |
| `PublishEligibilityEvaluator` rule order | Source of enforcement truth |
| `VendorPublishRequirementsGate` / `PaidPublishStripeGate` logic | Payments/safety — no drive-by |
| Checkout / Commerce payment gateways | Out of scope |
| Legacy wizard validators (unless deleting dead code in later cleanup) | Separate chore |
| DDR documents / accepting DDRs | PO process |
| Public theme event cards / heroes | Unrelated |

---

## 5. Proposed implementation slices (post-PO)

| Slice | Scope | Risk |
| --- | --- | --- |
| **3C.1** | Launch Centre layout: Ready / checklist / control hint / aftercare — data from existing readiness | Theme/UX |
| **3C.2** | Remove dual Publish: card becomes status; Hero only | CTA regression — test matrix |
| **3C.3** | Success Alternative A wiring (handoff + focus + a11y) | Low |
| **3C.4** | Visibility progressive disclosure or Settings link | Form split care |
| **3C.5** | Unpublish confirm UX | Destructive — careful |
| **3C.6** | Past CTA (optional) | Requires date logic confirmation |

Do not start 3C until PO marks design READY.

---

## 6. Cache implications

| Item | Notes |
| --- | --- |
| Workspace `#cache` | Keep existing contexts (`mel_celebrate`, user, route, permissions) |
| Publish JSON | Remains uncached |
| New template | Add cache contexts/tags from event + readiness deps via controllers/builders as today |
| Avoid | Caching “Ready” without user/vendor context |

---

## 7. AJAX implications

| Keep | Careful |
| --- | --- |
| POST publish/unpublish contract | Payload shape consumed by shell JS |
| Mission Control refresh after publish | Do not alter MC IA while refreshing |
| `primary_cta` in topbar | Must stay authoritative |
| Handoff on success | Extend fields additively; don’t rename without JS update |

**Contract test:** after publish 200, Hero key `share`, Launch Centre shows live narrative, MC payload non-null (existing degraded fallbacks remain).

---

## 8. Testing strategy

### Automated

- Unit: eligibility unchanged golden cases
- Unit: hub builder — Ready vs Needs attention band props
- Unit: CTA resolver matrix (include Past if implemented)
- Contract: shell selectors still find `[data-mel-publish-action]` once
- PHPUnit existing Studio workspace state matrix updates

### Manual / browser

| Case | Viewport |
| --- | --- |
| Draft → checklist blockers → Continue setup | 390, desktop |
| Ready → single Publish in Hero → success A | 390, 768, desktop |
| Live → Share primary; Boost secondary | desktop |
| Failure 422 readiness | desktop |
| Failure 409 stale / autosave | desktop |
| Unpublish confirm → draft | desktop |
| Keyboard-only publish + focus to success | desktop |
| `prefers-reduced-motion` | desktop |
| Paid Stripe blocked | desktop |

### Regression guards

- Mission Control Home unchanged visually (screenshot / checklist from Phase A.5)
- No publish without eligibility (attempt via UI + confirm 422)
- Vendor isolation: other vendor’s event 403

---

## 9. Config / deploy

- Expect **no** `config/sync` changes for Launch Centre presentation.
- If visibility field widgets move, still no config unless form display modes change — verify before cex.
- Validation commands after implementation:

```bash
ddev drush cr
ddev drush config:status
composer validate
npm run mel:lint
npm run mel:build
# targeted phpunit for event_studio unit tests
```

---

## 10. Risks

| Risk | Mitigation |
| --- | --- |
| Splitting Settings form breaks passcode validation | Keep `EventSettingsForm` intact on Settings route; Launch only slim/link |
| Removing card Publish confuses users who learned card path | Header hint + sticky mobile CTA |
| Past CTA wrong date timezone | Confirm `field_event_end` handling from existing presentation helpers before coding |
| Success panel fights Boost card | Explicit demotion in Twig |
| Scope creep into MC | Freeze enforcement in PR checklist |
| Legacy redirects still → Settings | Optional follow-up: redirect publish legacy → Launch Centre |

---

## 11. Outstanding questions (blockers for some slices)

1. Visibility on Launch Centre vs Settings-only? (affects 3C.4)
2. Past/Closed CTA in resolver now or later? (affects 3C.6)
3. First-publish celebrate C vs always A? (affects 3C.3)
4. Unpublish modal vs disclose? (affects 3C.5)

Non-blocking for 3C.1–3C.2 if PO accepts defaults in `16` recommendation seed.

---

## 12. Definition of done (implementation sprint)

- [ ] Launch Centre answers “Am I ready to launch?” in ≤5 seconds
- [ ] Exactly one primary Publish affordance when Ready
- [ ] Eligibility/readiness ownership unchanged
- [ ] Success Alternative A shipped with a11y focus/live region
- [ ] Mission Control visually unchanged
- [ ] Tests + mel:lint/build + drush cr green
- [ ] PDS references on PR; no DDR silently accepted

---

## Recommendation

**READY FOR IMPLEMENTATION** of slices **3C.1–3C.3** after PO sign-off on docs `15`–`20`, assuming defaults:

- Visibility secondary
- Scheduled publish deferred
- Success Alternative A
- Past CTA deferred to 3C.6

**ADDITIONAL DISCOVERY REQUIRED** only if PO rejects defaults on visibility/Past/unpublish — then spike those before coding.
