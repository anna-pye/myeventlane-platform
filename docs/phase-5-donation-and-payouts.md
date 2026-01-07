# Phase 5: Donation and Payout Financial Handling

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Ensure correct financial handling of donations and ticket payments in Stripe Connect environment

---

## Summary

Phase 5 implements correct financial separation between ticket revenue and donation revenue in Stripe Connect destination charges:

1. **Donations excluded from vendor payouts** - Donations remain with platform
2. **Application fees calculated on ticket revenue only** - Donations do not incur vendor payout fees
3. **Transfer amounts explicitly set** - Vendor receives only ticket revenue (minus platform fee)
4. **Refund behavior documented** - Refunds correctly reverse payouts for tickets, not donations

This ensures vendors only receive ticket revenue, while donations are retained by the platform.

---

## Stripe Connect Math

### Destination Charge Structure

In Stripe Connect destination charges:
- **Customer pays**: Total order amount (tickets + donations + fees + tax)
- **Platform receives**: Application fee + donation revenue
- **Vendor receives**: Ticket revenue - application fee

### Example Calculation

**Order Contents:**
- Tickets: $100.00
- Donations: $20.00
- Application fee (3% + $0.30 on $100): $3.30
- Total charged to customer: $120.00

**Financial Flow:**
- Customer charged: $120.00
- Vendor receives: $100.00 - $3.30 = **$96.70**
- Platform receives: $3.30 (fee) + $20.00 (donation) = **$23.30**

**Verification:**
- $96.70 (vendor) + $23.30 (platform) = $120.00 ✓

---

## Implementation Details

### Task 1: Exclude Donations from Vendor Payout ✅

**File:** `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php`

**Key Methods:**

1. **`isDonationItem(OrderItemInterface $item): bool`**
   - Identifies donation order items by bundle:
     - `checkout_donation`
     - `platform_donation`
     - `rsvp_donation`

2. **`calculateTicketRevenue(OrderInterface $order): int`**
   - Calculates ticket revenue in cents
   - Excludes: donations, boost items
   - Includes: all other order items (tickets)

3. **`calculateDonationRevenue(OrderInterface $order): int`**
   - Calculates donation revenue in cents (for reference/logging)

4. **`getConnectPaymentIntentParams(OrderInterface $order): array`**
   - Builds Stripe Connect parameters
   - Sets `transfer_data[amount]` to ticket revenue only
   - Ensures vendor receives: `ticket_revenue - application_fee`

**Payment Intent Parameters:**
```php
[
  'application_fee_amount' => 330,  // Fee on ticket revenue only
  'transfer_data' => [
    'destination' => 'acct_vendor123',
    'amount' => 10000,  // Ticket revenue only ($100.00)
  ],
]
```

**Result:**
- Vendor receives: $100.00 - $3.30 = $96.70
- Platform receives: $3.30 (fee) + $20.00 (donation) = $23.30

---

### Task 2: Application Fee Calculation ✅

**File:** `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php`

**Method:** `calculateApplicationFee(OrderInterface $order, float $feePercentage = 0.03, int $fixedFeeCents = 30): int`

**Key Points:**
- Fee calculated **ONLY on ticket revenue**
- Donations do NOT incur vendor payout fees
- Formula: `(ticket_revenue * fee_percentage) + fixed_fee`

**Example:**
- Ticket revenue: $100.00
- Fee percentage: 3%
- Fixed fee: $0.30
- Application fee: ($100.00 × 0.03) + $0.30 = $3.30

**Code Comments:**
```php
/**
 * IMPORTANT: Application fee is calculated ONLY on ticket revenue,
 * NOT on donations. Donations are platform revenue and do not incur
 * vendor payout fees.
 */
```

---

### Task 3: Refund Behavior ✅

**Refund Handling:**

Stripe Connect automatically handles refunds for destination charges:

1. **Full Refund:**
   - Refunds entire PaymentIntent amount to customer
   - Stripe automatically reverses:
     - Vendor payout (for ticket portion)
     - Application fee (platform portion)
   - Donation portion is also refunded to customer

2. **Partial Refund (Tickets Only):**
   - If refunding only ticket items:
     - Vendor payout is reversed proportionally
     - Application fee is reversed proportionally
     - Donation remains with platform (not refunded)

3. **Partial Refund (Donations Only):**
   - If refunding only donation items:
     - No vendor payout reversal (donations weren't transferred)
     - Platform refunds donation amount to customer

**Important Notes:**
- Stripe handles refund reversals automatically
- No custom refund logic needed in code
- Refunds are processed through Commerce Stripe module
- Vendor payouts are reversed via Stripe Connect API

**Current Implementation:**
- Refunds are handled by Commerce Stripe contrib module
- No custom refund logic in MyEventLane codebase
- Stripe Connect API automatically reverses transfers

---

## Test Coverage

**File:** `web/modules/custom/myeventlane_commerce/tests/src/Kernel/StripeConnectDonationTest.php`

**Test Cases:**

1. **`testDonationOnlyOrderNoTransfer()`**
   - Creates order with donation only
   - Asserts Connect params are empty (no transfer)
   - Verifies ticket revenue = 0
   - Verifies donation revenue = $20.00

2. **`testMixedOrderTransfersOnlyTicketAmount()`**
   - Creates order with ticket ($100) + donation ($20)
   - Asserts transfer amount = $100.00 (ticket only)
   - Asserts application fee = $3.30 (on $100 only)
   - Verifies vendor receives: $96.70

3. **`testApplicationFeeExcludesDonations()`**
   - Creates order with ticket ($100) + donation ($50)
   - Asserts application fee = $3.30 (on $100, not $150)
   - Verifies donations don't affect fee calculation

---

## Files Modified

1. **`src/Service/StripeConnectPaymentService.php`**
   - Added `isDonationItem()` method
   - Added `calculateTicketRevenue()` method
   - Added `calculateDonationRevenue()` method
   - Updated `calculateApplicationFee()` to exclude donations
   - Updated `getConnectPaymentIntentParams()` to set transfer amount

2. **`tests/src/Kernel/StripeConnectDonationTest.php`** (New)
   - Kernel tests for donation exclusion
   - Tests for fee calculation
   - Tests for transfer amount calculation

---

## Stripe Connect Parameter Structure

### Payment Intent Creation

```php
$params = [
  'amount' => 12000,  // Total: tickets ($100) + donations ($20)
  'currency' => 'aud',
  'application_fee_amount' => 330,  // Fee on tickets only
  'transfer_data' => [
    'destination' => 'acct_vendor123',
    'amount' => 10000,  // Ticket revenue only
  ],
];
```

### Financial Breakdown

| Item | Amount | Recipient |
|------|--------|-----------|
| Customer charged | $120.00 | - |
| Ticket revenue | $100.00 | Vendor (minus fee) |
| Donation revenue | $20.00 | Platform |
| Application fee | $3.30 | Platform |
| **Vendor receives** | **$96.70** | Vendor |
| **Platform receives** | **$23.30** | Platform |

---

## Edge Cases Handled

✅ **Donation-only orders** - No Connect transfer (returns empty params)

✅ **Mixed orders** - Only ticket portion transferred to vendor

✅ **Multiple donations** - All donations excluded from transfer

✅ **Zero ticket revenue** - No Connect transfer attempted

✅ **Boost items** - Already excluded (use platform account)

---

## Verification Steps

### Manual Test 1: Donation-Only Order

**Steps:**
1. Create order with donation only ($20)
2. Process payment via Stripe Connect
3. Check Stripe dashboard

**Expected:**
- PaymentIntent created with amount = $20.00
- No `transfer_data` in PaymentIntent (no Connect transfer)
- Platform receives full $20.00

---

### Manual Test 2: Mixed Order

**Steps:**
1. Create order with ticket ($100) + donation ($20)
2. Process payment via Stripe Connect
3. Check Stripe dashboard

**Expected:**
- PaymentIntent amount = $120.00
- `transfer_data[amount]` = $100.00 (ticket revenue)
- `application_fee_amount` = $3.30 (on $100)
- Vendor receives: $96.70
- Platform receives: $23.30

---

### Manual Test 3: Refund Behavior

**Steps:**
1. Create and pay for mixed order (ticket + donation)
2. Refund ticket portion only
3. Check Stripe dashboard

**Expected:**
- Vendor payout reversed proportionally
- Application fee reversed proportionally
- Donation remains with platform

---

## Code Documentation

### Inline Comments

All key methods include detailed comments explaining:
- What revenue is included/excluded
- How fees are calculated
- Stripe Connect math
- Edge cases handled

### Example Comment:

```php
/**
 * STRIPE CONNECT MATH:
 * - Customer pays: total order amount (tickets + donations + fees + tax)
 * - Platform receives: application_fee_amount + donation revenue
 * - Vendor receives: ticket revenue - application_fee_amount
 *
 * Example:
 * - Tickets: $100.00
 * - Donations: $20.00
 * - Application fee (3% + $0.30 on $100): $3.30
 * - Total charged: $120.00
 * - Vendor receives: $100.00 - $3.30 = $96.70
 * - Platform receives: $3.30 (fee) + $20.00 (donation) = $23.30
 */
```

---

## Security Considerations

1. **Financial accuracy** - Transfer amounts explicitly set to prevent over-payment
2. **Donation protection** - Donations cannot be transferred to vendors
3. **Fee calculation** - Fees calculated only on ticket revenue (prevents fee inflation)
4. **Refund safety** - Stripe handles refund reversals automatically

---

## Integration Points

- **Commerce Stripe** - Uses PaymentIntent creation from Commerce Stripe
- **Stripe Connect API** - Uses destination charge structure
- **Order Items** - Identifies donations by bundle type
- **Store Configuration** - Reads Stripe Connect account ID from store

---

## Next Steps

- **Future:** Consider adding refund tracking/logging
- **Future:** Consider adding financial reporting for donations vs tickets

---

**END OF PHASE 5 DOCUMENTATION**

