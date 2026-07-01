# Archived root-level implementation notes

These markdown files lived at the repository root during active development sessions. They were moved here on **2026-06-22** as part of a repository root cleanup ([`docs/audits/repository-root-cleanup.md`](../../audits/repository-root-cleanup.md)).

## Why they were archived

The repository root had accumulated dozens of one-off audit reports, debug write-ups, wizard implementation logs, grid-layout fix notes, and session status trackers. That clutter made it harder to find current operating instructions and design contracts.

Git remains the backup mechanism. These files were relocated with `git mv` so **full history is preserved** — use `git log --follow docs/archive/root-notes/<filename>` to trace a document back to its original root path.

## What stayed at the repository root

| File | Role |
|------|------|
| `AGENTS.md` | Agent operating instructions |
| `CLAUDE.md` | Claude Code instructions |
| `DESIGN_SYSTEM.md` | Locked public theme / design contract |

There is no root `README.md` yet; see [`docs/brand/README.md`](../../brand/README.md) for brand strategy entry point.

## Active operational docs (not in this archive)

These remain maintained outside this folder:

| Path | Role |
|------|------|
| [`docs/SECRETS_PROTECTION_GUIDE.md`](../../SECRETS_PROTECTION_GUIDE.md) | Secret scanning and pre-push checks |
| [`docs/STAGING_SETUP.md`](../../STAGING_SETUP.md) | Staging environment hardening runbook |
| [`docs/operations/STAGING_INDEXING_PROTECTION.md`](../../operations/STAGING_INDEXING_PROTECTION.md) | Staging-only indexing protection |
| [`docs/operations/DEV_GIT_RULES.md`](../../operations/DEV_GIT_RULES.md) | Canonical repository and Git workflow rules |
| [`docs/TESTING_GUIDE.md`](../../TESTING_GUIDE.md) | Event state machine / capacity testing guide |

Where a file in this archive duplicates a path under `docs/`, the **`docs/` copy is canonical** for ongoing reference. Root copies were kept here as historical snapshots when content matched.

## How to use this archive

These notes are **valuable historical implementation records**. They capture:

- Investigation findings (checkout, auth, vendor settings, event forms)
- Step-by-step fix logs and completion markers
- Audit snapshots and wireframe references from MEL v2 build-out
- Diagnostic instructions tied to specific debugging sessions

They are not deleted because they document *why* decisions were made and *how* problems were diagnosed. When researching legacy behaviour, search here and follow `git log --follow` rather than assuming current code still matches every note.

Do not move files back to the repository root without an explicit documentation governance decision.
