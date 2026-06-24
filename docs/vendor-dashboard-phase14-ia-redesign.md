# Phase 14 — Organiser Operations Dashboard: IA Redesign

Date: 2026-06-24
Status: design / plan (no code in this phase)
Scope: information architecture only. Theme-only implementation expected.
Authority: repository is source of truth; payloads below were verified in
`VendorDashboardViewModelBuilder` and `VendorActionQueueBuilder`.

---

## 1. Why phases 11B–13 failed (root cause)

Nine consecutive passes (11B, 11C, 11D, 11E, 12A, 12C, 12C-polish, 13) all
restyled the **current-event surface** and shuffled section order/spacing.
The screenshots still read "event dominates / attention is weak" because the
problem was never styling. It is **content priority**:

1. The dashboard leads with **event content** (`current_event` rendered as a
   title + chips + meta + summary + metric strip + action rail). Even compacted,
   it is the first and largest *content* block, so it dominates.
2. The actual operational signal — **`model.action_queue`** — is rendered as a
   single faint line in the header (`priority_action.message`), with the rest
   hidden in a collapsed `secondary_actions`. The dashboard's most important data
   is its least visible element.
3. "Business health", "needs attention", and "events to fix" are **separate,
   weakly-styled sections** that each re-express slices of data the action queue
   already contains — so they read as disconnected fragments, not priorities.

You cannot fix a priority problem with CSS weight alone. The fix is to **change
what leads the page**, using data that already exists.

---

## 2. Key repository finding — the action queue already exists

`VendorActionQueueBuilder::build()` (called at
`VendorDashboardViewModelBuilder.php:145`, exposed as `model.action_queue`) is
already a **unified, severity-ranked operational feed**. Confirmed item sources:

| Item key | Severity | Source |
|---|---|---|
| `no_vendor_profile` | warning | readiness |
| `profile_incomplete` | warning | readiness.profile |
| `missing_booking_{nid}` | error | event booking setup |
| `stripe_payout_incomplete` | warning/error | readiness.stripe + paid events |
| `no_events_yet` | info | events empty |
| `draft_event_*` | (action) | draft events |
| analytics availability | info | analytics_summary |

Each item already has `key`, `severity`, `title`, `message`, `url`,
`action_label`. **This is the "what needs my attention / what business issues
require action" answer — fully built, prioritized, and currently hidden.**

Implication: the operations dashboard can be built **theme-only**. No new
ViewModel, no `eventFocusRank` change, no new selection logic, no new payloads.

---

## 3. Target information architecture

The dashboard answers five questions, in priority order. Each maps to a
confirmed payload.

| # | Question | Zone | Data (confirmed) | Weight |
|---|---|---|---|---|
| 0 | Whose business is this? | **Organiser identity** | `vendor`, `organiser_overview`, `kpis` | strong (chrome) |
| 2+5 | What needs me now? | **Action required** | `action_queue` (full list) | **strongest content** |
| 1 | What's happening today? | **Today** | `current_event` (compact status) | medium |
| 3 | What's coming? | **Upcoming** | `upcoming_events` | medium (browse) |
| 4 | What changed? | **Recent activity** | `activity_items` | low |
| — | Settings | **Tools** | existing details | lowest |

The inversion vs today's dashboard: **Action required moves up to become the
operational core; the current event drops to a compact status line.**

---

## 4. Zone-by-zone redesign

### Zone 1 — Organiser identity (header)
Keep. Organiser owns the H1 (semantic + visual). Identity + headline business
metrics + "Create event". No change to ownership.

### Zone 2 — Action required (NEW PROMINENCE — the core)
Render the **full `action_queue`**, not just `priority_action`.
- A short, scannable list: each row = severity dot + title + one action link
  (`action_label` → `url`).
- Sorted by severity (errors → warnings → info) — **already ordered by the
  builder**; render in given order, do not re-sort in Twig.
- Empty state: when the queue is empty, a single calm "You're all caught up"
  line. This is the only place that answers "what needs action".
- This **replaces**: the faint `priority_action` header message, the collapsed
  `secondary_actions`, the standalone "Events to fix" (`attention_events`) and
  "Business health" chips — all of which are subsets of the queue. (See Zone 6.)

### Zone 3 — Today (current event → compact status)
`current_event` becomes a **one-glance status strip**, not a panel:
- Line 1: title + status chip (Draft / Upcoming / Live-today).
- Line 2: date • attendee summary (one line, from `attendee_summary`).
- One primary action only: Open check-in (live) / Continue (draft) / Manage.
- **Remove from this zone**: the operation-summary paragraph, the attention
  reasons list (now in Zone 2), the multi-metric strip, and the secondary
  action rail. Deep event work lives in the event workspace, not the dashboard.
- It stays Q1 ("what's happening today") but no longer owns the page.

### Zone 4 — Upcoming events (browse)
Keep the existing card grid (`upcoming_events`). This is the largest *browsing*
area. No data change.

### Zone 5 — Recent activity (history)
Keep `activity_items` timeline, Slack-style. Q4. No data change.

### Zone 6 — Business health → fold into Zone 2
`readiness.stripe_ready / profile_complete / has_public_profile` are **already
emitted as action_queue items** (`stripe_payout_incomplete`,
`profile_incomplete`, `no_vendor_profile`). A separate Business Health section
therefore **duplicates** the action queue. Recommendation: **remove the
standalone Business Health section**; health *issues* appear in Zone 2, and a
single "All set up" confirmation can appear there when the queue is clear.
(This reverses Phase 12C/13's Business Health addition — it was the wrong shape.)

### Zone 7 — Tools
Keep accordions, lowest priority. No change.

---

## 5. What this is NOT (anti-scope)

- No new ViewModel, service, or `eventFocusRank` change.
- No new selection logic or sorting in Twig (`action_queue` is pre-ordered).
- No new routes, permissions, fields, entities, APIs, config, Commerce, JS.
- No new charts/rings/KPI libraries.
- No new "authoritative CSS layer" stacked on the existing five — see §7.

---

## 6. Implementation phases (small, reviewable; theme-only)

- **14A — Surface the action queue.** Twig: render full `model.action_queue` as
  the "Action required" zone directly under the header. SCSS: a list style
  (rows, severity dot) — *reuse* existing list/row primitives, do not add cards.
- **14B — Compact Today.** Twig: reduce the current-event block to the status
  strip (title/status/date/attendees + one action); stop rendering the summary,
  reasons, metric strip, and secondary rail on the dashboard.
- **14C — Remove duplicate signals.** Twig: remove the standalone Business
  Health section and the separate "Events to fix" block (now in 14A).
- **14D — Consolidation (was Phase 12B).** Only after 14A–C are visually
  verified: collapse the 11B–13 SCSS layers into one authoritative block and
  delete the superseded rules, with screenshots as the regression reference.

Each phase: `git diff --stat`, `ddev drush cr`, `ddev drush config:status`,
`npm run mel:lint`, `npm run mel:build`, then browser verification at
390 / 768 / 1280 × empty / draft / upcoming / live.

---

## 7. Critical process note

The dashboard now carries **five stacked authoritative CSS layers** plus legacy
rules (~3,955 lines). Phase 14 must be implemented by **changing the Twig
structure and rendering the right data** — not by appending a sixth CSS layer.
After 14A–C land and are verified, 14D consolidates. Appending more CSS without
the Twig/content change will reproduce the same failure for a tenth time.

---

## 8. Risks

- **Action-queue copy/empty states**: depends on `MelReadinessHelper` strings
  already used by the builder; no new copy logic required, but verify the empty
  ("all caught up") state renders when `action_queue` is `[]`.
- **Removing Business Health / Events-to-fix**: ensure no data is *only* visible
  there (it isn't — all of it is in `action_queue`), to avoid hiding a signal.
- **Compacting Today**: confirm `current_event.attendee_summary` and a single
  primary `quick_action` exist for the status strip (both confirmed in payload).
- **Verification gap**: this plan is repository-grounded but not browser-verified;
  14A–C must each be screenshot-checked before 14D deletion.
