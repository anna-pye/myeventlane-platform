# Stripe.js Not Loading - Fix Instructions

## Issue
`window.Stripe` is `undefined` even though `drupalSettings.commerceStripe` exists. This means the Stripe.js library from CDN isn't loading.

## Root Cause
The `commerce_stripe/stripe` library (which loads Stripe.js from CDN) may not be loading properly, or there's a timing issue with deferred scripts.

## Immediate Fix Applied
1. ✅ Explicitly attached `commerce_stripe/stripe` library when Card Element is detected
2. ✅ Improved library detection logic
3. ✅ Cache cleared

## Manual Verification Steps

### 1. Check Network Tab
Open browser DevTools → Network tab → Filter by "stripe":
- Look for: `https://js.stripe.com/v3/`
- Status should be: `200 OK`
- If missing or failed → CDN blocked or network issue

### 2. Check Script Tag in HTML
In browser console, run:
```javascript
// Check if Stripe script tag exists
document.querySelector('script[src*="js.stripe.com"]')
// Should return the script element

// Check if it loaded
document.querySelector('script[src*="js.stripe.com"]').onload
// Check Network tab to see if script actually loaded
```

### 3. Check Library Dependencies
The `commerce_stripe/form` library should automatically include `commerce_stripe/stripe` as a dependency. Verify in browser:
- View page source
- Look for: `<script src="https://js.stripe.com/v3/"`
- Should appear before `commerce_stripe.form.js`

### 4. Test Stripe.js Load After Page Load
In browser console, wait a few seconds then check:
```javascript
// Wait 5 seconds, then check
setTimeout(() => {
  console.log('Stripe loaded:', typeof window.Stripe);
  if (typeof window.Stripe === 'function') {
    console.log('✅ Stripe.js is loaded!');
  } else {
    console.error('❌ Stripe.js still not loaded');
  }
}, 5000);
```

## If Stripe.js Still Doesn't Load

### Option 1: Check Content Security Policy (CSP)
If your site has CSP headers, Stripe CDN must be allowed:
```
script-src 'self' https://js.stripe.com;
```

### Option 2: Check Browser Console for Errors
Look for:
- CORS errors
- Blocked script errors
- Network errors

### Option 3: Force Load Stripe.js
Add this to your theme's JavaScript (temporary debug):
```javascript
// In browser console, force load Stripe.js
const script = document.createElement('script');
script.src = 'https://js.stripe.com/v3/';
script.onload = () => {
  console.log('Stripe.js manually loaded');
  // Re-run the behavior
  if (Drupal.behaviors.commerceStripeForm) {
    Drupal.behaviors.commerceStripeForm.attach(document);
  }
};
document.head.appendChild(script);
```

### Option 4: Check Commerce Stripe Settings
```bash
ddev drush config:get commerce_stripe.settings
```
Should show:
```yaml
load_on_every_page: true  # This helps ensure Stripe.js loads early
```

## Expected Behavior After Fix

1. **Network Tab**: `https://js.stripe.com/v3/` loads with 200 status
2. **Console**: `typeof window.Stripe` returns `"function"`
3. **DOM**: Card fields become interactive Stripe iframes
4. **Fields**: Can type in card number, expiration, CVC

## Next Steps

1. **Refresh the checkout page** (hard refresh: Cmd+Shift+R / Ctrl+Shift+R)
2. **Check Network tab** for Stripe.js load
3. **Check console** for `window.Stripe`
4. **Test card fields** - they should be interactive

If still not working, check browser console for specific errors and share them.
