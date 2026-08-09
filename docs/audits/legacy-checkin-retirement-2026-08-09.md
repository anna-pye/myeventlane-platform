# Legacy check-in retirement audit

**Date:** 2026-08-09

**Branch:** `audit/retire-legacy-checkin`

**Scope:** Retire `myeventlane_checkin` in favour of canonical Door Mode.

**Excluded:** MEL Hold, ticket entity migration, RSVP-specific check-in, staging deployment.

## Decision

`myeventlane_checkin` is a superseded combined check-in implementation. The
canonical organiser surface is
`myeventlane_event_attendees.vendor_operations_door`, with mutation handled by
the current attendee and ticket services.

The retired module owns no database schema or content entities. On staging,
only the vendor role had its permissions; all 24 active vendor accounts also
had vendor-console access. No check-in-only staging role was found.

`mel_ticket` is not part of this retirement. It owns active ticket-type
entities and remains a required architectural boundary.

## Implemented boundary

- Removed the retired module from active configuration.
- Removed obsolete role dependencies and permissions.
- Added an update hook that cleans active role state before config import.
- Preserved the old page, list and scan route names as access-controlled 301
  redirects to Door Mode.
- Removed the old toggle and search mutation routes.
- Removed legacy controllers, storage services, templates and assets.
- Retained only a disabled compatibility shim directory for one release.
- Updated remaining executable fallbacks to use canonical routes.

## Security effect

The change removes parallel state-changing check-in endpoints and their
separate permission vocabulary. Compatibility redirects use the existing
vendor-console access service, which retains event ownership and workspace
checks.

## Validation

- PHP syntax: pass.
- Drupal and DrupalPractice coding standards for new PHP: pass.
- YAML syntax for changed configuration, routing and services: pass.
- Event attendee unit suite: 39 tests, 112 assertions; pass with existing PHP
  and PHPUnit deprecations.
- Event Studio compatibility contract: 8 tests, 68 assertions; pass with one
  PHPUnit deprecation.
- Checkout flow unit suite: 42 tests, 145 assertions; pass with one existing
  warning and existing PHPUnit deprecations.
- Vendor unit suite: 104 of 105 tests passed. The remaining test could not run
  because this clean worktree does not contain Composer-scaffolded `web/core`;
  it looked for Drupal core's optional Files View. This is unrelated to the
  changed controller dependency list and must be rerun in the build artifact.

## Deployment gate

Do not deploy without all of the following:

1. A fresh staging database snapshot.
2. `drush updatedb` before `drush config:import`.
3. Confirmation that `myeventlane_checkin` is disabled.
4. Vendor acceptance of Door Mode search, manual check-in and ticket scan.
5. RSVP-specific check-in verification.
6. Legacy page, list and scan URLs redirect for an authorised vendor and deny
   an unauthorised account.
7. MEL Hold release target and public HTTP response remain unchanged.

Physical deletion of the compatibility directory remains a separate release
gate.
