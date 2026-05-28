# Editorial update log — priority help articles

**Date:** 2026-05-22  
**Branch:** `feature/help-article-editorial-updates`  
**Publish-prep on main:** Confirmed (`docs/content-audit/publish-prep/` from commit `644f171b`)

## Working tree note

Unrelated uncommitted files were present at start (`myeventlane_event_studio/*`). They were **not** staged or committed. Editorial work used Drush entity saves only.

---

## Node 1498 — Contacting support

| | Before | After |
|---|--------|-------|
| Title | Contacting support | Contacting support (unchanged) |
| Summary | How to get help | How to get help with a booking, payment, or ticket — and when to contact the event organiser instead. |
| Body | ~100 chars (single paragraph) | ~987 chars (steps, organiser vs platform, `/my-tickets`, `/my/support`, `/help`) |
| Audience | public | public |
| `field_help_status` | published | published |
| `field_help_ai_allowed` | true | true |
| Article type | Guide | Guide |

**Source:** `support-contact-merge.md`

---

## Node 1510 — Payouts and fees

| | Before | After |
|---|--------|-------|
| Title | Payouts and fees | Payouts and fees (unchanged) |
| Summary | Payments and fees | How organisers connect Stripe, sell paid tickets, and where to check payouts and fees. |
| Body | ~61 chars | ~1069 chars (Connect Stripe, payouts/fees, troubleshooting) |
| Audience | vendor | vendor |
| `field_help_status` | published | published |
| `field_help_ai_allowed` | true | true |
| Article type | FAQ | FAQ |

**Edits:** Removed internal “Needs verification” label from published body; replaced with “Check there for current fee details.” No payout timing promises.

**Source:** `stripe-payouts-merge.md`

---

## Checkout errors — new article

| Decision | Detail |
|----------|--------|
| Duplicate search | No matching `help_article` for checkout / payment failed / card declined |
| Action | **Created** new node |
| **nid** | **1668** |
| Title | Having trouble checking out |
| Audience | public |
| Article type | Troubleshooting (tid 74) |
| `field_help_status` | published |
| `field_help_ai_allowed` | true |
| Moderation | Set to `published` (initial save left `draft` / unpublished — corrected in same pass) |

**Source:** `checkout-errors-verification.md` + cautious wording (no specific Stripe decline strings).

---

## Skipped / blocked (not published)

| Article | Status |
|---------|--------|
| Waitlist | **Blocked** — staging QA required (`waitlist-verification.md`) |
| Ticket confirmation | **Blocked** — staging QA required (`ticket-confirmation-verification.md`) |

---

## Commands run

```bash
ddev drush php:eval   # entity updates (1498, 1510, create 1668, moderation fix)
ddev drush cr
ddev drush search-api:index mel_content
ddev drush search-api:status mel_content
composer validate
npm run mel:lint
npm run mel:build
```

---

## Validation results

| Check | Result |
|-------|--------|
| `composer validate` | Pass |
| `mel:lint` | Pass |
| `mel:build` | Pass (pre-commit hook on prior commits) |

## Search API status

| Index | Status |
|-------|--------|
| `mel_content` | **100%** (60/60) — 3 items indexed this pass (updates + new node) |

---

## Access verification

| Node | Anonymous | Vendor (uid 2) |
|------|-----------|----------------|
| 1498 | allow | allow |
| 1510 | deny | allow |
| 1668 | allow (after moderation publish) | allow |

## Help Assistant verification

| Query | Anonymous | Vendor |
|-------|-----------|--------|
| Support / contacting help | Includes **1498** | — |
| Checkout trouble / payment failed | Includes **1668** | — |
| Payouts / Stripe fees | Excludes **1510** | Includes **1510** |

---

## Remaining risks

| Risk | Notes |
|------|-------|
| Path aliases | 1498, 1510, 1668 still `/node/{nid}` unless aliases added separately |
| Fee dashboard labels | 1510 does not quote exact fees (by design) |
| Waitlist / ticket confirmation | Still blocked for publish |
| New node 1668 | Requires content moderation `published` when creating help articles via API |

---

## Git commit

Only this log file is committed; **Drupal node content is database-only** (not exported to config in this pass).

---

## Final polish pass (2026-05-22)

**Branch:** `feature/help-article-final-polish`

### Path aliases applied (local DDEV)

| nid | Alias | Anonymous HTTP |
|-----|-------|----------------|
| 1498 | `/help/attendees/contacting-support` | 200 |
| 1510 | `/help/vendors/payouts-and-fees` | 403 (vendor-only; expected) |
| 1668 | `/help/attendees/having-trouble-checking-out` | 200 |

Pattern matches seeded articles in `myeventlane_help_centre.help_content.yml` (`/help/attendees/…`, `/help/vendors/…`). No pathauto pattern exists for `help_article`; aliases created manually.

### Stripe fee label verification (nid 1510)

**Method:** Code audit (vendor dashboard, checkout, analytics) — vendor UI not browser-walked this pass.

| UI surface | Label found |
|------------|-------------|
| Checkout fee pane | “Platform fee” (`FeeTransparencyPane`) |
| Vendor analytics KPI | “platform_fees” / net earnings |
| Stripe Connect dashboard copy | “payouts”, “Connect Stripe to receive card payments and payouts” |

**Decision:** No change to nid 1510 body. Existing copy (“Platform and processing fees”, “organiser dashboard or Stripe”) aligns with product terminology without quoting percentages or payout schedules.

### Checkout contextual link

| Item | Before | After |
|------|--------|-------|
| Config key `checkout` | “Help with checkout and tickets” → `/help/attendees/how-to-request-a-refund` | “Having trouble checking out?” → `/help/attendees/having-trouble-checking-out` |
| Refund link | Unchanged (`refunds_checkout` key) | — |

**Files:** `config/sync/myeventlane_help_centre.contextual_help.yml` + active config updated via Drush.

**Rationale:** Smallest safe fix — config only, no module changes. Surfaces when checkout has no inline support card (`mel_help_checkout_guide` in `myeventlane_help_centre_preprocess_commerce_checkout_form`).

### Articles unchanged

- Waitlist, ticket confirmation — still blocked.
- No body copy edits on 1498, 1510, 1668.
