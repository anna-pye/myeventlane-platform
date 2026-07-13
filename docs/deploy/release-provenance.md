# Release provenance (REVISION)

Every MEL staging release ships a top-level `REVISION` file so operators can answer:

- What code was built?
- Which GitHub workflow / run deployed it?
- When was it built and deployed?
- Which `composer.lock` was in the artifact?
- Which `scripts/deploy/remote-deploy.sh` was fingerprinted into the artifact?
- Which release directory is live?

GitHub remains the deployment source of truth. This metadata is observational: it does **not** change release activation, symlink switching, health checks, or rollback.

**Scope:** staging only. Production HOLD (`/home/mel/sites/myeventlane_hold`) is out of scope and must not be modified by deploy or provenance tooling.

**Policy:** never edit a live release tree by hand to “fix” provenance. Redeploy from GitHub, or use the existing rollback process.

## Format

`REVISION` is plain text `KEY=VALUE` pairs, one per line (UTF-8, no BOM).

Parsers must treat the file as **data only**. Never `source` / `eval` it.

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

| Key | Required for post-deploy verify | When written | Source |
|-----|----------------------------------|--------------|--------|
| `artifact_sha` | Yes (40 lowercase hex) | Build | `GITHUB_SHA` / `${{ github.sha }}` |
| `branch` | No | Build | `GITHUB_REF_NAME` when `GITHUB_REF_TYPE=branch` |
| `tag` | No | Build | `GITHUB_REF_NAME` when `GITHUB_REF_TYPE=tag` |
| `workflow` | No | Build | `GITHUB_WORKFLOW` (caller workflow name) |
| `workflow_run` | No (validated in artifact packaging) | Build | `GITHUB_RUN_ID` |
| `run_attempt` | No | Build | `GITHUB_RUN_ATTEMPT` |
| `actor` | No | Build | `GITHUB_ACTOR` |
| `repository` | No | Build | `GITHUB_REPOSITORY` |
| `build_time_utc` | No | Build | ISO 8601 UTC when artifact `REVISION` is written (`date -u +%Y-%m-%dT%H:%M:%SZ`) |
| `composer_lock_sha256` | Yes (64 lowercase hex) | Build | `sha256sum composer.lock` |
| `deploy_script_sha256` | Yes (64 lowercase hex) | Build | `sha256sum scripts/deploy/remote-deploy.sh` |
| `release_identifier` | No | Build (may be updated on deploy if `MEL_RELEASE_IDENTIFIER` set) | `${GITHUB_RUN_ID}.${GITHUB_RUN_ATTEMPT}` |
| `deploy_time_utc` | No | Deploy | `MEL_DEPLOY_TIME_UTC` or server UTC clock |
| `release_dir` | No | Deploy | Basename of the new release directory (`YYYYMMDDHHMMSS`) |

Only values GitHub Actions or the deploy host can reliably provide are recorded. There is no invented semantic `deploy_script_version`; use `deploy_script_sha256` instead.

### Timestamps

| Field | Meaning |
|-------|---------|
| `build_time_utc` | When the release artifact metadata (`REVISION`) was created on the build runner. This is the artifact creation timestamp. |
| `deploy_time_utc` | When remote deploy stamped the active release. |

Do **not** add a second field with the same meaning as `build_time_utc` (for example `artifact_created_utc`).

## Where it is written

1. **Build** (`.github/workflows/reusable-build.yml`) writes `REVISION` into the artifact before `tar`. The deploy-script fingerprint is taken from the **same checkout** that is packaged, so the release tree contains the exact `scripts/deploy/remote-deploy.sh` that was hashed.
2. **Artifact copy** lands `REVISION` under `~/staging/releases/<timestamp>/REVISION`.
3. **Remote deploy** (`scripts/deploy/remote-deploy.sh` → `mel_write_revision_metadata`) preserves KEY=VALUE provenance and stamps `deploy_time_utc` + `release_dir` only.
4. **Live path:** `~/staging/current/REVISION` (symlink to the active release).

`MEL_REVISION` (plain SHA from the deploy workflow) remains as a legacy fallback when an artifact has no KEY=VALUE `artifact_sha=` line. It no longer overwrites a complete provenance file.

## Operator inspection: `show-release.sh`

On a deployed release:

```bash
cd /home/mel/staging/current
./scripts/deploy/show-release.sh --path /home/mel/staging/current
./scripts/deploy/show-release.sh --path /home/mel/staging/current --verify
./scripts/deploy/show-release.sh --path /home/mel/staging/current --verify --quiet
```

From a repository checkout (no `REVISION` in cwd):

```bash
./scripts/deploy/show-release.sh
```

This reports repository Git metadata and clearly states that **deployed-release metadata is unavailable**. It does not pretend a checkout is a live release.

### Behaviour

- Never modifies files
- Never requires database credentials
- Never prints secrets (only REVISION fields and verification status)
- Parses KEY=VALUE as data (never sources REVISION)
- Rejects malformed lines and duplicate keys
- Works without `jq`
- Optional fields may be absent; required checksum fields are enforced under `--verify`

### Exit codes

| Code | Meaning |
|------|---------|
| 0 | Success (display and/or `--verify` passed) |
| 1 | Verification failed or fatal error |
| 2 | Usage error |

### Verification vocabulary

| Status | Meaning |
|--------|---------|
| `verified` | Local file hash / format matches REVISION |
| `mismatch` | Compared and failed |
| `unavailable` | Field or file not present |
| `unknown` / `not checked` | Not evaluated in this mode |
| `not applicable` | Outside this tool’s scope (for example claiming GitHub commit existence) |

`--verify` checks local consistency only:

1. REVISION syntax
2. `composer_lock_sha256` vs `RELEASE_PATH/composer.lock`
3. `deploy_script_sha256` vs `RELEASE_PATH/scripts/deploy/remote-deploy.sh`
4. `artifact_sha` is 40 lowercase hex (does **not** prove the commit exists on GitHub)

## Post-deploy verification (GitHub Actions)

After remote deploy succeeds, `.github/workflows/deploy-staging.yml`:

1. Checks out `${{ github.sha }}` (sparse: `composer.lock`, `remote-deploy.sh`, verifier, `show-release.sh`).
2. SSHs to staging and reads `~/staging/current/REVISION`.
3. Runs `scripts/deploy/verify-revision-metadata.sh` which asserts:
   - `artifact_sha` equals `${{ github.sha }}`
   - `composer_lock_sha256` equals `sha256sum` of the repository `composer.lock` at that commit
   - `deploy_script_sha256` equals `sha256sum` of the repository `scripts/deploy/remote-deploy.sh` at that commit
   - critical keys are present, well-formed, and not duplicated
4. Asserts the build job output `composer_lock_sha256` matches the same lockfile hash.
5. Runs the **deployed** `~/staging/current/scripts/deploy/show-release.sh --path ~/staging/current --verify --quiet` after the REVISION fingerprints match this commit.

Expected repository calculation (Linux / Actions / staging hosts):

```bash
sha256sum scripts/deploy/remote-deploy.sh
sha256sum composer.lock
```

macOS local helpers may use `shasum -a 256` via `show-release.sh`; Actions and staging remain the deployment source of truth and use `sha256sum`.

### Failure behaviour

Any mismatch or missing required field **fails the GitHub Actions job** (non-zero exit). Deploy does not silently succeed with bad provenance.

Verification runs **after** the release has been activated. A red verification step means:

- the live release may already be active
- the Actions job fails
- an operator must investigate and redeploy or invoke the **existing** rollback process

Verification itself does **not** perform automatic rollback unless a future architecture change defines that behaviour.

## Troubleshooting

| Symptom | Likely cause | What to check |
|---------|--------------|---------------|
| `artifact_sha` mismatch | Wrong artifact / wrong checkout pin | Build `ref: ${{ github.sha }}`, artifact name `staging-release-<sha>`, live `REVISION` |
| `composer_lock_sha256` missing | Old artifact format | Confirm build step writes KEY=VALUE `REVISION`; rebuild from `main` |
| `composer_lock_sha256` mismatch | Lockfile drift between build tree and verify checkout | Same commit for build and verify; inspect `composer.lock` at `${{ github.sha }}` |
| `deploy_script_sha256` missing / empty | Old artifact or truncated REVISION | Rebuild from a commit that fingerprints `remote-deploy.sh` |
| `deploy_script_sha256` mismatch | Deploy script in release differs from repository commit | Compare `scripts/deploy/remote-deploy.sh` at `${{ github.sha }}` to the release copy; do not trust an ad-hoc server overlay |
| Duplicate key error | Corrupt REVISION | Inspect `~/staging/current/REVISION`; redeploy; do not hand-edit |
| Malformed SHA | Truncation / non-hex value | Inspect REVISION; rebuild |
| `REVISION` missing on `current` | Deploy failed before metadata write, or `current` not switched | Remote deploy logs; `ls -l ~/staging/current` |
| Legacy single-line SHA only | Pre-provenance release | Redeploy from a commit that includes KEY=VALUE generation |
| `show-release.sh` missing on release | Release predates the operator script | Redeploy after merge; do not copy scripts onto the server manually |

### Manual inspection

```bash
ssh <staging-user>@<host> 'cat ~/staging/current/REVISION'
ssh <staging-user>@<host> \
  '~/staging/current/scripts/deploy/show-release.sh --path ~/staging/current --verify'
```

Local verifier (optional):

```bash
scripts/deploy/verify-revision-metadata.sh \
  <expected_sha> \
  <expected_lock_sha256> \
  <expected_deploy_script_sha256> \
  /path/to/REVISION
```

Focused local tests:

```bash
scripts/deploy/test-release-provenance.sh
```

## Related

- [STAGING_DEPLOY_GIT.md](../STAGING_DEPLOY_GIT.md) — push/merge deploy wiring
- [`.github/workflows/deploy-staging.yml`](../../.github/workflows/deploy-staging.yml)
- [`.github/workflows/reusable-build.yml`](../../.github/workflows/reusable-build.yml)
- [`scripts/deploy/remote-deploy.sh`](../../scripts/deploy/remote-deploy.sh)
- [`scripts/deploy/verify-revision-metadata.sh`](../../scripts/deploy/verify-revision-metadata.sh)
- [`scripts/deploy/show-release.sh`](../../scripts/deploy/show-release.sh)
