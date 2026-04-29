# Staging smoke test and P1 watchdog cleanup (Task 14)

**Date:** 2026-04-29  
**Workspace branch:** `cursor/audit-doc-update-61d60` @ `a2967c15` (verify against your PR branch before merge)  
**Staging server SSH:** Not available from this environment; staging-specific commands were not run here.

---

## 1. Preflight (local)

| Check | Result |
|-------|--------|
| Branch | `cursor/audit-doc-update-61d60` |
| Latest commit | `a2967c15` |
| Dirty files | None |
| `composer validate` | Valid |
| `ddev drush cr` | Success |

---

## 2. Deploy / branch vs `origin/main`

Commands run: `git fetch origin`, `git status -sb`, `git diff --stat origin/main...HEAD`, `git log --oneline origin/main..HEAD`.

**Observation:** After fetch, local HEAD aligned with `origin/cursor/audit-doc-update-61d60`. Comparison with `origin/main`: merge-base `a2967c15`; `origin/main` tip included merge `b7b90f93` (PR merge). Confirm whether your integration branch matches the open PR (`cursor/onboard-storage-fix-128b4` per Task 14 brief) and pull/reconcile before deploying.

**Staging git / Drush:** Not verified (no SSH host configured).

---

## 3. Staging browser smoke (manual)

Execute on **staging** URLs with appropriate accounts. Results below are placeholders until you fill them after manual QA.

### Public

| # | Path | Result | URL tested | Role | Watchdog ref |
|---|------|--------|------------|------|--------------|
| 1 | `/` | Not tested | | | |
| 2 | `/events` | Not tested | | | |
| 3 | Category page | Not tested | | | |
| 4 | Published paid event | Not tested | | | |
| 5 | Published RSVP event | Not tested | | | |
| 6 | Paid `/book` | Not tested | | | |
| 7 | RSVP `/book` | Not tested | | | |
| 8 | `/cart` | Not tested | | | |
| 9 | Checkout to payment step | Not tested | | | |
| 10 | Completion (test mode) | Not tested | | | |

### Vendor

| # | Step | Result | Notes |
|---|------|--------|-------|
| 1 | `/vendor/dashboard` | Not tested | |
| 2–10 | Draft RSVP/publish, paid draft/publish, Stripe gate, analytics, export, cross-vendor denial | Not tested | |

### Help / security

| # | Step | Result |
|---|------|--------|
| 1 | `/help` | Not tested |
| 2 | `/help/assistant` | Not tested |
| 3 | Vendor help route behaviour | Not tested |
| 4 | Anonymous vendor dashboard blocked | Not tested |
| 5 | `/my-tickets` isolation | Not tested |

---

## 4. Watchdog collection

### Staging

Run on staging after smoke (not executed here):

```bash
./vendor/bin/drush ws --count=200 --severity=Error
./vendor/bin/drush ws --count=100 --severity=Warning
./vendor/bin/drush ws --count=250 | grep -A4 -B2 -Ei "abandoned|Order::isEmpty|OnboardingState::getOwnerId|ticket_type maps|blocking purchase|issuance|Express Dashboard|session|headers already sent|404|event/1567|event/1540|checkout|stripe|commerce|cron|exception|fatal|error" || true
```

### Local DDEV (sample after `ddev drush cron`)

Recent **Error** severity entries included recurring themes aligned with Task 13 candidates:

| Theme | Severity | Module/channel | Notes |
|-------|----------|----------------|-------|
| `Order::isEmpty()` undefined on commerce order | P1 | `myeventlane_pro` | **Fixed in this task** — invalid API usage |
| `OnboardingState::getOwnerId()` must be `?int`, string returned | P1 | `myeventlane_auth` | **Fixed in this task** — cast/normalize owner id |
| Session / headers already sent during cron | P1/P2 | `cron` | Not changed — needs subscriber identification from stack |
| Stripe Express Dashboard login link / edit link | P1/P2 | `myeventlane_*`, `stripe_error` | Expected for restricted accounts; existing controller paths — **no change** pending staging correlation |
| Ticket issuance: no attendee records | P1 | `myeventlane_launch` | Order-specific — investigate order/event data |
| No `mel_ticket_type` maps variation — blocking purchase | P1 | `myeventlane_commerce` ([TicketAvailabilityService](web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php)) | Variation/event mismatch — treat as data or legacy unless reproducible on current catalog |

Redact user-identifying details in operational logs.

---

## 5. P1 triage decisions

### A. Abandoned cart — `Order::isEmpty()`

**Cause:** `Drupal\commerce_order\Entity\Order` does not implement `isEmpty()`; calls were in [AbandonedCartScheduler](web/modules/custom/myeventlane_pro/src/Service/AbandonedCartScheduler.php) and [ProAbandonedCartJob](web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php).

**Fix:** Replaced with the existing semantics “has at least one order item with quantity &gt; 0” (`orderHasPurchasableItems` in scheduler; equivalent private helper in job). Scheduler behaviour beyond removing the fatal is unchanged.

### B. `OnboardingState::getOwnerId()` return type

**Cause:** Entity reference `target_id` can be returned as a numeric string while the method declared `?int`.

**Fix:** Return `NULL` when empty; otherwise `(int)` cast in [OnboardingState](web/modules/custom/myeventlane_core/src/Entity/OnboardingState.php).

### C. Ticket / commerce mapping

**Decision:** Log lines tie to specific orders/variations/events. No broad code fallback (would risk selling wrong inventory). Reconcile per-event data or retire stale test events; escalate if a **current** paid path fails.

### D. Stripe Express Dashboard link errors

**Decision:** Code already gates login links via eligibility and user messaging in [StripeConnectController::manage](web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php). Downgrade log noise vs expected restriction is **not** implemented in this task — confirm with staging watchdog after deploy.

### E. Cron session / headers

**Decision:** No code change without a proven subscriber/file from a full stack trace on staging.

### F. 404 `/event/1567`, `/event/1540`

**Local DDEV check:** `path_alias.manager` returned default `/node/{id}` for nodes 1567 and 1540 (no custom alias in this DB). If `/event/{nid}` is not a registered route, 404s are **P2** (bookmark/old marketing links) unless product requires that path pattern.

### G. Manual vendor team access matrix

**Decision:** Still pending as a process QA item — out of scope for this code change.

---

## 6. Files changed

- `web/modules/custom/myeventlane_pro/src/Service/AbandonedCartScheduler.php`
- `web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php`
- `web/modules/custom/myeventlane_core/src/Entity/OnboardingState.php`
- `docs/audits/mel-staging-smoke-p1-watchdog-cleanup.md`

---

## 7. Verification (local)

| Command | Result |
|---------|--------|
| `composer validate` | OK |
| `ddev drush cr` | OK |
| `php -l` on each changed PHP file | No syntax errors |
| `ddev drush cron` | Completed (sample notices only) |
| `ddev drush php-eval` onboarding states | `getOwnerId()` returned ints for sample entities |
| `ddev drush ws --count=50 --severity=Error` | Historical entries still listed pre-fix errors; new cron run did not add new `Order::isEmpty` lines in the sampled window |

Re-run watchdog on staging after deploy to confirm errors clear.

---

## 8. Remaining P1 / P2

| Item | Status |
|------|--------|
| Cron session / headers | Open — needs trace |
| Stripe Express noise vs incomplete accounts | Open — optional narrow logging UX |
| Ticket mapping / issuance for specific legacy orders | Open — data / case-by-case |
| `/event/{nid}` 404 | P2 unless product requirement |
| Browser smoke + staging `drush ws` | Pending manual staging pass |

---

## 9. Launch recommendation

- **Code fixes in this task** address two proven production-risk issues: abandoned cart fatal and onboarding owner id type errors.
- **Before merge/deploy:** Run full staging smoke + staging watchdog grep pipeline; confirm PR branch matches repo intent; reconcile local branch with `origin/main` if needed.
- **Ready for staging deploy after:** Manual smoke Pass on critical paths and confirmation no new P1 errors in watchdog for the fixed signatures.

---

## 10. P0 list

None identified from this task’s scope (Task 13B Stripe secret P0 addressed separately per brief).
