# MyEventLane Seed

Development-only deterministic seed tooling for MyEventLane. Keep this module
disabled in staging and production.

## Safety boundary

This module must never provide a command that deletes every event. The former
`mel:reset-events` and `mel:seed-demo` commands were retired because they
deleted all Event nodes without a confirmation or environment guard.

Use only the seed-key-scoped commands below. Take a database backup before any
non-dry-run purge.

## Commands

Preview deterministic seed events without writing content:

```bash
ddev drush mel:seed-events --use-settings --dry-run
```

Generate seed-key-marked events using the saved development settings:

```bash
ddev drush mel:seed-events --use-settings
```

Preview removal of seed-key-marked events and their seed-owned dependencies:

```bash
ddev drush mel:purge-events --dry-run
```

Remove seed-key-marked events after reviewing the dry-run and taking a backup:

```bash
ddev drush mel:purge-events
```

`mel:purge-demo` remains available only to clean up legacy `vendor2` and
`vendor3` datasets created by older development environments. It must not be
used against staging or production.

## Administrative form

The development settings form is available at
`/admin/config/myeventlane/demo-content` when the module is enabled. Access
requires the restricted `administer myeventlane demo content` permission.
Generation and purge actions require the configured confirmation text. Purge
from the UI is disabled by default.

## Configuration defaults

- Generated content is marked with the `[MEL TEST]` title prefix and a seed key.
- Dry-run is available for command-line validation.
- Purge from the UI defaults to disabled.
- Confirmation text defaults to required.
- The module is excluded from synchronised production extensions.

## Main services

- `DemoEventSeeder` creates deterministic seed-key-marked events.
- `DemoEventPurger` removes only matching seed-owned content.
- `DemoPurger` exists for legacy development-dataset cleanup.
- `ImageFactory` creates development placeholder images.

No service or command may perform an unscoped deletion of all Event nodes.
