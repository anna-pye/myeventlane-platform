# Task 13B — Remove committed Stripe secret material from backup/audit paths

**Branch:** `cursor/onboard-storage-fix-128b4`  
**Date:** 2026-04-29  
**Scope:** Repository hygiene only. No live Drupal gateway entities, no `drush cex`, no runtime Stripe settings, no `settings.php` under `web/sites/default`, no payment gateway DB changes.

---

## Original secret-bearing paths (tracked)

| Path | Contents removed |
|------|------------------|
| `_INVALID_config_backup_2026-01-02/sync/commerce_payment.commerce_payment_gateway.stripe.yml` | **Deleted from git** — stale config dump copy of Commerce Stripe gateway YAML containing **full** test **`secret_key`** and **`publishable_key`** values (same logical credential pair). |
| `_myeventlane_audit/config-sync/commerce_payment.commerce_payment_gateway.stripe.yml` | **Deleted from git** — duplicate audit copy of the same gateway export with the same fields. |

Active canonical config under [`config/sync`](../../config/sync) was **not** modified. Drupal runtime continues to load gateways from the database and/or deployed config as before.

---

## Remediation

- **`git rm`** on both files above (preferred over redaction for disposable backup/audit dumps).
- **`.gitignore`** additions (narrow; does not ignore `docs/audits/`, `config/sync/`, `web/modules/custom`, or `web/themes/custom`):

```gitignore
/_INVALID_config_backup_*/
/_myeventlane_audit/config-sync/
/_myeventlane_audit/sites-default/settings.php
```

**Note:** Ignoring `_INVALID_config_backup_*/` and `_myeventlane_audit/config-sync/` prevents **new** untracked files under those paths from being added by mistake. **Previously tracked** files in those trees (except the two removed) remain in the index until explicitly removed in a future cleanup. This task only removed the two gateway YAML files identified in Task 13.

---

## Post-fix verification

Commands used (see git history for exact runs):

- **`git grep`** (tracked files only) for long-form `sk_*`, `pk_*`, `whsec_*` strings — **no matches** after removing the two YAML copies (docs only reference ellipses like `sk_test_…`).
- **Untracked / ignored paths** (for example `.ddev/config.local.yaml`, generated compose snippets under `.ddev/`) may still contain keys locally — those are **not** part of Git and should stay **gitignored**; rotate local env if needed.
- **`web/modules/contrib/commerce_stripe`** ships upstream Stripe **test** constants in kernel tests — tracked vendor fixture; not introduced by this task.
- `composer validate` — valid.
- `ddev drush cr` — cache rebuild only; no config export.

---

## Git history

**This commit does not rewrite history.** The removed key material may still exist in older commits. If the repository was ever exposed beyond trusted collaborators, treat the affected **test** credentials as compromised for hygiene:

- **Rotate** the Stripe **test** secret and publishable keys in the [Stripe Dashboard](https://dashboard.stripe.com/) (test mode), then update Commerce payment gateways or environment variables on each environment — **not** in Git.
- **Webhook signing secrets** (`whsec_…`): rotate in Stripe and update site config/env if any matching secret was ever committed (none were removed in these two files beyond gateway YAML fields; confirm Dashboard for webhook endpoints).

A **separate** history-rewrite or `git filter-repo` task should be planned only with team agreement (force-push, fork mirrors, CI caches).

---

## Next steps (optional)

1. Rotate Stripe **test** keys that appeared in removed YAML (`sk_test_51…` prefix noted in Task 13 audit only).
2. Consider gradually **`git rm --cached`** other stale tracked files under `_INVALID_config_backup_*` / `_myeventlane_audit/config-sync/` if policy allows shrinking the repo (large trees; coordinate before bulk removal).
3. Run `git grep` on CI for restricted patterns to catch regressions.

---

## Related

- [mel-final-launch-readiness-audit.md](mel-final-launch-readiness-audit.md) — Task 13 P0 finding.
