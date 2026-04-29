# Event full page polish — Task 10 audit

**Branch:** `cursor/onboard-storage-fix-128b4`  
**Date:** 2026-04-29  
**Scope:** Full event page (`node--event--full`) UI only — no ticketing/checkout/Stripe/vendor/help/schema changes.

## Phase 1 — Preflight

| Check | Result |
| --- | --- |
| Branch | `cursor/onboard-storage-fix-128b4` |
| Latest commit (pre-change) | `a0e82d01` — `fix(theme): polish discovery chips and mobile filters` (Task 9) |
| `composer validate` | Valid |
| `ddev drush cr` | OK |
| `npm run mel:lint` | Pass |
| `npm run mel:build` | Pass |

Task 9 work was already committed on this branch before these edits; Task 10 changes were uncommitted until documented here.

## Phase 2 — Template map (answers)

1. **Full event Twig:** `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`
2. **Full event layout SCSS:** `web/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss` (also `_event-hero.scss`, `_event-card.scss` for hero variant)
3. **CTA/card:** Booking sidebar `mel-card--sticky` + `partial--event-full-booking-cta.html.twig`; mobile bar at bottom of same template
4. **Share (after change):** Sidebar booking panel block `.mel-booking-panel__share` (was under About in main column)
5. **Calendar:** Google Calendar link built in Twig (`mel_gcal_href`) + trust row “Add to calendar” in sidebar
6. **Organiser:** `mel_organiser` — `myeventlane_theme_preprocess_node__event()` + organiser card in full template
7. **Variables:** From `myeventlane_event` preprocess: `cta_type`, `event_cta`, `event_ui`, `mel_tickets_from`, `event_domain_state`, etc.; theme adds hero helpers, `mel_address`, `mel_organiser`, `mel_booking_action_label` (full view, display-only)
8. **Fallbacks:** Hero image placeholder div if no image; category from field; address/venue chains in Twig; organiser fallback initial + “Events on MyEventLane”

## Phase 3 — Browser audit

**Not run in this environment** (no interactive browser). Recommended manual checks:

- `/event/1567`, `/event/1540` — CTA labels, mobile order (booking card before About), sticky footer CTA, share row in sidebar
- `/event/1567/book`, `/event/1540/book` — visual regression only (out of scope for logic)

## Phase 4 — Issue classification (addressed in Phase 5)

| Severity | Issue | Action |
| --- | --- | --- |
| P1 | Primary CTA showed “Continue to book” instead of resolver/event labels | Partial now uses `mel_cta_text` (+ fallbacks) |
| P1 | RSVP primary copy wanted as “Book free RSVP” | Theme `mel_booking_action_label` + Twig override |
| P1 | Mobile: booking sidebar below long main column | CSS flex order: sidebar `order: 1`, main sections `order: 2` |
| P1 | Share competing with About; needed under primary CTA | Moved share into sidebar below CTA |
| P2 | Share hit targets &lt; 44px | `.mel-social` 40px → 44px |
| P2 | Decision prompt hover motion | `prefers-reduced-motion` disable transform |

## Phase 5 — Files changed

| File | Change |
| --- | --- |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | `mel_booking_action_label` for full view (primary paid/RSVP display labels) |
| `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig` | Merge display label; remove main-column share; add sidebar share + separator; sidebar title fallback “Book free RSVP” |
| `web/themes/custom/myeventlane_theme/templates/node/partial--event-full-booking-cta.html.twig` | Primary link text from `mel_cta_text` / resolver label |
| `web/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss` | Mobile column order; share sidebar styles; 44px share targets; reduced motion on `.mel-decision-item` |

## Before / after (summary)

- **CTA copy:** Anchors match public labels (e.g. Buy tickets, Book free RSVP) instead of generic “Continue to book”.
- **Mobile stack:** Booking card surfaces before About/highlights when viewport ≤768px.
- **Share + calendar:** Share sits under the primary CTA in the sidebar; calendar link remains in trust block below.
- **A11y:** Larger share targets; external share labels note new tab; reduced motion on decision prompts.

## Commands run (verification)

```bash
git branch --show-current
git status --short
git log -10 --oneline
composer validate
ddev drush cr
npm run mel:lint
npm run mel:build
php -l web/themes/custom/myeventlane_theme/myeventlane_theme.theme
ddev drush ws --count=80 | grep -Ei "theme|twig|event|view|category|error|exception|warning" || true
```

## Remaining P1 / P2

**P1 (needs manual confirmation):**

- Keyboard tab order on mobile reorder (sidebar first): confirm logical reading order with screen readers
- Fixed `.mel-mobile-cta` vs in-flow card: both still present — acceptable pattern; confirm no double-announcing in VoiceOver

**P2:**

- Trust rotator vs decision prompts: still overlapping messaging on some events — narrow copy pass later
- Strict heading outline: sidebar `h2` vs main `h2`s — valid HTML5; optional tighten to `h3` for sidebar card title in a follow-up
- Hero image `alt` text: still driven by field formatter — optional editorial pass

## Recommended next task

**No P0** identified. **P1** items above are verification-only.

Recommend **Task 11 — Cart and checkout visual trust polish** once manual browser/keyboard checks on `/event/1567` and `/event/1540` pass.

If keyboard order on mobile is problematic, open **Task 10B — Event full mobile focus order / landmark tune-up** with that exact title.
