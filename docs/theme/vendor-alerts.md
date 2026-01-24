# Vendor Alert Strip — Priority and Conditions

The Vendor Alert Strip shows **one** alert at a time. Evaluation follows a **locked priority order**. The first condition that matches wins.

---

## Priority order (locked)

| # | Type    | Alert                       | Condition |
|---|---------|-----------------------------|-----------|
| 1 | error   | Stripe payouts disabled     | `field_stripe_payouts_enabled` is false on the vendor’s store |
| 2 | warning | Stripe account missing      | No `field_stripe_account_id` on the store (or not connected) |
| 3 | success | Recent payout               | Stripe-backed: paid payout in the last 3 days (via `myeventlane_stripe.vendor_stripe`) |
| 4 | warning | Boost expiring              | Vendor has an active Boost and `field_promo_expires` is within the next 7 days |
| 5 | info    | Event sold out              | At least one published event is at capacity (RSVP or tickets; `myeventlane_capacity.service`) |
| 6 | info    | New order received          | Session-only: a completed order for the vendor’s events exists with id &gt; last seen in session |
| 7 | info    | Payouts (fallback)          | None of the above; generic “View payout schedule” message |

---

## Conditions (summary)

### 1. Stripe payouts disabled
- **Source:** `commerce_store.field_stripe_payouts_enabled`
- **CTA:** View Payouts → `/vendor/payouts`

### 2. Stripe account missing
- **Source:** `commerce_store.field_stripe_account_id` empty or missing
- **CTA:** Connect Stripe → `/vendor/payouts`

### 3. Recent payout (Stripe-backed)
- **Source:** `myeventlane_stripe.vendor_stripe->hasRecentPayout($store, 3)`
- **CTA:** none

### 4. Boost expiring
- **Source:** `node` (event), `field_promoted = 1`, `field_promo_expires` between now and now+7 days. **Requires `myeventlane_boost`.** Skipped if the module is not enabled.
- **CTA:** Manage promotion → `/vendor/boost`

### 5. Event sold out
- **Source:** `myeventlane_capacity.service->isSoldOut($event)` for any of the vendor’s published events (owner `uid`). **Requires `myeventlane_capacity`.** Skips if the service is missing. Does not name the event; fast query, limited to 20 events.
- **CTA:** View events → `/vendor/events`

### 6. New order received (session-only)
- **Source:** Max completed `commerce_order.id` for orders that contain `commerce_order_item` with `field_target_event` in the vendor’s event set. Compared to `$_SESSION['mel_vendor_alert_last_seen_order_id']`. On first load the session is initialised to the current max (no alert). When `max > last_seen`, the alert is shown and `last_seen` is set to `max`.
- **CTA:** View orders → `/vendor/orders`
- **Note:** `/vendor/orders` may not exist; the site may need to add a vendor orders list route.

### 7. Payouts fallback
- **Source:** Always applies when 1–6 do not.
- **CTA:** View Payouts → `/vendor/payouts`

---

## Known limitations

- **Session-only for “New order”:** No database or config persistence. Resets when the browser session ends. “Last seen” is stored only in `mel_vendor_alert_last_seen_order_id` in the PHP session.
- **No “last seen” or “dismiss forever”:** Dismiss is handled in JS (Stage D1) via `sessionStorage` per alert type; it is not persisted on the server.
- **`/vendor/orders`:** CTA for “New order” points to `/vendor/orders`. This route may be absent; implementations can add it or change the CTA to an existing vendor orders list (e.g. per-event orders).
- **Boost and Capacity modules:** “Boost expiring” is skipped when `myeventlane_boost` is not enabled. “Event sold out” is skipped when `myeventlane_capacity.service` is not available.
- **Stripe success:** Depends on `myeventlane_stripe.vendor_stripe`; if the module or service is missing, the success alert is skipped.

---

## Code references

- **Preprocess:** `myeventlane_vendor_theme_preprocess_page()` → `_myeventlane_vendor_theme_get_vendor_alert()`
- **Helpers:** `_myeventlane_vendor_theme_alert_event_sold_out()`, `_myeventlane_vendor_theme_alert_boost_expiring()`, `_myeventlane_vendor_theme_alert_new_order()`, `_myeventlane_vendor_theme_get_max_completed_order_id_for_user()`
- **Template:** `templates/includes/vendor-alert-strip.html.twig`
- **Dismiss (JS):** `src/js/vendor-alert.js` (sessionStorage, per `data-alert-type`)
