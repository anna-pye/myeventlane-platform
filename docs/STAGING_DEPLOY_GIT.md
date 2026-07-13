# Local work, push on your terms, and staging deploy

This document describes how Git and GitHub Actions work for staging in this repository, and how to debug a failed deploy.

## How staging deploy is wired

- **Trigger:** Any **push to `main`** runs `.github/workflows/deploy-staging.yml`.
- **Flow:** The **Build** job (reusable workflow in `.github/workflows/reusable-build.yml`) produces a deployable artifact, then the **Deploy** job downloads it, uses SSH/SCP to the staging host, and runs `scripts/deploy/remote-deploy.sh` on the server (see the workflow for exact steps and `SITE_URI` / `APP_PATH`).
- **Release identity:** Each release has a top-level `REVISION` file in KEY=VALUE provenance format (`artifact_sha`, workflow run ids, `composer_lock_sha256`, `deploy_script_sha256`, build/deploy timestamps, etc.). Build writes it into the artifact; remote deploy stamps deploy-time fields only. After deploy, Actions verifies `~/staging/current/REVISION` against `${{ github.sha }}`, the repository `composer.lock`, and `scripts/deploy/remote-deploy.sh`, then runs `show-release.sh --verify --quiet` on the active release, and **fails the job** on mismatch. See [deploy/release-provenance.md](./deploy/release-provenance.md).
- **UI-only deploys:** The staging workflow is triggered by `push` to `main` only. There is no `workflow_dispatch` in that file, so you cannot start the same deploy from the Actions "Run workflow" button without a workflow change.

## 1. Work locally without affecting staging

1. Use a **feature branch**, not `main` directly for ongoing work:
   - `git checkout -b fix/your-feature` (or `feature/...`).
2. Commit on that branch only. **Do not merge to `main`** until you want a staging deploy.
3. Pushing a **non-main** branch does **not** run the Deploy Staging workflow (only `main` does). For example: `git push -u origin fix/your-feature`.
4. Open a **pull request** to `main` to review; leave it unmerged until you are ready to deploy to staging.

## 2. Delaying push and merging when you are ready

- You can keep work local or on a remote feature branch for as long as you like, then push or merge when you choose.
- **Deploy to staging** happens when something **lands on `main`** (merge of a PR or a direct `git push` to `main`).

**Recommended: PR then merge**

1. Push your branch: `git push -u origin your-branch`.
2. Open or update the PR into `main`.
3. **Merge the PR (or squash-merge)** when you are ready. That update to `main` runs the workflow once for that commit.

**Direct to `main` (if your process allows it)**

1. `git checkout main` → `git pull` → merge or rebase your work into `main`.
2. `git push origin main` — that push triggers the staging deploy for that commit.

## 3. When "Deploy to staging" fails in GitHub Actions

If **Build** succeeds and **Deploy to staging** is red, the problem is in the deploy job, not in "PHP failed to build" (unless the build actually failed first).

1. In GitHub: open the **failed** workflow run → open the **Deploy to staging** job.
2. **Expand the first step that shows a red X** (failed steps are ordered; find the first failure).
3. Read the **last 30–50 lines** of that step. The generic "Process completed with exit code 1" is not the real error; the line above it usually is.

**Typical failure points (per `.github/workflows/deploy-staging.yml`):**

- **Validate SSH key / Test SSH connection** — key format, `secrets.SSH_PRIVATE_KEY`, host, user, firewall, or `known_hosts`.
- **Upload artifact / Run remote deploy script** — `scp` or the remote `bash scripts/deploy/remote-deploy.sh` exiting non-zero (Drush, Composer, permissions, `APP_PATH`, etc.).
- **Verify release** — the post-deploy `ssh ... ls` check if paths do not match what the server uses.
- **Verify deployed REVISION provenance** — `artifact_sha`, `composer_lock_sha256`, or `deploy_script_sha256` in `~/staging/current/REVISION` does not match this workflow commit / repository lockfile / `remote-deploy.sh`, or `show-release.sh --verify` fails (see [deploy/release-provenance.md](./deploy/release-provenance.md)).

**Secrets to verify** (Repository **Settings → Secrets and variables**): `SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_USER` must match a key that is authorised on the staging host.

## 4. Optional: other deploy triggers (not in the repo today)

To deploy only on tags, on a `staging` branch, or from a **workflow_dispatch** button, you need a separate or amended workflow. That is a process change and a YAML change, not a Git client-only switch.

## Quick reference

| Goal | What to do |
|------|------------|
| Work without deploying staging | Branch off `main`, do not merge to `main` yet |
| Deploy staging | Merge PR to `main`, or push `main`, when ready |
| Diagnose a red deploy | Open the failed "Deploy to staging" step; read the end of the log; fix SSH, secrets, or `remote-deploy.sh` output on the server |

## Related

- [GIT_PUSH_WORKFLOW.md](./GIT_PUSH_WORKFLOW.md) — add, commit, push, and secret checks
- [STAGING_SETUP.md](./STAGING_SETUP.md) — staging environment setup
- [deploy/release-provenance.md](./deploy/release-provenance.md) — REVISION format, verification, troubleshooting
- [`.github/workflows/deploy-staging.yml`](../.github/workflows/deploy-staging.yml) — exact job steps
