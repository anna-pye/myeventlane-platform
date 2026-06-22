# Staging smoke test — public discovery + SEO hygiene

**Branch:** `feature/public-seo-commerce-polish`  
**Related commits:**

- `4c0735ac` — feat(events): refine homepage event card system
- `ba2f2212` — fix(public): tighten event discovery seo and staging hygiene

**Last updated:** 2026-05-24

---

## 1. Purpose

This runbook validates the **public discovery, event card, SEO, Pathauto, JSON-LD, Help Centre, staging noindex, and vendor onboarding** changes delivered on `feature/public-seo-commerce-polish` after merge to `main` and deploy to staging.

It does **not** replace general deploy documentation. Use it as a release-specific checklist layered on top of:

- [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md) — how staging deploy is triggered (push to `main` → CI → `remote-deploy.sh`)
- [release-hardening.md](../release-hardening.md) — config sync discipline and post-deploy health checks
- [STAGING_INDEXING_PROTECTION.md](../operations/STAGING_INDEXING_PROTECTION.md) — staging-only indexing protection (do **not** apply to production)

---

## 2. Scope

### In scope

| Surface | What to verify |
| --- | --- |
| Homepage | Event card sections, visibility filters, hero/search |
| Events listing | `/events` — public future events, card CTAs |
| Calendar | `/calendar` — non-empty, future/current public events |
| Category pages | Taxonomy-driven discovery under `/events/…` |
| Event detail | Canonical aliases, JSON-LD, lifecycle visibility |
| Paid booking entry | `/event/{nid}/book` loads (no full payment) |
| RSVP booking entry | `/event/{nid}/rsvp/form` loads (no full submission) |
| Vendor directory | `/vendors` — public shell |
| Help Centre | `/help`, anonymous `/help/vendors` routing |
| Vendor onboarding registration | `/user/register?destination=/vendor/onboard/profile&vendor=1` |
| Staging noindex | `X-Robots-Tag` on staging hosts |
| Production noindex safety | Production must **not** receive staging noindex |

### Out of scope

- Full checkout / Stripe payment testing
- Full vendor dashboard regression
- Unrelated theme settings work
- Unrelated vendor theme SCSS work

---

## 3. Pre-deploy checks (local / DDEV)

Run from the repo root on the merged commit **before** or immediately after PR merge.

```bash
git status -sb
git branch --show-current
git log --oneline --decorate -5
composer validate
find web/modules/custom -name "*.php" -print0 | xargs -0 -n1 php -l
npm run mel:lint
npm run mel:build
ddev drush cr
ddev drush config:status
```

### Expected

| Check | Pass criteria |
| --- | --- |
| Working tree | Clean (`git status -sb` shows no unexpected changes) |
| Branch / history | On merged `main` (or PR branch pre-merge); log includes `4c0735ac` and `ba2f2212` |
| Composer | `composer validate` succeeds |
| PHP syntax | No parse errors from `php -l` |
| Theme | `npm run mel:lint` and `npm run mel:build` pass |
| Config drift (local) | `ddev drush config:status` — no unexpected differences; this release **adds** Pathauto patterns, Views, and role config that must import on staging |

### Config items introduced by this release (expect in `config/sync` after merge)

- `pathauto.pattern.event` — `events/[node:title]`
- `pathauto.pattern.event_category` — `events/[term:name]`
- `pathauto.pattern.help_article` — `help/[node:title]`
- Views updates: `views.view.upcoming_events`, `views.view.events_calendar`, `views.view.taxonomy_term`
- `block.block.front_featured_events`, `user.role.vendor`

---

## 4. Staging deploy steps

### 4.1 Automatic deploy (default)

Merging to `main` triggers [`.github/workflows/deploy-staging.yml`](../../.github/workflows/deploy-staging.yml):

1. Reusable build produces artifact (theme `dist/`, checksums, `config/sync`).
2. Artifact is SCP'd to the staging host.
3. [`scripts/deploy/remote-deploy.sh`](../../scripts/deploy/remote-deploy.sh) runs with:

```bash
APP_PATH="$HOME/staging"
APP_ENV=staging
SITE_URI="https://staging.myeventlane.com.au"
```

**Important:** The default workflow does **not** set `RUN_UPDB=1` or `RUN_CIM=1` (both default to `0` in `remote-deploy.sh`). This release includes **config/sync changes** — staging **must** run config import after deploy (see §5).

See [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md) for CI troubleshooting.

### 4.2 Manual / supplemental deploy (when re-running on the staging host)

From the extracted release directory (or with `ARTIFACT_PATH` pointing at an extracted artifact):

```bash
APP_ENV=staging \
SITE_URI=https://staging.myeventlane.com.au \
RUN_UPDB=1 \
RUN_CIM=1 \
ARTIFACT_PATH=/path/to/extracted/artifact \
./scripts/deploy/remote-deploy.sh
```

- `RUN_CIM=1` — **required for this release** (Pathauto patterns, Views, blocks).
- `RUN_UPDB=1` — run if `drush updatedb:status` reports pending updates on staging.
- `APP_PATH` defaults to `$HOME/staging` when omitted.

The deploy script:

- Validates theme and vendor theme `dist/` assets and checksums
- Rsyncs `config/sync` from the artifact to the shared sync directory (`rsync --delete`)
- Applies staging domain `cset` when `APP_ENV=staging`
- Runs `drush cr` on finalize
- On failure: rolls back the `current/` symlink to the previous release and clears maintenance mode (see §10)

---

## 5. Post-deploy Drupal commands (staging host)

SSH to staging, then from `~/staging/current`:

```bash
cd ~/staging/current
export SITE_URI=https://staging.myeventlane.com.au

# If CI deploy did not pass RUN_UPDB / RUN_CIM:
php -d memory_limit=1024M vendor/bin/drush.php updb -y --uri="$SITE_URI"
php -d memory_limit=1024M vendor/bin/drush.php cim -y --uri="$SITE_URI"
php -d memory_limit=1024M vendor/bin/drush.php cr --uri="$SITE_URI"

# Verify config import (expect "No differences"):
php -d memory_limit=1024M vendor/bin/drush.php config:status --uri="$SITE_URI"
```

### Pathauto alias generation

Confirm Drush command availability:

```bash
php -d memory_limit=1024M vendor/bin/drush.php list | grep -i pathauto
```

Expected aliases include `pathauto:aliases-generate` (`pag`).

Generate aliases for entities covered by new patterns:

```bash
php -d memory_limit=1024M vendor/bin/drush.php pathauto:aliases-generate create canonical_entities:node -y --uri="$SITE_URI"
php -d memory_limit=1024M vendor/bin/drush.php pathauto:aliases-generate create canonical_entities:taxonomy_term -y --uri="$SITE_URI"
php -d memory_limit=1024M vendor/bin/drush.php cr --uri="$SITE_URI"
```

Run alias generation **per environment** after config import. Existing content will not receive new `/events/…` or `/help/…` paths until aliases are generated.

Optional post-deploy health check (see [release-hardening.md](../release-hardening.md)):

```bash
php -d memory_limit=1024M vendor/bin/drush.php mel:health --uri="$SITE_URI"
```

---

## 6. URL smoke tests

Replace `{placeholders}` with real staging content after alias generation. Record chosen URLs in the sign-off section.

| Area | URL | Expected |
| --- | --- | --- |
| Homepage | `https://staging.myeventlane.com.au/` | Loads; no draft/test/copy/internal events; no ended events in upcoming sections; **On tonight** shows events occurring today or section fallback; **Free & RSVP** excludes paid-only events |
| Events listing | `https://staging.myeventlane.com.au/events` | Future public events only; cards render; CTAs use existing booking logic (paid → book, RSVP → rsvp form) |
| Calendar | `https://staging.myeventlane.com.au/calendar` | Page not empty; future/current public events only; accessible list/fallback visible |
| Vendor directory | `https://staging.myeventlane.com.au/vendors` | Public discovery shell — **not** vendor dashboard chrome |
| Help Centre hub | `https://staging.myeventlane.com.au/help` | Public help loads; no hard 403 from hub links |
| Vendor onboarding register | `https://staging.myeventlane.com.au/user/register?destination=/vendor/onboard/profile&vendor=1` | Registration form hides raw Drupal-only fields in vendor context; email/password remain; username hidden (set from email) |
| Public future event | `https://staging.myeventlane.com.au/events/{future-event-slug}` | Canonical alias resolves; exactly **one** `application/ld+json` Event script; booking CTA works |
| Ended event | `https://staging.myeventlane.com.au/events/{ended-event-slug}` | Not listed on discovery surfaces; **no** JSON-LD (or not publicly listable per `PublicEventVisibility`) |
| Category page | `https://staging.myeventlane.com.au/events/{category-slug}` | Category alias under `/events/…`; future public events in category only |
| Paid booking entry | `https://staging.myeventlane.com.au/event/{nid}/book` | Booking entry page loads (ticket panel / form visible); **stop before payment** |
| RSVP booking entry | `https://staging.myeventlane.com.au/event/{nid}/rsvp/form` | RSVP entry form loads; **stop before submission** |
| Vendor help (anonymous) | `https://staging.myeventlane.com.au/help/vendors` | Redirects to login with `destination=/help/vendors` (302) — **not** a hard 403 |

### Detailed expectations by area

**Homepage**

- No draft, test, copy, or internal-only events in carousels/rows.
- No ended events in upcoming sections.
- **On tonight** — events occurring today only, or empty/fallback state.
- **Free & RSVP** — no paid-only events.

**`/events`**

- Future public events only.
- Event cards render with correct metadata and CTA targets.

**`/calendar`**

- Non-empty when public events exist.
- Future/current public events only.

**Event detail**

- Canonical Pathauto alias works (`/events/[title]`).
- `/node/{nid}` may still resolve; prefer alias links on all public cards.
- Exactly one JSON-LD `Event` block on publicly listable events.
- No JSON-LD on ended, private, or unpublished events.

**Booking**

- Paid: `/event/{nid}/book` — entry only.
- RSVP: `/event/{nid}/rsvp/form` — entry only.

**`/vendors`**

- Public theme/layout — not vendor console shell.

**Help Centre**

- `/help` loads for anonymous users.
- `/help/vendors` as anonymous → login with destination (see `HelpCentreController::vendorsIndex()`).
- No 403 from links visible on the public help hub.

**Vendor onboarding**

- Query `vendor=1` or destination containing `/vendor/onboard` triggers vendor registration context.
- Hidden: `user_picture`, `contact`, `timezone`, `path`, `language`, `vendor_profiles`, `field_marketing`, `field_vendor`, username (vendor context).
- Visible: email, password (and other required account fields).
- Admin user edit forms (`/user/{uid}/edit` as admin) — **unchanged** (fields not hidden outside register route).

---

## 7. Header checks (staging vs production)

### Staging — required

```bash
curl -sI https://staging.myeventlane.com.au/ | grep -i x-robots-tag
curl -sI https://staging.myeventlane.com.au/events | grep -i x-robots-tag
```

**Expected staging:**

```http
X-Robots-Tag: noindex, nofollow, noarchive, nosnippet
```

Set by `StagingSecuritySubscriber` when the host is a staging hostname. See also server-level guidance in [STAGING_INDEXING_PROTECTION.md](../operations/STAGING_INDEXING_PROTECTION.md).

### Production — safety check only

```bash
curl -sI https://myeventlane.com.au/ | grep -i x-robots-tag || true
```

**Expected production:**

- **No** staging `noindex, nofollow, noarchive, nosnippet` header.
- If production is unreachable from your test environment, mark **unable to confirm** and verify manually before any production deploy.
- **Never** apply staging noindex configuration to production.

---

## 8. JSON-LD checks

### Command line

Replace `{future-event-slug}` with a known public listable event alias:

```bash
curl -s https://staging.myeventlane.com.au/events/{future-event-slug} \
  | grep -c 'application/ld+json'
```

| Event type | Expected count |
| --- | --- |
| Public listable (future/current, published) | `1` |
| Ended / private / unpublished | `0` |

### Browser

1. View source on a public event detail page.
2. Confirm a single `<script type="application/ld+json">` with `@type": "Event"`.
3. Validate manually with [Google Rich Results Test](https://search.google.com/test/rich-results) after deploy (staging URL is fine for structural validation; staging is noindex).

---

## 9. Alias checks

### Drush (after `cim`)

```bash
php -d memory_limit=1024M vendor/bin/drush.php pathauto:aliases-generate create canonical_entities:node -y --uri="$SITE_URI"
php -d memory_limit=1024M vendor/bin/drush.php pathauto:aliases-generate create canonical_entities:taxonomy_term -y --uri="$SITE_URI"
```

### Manual

| Entity | Pattern (from config) | Example |
| --- | --- | --- |
| Event nodes | `/events/[title]` | `/events/summer-markets-2026` |
| Category terms (`categories` bundle) | `/events/[term]` | `/events/music` |
| Help articles | `/help/[title]` | `/help/add-event-to-calendar` |
| Public cards / listings | — | Must **not** link to `/node/{nid}` |

---

## 10. Rollback notes

`remote-deploy.sh` automatic rollback on deploy failure:

- Restores `~/staging/current` symlink to the **previous release** directory (if captured before switch).
- Clears maintenance mode and runs `drush cr` on the restored release.

See [`scripts/deploy/remote-deploy.sh`](../../scripts/deploy/remote-deploy.sh) — `mel_deploy_cleanup` trap on `EXIT`.

### Operator rollback (if staging is broken after a successful deploy)

1. Identify previous release: `ls -lt ~/staging/releases`
2. Point `current` at the prior release: `ln -sfn ~/staging/releases/{PREVIOUS_TIMESTAMP} ~/staging/current`
3. **Do not** rollback config alone without matching code — config from this release (Pathauto, Views) requires the merged code.
4. Clear cache: `cd ~/staging/current && php -d memory_limit=1024M vendor/bin/drush.php cr --uri=https://staging.myeventlane.com.au`
5. Re-check staging `X-Robots-Tag` header (§7).
6. Record incident and remaining risks (§12).

---

## 11. Sign-off checklist

- [ ] Deploy completed
- [ ] DB updates completed (`updb` — if pending)
- [ ] Config import completed (`cim`)
- [ ] Pathauto aliases generated
- [ ] Cache rebuilt
- [ ] Homepage checked
- [ ] Events page checked
- [ ] Calendar checked
- [ ] Category page checked
- [ ] Public event JSON-LD checked (count = 1)
- [ ] Ended/private event JSON-LD absent (count = 0)
- [ ] Paid booking entry checked
- [ ] RSVP booking entry checked
- [ ] Vendors page public shell checked
- [ ] Help Centre checked
- [ ] Vendor help anonymous routing checked
- [ ] Vendor onboarding register checked
- [ ] Staging noindex header present
- [ ] Production noindex header absent (or manually confirmed)
- [ ] Remaining risks recorded

**URLs used for this run:**

| Placeholder | Staging URL used |
| --- | --- |
| Future event | |
| Ended event | |
| Category | |
| Paid booking | |
| RSVP booking | |

**Signed off by:** _______________ **Date:** _______________

---

## 12. Remaining risks

| Risk | Notes |
| --- | --- |
| `field_event_visibility` | Referenced in vendor/Studio PHP but **not exported** in `config/sync` — private/unlisted/passcode visibility may not be fully enforced on all discovery surfaces until audited separately |
| Private / unlisted / passcode events | Require a dedicated visibility audit beyond this smoke test |
| `simple_sitemap` | Not installed or configured in `config/sync` — no automated sitemap generation in repo; see [STAGING_INDEXING_PROTECTION.md](../operations/STAGING_INDEXING_PROTECTION.md) |
| Per-environment aliases | Existing nodes/terms need Pathauto generation on **each** environment after deploy |
| Default CI deploy skips `cim` | Until workflow passes `RUN_CIM=1`, operators must run config import manually for this release |
| Full checkout payment | Out of scope — booking **entry** only |

---

## Related documentation

| Doc | Use |
| --- | --- |
| [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md) | Git → CI → staging deploy flow |
| [STAGING_SETUP.md](../STAGING_SETUP.md) | Staging environment hardening |
| [STAGING_INDEXING_PROTECTION.md](../operations/STAGING_INDEXING_PROTECTION.md) | Staging-only noindex (never production) |
| [release-hardening.md](../release-hardening.md) | Config sync discipline, `mel:health` |
| [operational-addons-staging-qa.md](../operational-addons-staging-qa.md) | Example staging QA runbook pattern |
