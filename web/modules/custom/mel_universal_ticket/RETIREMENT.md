# MEL Universal Ticket retirement

`mel_universal_ticket` is a compatibility shim. Universal entitlement fields,
services, and runtime behaviour are owned by `myeventlane_tickets`.

The first retirement release deliberately keeps this directory present while
removing the module from exported enabled configuration. Deployment must run
database updates before configuration import so
`myeventlane_tickets_update_8011()` can transfer any last-installed field
providers before Drupal uninstalls this module.

The compatibility service ID `mel_universal_ticket.capability_manager` remains
an alias of `mel_ticket_capability.manager`. New consumers must use the latter.

Do not delete this directory until every deployed environment confirms:

1. `mel_universal_ticket` is disabled.
2. No pending database updates remain.
3. The ticket and redemption-log fields are still installed and identify
   `myeventlane_tickets` as their runtime and last-installed provider.
4. No code or configuration outside this directory references the old module
   or service ID.
