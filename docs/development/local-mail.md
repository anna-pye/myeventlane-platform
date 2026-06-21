# Local mail (DDEV)

MyEventLane messaging on local development must use Drupal core mail (`drupal_mail`) and Mailpit. Production uses Postmark via `config/sync/myeventlane_messaging.settings.yml` (`delivery_provider: postmark`).

## Architecture

| Layer | Local (DDEV) | Production |
| --- | --- | --- |
| Messaging delivery | `DrupalMailProvider` (`delivery_provider: drupal_mail`) | `PostmarkDeliveryProvider` |
| Transport | `mime_mail` → PHP `sendmail` → Mailpit (`localhost:1025`) | Postmark API |
| Secrets | None required | `MEL_POSTMARK_SERVER_TOKEN`, `MEL_POSTMARK_WEBHOOK_SECRET` |

Flow for queued messages:

```text
MessagingManager::sendNow()
  → DeliveryProviderManager
  → DrupalMailProvider (local)
  → plugin.manager.mail
  → mime_mail
  → sendmail / Mailpit
```

Attachments from `MessagingManager::queue()` pass through `DrupalMailProvider` unchanged (same shapes as Postmark: `filename` + `content` + `mime`).

## Committed local overrides

**Delivery provider (DDEV only)** — `web/sites/default/settings.mel_shared_session.php`:

```php
if (getenv('IS_DDEV_PROJECT') === 'true') {
  $config['myeventlane_messaging.settings']['delivery_provider'] = 'drupal_mail';
}
```

This override is intentional: `delivery_provider` must not be `drupal_mail` in `config/sync` (production ships `postmark`).

**Mailpit transport** — DDEV-generated `web/sites/default/settings.ddev.php` points Symfony Mailer transports at `localhost:1025`. Active stack uses `config/sync/system.mail.yml` (`mime_mail` + `sendmail`), which DDEV routes to Mailpit by default.

**Domain URLs** — `web/sites/default/settings.local.php` (optional, per-developer) overrides `myeventlane_core.domain_settings` for `*.ddev.site`. Not required for mail capture.

## Postmark on local (optional)

`settings.mel_shared_session.php` merges Postmark tokens from the environment when set. With `delivery_provider` forced to `drupal_mail`, tokens are unused for outbound mail. Do not set `MEL_POSTMARK_SERVER_TOKEN` locally unless you are explicitly testing Postmark (not recommended for day-to-day dev).

## Mailpit UI

After sending mail:

```text
https://myeventlane.ddev.site:8025
```

Or from the project root:

```bash
ddev mailpit
```

## Process the messaging queue

```bash
ddev drush mel:msg-run
```

Scan schedulers (reminders, cart, boost) without sending:

```bash
ddev drush mel:msg-scan
ddev drush mel:msg-run
```

## Quick smoke test

```bash
ddev drush myeventlane:messaging:test order_confirmation you@example.test
ddev drush mel:msg-run
```

Open Mailpit and confirm one message to `you@example.test`.

## Verify active provider

```bash
ddev drush php:eval "echo \Drupal::service('myeventlane_messaging.delivery_provider_manager')->getProvider()->id();"
```

Expected on DDEV: `drupal_mail`.

## Related docs

- Attachment validation checklist: [mail-attachment-validation.md](./mail-attachment-validation.md)
- Order confirmation troubleshooting: [../TROUBLESHOOTING_RECEIPT_EMAILS.md](../TROUBLESHOOTING_RECEIPT_EMAILS.md)

## Residual risk

- `settings.local.php` is loaded only under DDEV (`settings.php`). Do not commit secrets; keep API keys in env or gitignored `config.local.yaml`.
- Category B mail (Drupal auth, some module `mail()` calls) also flows through Mailpit but is outside `myeventlane_messaging` delivery providers.
