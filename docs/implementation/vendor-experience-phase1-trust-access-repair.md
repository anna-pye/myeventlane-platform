# Vendor Experience Phase 1 — Trust and Access Repair

**Date:** 2026-07-20 (implementation) / 2026-07-21 (manual QA)  
**Branch:** `fix/mel-vendor-access-and-create-flow` (from `origin/main`)  
**Evidence base:** `docs/audits/vendor-experience-independent-audit.md`  
**Status:** Implementation complete; manual DDEV organiser journey completed; defects repaired; ready for commit

---

## 1. Audit findings implemented

| Finding | Phase 1 action |
|---|---|
| **F-01** check-in permission drift | **Not re-fixed.** Repository already uses defined permission strings; vendor role holds them. Mutation hardening only: toggle route is POST-only. |
| **F-02** ticket list / resend access | **Implemented.** Groups + widgets lists use `EventTicketsAccess`. Resend keeps `resend ticket emails` + ownership via `TicketOperationsAccess` (console + workspace parity). Vendor role granted `resend ticket emails`. |
| **F-03** create-event draft trap | **Implemented.** Resumable draft narrowed; choice form Continue / Start new; first-event onboarding silent continue preserved. Form ID avoids language-style corruption. |
| **R10** organiser-owner parity | **Implemented.** `EventVendorAccessChecker` (and mirrored controller checks) include vendor entity owner uid. Event Studio access no longer requires node `update` grants. |

Deferred (out of phase): F-04–F-15, dashboard redesign, Stripe architecture, route-family consolidation, publish pipeline.

---

## 2. Repository evidence confirmed

### F-01 contradiction (newer than independent audit)

Independent audit (2026-07-20) claimed routes required `myeventlane_checkin.access` / `.scan` / `.toggle`.

**Current `main` / this branch:**

- `myeventlane_checkin.routing.yml` uses `access check-in`, `scan qr codes`, `toggle check-in status`
- `user.role.vendor.yml` grants those permissions
- `CheckInController::scan` 302s to Door Mode (`myeventlane_event_attendees.vendor_operations_door`)
- Toggle restricted to POST (`_method: POST`)

### F-02 still valid (fixed)

- Groups + widgets lists use `EventTicketsAccess`
- Access codes list already used `EventTicketsAccess` (unchanged)
- Resend: permission on vendor role + ownership + CSRF

### F-03 still valid (fixed)

- Resumable = unpublished and (`field_event_state` empty or `draft`)

### R10 still valid (fixed)

- Organiser entity owner uid included in workspace parity
- Event Studio route access aligned to parity (not node update alone)

---

## 3. Files changed

- `docs/audits/vendor-experience-independent-audit.md`
- `docs/implementation/vendor-experience-phase1-trust-access-repair.md`
- `config/sync/user.role.vendor.yml` — add `resend ticket emails`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.routing.yml` — toggle `_method: POST`
- `web/modules/custom/myeventlane_tickets/*` — groups/widgets access; resend CSRF; EventAccess / TicketOperationsAccess DI
- `web/modules/custom/myeventlane_vendor/*` — draft choice, create gateway, ownership parity
- `web/modules/custom/myeventlane_event_studio/src/Access/EventStudioAccess.php` — parity without node update gate
- `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php` — choice / first-event
- Unit tests under `myeventlane_vendor`, `myeventlane_tickets`, `myeventlane_checkin`, `myeventlane_event_studio`

---

## 4. Access model before and after

### Ticket groups / widgets lists

| | Before | After |
|---|---|---|
| Route gate | `_permission: manage own events tickets` | `_custom_access: EventTicketsAccess` |
| Vendor role | Missing permission → 403 | Console + workspace parity (no broad manage-own grant) |

### Access codes list

Already on `EventTicketsAccess` — unchanged.

### Ticket resend

| | Before | After |
|---|---|---|
| Permission | `resend ticket emails` (not on vendor) | Same permission **granted** to vendor role |
| Ownership | `canManageEventTickets` only | manage-own path **or** console + workspace parity |
| CSRF | none | `_csrf_token: TRUE` |

### Check-in toggle

| | Before | After |
|---|---|---|
| Method | any | POST only |
| Permissions | already correct on main | unchanged |

### Organiser ownership / Event Studio

Author **or** `field_vendor_users` **or** vendor entity owner uid.  
Event Studio allows when workspace parity holds (node `update` grant no longer required).

---

## 5. Create-event behaviour before and after

**Before:** Any unpublished authored event silently captured Create event.

**After:**

- Resumable = unpublished **and** (`field_event_state` empty **or** `draft`)
- Unpublished `ended` / `cancelled` / other non-draft states do **not** capture create
- Explicit create + resumable draft → `/vendor/events/create/draft-choice` (Form API CSRF)
- Continue → existing Studio workspace
- Start new → new draft (`field_event_state = draft`) → Studio; prior draft kept
- `mel_first_event=1` → silent continue/create (onboarding preserved)
- No draft → create as before
- Form ID `mel_event_draft_choice` (avoids vendor→organiser language rewrite corrupting `form_id`)

---

## 6. AAA recovery-copy decisions

Draft choice copy:

- **Acknowledge:** “You already have an unfinished event”
- **Align:** “You can continue working on it or start a different event.”
- **Assure:** “Your existing draft will stay saved whichever option you choose.”

Genuine cross-organiser access still returns access denied (no existence leak of another organiser’s draft content).

---

## 7. Security impact

- **Positive:** Event-scoped ticket lists; resend ownership + CSRF; toggle not GET-mutable; organiser-owner parity without weakening cross-vendor isolation; Studio parity aligned
- **Unchanged / deferred:** F-06 broad Commerce permissions on vendor role
- **Canonical check-in:** Door Mode preserved; legacy module not expanded

---

## 8. Config impact

- Sync: `config/sync/user.role.vendor.yml` + `resend ticket emails`
- Active DDEV: vendor role has `resend ticket emails` (confirmed 2026-07-21)
- `drush config:status` shows large pre-existing “Only in DB” drift. **No full `cex` run** — would export unrelated active config. Sync YAML change is intentional and scoped.

---

## 9. Tests added

- `EventVendorAccessCheckerTest` — author / owner / team / unrelated / removed member
- `VendorEventStudioCreateDraftLogicTest` — resumable vs ended/cancelled/published
- `TicketListRoutingAccessTest` — list routes + resend CSRF/permission
- `TicketResendAccessDecisionTest` — resend decision matrix
- `CheckinRoutingSafetyTest` — POST toggle + permission string parity
- `EventStudioAccessParityTest` — Studio access contract (parity, no node-update gate)

---

## 10. Commands run

```bash
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_vendor/tests/src/Unit/EventVendorAccessCheckerTest.php \
  web/modules/custom/myeventlane_vendor/tests/src/Unit/VendorEventStudioCreateDraftLogicTest.php \
  web/modules/custom/myeventlane_tickets/tests/src/Unit/TicketResendAccessDecisionTest.php \
  web/modules/custom/myeventlane_tickets/tests/src/Unit/TicketListRoutingAccessTest.php \
  web/modules/custom/myeventlane_checkin/tests/src/Unit/CheckinRoutingSafetyTest.php \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/EventStudioAccessParityTest.php
# Result: 21 tests, 73 assertions, OK

php -l <changed PHP files>  # no syntax errors
yaml_parse_file on changed YAML  # OK
ddev drush cr  # success
ddev drush config:status  # large pre-existing Only-in-DB drift untouched
ddev exec bash scripts/check-webroot-safety.sh  # passed
# PHPCS: no new errors on Phase 1 files; pre-existing line-length warnings only on older service comments
```

---

## 11. Manual QA (DDEV organiser journey)

### Environment

- Project: `myeventlane-wt-wallet` (DDEV)
- URI: `https://vendor.myeventlane-wt-wallet.ddev.site`
- Drupal 11.4.4 / PHP 8.3
- Mail: Mailpit (`:8026`) — external delivery not used for resend proof
- Viewports: 390×844 (primary), desktop spot-checks via HTTP kernel matrix

### Safe test entities (IDs only — no secrets)

| Role | Username | UID |
|---|---|---|
| Organiser A (owner) | `mel_phase1_org_a` | 101 |
| Organiser A team | `mel_phase1_team_a` | 102 |
| Organiser B | `mel_phase1_org_b` | 103 |
| First-event organiser | `mel_phase1_first` | 104 |

| Entity | ID |
|---|---|
| Organiser A vendor | 57 |
| Organiser B vendor | 58 |
| First-event vendor | 59 |
| Owned published event | 1760 |
| Team-authored published event | 1761 |
| Resumable draft (initial) | 1762 |
| Unpublished ended (non-resumable) | 1763 |
| Org B draft | 1764 |
| Ticket group | 4 |
| Access code | 8 |
| Widget (`mel_purchase_surface`) | 4 |
| Ticket | 241 |
| Attendee | 385 |

Emails used: `@example.invalid` only.

### Results

| Check | Result | Evidence |
|---|---|---|
| A1 No resumable draft → create | **PASS** | `/vendor/events/create` opened new Studio (e.g. 1766/1768); no choice page |
| A2 Draft choice shown | **PASS** | `/vendor/events/create/draft-choice`; AAA copy; draft title; Continue / Start new / Back to my events |
| A3 Continue draft | **PASS** | Opened `/vendor/events/1762/studio`; no second draft |
| A4 Start new | **PASS** | Created 1765; message “previous unfinished event is still saved”; 1762 unchanged |
| A5 Unpublished non-resumable | **PASS** | With only ended `1763`, create made a new Studio event; did not open 1763 |
| A6 First-event onboarding | **PASS** | uid 104 + `mel_first_event=1` → 302 to `/vendor/events/1767/studio?mel_first_event=1` (silent) |
| A7 Cross-organiser draft isolation | **PASS** | Org B Studio on 1760/1762/1768 DENY; draft-choice shows only Org B’s own draft (1764) |
| B1 Ticket groups | **PASS** | Org A HTTP 200; Org B 403 |
| B2 Widgets | **PASS** | Org A HTTP 200; Org B 403 |
| B3 Access codes | **PASS** | Org A HTTP 200; Org B 403 |
| B4 Ticket resend | **PASS** | CSRF required (no token → 403); Org A mailer send OK → Mailpit to `mel-phase1-attendee@example.invalid`; calm status copy in controller |
| B5 Cross-organiser denial | **PASS** | Groups/widgets/codes/door/studio/resend denied for Org B |
| B6 Permission-only resend | **PASS** | Org B has `resend ticket emails` = YES but `accessResend` = DENY (ownership / workspace parity chain) |
| C1 Door Mode | **PASS** | Org A Door check-in loads; Org B 403 via `assertEventOwnership` |
| C2 POST-only toggle | **PASS** | Route `_method: POST`; anonymous GET → 403; unit test coverage |
| D1 Event author | **PASS** | Org A on 1760 Studio + ticket tools + door |
| D2 Organiser-owner parity | **PASS** | Org A on team-authored 1761 Studio + groups ALLOW after EventStudioAccess fix |
| D3 Team member | **PASS** | uid 102 Studio 1760 + 1761 ALLOW; groups ALLOW |
| D4 Unrelated | **PASS** | Org B DENY across surfaces |
| E Regressions (scoped) | **PASS** | Studio open/nav/readiness visible; events index Create Event; access-code/groups routes; first-event redirect; Stripe banner present (no live Stripe txn) |
| Mobile 390px | **PASS** | Choice UI usable; buttons ~52px tall |
| Keyboard / a11y (choice) | **PASS** | Named Continue / Start new; h2 under chrome h1; escaped title; human date; return link present. Colour not sole distinguisher (primary vs secondary labels). |

### Defects discovered and repaired during QA

| # | Symptom | Root cause | Fix | Test | Retest |
|---|---|---|---|---|---|
| D-QA-1 | Organiser owner / team could not open Event Studio for each other’s events | `EventStudioAccess` required node `update` after parity | Allow Studio when workspace parity holds | `EventStudioAccessParityTest` | Studio 1760/1761 ALLOW for 101/102 |
| D-QA-2 | Continue / Start new did not submit | Theme language rewrite changed `form_id` `…vendor…` → `…organiser…` | Form ID `mel_event_draft_choice`; heading h2 | Manual A3/A4 | PASS |

### Final security verdict

- Resend permission alone cannot bypass ownership
- Cross-organiser access remains denied
- GET cannot mutate check-in toggle state
- Draft choice cannot expose or resume another organiser’s event
- No new broad Commerce permission was added

### Accessibility result

Choice screen meets Phase 1 expectations at 390px; dual chrome/content headings mitigated by using h2 for the choice title under console chrome.

### Configuration result

- Sync role contains `resend ticket emails`
- Active role contains `resend ticket emails`
- Unrelated Only-in-DB drift untouched; no full export

### Remaining deferred issues

See section 13. Also: vendor-theme language rewrite can still corrupt other `*vendor*` machine strings in form values — systemic hardening deferred (Phase 1 mitigated for this form).

---

## 12. Remaining risks

- Active vs sync config drift in this DDEV environment remains wide
- Legacy `myeventlane_checkin` pages still exist (not promoted); consolidation deferred (F-05)
- Draft heuristic depends on `field_event_state`; empty state still counts as resumable (intentional)
- Content moderation can reset `field_event_state` on entity save in some cases — QA used SQL for ended-state fixture where needed

---

## 13. Explicitly deferred findings

F-04, F-05, F-06, F-07, F-08, F-09, F-10, F-11, F-12, F-13, F-14, F-15; analytics/Pro boundaries; Stripe architecture; Event Studio section redesign; publish readiness; wholesale route-family consolidation; module deletion; systemic language-style machine-name hardening.

---

## 14. Recommended next phase

1. Review the Phase 1 commits and open a PR  
2. Phase 2: create-event polish + remaining AAA blocked states  
3. Contained F-06 Commerce permission verification (runtime scope)  
4. Check-in surface consolidation onto Door Mode (F-05) with redirects  
5. Legacy `/vendor/event/{event}/*` retirement (F-04)

---

## Commit status

Commits created after manual QA completion (see git log on branch).
