# Mail attachment validation (local)

Repeatable checks for transactional email attachments. Run on DDEV after confirming [local-mail.md](./local-mail.md) (`delivery_provider` = `drupal_mail`, Mailpit reachable).

Do not modify messaging code unless a step below fails; trace producer → resolver → provider → transport.

## Preconditions

```bash
ddev drush cr
ddev drush php:eval "echo \Drupal::service('myeventlane_messaging.delivery_provider_manager')->getProvider()->id();"
# expect: drupal_mail
```

Open Mailpit: `https://myeventlane.ddev.site:8025`

## 1. Order confirmation (PDF + ICS)

**Producer:** `OrderConfirmationQueueBuilder` → `MessagingManager::queue()`  
**Resolver:** `OrderConfirmationAttachmentResolver` at send time (ticket PDFs)  
**Provider:** `DrupalMailProvider` → `mime_mail`

Use a completed order with ticket-backed line items and checkout holder data:

```bash
# Replace ORDER_ID and email as needed
ddev drush php:eval "
\$order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load(ORDER_ID);
\Drupal::service('myeventlane_messaging.order_confirmation_queue_builder')->queue(\$order, 'you@example.test');
"
ddev drush mel:msg-run
```

**Mailpit expectations:**

| Attachment | MIME | Notes |
| --- | --- | --- |
| `event-*.ics` | `text/calendar` | One per event on the order |
| `*.pdf` | `application/pdf` | One per **assigned** `myeventlane_ticket` row |

If PDFs missing: check ticket status (`issued_unassigned` vs `assigned`) and holder fields:

```bash
ddev drush sqlq "SELECT id,status,holder_email FROM myeventlane_ticket WHERE order_id=ORDER_ID"
```

## 2. RSVP confirmation

**Producer:** RSVP / messaging queue (template key from your event RSVP flow)

Trigger via UI or module-specific drush if available. After queue processing:

**Mailpit expectations:**

| Attachment | MIME |
| --- | --- |
| `event-*.ics` | `text/calendar` |

## 3. Event reminder (ICS)

```bash
ddev drush mel:reminder-test 24h you@example.test ORDER_ID
ddev drush mel:msg-run
```

**Mailpit expectations:**

| Attachment | MIME |
| --- | --- |
| `event-*.ics` | `text/calendar` |

## 4. Ticket-ready email (assigned ticket PDF)

Requires `myeventlane_ticket` with `status=assigned` and holder name/email. After assignment:

```bash
# Load ticket TICKET_ID, then:
ddev drush php:eval "
\$ticket = \Drupal::entityTypeManager()->getStorage('myeventlane_ticket')->load(TICKET_ID);
\Drupal::service('myeventlane_tickets.ticket_mailer')->sendAssignedTicket(\$ticket);
"
```

**Mailpit expectations:**

| Attachment | MIME |
| --- | --- |
| `*.pdf` | `application/pdf` |

## 5. Postmark attachment shapes (staging only)

On staging with Postmark enabled, the same attachment counts and MIME types apply. `PostmarkDeliveryProvider` accepts:

1. MEL shape: `filename`, `content` (raw bytes), `mime`
2. Postmark shape: `Name`, `Content` (base64), `ContentType`
3. File path shape: `path`, `name`, `content_type`

## Failure tracing

| Symptom | Check |
| --- | --- |
| No email in Mailpit | `ddev drush queue:list`; run `mel:msg-run`; `ddev drush ws --count=20` |
| Email without attachments | Watchdog: `Order confirmation: ticket PDF not attached` |
| PDF skipped | Ticket `holder_name` / `holder_email` empty or `issued_unassigned` |
| ICS missing | `myeventlane_rsvp.ics_generator` service; event start field |
| Wrong provider | `delivery_provider` override in DDEV `settings.mel_shared_session.php` |

## Log tail

```bash
ddev drush ws --count=50
ddev drush queue:list
```

## Record results

When validating a release, note in your test log:

- Template key
- Recipient
- Attachment count in Mailpit
- Each attachment filename and Content-Type
- Any watchdog warnings from `myeventlane_messaging` or `myeventlane_tickets`
