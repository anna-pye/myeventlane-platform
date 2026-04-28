# Event Studio final publish fix (TASK 5F)

## Root cause (one sentence)

**Event 1567 did not publish because the event uses Content Moderation (`moderation_state` was `draft`), and Content Moderation’s presave handler syncs the node’s published flag to the moderation state—so `EventStudioSaveService` calling `setPublished(TRUE)` was immediately undone while `moderation_state` stayed `draft`.**

Reference: `ModerationHandler::onPresave()` aligns `EntityPublishedInterface::isPublished()` with the current moderation state’s `isPublishedState()` flag.

## Preflight (Phase 1)

- **Branch:** `cursor/onboard-storage-fix-128b4`
- **`git status --short`:** modified `EventStudioMelPayloadService.php`; untracked `mel-event-studio-publish-blocker-fix.md`; plus this audit doc after creation.
- **`composer validate`:** OK
- **`ddev drush cr`:** OK

## Event state before fix (Phase 2)

Commands:

```bash
ddev drush php-eval '$n=\Drupal::entityTypeManager()->getStorage("node")->load(1567); echo "published=" . ($n && $n->isPublished() ? "yes" : "no") . PHP_EOL; ...'
```

Observed for node **1567**:

- `published=no` (node `status` field `0`)
- `field_event_type`: `paid`
- `field_ticket_types`: refs `88`, `89`
- `field_product_target`: ref `90`
- **`moderation_state`:** `draft`

## Answers — trace publish pipeline (Phase 3)

| # | Answer |
|---|--------|
| 1 | **Full editor** (`/vendor/events/{nid}/edit`): primary submit is **`Save`** (`EventStudioForm`). **Wizard publish route** (`/vendor/events/{nid}/edit/publish`): **`Save and view event`** → `EventStudioBaseForm::submitContinue`. |
| 2 | Yes — `mel-event-studio.html.twig` renders `{{ element.mel.status }}` in the Publish step. |
| 3 | **`mel[status]`** (checkbox). |
| 4 | Yes — `EventStudioMelPayloadService::buildFromFormState()` sets `'status' => !empty($mel['status'])`. |
| 5 | When the checkbox is checked, **`status` is `true`** in the payload. |
| 6 | **`EventStudioForm::submitForm()`** passes **`$draft = FALSE`** to `save()`. **`EventStudioPublishForm`** uses **`isDraftWizardSave(): FALSE`**. |
| 7 | **`save()`** called **`setPublished()`** from payload `status`; without the moderation fix this did not survive entity save. |
| 8 | **Yes — Content Moderation** forced unpublished while **`moderation_state`** remained **`draft`**. Not ticket payload / JS draft routing for this case. |
| 9 | No — main submit is not replaced by a draft action; ticket-builder submits are explicitly excluded from highlight validation. |
| 10 | Footer/readiness JS adjusts styling; it does not cancel the main form submit (highlights validation can). |

## Fix (Phase 6 — narrow)

**Allowed category:** D — preserve intended publish state against later logic (moderation presave).

### Files changed

1. **`web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php`**
   - After the node is created or loaded, when **`!$draft && $willPublish`** and **`moderation_state` is `draft`**, set **`moderation_state` to `published`** (Editorial workflow `publish` transition target state).
   - Keeps existing Stripe gate and ticket validation unchanged.

2. **Existing local work (unchanged by this task):** `EventStudioMelPayloadService.php` — ticket/product payload merge from DB for saved nodes (separate product-autocomplete issue).

### Status payload before / after

| Stage | Before fix | After fix |
|-------|------------|-----------|
| Form → payload `status` | `true` when Published checked | Same |
| Before entity save | `setPublished(true)` then presave resets visibility | `moderation_state`: `draft` → `published`; published flag stays aligned at save |

## Verification commands (Phase 7)

```bash
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php
```

**Browser (required to confirm 1567):**

1. Open `/vendor/events/1567/edit`, Publish step.
2. Check **Published**, **Save**.
3. Re-run php-eval; expect **`published=yes`**, **`moderation_state`** **`published`** (or equivalent published state label in UI).

```bash
ddev drush php-eval '$n=\Drupal::entityTypeManager()->getStorage("node")->load(1567); echo "published=" . ($n && $n->isPublished() ? "yes" : "no") . PHP_EOL; echo "moderation=" . ($n->get("moderation_state")->isEmpty() ? "empty" : $n->get("moderation_state")->value) . PHP_EOL;'
```

## Event 1567 final state

- **After code deploy, cache rebuild, and successful publish save in browser:** expect **`published=yes`**; confirm with Drush eval above.
- **At documentation time (before your browser retest):** node remained **`published=no`**, **`moderation=draft`** — fix not exercised via automated publish in this session.

## Diagnostics (Phase 4 / 8)

- No temporary log lines were added; root cause was identifiable from **`moderation_state`** + Content Moderation behaviour.
- Remove any local debug if you had added it separately.

## Follow-ups

- **In review:** Events need **`publish_from_review`** if vendors should go live from **`review`** without admin; vendor role currently has **`publish`** (draft→published) per `config/sync/user.role.vendor.yml`.
- **Bulk list publish** (`setNodePublishedState`): same moderation issue may apply; treat as a separate change if lists fail for moderated events.

## Ready to commit

- **Code:** Yes, after you complete browser + Drush verification on **1567** and confirm paid/RSVP/external regression smoke tests you care about.
- **Docs:** This file + your existing `mel-event-studio-publish-blocker-fix.md` as needed.
