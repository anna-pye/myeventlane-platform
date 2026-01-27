# Entity/Field Definition Updates - Instructions

## Status

The following entity/field definition updates need to be applied:

1. ✅ **Escalation entity type** - INSTALLED
2. ⚠️ **RSVP Submission Status field** - Needs manual update via UI
3. ⚠️ **RSVP Submission Event field** - Needs manual update via UI  
4. ⚠️ **Vendor entity type** - Needs manual update via UI

## How to Apply Updates

These base field updates require batch processing through the Drupal UI:

1. **Navigate to:** `/admin/reports/status/entity-updates`
2. **Review the changes** listed:
   - RSVP Submission: Status field update
   - RSVP Submission: Event field update
   - Vendor: Entity type update
3. **Click "Apply"** for each update
4. **Wait for batch processing** to complete

## Why Manual Update?

Base field definition updates for existing fields require:
- Batch processing (to handle data migration if needed)
- Proper field storage definition retrieval
- Entity type cache clearing

The EntityDefinitionUpdateManager's `updateFieldStorageDefinition()` method requires both the new and original field storage definitions, but for base fields stored in entity tables, the original definition retrieval can fail in CLI context.

## Alternative: CLI Method (if UI unavailable)

If you need to apply via CLI, you can use:

```bash
ddev drush entity-updates
```

However, this may still require batch processing for base field updates.

## Verification

After applying updates, verify with:

```bash
ddev drush php:eval "\$manager = \Drupal::entityDefinitionUpdateManager(); \$changes = \$manager->getChangeList(); if (empty(\$changes)) { echo 'All updates complete!'; } else { print_r(\$changes); }"
```

---

**Note:** The Escalation entity type has been successfully installed. The remaining updates are for field definition changes (not new fields), which require the batch process.
