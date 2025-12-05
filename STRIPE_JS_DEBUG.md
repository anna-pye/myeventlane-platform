# Stripe Payment Element JavaScript Debugging Guide

## Issue: Credit Card Fields Visible But Not Editable

The credit card input fields (Card number, Expiration date, CVC) are rendering as empty boxes but are not interactive. This indicates the Stripe Elements JavaScript is not mounting properly.

## Root Cause Analysis

### Current State
- Gateway uses: `plugin: stripe_payment_element` (Payment Element - modern)
- But fields shown are: Card Element format (three separate fields)
- This suggests `PaymentMethodAddForm` (Card Element) is being used instead of Payment Element

### Why This Happens
1. **Payment Element** (`stripe_payment_element` plugin):
   - Renders in `stripe_review` pane on review step
   - Single unified payment field
   - Uses `commerce_stripe/payment_element` library

2. **Card Element** (legacy `stripe` plugin):
   - Renders in `payment_information` pane
   - Three separate fields: card-number, expiration, CVC
   - Uses `commerce_stripe/form` library
   - Requires `stripe-form` class and proper library attachment

## Debugging Steps

### 1. Check Browser Console
Open DevTools Console and check for:

```javascript
// Check if Stripe.js loaded
typeof window.Stripe
// Should return: "function"

// Check if drupalSettings exist
drupalSettings.commerceStripe
// Should return object with publishableKey

// Check for Payment Element settings
drupalSettings.commerceStripePaymentElement
// Should return object if Payment Element is active
```

### 2. Check Network Tab
- Look for `https://js.stripe.com/v3` script load
- Check status: should be 200 OK
- Check if `commerce_stripe/form` or `commerce_stripe/payment_element` library loaded

### 3. Check DOM Elements
Inspect the payment fields:

```javascript
// Card Element mount points
document.getElementById('card-number-element')
document.getElementById('expiration-element')
document.getElementById('security-code-element')

// Payment Element mount point (if using Payment Element)
document.querySelector('[id^="stripe-payment-element"]')
```

### 4. Check Form Classes
```javascript
// Form should have 'stripe-form' class for Card Element
document.querySelector('form.commerce-checkout-flow').classList.contains('stripe-form')

// Or check payment_information pane
document.querySelector('.checkout-pane-payment-information').classList.contains('stripe-form')
```

## Common Issues & Fixes

### Issue 1: Library Not Attached
**Symptom**: Fields render but no Stripe iframes
**Check**: 
```javascript
// In console
Object.keys(drupalSettings.commerceStripe || {})
```
**Fix**: Ensure `commerce_stripe/form` library is attached in form alter hook

### Issue 2: Stripe.js Not Loading
**Symptom**: `window.Stripe` is undefined
**Check**: Network tab for failed `js.stripe.com` request
**Fix**: 
- Check CSP headers
- Verify internet connection
- Check browser console for CORS errors

### Issue 3: Wrong Plugin Being Used
**Symptom**: Payment Element fields expected but Card Element shown
**Check**: 
```bash
ddev drush config:get commerce_payment.commerce_payment_gateway.mel_stripe plugin
```
**Fix**: Ensure gateway uses correct plugin or update checkout flow

### Issue 4: JavaScript Error Preventing Mount
**Symptom**: Console shows JavaScript errors
**Check**: Browser console for errors
**Fix**: Fix JavaScript errors, check for jQuery conflicts

### Issue 5: Element IDs Don't Match
**Symptom**: JS tries to mount but elements not found
**Check**: 
```javascript
$('#card-number-element').length  // Should be > 0
```
**Fix**: Ensure form structure matches expected IDs

## Quick Fix Commands

```bash
# Clear all caches
ddev drush cr

# Rebuild cache
ddev drush cache:rebuild

# Check gateway config
ddev drush config:get commerce_payment.commerce_payment_gateway.mel_stripe

# Check checkout flow
ddev drush config:get commerce_checkout.commerce_checkout_flow.default panes
```

## Expected Behavior

### If Using Payment Element (`stripe_payment_element`):
- Single unified payment field appears in `stripe_review` pane
- Field is interactive Stripe iframe
- Mounts to `#stripe-payment-element-{uniqueId}`

### If Using Card Element (legacy `stripe`):
- Three separate fields in `payment_information` pane
- Each field is interactive Stripe iframe
- Mounts to: `#card-number-element`, `#expiration-element`, `#security-code-element`

## Next Steps

1. Clear cache and test
2. Check browser console for errors
3. Verify library attachment
4. Check if correct plugin is being used
5. Verify element IDs exist in DOM
