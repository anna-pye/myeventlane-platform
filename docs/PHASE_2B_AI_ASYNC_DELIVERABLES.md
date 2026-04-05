# Phase 2B — AI Async & Vendor Opt-in

## Summary

- Async job execution via Queue API (no provider calls during request)
- Vendor opt-in toggle (ai_enabled)
- AI Job content entity (no prompt storage)
- Polling endpoint for job status
- Vendor dashboard token usage panel

---

## 1. Full File List

### New Files
- `web/modules/custom/myeventlane_ai/src/Entity/AiJob.php`
- `web/modules/custom/myeventlane_ai/src/Entity/AiJobAccessControlHandler.php`
- `web/modules/custom/myeventlane_ai/src/Controller/AiJobPollController.php`
- `web/modules/custom/myeventlane_ai/src/Service/AiJobEnqueueService.php`
- `web/modules/custom/myeventlane_ai/src/Plugin/QueueWorker/AiJobQueueWorker.php`
- `web/modules/custom/myeventlane_ai/js/ai-poll.js`
- `web/modules/custom/myeventlane_ai/myeventlane_ai.libraries.yml`

### Modified Files
- `web/modules/custom/myeventlane_vendor/src/Entity/Vendor.php` — added ai_enabled base field
- `web/modules/custom/myeventlane_vendor/src/Form/VendorForm.php` — preferences fieldset for ai_enabled
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.install` — update 10015 for ai_enabled
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.info.yml` — optional dep myeventlane_ai
- `web/modules/custom/myeventlane_ai/src/Service/AiManager.php` — vendor opt-in check, EntityTypeManager
- `web/modules/custom/myeventlane_ai/myeventlane_ai.services.yml` — job_enqueue, entity_type.manager, usage_store fix
- `web/modules/custom/myeventlane_ai/myeventlane_ai.permissions.yml` — view own ai jobs, view vendor ai jobs, administer ai jobs
- `web/modules/custom/myeventlane_ai/myeventlane_ai.routing.yml` — myeventlane_ai.job_poll
- `web/modules/custom/myeventlane_ai/myeventlane_ai.install` — update 9003 for ai_job entity
- `web/modules/custom/myeventlane_vendor_ai/src/Form/VendorAiAssistantForm.php` — async via job + poll
- `web/modules/custom/myeventlane_vendor_ai/myeventlane_vendor_ai.libraries.yml` — removed ai_poll (uses myeventlane_ai)
- `web/modules/custom/myeventlane_help_centre_ai/src/Form/HelpCentreAiForm.php` — async via job + poll
- `web/modules/custom/myeventlane_escalations_ai_draft/src/Controller/EscalationAiDraftController.php` — async via job
- `web/modules/custom/myeventlane_escalations_ai_draft/js/escalation-ai-draft.js` — poll for job result
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` — ai_usage_panel
- `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` — ai_usage_panel variable
- `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` — AI usage panel block

### Deleted Files
- `web/modules/custom/myeventlane_vendor_ai/js/ai-poll.js` — moved to myeventlane_ai

---

## 2. Drush Commands

```bash
drush cr
drush updb -y
```

---

## 3. Manual Test Checklist

- [ ] **Vendor AI opt-in off** → Enable vendor, set ai_enabled = FALSE, open Vendor AI on escalation → Cannot run AI (error: "AI is disabled for this vendor.")
- [ ] **Vendor AI opt-in on** → Set ai_enabled = TRUE, submit question → Job created, "Generating…" appears, result returns via poll.
- [ ] **Poll access** → Owner can GET /ai/job/{id}; other user denied.
- [ ] **Vendor token usage** → After job completes, vendor dashboard shows tokens used today and limit.
- [ ] **Help Centre AI** → Logged-in user with permission submits question → Job created, polling returns result.
- [ ] **Draft generation** → Staff clicks "Draft response" → Returns job_id, frontend polls, inserts draft when done.

---

## 4. Queue

Queue name: `myeventlane_ai.jobs`

Process via cron or:
```bash
drush queue:run myeventlane_ai.jobs
```

---

## 5. Permissions

- `view own ai jobs` — View own AI jobs
- `view vendor ai jobs` — View jobs for vendor user belongs to
- `administer ai jobs` — View all AI jobs (admin/staff)

---

## 6. Notes

- No prompt stored in ai_job; only prompt_hash.
- Prompt exists only in queue item payload (in-memory).
- Phase 2A guardrails (quotas, circuit breaker) unchanged and still enforced in worker.
