# Repository root cleanup audit

**Date:** 2026-06-22  
**Scope:** Files at repository root (`/`) only. Drupal application code under `web/` was not modified except one npm path update for the hero lint script.

## Summary

The repository root had accumulated historical markdown notes, one-off shell scripts, PHP utilities, accidental command artefacts, and local database dumps. Operational scripts were moved to `scripts/`; destructive scripts to `scripts/dangerous/`; six obsolete PHP utilities were removed after reference checks; accidental junk files were deleted.

**Follow-up (not done in this pass):** move ~45 historical markdown notes to `docs/archive/root-notes/`; relocate JSON audit snapshots; remove or untrack local DB dumps and staging artefacts after owner sign-off.

---

## Keep in root

These files belong at the repository root and should remain unless proven obsolete.

| File | Reason |
|------|--------|
| `AGENTS.md` | Agent operating instructions |
| `CLAUDE.md` | Claude Code instructions |
| `DESIGN_SYSTEM.md` | Runtime theme/design contract |
| `composer.json` | Composer project definition |
| `composer.lock` | Locked PHP dependencies |
| `package.json` | Root npm scripts (`mel:build`, `mel:lint`, husky) |
| `package-lock.json` | Locked root npm dependencies |
| `yarn.lock` | Alternate lockfile (present in repo) |
| ~~`vite.config.js`~~ | **Removed** — legacy; see [`frontend-build-ownership.md`](frontend-build-ownership.md) |
| `phpcs.xml` | PHP_CodeSniffer project config |
| `.cursorignore` | Cursor ignore rules |
| `.editorconfig` | Editor formatting |
| `.gitattributes` | Git attributes |
| `.gitignore` | Git ignore rules |
| `.gitleaks.toml` | Secret scanning config |
| `.metadata_never_index` | macOS Spotlight hint |

**Missing:** there is no root `README.md`. Recommend adding one that points to `docs/brand/README.md` and `CLAUDE.md`.

**DDEV / Drupal config:** `.ddev/` (directory), `web/` (docroot), and standard Drupal paths are outside this flat-file audit but remain at their conventional locations.

---

## Move to `docs/`

Historical session notes and reports. None were moved in this pass; target layout below.

### → `docs/archive/root-notes/` (historical implementation/debug notes)

| File | Notes |
|------|-------|
| `AUDIT_REPORT.md` | General audit snapshot |
| `CHECKOUT_ISSUES_REPORT.md` | Checkout investigation |
| `DDEV_MULTI_DOMAIN_CHECKLIST.md` | Multi-domain setup checklist |
| `DEBUG_GRID_ISSUE.md` | Grid layout debug |
| `DEBUG_INSTRUCTIONS.md` | Debug how-to |
| `DEV_GIT_RULES.md` | Git workflow notes |
| `DIAGNOSTIC_FORM_VALUES.md` | Form diagnostic |
| `DUAL_DOMAIN_ARCHITECTURE_ANALYSIS.md` | Domain architecture |
| `ENTITY_UPDATES_INSTRUCTIONS.md` | Entity update runbook |
| `EVENT_FORM_AUDIT.md` | Event form audit |
| `EVENT_FORM_FIX_IMPLEMENTATION.md` | Form fix notes |
| `EVENT_NODE_DISCOVERY_REPORT.md` | Event node discovery |
| `EVENT_WIZARD_FIX_COMPLETE.md` | Wizard fix completion |
| `EVENT_WIZARD_FULL_AUDIT.md` | Wizard audit |
| `EVENT_WIZARD_IMPLEMENTATION.md` | Wizard implementation |
| `EVENT_WIZARD_PAGE_ALIGNMENT_AUDIT.md` | Wizard alignment |
| `FILES_CREATED.md` | Session file list |
| `FIXES_APPLIED.md` | Fix log |
| `GIT_MERGE_PLAN.md` | Merge planning |
| `GIT_PUSH_WORKFLOW.md` | Push workflow |
| `GRID_FIX_CRITICAL.md` | Grid fix |
| `GRID_FIX_DIAGNOSTICS.md` | Grid diagnostics |
| `GRID_FIX_FINAL.md` | Grid fix final |
| `HOMEPAGE_AND_EVENT_PAGE_FIXES.md` | Homepage/event fixes |
| `HOMEPAGE_EVENT_PAGE_FIXES_COMPLETE.md` | Fix completion |
| `IMPLEMENTATION_STATUS.md` | Status tracker |
| `LIBRARY_ATTACHMENT_CHECK.md` | Library attachment |
| `MEL_V2_REAL_CONTENT_IMPLEMENTATION.md` | Real content work |
| `MULTI_DOMAIN_AUTH_DIAGNOSIS_REPORT.md` | Auth diagnosis |
| `ONBOARDING_ANALYSIS.md` | Onboarding analysis |
| `RESET_ADMIN_INSTRUCTIONS.md` | Admin reset instructions |
| `RSVP_DONATION_REVIEW.md` | RSVP/donation review |
| `TESTING_GUIDE.md` | Testing guide |
| `TEST_RESULTS.md` | Test results |
| `VENDOR_DASHBOARD_STRIPE_UPDATES.md` | Vendor Stripe notes |
| `VENDOR_SETTINGS_DIAGNOSIS.md` | Vendor settings debug |
| `VIEW_MODES_SETUP.md` | View modes setup |
| `WIREFRAMES_MEL_V2.md` | Wireframes |
| `WIZARD_COMPONENT_IMPLEMENTATION.md` | Wizard components |
| `WIZARD_UI_FIX.md` | Wizard UI fix |
| `myeventlane-audit-checklist.md` | Audit checklist |
| `myeventlane-audit-report.md` | Audit report |

### → `docs/operations/` (retain as operational reference)

| File | Notes |
|------|-------|
| `SECRETS_PROTECTION_GUIDE.md` | Security guidance — keep discoverable |
| `STAGING_INDEXING_PROTECTION.md` | Staging SEO/indexing |
| `STAGING_SETUP.md` | Staging setup runbook |

### → `.cursor/rules/` (misplaced Cursor rule)

| File | Notes |
|------|-------|
| `mel-drupal-commerce.mdc` | Duplicate of `.cursor/rules/mel-drupal-commerce.mdc` content; remove from root after confirming parity |

---

## Move to `scripts/` — **done**

Root operational scripts relocated to `scripts/`. Destructive scripts relocated to `scripts/dangerous/`.

See [`scripts/README.md`](../../scripts/README.md) for per-script safety metadata.

| Former root path | New path |
|------------------|----------|
| `check-attendee-matching.sh` | `scripts/check-attendee-matching.sh` |
| `check-email-queue.sh` | `scripts/check-email-queue.sh` |
| `check-mel-hero-variants.mjs` | `scripts/check-mel-hero-variants.mjs` |
| `check-session-status.sh` | `scripts/check-session-status.sh` |
| `check-session-user.sh` | `scripts/check-session-user.sh` |
| `check-ticket-issue.sh` | `scripts/check-ticket-issue.sh` |
| `create-email-template.sh` | `scripts/create-email-template.sh` |
| `create-staging-backup.sh` | `scripts/create-staging-backup.sh` |
| `diagnose-anna-access.sh` | `scripts/diagnose-anna-access.sh` |
| `ensure-uid1-admin.sh` | `scripts/ensure-uid1-admin.sh` |
| `fix-anna-access.sh` | `scripts/fix-anna-access.sh` |
| `fix-email-and-tickets.sh` | `scripts/fix-email-and-tickets.sh` |
| `fix-vendor-access-complete.sh` | `scripts/fix-vendor-access-complete.sh` |
| `fix-vendor-access-routes.sh` | `scripts/fix-vendor-access-routes.sh` |
| `myeventlane-audit-collector.sh` | `scripts/myeventlane-audit-collector.sh` |
| `preflight-health-check.sh` | `scripts/preflight-health-check.sh` |
| `rebuild-scss.sh` | `scripts/rebuild-scss.sh` |
| `setup-email-template.sh` | `scripts/setup-email-template.sh` |
| `setup-event-ct.sh` | `scripts/setup-event-ct.sh` |
| `start-ngrok-tunnels.sh` | `scripts/start-ngrok-tunnels.sh` |
| `test-cookie-domain.sh` | `scripts/test-cookie-domain.sh` |
| `test-email-and-tickets.sh` | `scripts/test-email-and-tickets.sh` |
| `test-phase2.sh` | `scripts/test-phase2.sh` |
| `test-vendor-access.sh` | `scripts/test-vendor-access.sh` |
| `verify-access-fix.sh` | `scripts/verify-access-fix.sh` |
| `backup-build-and-db.sh` | `scripts/backup-build-and-db.sh` (was untracked) |
| (new maintenance utility) | `scripts/maintenance/mel-update-drupal.sh` (guarded local Drupal dependency updater) |
| `delete-events.php` | `scripts/dangerous/delete-events.php` |
| `reset-admin-password.sh` | `scripts/dangerous/reset-admin-password.sh` |
| `reset-drupal.sh` | `scripts/dangerous/reset-drupal.sh` |
| `setup-event-content-type.sh` | `scripts/dangerous/setup-event-content-type.sh` |
| `wipe-custom-config.sh` | `scripts/dangerous/wipe-custom-config.sh` |

**Path update required (done):** `web/themes/custom/myeventlane_theme/package.json` now references `scripts/check-mel-hero-variants.mjs`. `DESIGN_SYSTEM.md` updated to match.

---

## Delete — **done**

### Obsolete one-off PHP utilities (reference check)

| File | Reference check | Action |
|------|-----------------|--------|
| `delete-events.php` | Not referenced; destructive bulk delete | **Moved** to `scripts/dangerous/` (retained with warnings) |
| `debug-vendor-access.php` | Not referenced; hardcoded `anna` user debug | **Deleted** |
| `tmp_form_dump.php` | Not referenced; event form structure dump | **Deleted** |
| `install-phase2-fields.php` | Self-doc only; fields exist in `config/sync/` (e.g. `field.field.myeventlane_vendor.myeventlane_vendor.field_vendor_bio.yml`) | **Deleted** |
| `install-phase3-event-fields.php` | Self-doc only; fields exist in `config/sync/` (e.g. `field.field.node.event.field_event_vendor.yml`) | **Deleted** |
| `create-order-receipt-template.php` | Not referenced; superseded by `web/modules/custom/myeventlane_messaging/config/install/myeventlane_messaging.template.order_receipt.yml`, `config/sync/myeventlane_messaging.template.order_receipt.yml`, and `scripts/create-email-template.sh` | **Deleted** |

### Accidental junk / scratch (zero-byte or crash artefacts)

| File | Reason |
|------|--------|
| `alter`, `cd`, `node`, `vite`, `stylelint`, `memory` | Empty files from mistyped shell commands |
| `myeventlane@2.0.0`, `myeventlane_theme@1.0.0` | Empty npm artefact filenames |
| `core` | 37 MB ELF core dump — must not live in repo root |
| `vendor-event-form-page.js.bak`, `vendor-studio.js.bak` | Stale JS backups |
| `test-event-full.css`, `test-hooks.txt` | Local test scratch |

---

## Needs owner review

Do not delete or move without explicit owner decision.

| File | Concern | Recommendation |
|------|---------|----------------|
| `mel@staging.myeventlane.com.au` | ~3 MB gzip (local dump/artefact); matches `.gitignore` `mel@*` but may exist on disk | Delete locally; ensure not tracked |
| `mel_help_sync.sql.gz` | Database dump; gitignored `*.sql.gz` | Keep outside repo or in secure backup store only |
| `pre_sync_backup.sql.gz` | Database dump | Same |
| `staging-db.sql.gz` | Database dump | Same |
| `staging-nginx.conf` | **Resolved 2026-06-22** — moved to `infrastructure/nginx/staging-nginx.conf`; see `infrastructure/README.md` | — |
| `blog-export.json` | Content export — may contain PII | Confirm retention policy; move to `docs/archive/` or secure storage |
| `enabled-modules.json` | Drush/composer audit snapshot | Move to `docs/archive/root-notes/` or regenerate on demand |
| `extensions.json` | Extension audit snapshot | Same |
| `mel-menu.json`, `mel-routes.json`, `mel-services.json`, `mel-template-parity.json` | Governance audit inputs used by `scripts/governance/*` | Move alongside governance scripts or document as generated artefacts |
| `mel-drupal-commerce.mdc` | Root copy of Cursor rule | Remove after confirming match with `.cursor/rules/mel-drupal-commerce.mdc` |
| `.cursor-scss.md`, `.cursor-system.md`, `.cursor-view-modes.md` | Gitignored Cursor scratch (` .cursor-*.md`) | Safe to delete locally |
| ~45 historical `*.md` files (see above) | Clutter root; valuable as archive | Batch move to `docs/archive/root-notes/` in follow-up PR |

---

## Validation commands

```bash
git status
find . -maxdepth 1 -type f | sort
find scripts -maxdepth 2 -type f | sort
npm run mel:lint   # confirms hero script path after move
```

---

## Residual risk

- Historical markdown still at root until follow-up move.
- Local DB dumps and `mel@*` artefacts may remain on developer machines even when gitignored.
- Anna-specific debug scripts under `scripts/` (`diagnose-anna-access.sh`, `fix-anna-access.sh`, etc.) are safe but environment-specific; consider `scripts/dev/` subfolder in a later pass.
- `scripts/dangerous/*` must never be wired into CI, hooks, or deployment automation.
