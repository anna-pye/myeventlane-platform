# Phase 12: Refunds, Cancellations & Customer Recovery

## Overview

This module implements Humanitix-level refund and cancellation functionality for MyEventLane vendors. Vendors can view orders per event, issue refunds (full or partial), and cancel events with automatic customer notifications.

## Module: `myeventlane_refunds`

### Key Features

1. **Vendor Refund Management**
   - View orders per event
   - Issue full or partial refunds
   - Default scope: tickets-only (donations excluded)
   - Optional toggle to include donations

2. **Event Cancellation**
   - Cancel event with email notifications
   - Optional auto-refund for all orders
   - Batch processing (50 orders per run)

3. **Audit Logging**
   - All refund actions logged in `myeventlane_refund_log` table
   - Tracks: order, event, vendor, amount, status, Stripe refund ID

4. **Access Control**
   - Vendor ownership enforced server-side
   - Admin override allowed
   - Order state validation

## Routes

### `/vendor/events/{node}/orders`
- **Controller**: `VendorOrdersController::list`
- **Access**: Vendor must own event
- **Purpose**: List all paid/placed/completed orders for an event

### `/vendor/orders/{commerce_order}/refund?event={node}`
- **Form**: `VendorRefundForm`
- **Access**: Vendor must own event and order must contain tickets for that event
- **Purpose**: Refund form with full/partial options

### `/vendor/events/{node}/cancel`
- **Form**: `VendorCancelEventForm`
- **Access**: Vendor must own event
- **Purpose**: Cancel event with optional auto-refund

## Services

### `myeventlane_refunds.order_inspector`
**Class**: `RefundOrderInspector`

Methods:
- `isDonationItem(OrderItemInterface): bool` - Checks if item is donation (bundle: checkout_donation, platform_donation, rsvp_donation)
- `isTicketItem(OrderItemInterface): bool` - Checks if item is ticket
- `getEventIdFromItem(OrderItemInterface): ?int` - Gets event ID from `field_target_event`
- `extractItemsForEvent(OrderInterface, int): array` - Gets order items for specific event
- `calculateTicketSubtotalCents(OrderInterface, int): int` - Ticket subtotal in cents for event
- `calculateDonationTotalCents(OrderInterface): int` - Donation total in cents
- `calculateRefundableAmountCents(OrderInterface): int` - Refundable amount based on payments/refunds
- `maskEmail(string): string` - Masks email for display

### `myeventlane_refunds.access_resolver`
**Class**: `RefundAccessResolver`

Methods:
- `vendorCanManageEvent(NodeInterface, AccountInterface): bool` - Checks vendor ownership
- `vendorCanRefundOrderForEvent(OrderInterface, NodeInterface, AccountInterface): bool` - Validates refund access
- `accessManageEvent(NodeInterface, AccountInterface): AccessResult` - Access result for event management
- `accessRefundOrder(OrderInterface, NodeInterface, AccountInterface): AccessResult` - Access result for refund

### `myeventlane_refunds.processor`
**Class**: `RefundProcessor`

Methods:
- `requestRefund(OrderInterface, NodeInterface, AccountInterface, array): int` - Creates audit log and queues refund job
- `processRefund(int): void` - Executes refund via Commerce payment gateway

## Database Schema

### `myeventlane_refund_log`

Fields:
- `id` (serial) - Primary key
- `order_id` (int) - Commerce order ID
- `event_id` (int) - Event node ID
- `vendor_uid` (int) - Vendor user ID
- `refund_type` (varchar) - 'full' or 'partial'
- `refund_scope` (varchar) - 'tickets_only', 'tickets_and_donation', or 'donation_only'
- `amount_cents` (int) - Refund amount in cents
- `currency` (varchar) - Currency code (default: 'aud')
- `donation_refunded` (int) - Whether donation included (0 or 1)
- `stripe_refund_id` (varchar, nullable) - Stripe refund ID
- `status` (varchar) - 'pending', 'completed', or 'failed'
- `reason` (text, nullable) - Refund reason
- `created` (int) - Unix timestamp when requested
- `completed` (int, nullable) - Unix timestamp when completed
- `error_message` (text, nullable) - Error message if failed

Indexes: `order_id`, `event_id`, `vendor_uid`, `status`

## Queue Workers

### `vendor_refund_worker`
- **Class**: `VendorRefundWorker`
- **Purpose**: Processes individual refund requests
- **Cron time**: 60 seconds
- **Payload**: `['log_id' => int]`

### `event_cancel_refund_worker`
- **Class**: `EventCancelRefundWorker`
- **Purpose**: Processes refunds for all orders when event is cancelled
- **Cron time**: 60 seconds
- **Batch size**: 50 orders per run
- **Payload**: `['event_id' => int, 'vendor_uid' => int]`

## Refund Workflow

### 1. Vendor Requests Refund

1. Vendor navigates to `/vendor/events/{node}/orders`
2. Clicks "Refund" on an order
3. Fills out refund form:
   - Select full or partial refund
   - (If partial) Enter amount
   - (Optional) Include donation checkbox
   - (Optional) Reason
   - Confirm checkbox
4. Submits form

### 2. Refund Processing

1. `RefundProcessor::requestRefund()` creates audit log entry (status: 'pending')
2. Queues job in `vendor_refund_worker`
3. Queue worker calls `RefundProcessor::processRefund()`
4. Processor:
   - Validates access and order state
   - Finds eligible payment entity
   - Calls Commerce payment gateway `refundPayment()` method
   - Updates audit log (status: 'completed' or 'failed')
   - Queues email to customer via `myeventlane_messaging`

### 3. Commerce Refund API

The refund is executed via Commerce payment gateway plugin:

```php
$gateway = $payment->getPaymentGateway();
$plugin = $gateway->getPlugin();
$plugin->refundPayment($payment, $refundAmount);
```

For Stripe Connect, this automatically:
- Creates Stripe refund
- Reverses vendor payout (proportionally)
- Reverses application fee (proportionally)
- Handles donation refunds if included

## Donation Handling

### Default Behavior
- **Tickets-only refund**: Only ticket items for the event are refunded
- **Donations excluded**: Donation items are NOT refunded by default
- **Toggle available**: Vendor can check "Include donation" to refund donations too

### Donation Bundles
- `checkout_donation` - Donation added during checkout
- `platform_donation` - Platform-wide donation
- `rsvp_donation` - RSVP donation

### Refund Scope Options
- `tickets_only` - Default: only tickets for this event
- `tickets_and_donation` - Tickets + donations (if checkbox checked)
- `donation_only` - Only donations (rare use case)

## Event Cancellation Workflow

### 1. Vendor Cancels Event

1. Vendor navigates to `/vendor/events/{node}/cancel`
2. Selects action:
   - "Cancel only" - Sends cancellation emails
   - "Cancel and auto-refund" - Sends emails + queues refunds
3. Types "CANCEL" to confirm
4. Checks confirmation checkbox
5. Submits form

### 2. Cancellation Processing

1. Form queues cancellation emails via `myeventlane_messaging` (template: `event_cancelled`)
2. If "cancel and refund" selected:
   - Queues job in `event_cancel_refund_worker`
   - Worker processes orders in batches of 50
   - Each order gets full refund (tickets-only, donations excluded)
   - Re-queues if more orders remain

## Messaging Templates

### `refund_processed`
**Context variables:**
- `event_title` - Event name
- `event_date` - Event start date/time
- `event_location` - Venue name
- `order_number` - Order number
- `refunded_amount` - Formatted refund amount (e.g., "AUD 100.00")
- `donation_refunded` - Boolean: whether donation was included
- `my_tickets_url` - Link to order details

### `event_cancelled`
**Context variables:**
- `event_title` - Event name
- `event_date` - Event start date/time
- `event_location` - Venue name
- `event_url` - Link to event page

## Access Control Rules

### Vendor Ownership
- Vendor must own event (via `VendorOwnershipResolver`)
- Fallback: event owner UID matches vendor UID
- Admin override: users with `administer commerce_order` permission

### Order Refund Access
- Vendor must own event
- Order must contain ticket items for this event (via `field_target_event`)
- Order state must be: `completed`, `fulfilled`, or `placed`
- Order must have refundable amount > 0

## Manual Testing Steps

### 1. Test Refund Flow

```bash
# Enable module
ddev drush en myeventlane_refunds -y

# Clear cache
ddev drush cr

# Create test order with tickets + donation
# Navigate to /vendor/events/{event_id}/orders
# Click "Refund" on an order
# Submit refund form
# Check audit log
ddev drush sqlq "SELECT * FROM myeventlane_refund_log ORDER BY id DESC LIMIT 1"

# Process queue
ddev drush queue:run vendor_refund_worker

# Verify refund in Stripe dashboard (conceptually)
# Check customer email
```

### 2. Test Event Cancellation

```bash
# Navigate to /vendor/events/{event_id}/cancel
# Select "Cancel and auto-refund"
# Type "CANCEL" and confirm
# Submit form

# Process cancellation refunds
ddev drush queue:run event_cancel_refund_worker

# Check audit logs
ddev drush sqlq "SELECT * FROM myeventlane_refund_log WHERE event_id = {event_id}"

# Verify emails sent
ddev drush queue:run myeventlane_messaging
```

### 3. Verify in Stripe Dashboard

1. Log into Stripe dashboard
2. Navigate to Payments
3. Find the payment for the refunded order
4. Check Refunds section:
   - Refund amount matches audit log
   - Refund status: "Succeeded"
   - Transfer reversal (if Stripe Connect)
   - Application fee reversal (if applicable)

## Drush Queue Commands

```bash
# Process refund queue
ddev drush queue:run vendor_refund_worker

# Process event cancellation refunds
ddev drush queue:run event_cancel_refund_worker

# Process messaging queue (refund emails)
ddev drush queue:run myeventlane_messaging

# List queue items
ddev drush queue:list

# Delete queue items (if needed)
ddev drush queue:delete vendor_refund_worker
```

## Code Standards

- **Drupal 11 APIs only** - No deprecated methods
- **Dependency injection** - All services injected
- **Strict typing** - PHP 8.3.23 compatible
- **Access control** - Server-side validation, no UI-only checks
- **Error handling** - All exceptions logged
- **Audit trail** - All actions logged in database

## Security Considerations

1. **Vendor Isolation**: Vendors can only refund orders for their own events
2. **Order State Validation**: Only refundable states allowed
3. **Amount Validation**: Refund amount cannot exceed refundable amount
4. **Double Refund Prevention**: Refundable amount calculated from existing refunds
5. **Access Logging**: All refund attempts logged with vendor UID

## Future Enhancements

- Refund reason categories (dropdown)
- Bulk refund selection (multiple orders)
- Refund analytics dashboard
- Partial refund per ticket item (quantity-based)
- Refund approval workflow (admin approval required)
- Refund reversal (undo refund)

## Troubleshooting

### Refund Fails with "No eligible payment found"
- Check order has completed payments
- Verify payment gateway supports refunds
- Check payment state is 'completed' or 'partially_refunded'

### Refund Amount Exceeds Refundable
- Check existing refunds in audit log
- Verify payment amounts match order total
- Check for partial refunds already processed

### Access Denied Errors
- Verify vendor owns event (check `field_event_vendor` or event owner)
- Check order contains items for this event (`field_target_event`)
- Verify order state is refundable

### Queue Not Processing
- Check cron is running: `ddev drush cron`
- Verify queue worker is registered: `ddev drush queue:list`
- Check logs: `ddev drush watchdog:show --filter=myeventlane_refunds`







