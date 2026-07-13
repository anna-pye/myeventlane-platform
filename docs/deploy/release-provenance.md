# Release provenance (REVISION)

Every MEL staging release ships a top-level `REVISION` file so operators can answer:

- What code was built?
- Which GitHub workflow / run deployed it?
- When was it built and deployed?
- Which `composer.lock` was in the artifact?
- Which release directory is live?

GitHub remains the deployment source of truth. This metadata is observational: it does **not** change release activation, symlink switching, health checks, or rollback.

## Format

`REVISION` is plain text `KEY=VALUE` pairs, one per line (UTF-8, no BOM).

Example:

```text
artifact_sha=8e2876d542252fdd6f7b2492df59673dd4c8848d
branch=main
tag=
workflow=Deploy Staging
workflow_run=1234567890
run_attempt=1
actor=anna-pye
repository=anna-pye/myeventlane-platform
build_time_utc=2026-07-13T08:40:00Z
composer_lock_sha256=016d2ae9184bb9e62c12f68a1cab1c927c3b1273452520093a24ea0066590a52
deploy_script_sha256=c7572e7288bf5f927b7146e4015122758f16fad9ac04548950c35a1993e9462f
release_identifier=1234567890.1
deploy_time_utc=2026-07-13T08:45:12Z
release_dir=20260713084512
```

Empty values (for example `tag=` on a branch push) are intentional.

### Fields

| Key | When written | Source |
|-----|--------------|--------|
| `artifact_sha` | Build | `GITHUB_SHA` / `${{ github.sha }}` |
| `branch` | Build | `GITHUB_REF_NAME` when `GITHUB_REF_TYPE=branch` |
| `tag` | Build | `GITHUB_REF_NAME` when `GITHUB_REF_TYPE=tag` |
| `workflow` | Build | `GITHUB_WORKFLOW` (caller workflow name) |
| `workflow_run` | Build | `GITHUB_RUN_ID` |
| `run_attempt` | Build | `GITHUB_RUN_ATTEMPT` |
| `actor` | Build | `GITHUB_ACTOR` |
| `repository` | Build | `GITHUB_REPOSITORY` |
| `build_time_utc` | Build | `date -u +%Y-%m-%dT%H:%M:%SZ` on the runner |
| `composer_lock_sha256` | Build | `sha256sum composer.lock` |
| `deploy_script_sha256` | Build | `sha256sum scripts/deploy/remote-deploy.sh` |
| `release_identifier` | Build (updated on deploy if `MEL_RELEASE_IDENTIFIER` set) | `${GITHUB_RUN_ID}.${GITHUB_RUN_ATTEMPT}` |
| `deploy_time_utc` | Deploy | `MEL_DEPLOY_TIME_UTC` or server UTC clock |
| `release_dir` | Deploy | Basename of the new release directory (`YYYYMMDDHHMMSS`) |

Only values GitHub Actions or the deploy host can reliably provide are recorded. There is no invented semantic `deploy_script_version`; use `deploy_script_sha256` instead.

## Where it is written

1. **Build** (`.github/workflows/reusable-build.yml`) writes `REVISION` into the artifact before `tar`.
2. **Artifact copy** lands `REVISION` under `~/staging/releases/<timestamp>/REVISION`.
3. **Remote deploy** (`scripts/deploy/remote-deploy.sh` → `mel_write_revision_metadata`) preserves KEY=VALUE provenance and stamps `deploy_time_utc` + `release_dir` only.
4. **Live path:** `~/staging/current/REVISION` (symlink to the active release).

`MEL_REVISION` (plain SHA from the deploy workflow) remains as a legacy fallback when an artifact has no KEY=VALUE `artifact_sha=` line. It no longer overwrites a complete provenance file.

## Post-deploy verification

After remote deploy succeeds, `.github/workflows/deploy-staging.yml`:

1. Checks out `${{ github.sha }}` (sparse: `composer.lock` + verifier script).
2. SSHs to staging and reads `~/staging/current/REVISION`.
3. Runs `scripts/deploy/verify-revision-metadata.sh` which asserts:
   - `artifact_sha` equals `${{ github.sha }}`
   - `composer_lock_sha256` equals `sha256sum` of the repository `composer.lock` at that commit
4. Also asserts the build job output `composer_lock_sha256` matches the same lockfile hash.

### Failure behaviour

Any mismatch or missing required field **fails the GitHub Actions job** (non-zero exit). Deploy does not silently succeed with bad provenance.

Note: verification runs **after** the release has been activated. A red verification step means the live release is wrong or metadata is corrupt — investigate and redeploy or roll back using existing ops procedures. Verification itself does not perform rollback.

## Troubleshooting

| Symptom | Likely cause | What to check |
|---------|--------------|---------------|
| `artifact_sha` mismatch | Wrong artifact / wrong checkout pin | Build `ref: ${{ github.sha }}`, artifact name `staging-release-<sha>`, live `REVISION` |
| `composer_lock_sha256` missing | Old artifact format | Confirm build step writes KEY=VALUE `REVISION`; rebuild from `main` |
| `composer_lock_sha256` mismatch | Lockfile drift between build tree and verify checkout | Same commit for build and verify; inspect `composer.lock` at `${{ github.sha }}` |
| `REVISION` missing on `current` | Deploy failed before metadata write, or `current` not switched | Remote deploy logs; `ls -l ~/staging/current` |
| Legacy single-line SHA only | Pre-provenance release | Redeploy from a commit that includes KEY=VALUE generation |

### Manual inspection

```bash
ssh <staging-user>@<host> 'cat ~/staging/current/REVISION'
sha256sum composer.lock
```

Local verifier (optional):

```bash
scripts/deploy/verify-revision-metadata.sh <expected_sha> <expected_lock_sha256> /path/to/REVISION
```

## Related

- [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md) — push/merge deploy wiring
- [`.github/workflows/deploy-staging.yml`](../../.github/workflows/deploy-staging.yml)
- [`.github/workflows/reusable-build.yml`](../../.github/workflows/reusable-build.yml)
- [`scripts/deploy/remote-deploy.sh`](../../scripts/deploy/remote-deploy.sh)
- [`scripts/deploy/verify-revision-metadata.sh`](../../scripts/deploy/verify-revision-metadata.sh)
