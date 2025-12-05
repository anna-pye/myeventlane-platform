# Verify Stripe.js Loading - Diagnostic Steps

## Current Status
- ✅ `drupalSettings.commerceStripe` exists (library attached)
- ❌ `window.Stripe` is `undefined` (Stripe.js not loaded)

## Step 1: Check HTML Source
1. Go to checkout page
2. Right-click → "View Page Source" (or Cmd+Option+U / Ctrl+U)
3. Search for: `js.stripe.com`
4. **Expected**: Should find: `<script src="https://js.stripe.com/v3/" defer="defer">`
5. **If missing**: Library not being attached/rendered

## Step 2: Check Network Tab
1. Open DevTools → Network tab
2. Filter by: `stripe`
3. Refresh page
4. **Expected**: See `https://js.stripe.com/v3/` with status `200`
5. **If missing/failed**: CDN blocked, network issue, or CSP blocking

## Step 3: Check Console for Errors
Look for:
- CORS errors
- CSP (Content Security Policy) violations
- Network errors
- Script loading errors

## Step 4: Manual Test in Console
Run this in browser console:

```javascript
// Check if script tag exists
const stripeScript = document.querySelector('script[src*="js.stripe.com"]');
console.log('Stripe script tag:', stripeScript);

// Check if it loaded
if (stripeScript) {
  console.log('Script src:', stripeScript.src);
  console.log('Script defer:', stripeScript.hasAttribute('defer'));
  
  // Try to manually load if missing
  if (typeof window.Stripe === 'undefined') {
    console.log('⚠️ Stripe.js not loaded, checking...');
    
    // Wait a bit for deferred scripts
    setTimeout(() => {
      if (typeof window.Stripe === 'undefined') {
        console.error('❌ Stripe.js still not loaded after 3 seconds');
        console.log('Attempting manual load...');
        
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.onload = () => {
          console.log('✅ Stripe.js manually loaded!');
          console.log('window.Stripe:', typeof window.Stripe);
          // Re-run the behavior
          if (Drupal && Drupal.behaviors && Drupal.behaviors.commerceStripeForm) {
            Drupal.behaviors.commerceStripeForm.attach(document);
          }
        };
        script.onerror = () => {
          console.error('❌ Failed to load Stripe.js');
        };
        document.head.appendChild(script);
      } else {
        console.log('✅ Stripe.js loaded!');
      }
    }, 3000);
  } else {
    console.log('✅ Stripe.js already loaded!');
  }
} else {
  console.error('❌ Stripe script tag not found in HTML');
}
```

## Step 5: Check Library Attachment
In browser console, check what libraries are attached:

```javascript
// This won't work directly, but check the HTML for library attachments
// Look for data-drupal-library attributes on script tags
document.querySelectorAll('script[data-drupal-library]').forEach(script => {
  console.log(script.getAttribute('data-drupal-library'), script.src);
});
```

## Common Issues & Solutions

### Issue 1: Script Tag Missing
**Cause**: Library not attached or not rendering
**Fix**: The `hook_page_attachments()` I added should fix this

### Issue 2: Script Tag Exists But Stripe.js Not Loading
**Cause**: 
- CSP blocking external scripts
- Network/CORS issue
- Script loading but too late (defer timing)

**Fix**: 
- Check CSP headers
- Check network tab for failed requests
- The form.js should wait for Stripe, but might need adjustment

### Issue 3: Defer Timing Issue
**Cause**: Script loads after form.js tries to use it
**Fix**: The form.js has a check that waits for Stripe, but might need more time

## What I've Fixed

1. ✅ Added `hook_page_attachments()` to ensure Stripe.js loads on checkout pages
2. ✅ Explicitly attach `commerce_stripe/stripe` library in form alter
3. ✅ Improved library detection logic

## Next Steps

1. **Hard refresh** the checkout page (Cmd+Shift+R)
2. **Check HTML source** for Stripe script tag
3. **Check Network tab** for Stripe.js load
4. **Run diagnostic script** above in console
5. **Share results** - especially any errors or if script tag is missing
