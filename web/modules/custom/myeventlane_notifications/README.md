# MyEventLane Notifications — developer notes

## Deep links (route hygiene)

All **new** notifications must set:

- `route_name` — Drupal route name (e.g. `myeventlane_checkout_flow.order_detail`)
- `route_parameters` — JSON-serializable array (e.g. `['commerce_order' => 123]`)
- `action_label` — human-readable CTA label

Do **not** set `action_uri` on new notifications. Legacy notifications may still use `action_uri`; `NotificationViewBuilder::buildActionFromNotification()` prefers `route_name` and falls back to `action_uri` when the route is missing or invalid.

## Classification

Use `NotificationTaxonomy` for context/domain mapping:

- In-app: set `context`, `domain`, and optional `action_context` on create; or rely on taxonomy defaults from `type` + `action_context`.
- Email: `MessagingManager::queue()` stores `_mel_notification_*` keys from `NotificationTaxonomy::emailTemplateClassification()`.

## Priority

Allowed values: `low`, `normal`, `high`, `critical`.

- `critical` — security/platform alerts; never suppressed by user preferences; surfaces like `high`.
- `high` — refunds, capacity, boost expiring, etc.
- `normal` / `low` — routine business and personal updates.

## Trigger services

| Service ID | Role |
|---|---|
| `myeventlane_notifications.trigger` | Order paid, RSVP, event published |
| `myeventlane_notifications.business_trigger` | Capacity, stock, boost, followers, waitlist, event approved |
| `myeventlane_notifications.personal_trigger` | Event updates, ticket assigned |
| `myeventlane_notifications.reminder_trigger` | In-app 24h / 1h event reminders |
| `myeventlane_notifications.refund_trigger` | Refund lifecycle (vendor + buyer) |

Optional cross-module wiring uses `@?myeventlane_notifications.business_trigger` so modules stay bootable when notifications is disabled.
