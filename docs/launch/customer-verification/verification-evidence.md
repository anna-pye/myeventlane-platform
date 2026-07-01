# Verification Evidence Log

Raw runtime + code evidence captured during the live verification audit (2026-06-26).
Environment: DDEV `myeventlane`, Drupal 11 bootstrap **Successful**.

---

## Environment

```
ddev describe → web (drupal11 PHP 8.3) OK at https://myeventlane.ddev.site; db mariadb:10.11 OK; Mailpit active.
ddev drush status → Drupal bootstrap : Successful
```

## CB-01 — paid-state

Code:
```
myeventlane_theme.theme:1218
  $variables['mel_confirm_paid'] = $total_price instanceof Price && !$total_price->isZero();
# template usage: only `{% set mel_confirm_paid = mel_confirm_paid|default(false) %}` (1 occurrence) — never gates copy.

MelCustomerContinuityPresenter::buildCheckoutCompletionPresentation(email, order_number, customer_id,
  order_entity_id, has_tickets, donation_total, calendar_links, event_url)   # NO payment/order-state arg
MelReadinessHelper::customerCheckoutCompletionHeadline() → 'Booking confirmed'
  lead → 'Your tickets and receipt are on their way to your inbox.'

myeventlane_tickets/src/EventSubscriber/OrderPaidSubscriber.php:28
  OrderEvents::ORDER_PAID => 'onOrderPaid'      # ticket issuance gated on PAID, not placement
```

Runtime — recent orders (state / placed / payment):
```
#555 state=completed placed=Y pay=[completed/50.75]
#554 state=draft     placed=N pay=[]
#553 state=completed placed=Y pay=[completed/49.00]
#552 state=completed placed=Y pay=[completed/49.00]
#551 state=completed placed=Y pay=[pending/49.00]     ← completed order, pending payment
#550 state=completed placed=Y pay=[pending/50.75]     ← completed order, pending payment
#549 state=completed placed=Y pay=[completed/40.00]
#548 state=completed placed=Y pay=[completed/10.75]
```

Gateways:
```
mel_stripe_cc  | plugin=manual | status=on | label "MEL - Manual"  (display "Manual")
stripe         | plugin=stripe | status=on | mode=test | "Stripe Card Element"
stripe_pe_recurring | plugin=stripe_payment_element | status=on | mode=test
```

Tickets for pending vs paid orders:
```
order #550 tickets=0   order #551 tickets=0   order #552 tickets=0   order #553 tickets=0
# pending orders 550/551 carry no tickets → fulfilment correctly gated on payment.
```

## CB-02 — refund guards

Route:
```
myeventlane_refunds.buyer_refund
  path: /my-tickets/order/{commerce_order}/refund
  _entity_access: commerce_order.view
  _custom_access: myeventlane_refunds.buyer_refund_access_check:access
  commerce_order: \d+
```

Code guards:
```
BuyerRefundAccessCheck: anonymous→forbidden; requires ?event=; → eligibility.isEligible()
BuyerRefundEligibilityService::buyerOwnsOrder(): customerId === account->id()
  isOrderRefundable(): state ∈ [completed, fulfilled, placed]
RefundProcessor::requestBuyerRefund(): re-checks eligibility; throws if hasActiveBuyerRequest()
RefundRequestStorage::hasActiveBuyerRequest(): status IN (requested, approved)
RefundOrderInspector::calculateSelectedAttendeeRefundCents():
  throws InvalidArgumentException if selected attendee ∉ order/event refundable breakdown  # anti-tamper
loadRefundableTicketAttendeesForOrderEvent(): excludes cancelled/refunded (status != cancelled)
remaining-refundable: totalPaid − totalRefunded (floored at 0) over commerce_payment rows
```

Runtime:
```
GET /my-tickets/order/552/refund?event=1 (anonymous) → HTTP 403
SHOW TABLES LIKE 'myeventlane_refund_request' → exists
SELECT status, COUNT(*) … → completed=25, rejected=8, approved=4, requested=3
```

## CB-03 — payout + webhook

Webhook (`StripeWebhookController`):
```
route /stripe/webhook/payout: _access TRUE, methods [POST]
handle(): Webhook::constructEvent($payload,$sig,$secret)
  empty secret → 500 (fail-closed)
  SignatureVerificationException → 400 "Invalid signature"
  UnexpectedValueException → 400 "Invalid payload"
transfer.paid: row.status=='paid' & same transfer_id → idempotent skip;
               different transfer_id → critical log, NO overwrite
transfer.failed: logs error, NO ledger change
comment: "Never modifies commerce_order or payment entities — only the payout ledger table."
```

Revenue display:
```
VendorPayoutsController::payouts():
  pending_payout => '$0.00'  // "Would come from Stripe API"
  total_sales/net_earnings ← ticketSalesService->getManagedVendorRevenue()
TicketSalesService::buildVendorRevenueFromPublishedEventIds():
  counts $item->getTotalPrice() WHERE $order->getState()->getId() === 'completed'   # state, not isPaid(); no refund deduction
```

Runtime impact:
```
completed orders=139  fully_paid=127  NOT_fully_paid=12  unsettled_gross_in_revenue=$535.30
isPaid() available: yes
commerce_order states: completed=139, draft=22   (no 'refunded' / 'partially_refunded' state in use)
refund lifecycle: 25 completed refunds NOT netted from the revenue figure
```

## CB-04..13 — P1

```
CB-04 CreateEventGatewayController:205 → RedirectResponse to myeventlane_event_studio.edit (canonical funnel)
CB-05 view:mel_saved_events EXISTS; route view.mel_saved_events.page_1 /my-saved-events; EventSaveCountService present
CB-06 GET /calendar → HTTP=200 size=80988; fullcalendar×20, mel-calendar×2
CB-07 /user/login rendered → lang="en", "Skip to main", role=, <label>×2 ; theme 124/236 templates with a11y primitives
CB-08 mail keys: order_confirmation, order_invoice, refund_requested_buyer/vendor, refund_approved_buyer,
      rsvp_confirmation, event_reminder, event_cancelled, cart_abandoned, boost_confirmation, tickets_cancelled…
      Mailpit total=19 incl. "Your order is confirmed – …", "Tax invoice – Order #…"
CB-09 pro routes _custom_access ProOverviewController::accessProVendor → AccessResult::allowedIf($hasVendor && $hasPro)
      services: ProEntitlementManager, ProSubscriptionStatusService
CB-10 WaitlistController → JsonResponse position; route waitlist_signup present
CB-11 _event-book.scss:977 position: sticky ; _event-full.scss mel-event-sticky-cta--sidebar
CB-12 workflow storage → 'editorial' (single content-moderation workflow)
CB-13 GET /home rendered → "Skip to main"
```

## Validation

```
ddev composer validate         → ./composer.json is valid
ddev drush config:status       → No differences between DB and sync directory
git status --short             → ?? docs/launch/   (no code changes)
```
