# Phase 3: Capacity Enforcement at Commerce Lifecycle

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Guarantee event capacity enforcement for paid ticket orders at Commerce lifecycle level

---

## Summary

Phase 3 implements capacity enforcement at three critical points in the Commerce lifecycle:
1. **Add-to-cart** - Prevents adding items that would exceed capacity
2. **Cart form validation** - Enforces capacity on quantity updates and cart refresh
3. **Order placement pre-transition** - Final gate before order is placed

This prevents overselling even with multiple tabs, stale carts, and simultaneous checkouts.

---

## Implementation Details

### Task 1: Capacity Enforcement Subscriber ✅

**File:** `web/modules/custom/myeventlane_capacity/src/EventSubscriber/CommerceCapacityEnforcementSubscriber.php`

**Enforcement Points:**

1. **`CartEvents::CART_ENTITY_ADD`** - Add-to-cart
   - Method: `onCartEntityAdd()`
   - Validates capacity when items are added to cart
   - Throws `CapacityExceededException` if capacity would be exceeded
   - User-friendly error messages (AU English)

2. **`commerce_order.place.pre_transition`** - Order placement
   - Method: `onOrderPlacePreTransition()`
   - Final enforcement gate before order is placed
   - Prevents overselling even if cart validation was bypassed
   - Throws `CapacityExceededException` if capacity would be exceeded

**Enforcement Algorithm:**
- Extract event_id => requested_qty map from order using `CapacityOrderInspector`
- Skip non-ticket items (donations, boosts)
- Only process items with `field_target_event` set
- Load events in bulk for efficiency
- For each event:
  - Check if capacity is NULL (unlimited) → allow
  - Check if requested_qty is 0 → allow
  - Call `EventCapacityService->assertCanBook($event, $requested_total)`
  - On failure: throw `CapacityExceededException` with user-friendly message

---

### Task 2: Helper Service ✅

**File:** `web/modules/custom/myeventlane_capacity/src/Service/CapacityOrderInspector.php`

**Methods:**
- `extractEventQuantities(OrderInterface $order): array<int, int>` - Builds event_id => qty map
- `isNonTicketItem(OrderItemInterface $item): bool` - Checks if item should be excluded
- `getEventIds(OrderInterface $order): array<int>` - Gets all event IDs from order

**Excluded Item Types:**
- `checkout_donation`
- `platform_donation`
- `rsvp_donation`
- `boost`

---

### Task 3: Cart Form Validation ✅

**File:** `web/modules/custom/myeventlane_capacity/myeventlane_capacity.module`

**Hook:** `hook_form_alter()` + `myeventlane_capacity_cart_form_validate()`

- Adds validation to `commerce_cart_form`
- Enforces capacity on quantity updates and cart refresh
- Sets form errors with user-friendly messages
- Logs blocked cart updates

---

### Task 4: Kernel Test ✅

**File:** `web/modules/custom/myeventlane_capacity/tests/src/Kernel/CapacityEnforcementTest.php`

**Test Cases:**

1. **`testOversellBlockedAtOrderPlacement()`**
   - Creates event with capacity = 1
   - Creates order with 2 tickets
   - Asserts `CapacityExceededException` is thrown at order placement

2. **`testValidBookingAllowed()`**
   - Creates event with capacity = 10
   - Creates order with 5 tickets
   - Asserts no exception is thrown

3. **`testDonationItemsExcluded()`**
   - Creates order with 1 ticket + 1 donation
   - Asserts only ticket is counted in capacity check

---

## Enforcement Flow

### Add-to-Cart Flow
```
User adds ticket to cart
  ↓
CartEvents::CART_ENTITY_ADD fired
  ↓
CommerceCapacityEnforcementSubscriber::onCartEntityAdd()
  ↓
Extract event quantities from cart
  ↓
EventCapacityService->assertCanBook()
  ↓
If capacity exceeded → throw CapacityExceededException
  ↓
Commerce displays error message to user
```

### Cart Update/Refresh Flow
```
User updates quantity or cart refreshes
  ↓
Cart form validation runs
  ↓
myeventlane_capacity_cart_form_validate()
  ↓
Extract event quantities from cart
  ↓
EventCapacityService->assertCanBook() for each event
  ↓
If capacity exceeded → set form error
  ↓
User sees error message, form rebuilds
```

### Order Placement Flow
```
User submits checkout form
  ↓
Order transitions to "placed" state
  ↓
commerce_order.place.pre_transition fired
  ↓
CommerceCapacityEnforcementSubscriber::onOrderPlacePreTransition()
  ↓
Extract event quantities from order
  ↓
EventCapacityService->assertCanBook() for each event
  ↓
If capacity exceeded → throw CapacityExceededException
  ↓
Commerce converts exception to checkout error
  ↓
User sees error, order remains in draft state
```

---

## Manual Testing Guide

### Prerequisites
1. Create a paid event with capacity = 2
2. Ensure no tickets are sold yet (sold count = 0)

### Test 1: Add-to-Cart Enforcement

**Steps:**
1. Navigate to event page
2. Add 3 tickets to cart (exceeds capacity of 2)

**Expected Behavior:**
- Error message displayed: "Sorry, only 2 ticket(s) remaining for this event. Please adjust your quantity."
- Item is NOT added to cart
- Watchdog log entry: "Capacity enforcement blocked cart add"

---

### Test 2: Cart Quantity Update Enforcement

**Steps:**
1. Add 1 ticket to cart (within capacity)
2. Update quantity to 3 in cart form
3. Submit cart form

**Expected Behavior:**
- Form validation error: "Sorry, only 2 ticket(s) remaining for this event. Please adjust your quantity."
- Quantity reverts to 1
- Watchdog log entry: "Capacity enforcement blocked cart update"

---

### Test 3: Cart Refresh Enforcement

**Steps:**
1. Add 1 ticket to cart
2. In another tab/browser, sell 1 ticket (so 1 remaining)
3. Return to cart tab and refresh page
4. Try to update quantity to 2

**Expected Behavior:**
- Form validation error: "Sorry, only 1 ticket(s) remaining for this event. Please adjust your quantity."
- Cart reflects current capacity

---

### Test 4: Order Placement Final Gate

**Steps:**
1. Add 1 ticket to cart
2. Proceed to checkout
3. Fill in all required fields
4. In another tab, sell 1 ticket (event now sold out)
5. Return to checkout tab and submit payment

**Expected Behavior:**
- Checkout error: "Sorry, '[Event Name]' is sold out. Please try another event."
- Order remains in draft state (not placed)
- Payment is NOT processed
- Watchdog log entry: "Capacity enforcement blocked order placement"

---

### Test 5: Multiple Events in Cart

**Steps:**
1. Create Event A (capacity = 1) and Event B (capacity = 1)
2. Add 1 ticket for Event A to cart
3. Add 1 ticket for Event B to cart
4. Try to add another ticket for Event A

**Expected Behavior:**
- Error message for Event A only
- Event B ticket remains in cart
- Only Event A capacity is checked

---

### Test 6: Donation Items Excluded

**Steps:**
1. Create event with capacity = 1
2. Add 1 ticket to cart
3. Add donation to cart
4. Proceed to checkout and place order

**Expected Behavior:**
- Order places successfully
- Only ticket item is counted for capacity
- Donation does not affect capacity check

---

## Edge Cases Handled

✅ **Unlimited Capacity** - Events with NULL capacity are allowed (no enforcement)

✅ **Zero Quantity** - Orders with 0 quantity are allowed

✅ **Multiple Events** - Each event is checked independently

✅ **Non-Ticket Items** - Donations and boosts are excluded from capacity checks

✅ **Stale Carts** - Cart refresh validation ensures current capacity is checked

✅ **Simultaneous Checkouts** - Order placement pre-transition prevents race conditions

---

## Files Created/Modified

1. **New Files:**
   - `src/Service/CapacityOrderInspector.php` - Order inspection helper
   - `src/EventSubscriber/CommerceCapacityEnforcementSubscriber.php` - Enforcement subscriber
   - `tests/src/Kernel/CapacityEnforcementTest.php` - Kernel tests

2. **Modified Files:**
   - `myeventlane_capacity.services.yml` - Registered services and subscriber
   - `myeventlane_capacity.module` - Added cart form validation hook

---

## Service Registration

```yaml
services:
  myeventlane_capacity.service:
    class: Drupal\myeventlane_capacity\Service\EventCapacityService
    arguments: ['@entity_type.manager', '@cache.default']

  myeventlane_capacity.order_inspector:
    class: Drupal\myeventlane_capacity\Service\CapacityOrderInspector

  logger.channel.myeventlane_capacity:
    parent: logger.channel_base
    arguments: ['myeventlane_capacity']

  myeventlane_capacity.commerce_enforcement_subscriber:
    class: Drupal\myeventlane_capacity\EventSubscriber\CommerceCapacityEnforcementSubscriber
    arguments:
      - '@myeventlane_capacity.service'
      - '@myeventlane_capacity.order_inspector'
      - '@entity_type.manager'
      - '@string_translation'
      - '@logger.channel.myeventlane_capacity'
    tags:
      - { name: event_subscriber }
```

---

## Installation & Verification

1. **Clear cache:**
   ```bash
   ddev drush cr
   ```

2. **Verify subscriber is registered:**
   ```bash
   ddev drush ev "print_r(array_keys(\Drupal::service('event_dispatcher')->getListeners('commerce_cart.entity_add')));"
   ```

3. **Run tests:**
   ```bash
   ddev exec vendor/bin/phpunit web/modules/custom/myeventlane_capacity/tests/src/Kernel/CapacityEnforcementTest.php
   ```

---

## Next Steps

- **Phase 4:** Access control for attendee paragraphs
- **Future:** Per-variation stock enforcement (if needed)

---

**END OF PHASE 3 DOCUMENTATION**

