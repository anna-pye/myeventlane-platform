# Recovery artefacts disposition (staging)

Review-only disposition for leftover files from the July 2026 staging deployment recovery.

**Policy:** do not delete automatically. Operator confirms before any removal. Never touch `/home/mel/sites/myeventlane_hold`. Never use `rm -rf` on DocumentRoot without a written rollback plan.

**Live deploy path (must remain):** GitHub Actions → artifact → `scripts/deploy/remote-deploy.sh` inside the active release. Activation via `~/staging/current` and `~/public_html/staging/{current,web}` symlinks.

Verified post–PR #679 (2026-07-13): staging `show-release.sh --verify` **PASS**; live `remote-deploy.sh` SHA matches GitHub `main`; HOLD mtime unchanged.

## Inventory

### `/home/mel/staging/remote-deploy.631023e16.sh`

| Field | Value |
|-------|--------|
| Disposition | **Archive** (then remove after retention window) |
| Still required? | No — not on the activation path |
| Reasoning | Recovery-era backup of `remote-deploy.sh` (header: `MEL REMOTE DEPLOY WITH VALIDATION 2026-07-04`). SHA differs from the Git-shipped script in `~/staging/current/scripts/deploy/remote-deploy.sh`. Must **never** be executed for normal deploys. Keep briefly as forensic reference, then move off-host or delete after operator sign-off. |

### `/home/mel/public_html/staging/current.real-empty-20260711090819`

| Field | Value |
|-------|--------|
| Disposition | **Safe to remove** after confirming symlinks are healthy |
| Still required? | No for normal operation |
| Reasoning | Pre–Phase-2 empty DocumentRoot placeholder moved aside during Stage 3. Live `~/public_html/staging/current` is a symlink to `~/staging/current`. Directory only contains nested `web/.well-known/acme-challenge`. Referenced by `STAGE3_ROLLBACK_*.txt` as an emergency restore target — remove only after discarding that rollback procedure or archiving the note. |

### `/home/mel/public_html/staging/web.real-empty-20260711090819`

| Field | Value |
|-------|--------|
| Disposition | **Safe to remove** after confirming symlinks are healthy |
| Still required? | No for normal operation |
| Reasoning | Same Stage 3 empty `web` placeholder. Live `~/public_html/staging/web` → `~/staging/current/web`. Same ACME path residue. Pair with `current.real-empty-*` removal. |

### `/home/mel/staging/STAGE3_ROLLBACK_20260711090819.txt`

| Field | Value |
|-------|--------|
| Disposition | **Archive** |
| Still required? | Only if keeping Stage 3 empty dirs as emergency restore |
| Reasoning | Documents how to restore empty DocumentRoots (would **break** the Git-driven symlink model if followed today). Archive with the recovery programme notes; do not follow for routine rollback. Prefer [staging-ops-runbook.md](./staging-ops-runbook.md) rollback section. |

### `/home/mel/docs/deployment/*` (server-local)

| Paths | `deployment-contract.md`, `deployment-interface.md`, `deployment-redesign.md`, `release-metadata.md`, `release-validation.md` (dated ~2026-07-03) |
|-------|--------|
| Disposition | **Archive** or replace with pointers to Git docs |
| Still required? | No as operational source of truth |
| Reasoning | Stale Hold/redesign planning docs on the server. GitHub `docs/STAGING_DEPLOY_GIT.md`, `docs/deploy/release-provenance.md`, and `docs/deploy/staging-ops-runbook.md` are authoritative. Safe to archive off-host; do not treat as live deploy contracts. |

### Temporary recovery documentation (repository)

| Field | Value |
|-------|--------|
| Disposition | **Unknown** / case-by-case |
| Reasoning | Feature-specific audits and smoke notes under `docs/audits/` and `docs/deploy/staging-public-discovery-seo-smoke-test.md` are not recovery debris. Keep unless a separate docs cleanup decides otherwise. |

## What must not be removed

- `~/staging/releases/*` (except normal `MEL_KEEP_RELEASES` pruning by deploy)
- `~/staging/current` symlink
- `~/staging/shared/`
- `~/staging/config/sync`
- `~/public_html/staging/current` and `web` **symlinks** (the live ones)
- Anything under `/home/mel/sites/myeventlane_hold`

## Suggested cleanup order (manual)

1. Confirm `show-release.sh --verify` PASS and HTTP/Drush healthy.
2. Archive `remote-deploy.631023e16.sh` and `STAGE3_ROLLBACK_*.txt` off-host (or to a dated tarball outside DocumentRoot).
3. Remove `current.real-empty-*` and `web.real-empty-*` only after step 2.
4. Archive or delete stale `~/docs/deployment/*` and leave a one-line README pointing at the Git runbooks.
5. Record who approved deletion and the date in the ops log.

## Related

- [staging-ops-runbook.md](./staging-ops-runbook.md)
- [release-provenance.md](./release-provenance.md)
- [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md)