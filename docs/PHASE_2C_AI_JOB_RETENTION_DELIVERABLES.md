# Phase 2C — AI Job Retention + Cleanup — Deliverables

## Drush commands (safe order)

```bash
# 1. Clear cache (required after new services/module hooks)
drush cr

# 2. Run update hook for existing installs (adds retention config + index)
drush updb -y
# Runs: myeventlane_ai_update_9004()

# 3. If you use config import
drush cim -y
```

## Manual test checklist

- [ ] **Create job and complete** — Enqueue and complete an AI job; verify `expires_at` is set to `created + retention_days * 86400`
- [ ] **Retention 0 = no deletion** — Set `ai_job_retention_days` and `ai_job_error_retention_days` to 0; complete jobs; run cron; verify jobs are NOT deleted
- [ ] **Retention 1 day, simulate expiry** — Set `ai_job_retention_days` to 1, create and complete a job, manually set `expires_at` to past (e.g. `update ai_job set expires_at = 1 where id = X`), run `drush cron`, verify job is deleted
- [ ] **Queued jobs never deleted** — Create queued job (do not process); run cron; verify queued job remains
- [ ] **Running jobs never deleted** — (Harder to test: job would need to be stuck in running) — Query only selects `status IN (done, error)`, so queued/running are excluded by design
- [ ] **Cron when no jobs exist** — Run `drush cron` on a site with zero ai_job rows; must not error

## Summary of changes

| Item | Location |
|------|----------|
| Config keys | `ai_job_retention_days` (7), `ai_job_error_retention_days` (14) |
| Schema | `config/schema/myeventlane_ai.schema.yml` |
| Install config | `config/install/myeventlane_ai.settings.yml` |
| Update hook | `myeventlane_ai_update_9004()` |
| Admin form | AI Usage & Guardrails — "AI job retention (cleanup)" fieldset |
| Worker | `AiJobQueueWorker::setExpiresAt()` on done/error |
| Cron | `myeventlane_ai_cron()` → `AiJobCleanupService::run()` |
| Index | `ai_job_expires_at` on `ai_job.expires_at` |
