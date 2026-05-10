# Redemption Model

Phase 2A uses `mel_redemption_log` as an append-only operational audit table for ticket-backed entitlement activity.

`mel_redemption_log` is not an entitlement entity, ownership model, fulfilment entity, checkout artifact, or customer wallet object. The canonical entitlement remains `myeventlane_ticket`; redemption rows only record operational history for a ticket at a point in time.

Required audit fields are stored on the log entity:

- `ticket_id`
- `entitlement_type`
- `event_id`
- `vendor_id`
- `staff_uid`
- `action_type`
- `device_identifier`
- `ip_address`
- `created`
- `notes`
- `metadata_json`

Historical rows are immutable through entity access: update and delete operations are forbidden. Scanner and fulfilment code should append new rows instead of mutating old rows.

Customer access is denied by default. Admins can read logs. Staff and vendor visibility must remain scoped to the event or vendor relationship captured on the log.
