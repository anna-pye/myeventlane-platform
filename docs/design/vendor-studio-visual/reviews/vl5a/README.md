# VL-5A — Launch Success Alternative A

**Date:** 2026-07-25  
**Status:** ✅ VL-5A merged via PR #721 · VL-5A.1 conformance correction approved  
**Scope:** Outcome zone presentation only — Alternative A  
**Branch:** `feature/vendor-workspace-vl5a-launch-success`

---

## Zone map

```text
Identity: Hero updates to Live / Share — frozen behaviour
Guidance: Mission Control refreshes through existing contract — frozen
Work:     Launch Centre remains frozen
Outcome:  Launch Success Alternative A — VL-5A scope
```

## Product Design System References

This implementation follows:

- `docs/design/vendor-studio-visual/07-workspace-zones.md` (Outcome)
- `docs/design/vendor-studio-visual/03-option-b5.md`
- `docs/design/vendor-workspace-v2/19-publishing-ux-rules.md`
- `docs/design/vendor-workspace-v2/20-launch-success-experience.md` (Alternative A)
- `docs/design/vendor-studio-visual/06-implementation-guide.md` (VL-5 boundary)
- ADR-0001
- ADR-0002

---

## What shipped

| Surface | Change |
| --- | --- |
| Handoff payload | Additive Alternative A fields on `buildPublishSuccessHandoff` (same model) |
| Workspace Twig | Success panel → Alt A structure |
| Shell JS | Render Alt A; focus success heading; copy link; reduced-motion-safe enter |
| Vendor SCSS | `_mel-event-studio-launch-success.scss` Outcome presentation |
| Module shell CSS | Baseline success layout (no Boost-as-primary card) |

**Unchanged / frozen:** Hero CTA resolver · Mission Control · Launch Centre composition · eligibility · Commerce · routes · social networks set

---

## Event used

| Path | Event | Notes |
| --- | --- | --- |
| `?mel_celebrate=1` | **1583** (paid, published) | Same handoff as AJAX success |

No event data mutated for screenshots.

---

## Screenshots

| File | Viewport | What |
| --- | --- | --- |
| `vl5a-celebrate-desktop.png` | ~1440 | Outcome panel + Identity Live / Share |
| `vl5a-celebrate-768.png` | 768 | Workspace with success path |
| `vl5a-celebrate-768-panel.png` | 768 | Success panel crop |
| `vl5a-celebrate-390.png` | 390 | Stacked primary / secondary |
| `vl5a-celebrate-390-title.png` | 390 | Panel + Publishing Work below |

---

## Accessibility checks (CDP)

| Check | Result |
| --- | --- |
| Focus moves to success heading | `document.activeElement.id === mel-publish-success-title` |
| Live region | `aria-live="polite"` on `[data-mel-publish-feedback]`; announce text “Your event is now live” |
| Touch targets | Share / Copy / View `min-height: 44px` |
| Share primary colour | `rgb(107, 70, 255)` purple fill |
| Panel surface | White soft panel on Warm Cream |
| Paid people_can | discover · buy tickets · share |
| `prefers-reduced-motion: reduce` | Enter class suppressed; `animation-name: none` |
| Hero primary when live | Share (frozen Identity behaviour) |

---

## Automated tests

```text
EventStudioLaunchSuccessTest — 5 tests, 66 assertions — OK
```

---

## Validate commands run

```bash
npm run mel:lint   # pass
npm run mel:build  # pass (public + vendor)
ddev drush cr      # success
./vendor/bin/phpunit …/EventStudioLaunchSuccessTest.php  # OK
```

---

## Residual risk

- Builder-path celebrate UI in `mel-event-studio.html.twig` (non-workspace) still uses legacy celebrate + Boost card — out of VL-5A Workspace Outcome scope.
- Secondary social intent links remain available (existing networks only); marketing Share remains the recommended primary.
- VL-5A.1 corrects the Outcome zone order and action hierarchy before VL-5B.
