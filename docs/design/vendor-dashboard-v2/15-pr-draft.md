# PR Draft — Vendor Dashboard v2 Foundation

**Do not open until commit is approved.**

## Suggested title

`feat(vendor-dashboard): v2 foundation — Action Queue first + operational awareness`

## Suggested commit message

```
feat(vendor-dashboard): ship v2 foundation (Slices 1–2)

Make the organiser dashboard Action-Queue-first with calm empty state,
Today's focus, factual Daily Brief, lean KPIs, and safe cache metadata —
aligned to Vendor Studio PDS without new architecture.
```

## Suggested PR body

```markdown
## Summary

- Reorders `/vendor/dashboard` to Action-Queue-first (Slice 1) with calm caught-up empty state and a single ≤4 KPI strip.
- Adds operational awareness (Slice 2): Today's focus panel, factual Daily Brief, lean KPI presentation, activity grouping, skeleton chrome.
- Hardens cache (`user` / `user.roles` / `timezone`, entity tags, `max-age` 300 for relative-time copy).
- No new routes, permissions, config, AI, or parallel dashboard architecture.

## Vendor Studio Design System References

This implementation follows:

- docs/design/vendor-studio/01-vendor-studio-vision.md
- docs/design/vendor-studio/02-information-architecture.md
- docs/design/vendor-studio/03-layout-system.md
- docs/design/vendor-studio/05-component-library.md
- docs/design/vendor-studio/06-workspace-patterns.md
- docs/design/vendor-studio/07-interaction-guidelines.md
- docs/design/vendor-studio/08-mobile-guidelines.md
- docs/design/vendor-studio/09-drupal-mapping.md
- docs/design/vendor-studio/11-design-tokens.md
- docs/design/vendor-studio/12-dashboard-philosophy.md
- docs/design/vendor-studio/15-copywriting-guide.md
- docs/design/vendor-studio/16-design-review-checklist.md
- docs/design/vendor-studio/18-product-success-metrics.md
- docs/design/vendor-studio/19-anti-patterns.md
- docs/design/vendor-studio/21-definition-of-done.md
- ADR-0001
- ADR-0002 (intent — file missing in pack)
- DDR-001
- DDR-003
- DDR-004
- DDR-005

**PDS home:** `docs/design/vendor-studio/` (v1.0 FROZEN)

Please also apply the GitHub label: `design-system`

## Definition of Done / checklist

- [x] Applicable gates in `docs/design/vendor-studio/21-definition-of-done.md` — see `docs/design/vendor-dashboard-v2/13-definition-of-done-gates.md`
- [x] `16-design-review-checklist` filled — see `docs/design/vendor-dashboard-v2/12-design-review-checklist.md`
- [x] No contradiction of higher-order PDS docs (ADR-0001)
- [ ] Design Authority sign-off
- [ ] Technical Authority sign-off (cache)

## Test plan

- [ ] Empty vendor: welcome + Create event; queue may show profile/Stripe cards
- [ ] Caught-up vendor: calm empty queue + Create / View events
- [ ] Live / doors-open event: Today's event panel + Workspace / Door Mode
- [ ] Distant upcoming only: Today's panel hidden; Upcoming list present
- [ ] Daily Brief appears only with factual lines; hidden otherwise
- [ ] Business health shows ≤4 KPIs; no duplicate metric strips above Tools
- [ ] Tools / Pro / Stripe remain below attention path
- [ ] `prefers-reduced-motion`: skeleton does not pulse
- [ ] Keyboard: tab through queue CTAs, focus panel, KPIs
- [ ] `ddev drush cr` after deploy; smoke `/vendor/dashboard`

## Risk

- **Cache:** Relative doors/brief copy uses `max-age` 300; Pro welcome remains `max-age` 0.
- **Access:** Unchanged; Door Mode URLs remain access-checked.
- **Commerce:** Display-only reuse of existing completed order / RSVP signals — no checkout/payment mutation.
- **PII:** No new PII surfaces.
- **Residual:** Dual event loading (controller vs view model); optional progressive skeleton JS not wired.

## Merge docs

- `docs/design/vendor-dashboard-v2/09-merge-readiness.md`
- `docs/design/vendor-dashboard-v2/10-feature-implementation.md`
- `docs/design/vendor-dashboard-v2/11-implementation-checklist.md`
```

---

## Final implementation review (verdict)

| Dimension | Verdict |
| --- | --- |
| PDS alignment | **Pass** after hierarchy / today-gate hardening |
| DoD | **Pass** pending human sign-offs |
| Accessibility | **Pass** for SSR surface; progressive skeleton deferred |
| Cache | **Pass** with `max-age` 300 + contexts/tags |
| Performance | **Pass** — no new queries |
| DDR | **None required** |
| Merge readiness | **Ready to commit when you instruct** |

**STOP — no commit, no push.**
