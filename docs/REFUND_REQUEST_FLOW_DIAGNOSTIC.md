# Buyer “Request Refund” Flow — Where It Lives & Why You Might Not See It

## How a customer requests a refund (step-by-step)

1. **Log in** as the customer who placed the order.
2. **Go to My Tickets**  
   URL: **`/my-tickets`**  
   (Route: `myeventlane_checkout_flow.my_tickets`.)
3. **Open an order**  
   On My Tickets, each order has a **“View Details”** button.  
   Click it → URL: **`/my-tickets/order/{order_id}`** (order detail page).
4. **Find “Request Refund”**  
   On the **order detail** page, scroll to the **“Your Events”** section.  
   Each event card has:
   - **Download Calendar (.ics)**
   - **View Event Page**
   - **Request Refund** ← only if the event is eligible (see below).
5. **Click “Request Refund”**  
   Goes to **`/my-tickets/order/{order_id}/refund?event={event_id}`**  
   (Route: `myeventlane_refunds.buyer_refund`).  
   A **confirm form** asks to confirm the refund for that event.
6. **Submit**  
   Creates a refund request; vendor must approve.  
   Customer gets “Refund request received” email; after vendor approves and Stripe runs, customer gets “Refund completed” email.

**Important:** “Request Refund” does **not** appear on the My Tickets list. It appears **only on the order detail page**, and **only per event** when that event is eligible.

---

## Flow (as implemented)

1. **My Tickets → order detail**  
   Route: `myeventlane_checkout_flow.order_detail`  
   Template: `myeventlane-order-detail.html.twig`  
   “Request Refund” is shown **per event** when `event.can_refund` and `event.refund_url` are set.

2. **Who sets `can_refund` / `refund_url`**  
   `myeventlane_refunds_preprocess_myeventlane_order_detail()` (in `myeventlane_refunds.module`) runs when the order-detail theme is rendered. It uses `myeventlane_refunds.buyer_eligibility` → `isEligible($order, $event, $currentUser)` and, if eligible, sets `refund_url` to the buyer refund form.

3. **“Request Refund” link**  
   Goes to: `myeventlane_refunds.buyer_refund`  
   Path: `/my-tickets/order/{order_id}/refund?event={event_id}`  
   Form: `BuyerRefundForm` (confirm form).

4. **Submit**  
   `RefundProcessor::requestBuyerRefund()` creates a row in `myeventlane_refund_request`, sends `refund_requested_buyer` and `refund_requested_vendor` emails. **No** queue at this step; vendor must approve.

5. **Vendor approves**  
   Vendor uses Refund Requests tab → Approve → `RefundProcessor::approveBuyerRefundRequest()` → `requestRefund()` → **queues** `vendor_refund_worker` with `log_id`.

6. **Refund processed**  
   `VendorRefundWorker` runs cron → `RefundProcessor::processRefund($logId)` → Stripe refund → then **customer** gets **`refund_completed_buyer`** email (subject: “Refund completed – {{ event_title }}”).  
   There is no template named `refund_processed`; the “refund processed” email is **`refund_completed_buyer`**.

---

## Why “Request Refund” might not appear

“Request Refund” is only rendered when **both** `event.can_refund` and `event.refund_url` are truthy. Eligibility is decided in `BuyerRefundEligibilityService::isEligible()`. If **any** of the following fail, the button is hidden:

| Check | What it means |
|-------|----------------|
| **Module** | `myeventlane_refunds` must be **enabled**. If it’s off, the preprocess never runs and `can_refund` / `refund_url` are never set. |
| **Order owner** | Logged-in user must be the order’s customer (`order.getCustomerId() === current user`). |
| **Order state** | Order state must be one of: `completed`, `fulfilled`, `placed`. |
| **Order has tickets for this event** | `RefundOrderInspector::extractItemsForEvent($order, $eventId)` must return at least one item (e.g. real ticket product, not only donation). |
| **Event refund policy** | Event must have `field_refund_policy` **set** and **not** `no_refunds` or `none_specified`. Allowed values: `1_day`, `7_days`, `14_days`, `30_days`. |
| **Refund window** | Current time must be **before** (event start date − N days). E.g. if policy is `7_days` and the event is in 5 days, the window is closed and the button is hidden. |

So the two most common reasons the button is missing:

1. **`field_refund_policy`** on the event is empty, or set to “No refunds” / “None specified”.  
2. **Refund window closed**: event is within N days of start for the chosen policy.

---

## Quick checks

1. **Is the refunds module on?**  
   `ddev drush pm:list --status=enabled --type=module | grep refunds`  
   You should see `myeventlane_refunds`.

2. **Does the event have a refund policy?**  
   Edit the event (or check in DB): `field_refund_policy` should be one of `1_day`, `7_days`, `14_days`, `30_days`.

3. **Is the event in the future and outside the window?**  
   For `7_days`, the event start must be more than 7 days away for “Request Refund” to show.

4. **Order state**  
   Order should be `completed`, `fulfilled`, or `placed` (not `draft` or `canceled`).

5. **Correct page**  
   My Tickets → click an **order** (e.g. “Order #1234”) to open the **order detail** page. “Request Refund” is per **event** in the “Your Events” section, not on the list of orders.

---

## Files involved

| Piece | File / location |
|-------|------------------|
| Order detail theme | `myeventlane_checkout_flow` → theme `myeventlane_order_detail` → template `myeventlane-order-detail.html.twig` |
| Add `can_refund` / `refund_url` | `myeventlane_refunds.module` → `myeventlane_refunds_preprocess_myeventlane_order_detail()` |
| Eligibility rules | `myeventlane_refunds/src/Service/BuyerRefundEligibilityService.php` |
| Buyer refund form | `myeventlane_refunds/src/Form/BuyerRefundForm.php` |
| Request submit | `RefundProcessor::requestBuyerRefund()` |
| Vendor approve → queue | `RefundProcessor::approveBuyerRefundRequest()` → `requestRefund()` → queue `vendor_refund_worker` |
| Process refund + email | `VendorRefundWorker` → `RefundProcessor::processRefund()` → queues `refund_completed_buyer` |
| “Refund processed” email | Messaging template **`refund_completed_buyer`** (in `myeventlane_refunds.install` and MessagingManager). |

If you want, we can add a small “Refund policy” or “Eligibility” line on the order detail page (e.g. “Refunds allowed until X” or “Refunds not available”) so buyers see why the button is or isn’t there.
