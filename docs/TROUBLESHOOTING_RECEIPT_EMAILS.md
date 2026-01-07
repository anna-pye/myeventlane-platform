# Troubleshooting: Receipt Emails Not Sending After Purchase

## Issue
Receipt emails are not being sent when tickets are purchased.

## Root Causes & Fixes

### 1. OrderPlacedSubscriber Not Registered ✅ FIXED
**Problem:** The `OrderPlacedSubscriber` was not registered in `services.yml`.

**Fix Applied:**
- Added subscriber registration to `myeventlane_messaging.services.yml`
- Added logging to track when emails are queued

**File:** `web/modules/custom/myeventlane_messaging/myeventlane_messaging.services.yml`

### 2. Email Template Not Imported
**Problem:** The `order_receipt` template config may not be imported.

**Check:**
```bash
ddev drush config:get myeventlane_messaging.template.order_receipt enabled
```

**Fix if missing:**
```bash
# Import the template
ddev drush config:import --source=/var/www/html/web/modules/custom/myeventlane_messaging/config/install --partial --yes

# Or manually import
ddev drush config:set myeventlane_messaging.template.order_receipt enabled true
```

### 3. Emails Queued But Not Sent
**Problem:** Emails are queued but the queue worker hasn't processed them.

**Check queue:**
```bash
# Check if emails are in queue
ddev drush queue:list | grep myeventlane_messaging

# Process the queue
ddev drush queue:run myeventlane_messaging

# Or use the messaging command
ddev drush mel:msg-run
```

### 4. Check Logs
**Check for errors:**
```bash
# Check recent logs
ddev drush watchdog-show --filter=myeventlane_messaging --count=20

# Look for:
# - "Order receipt queued for order X" (success)
# - "Template order_receipt disabled or missing" (template issue)
# - "Failed to queue order receipt" (error)
```

### 5. Verify Order State
**Problem:** Order may not be transitioning to 'placed' state.

**Check:**
```bash
# Check recent orders
ddev drush sql-query "SELECT order_id, mail, state FROM commerce_order ORDER BY order_id DESC LIMIT 5"

# Order must be in 'completed' or 'placed' state for subscriber to fire
```

## Testing Steps

1. **Clear cache:**
   ```bash
   ddev drush cr
   ```

2. **Verify subscriber is registered:**
   ```bash
   ddev drush ev "print_r(array_keys(\Drupal::service('kernel')->getContainer()->get('event_dispatcher')->getListeners('commerce_order.place.post_transition')));"
   ```

3. **Verify template exists:**
   ```bash
   ddev drush config:get myeventlane_messaging.template.order_receipt
   ```

4. **Make a test purchase:**
   - Add tickets to cart
   - Complete checkout
   - Check logs immediately after

5. **Process queue:**
   ```bash
   ddev drush mel:msg-run
   ```

6. **Check logs:**
   ```bash
   ddev drush watchdog-show --filter=myeventlane_messaging --count=10
   ```

## Expected Behavior

When an order is placed:
1. `OrderPlacedSubscriber::onPlace()` is called
2. Log entry: "Order receipt queued for order X to email@example.com"
3. Email is added to `myeventlane_messaging` queue
4. Queue worker processes and sends email
5. Email is delivered to customer

## Common Issues

### Template Not Found
**Error:** `Template order_receipt disabled or missing.`

**Fix:**
```bash
# Import template config
ddev drush config:import --source=/var/www/html/web/modules/custom/myeventlane_messaging/config/install --partial --yes
ddev drush cr
```

### No Email Address
**Error:** `Order X placed but no email address found for receipt.`

**Fix:** Ensure order has email address:
- Check `commerce_order.mail` field
- Or customer account email

### Queue Not Processing
**Fix:**
```bash
# Process queue manually
ddev drush queue:run myeventlane_messaging

# Or set up cron to process automatically
```

## Files Modified

1. `myeventlane_messaging.services.yml` - Added subscriber registration
2. `OrderPlacedSubscriber.php` - Added logging

## Next Steps

1. Clear cache: `ddev drush cr`
2. Verify template is imported
3. Make a test purchase
4. Check logs for "Order receipt queued"
5. Process queue: `ddev drush mel:msg-run`
6. Verify email is sent







