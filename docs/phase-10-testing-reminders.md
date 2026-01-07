# Testing Event Reminders

Quick guide to test all reminder messages in the MyEventLane system.

## Quick Test Commands

### 1. Scan for Reminders
Scans for events needing reminders and enqueues reminder jobs:

```bash
ddev drush mel:reminder-scan
```

**Output:**
- Shows count of 7-day reminders queued
- Shows count of 24-hour reminders queued

### 2. Process Reminder Queues
Processes reminder queues and sends emails:

```bash
# Process all reminder queues
ddev drush mel:reminder-run

# Process only 7-day reminders
ddev drush mel:reminder-run 7d

# Process only 24-hour reminders
ddev drush mel:reminder-run 24h
```

**Output:**
- Shows count of reminders processed
- Errors logged if any failures occur

### 3. Send Test Reminder Email
Send a test reminder email directly to an email address:

```bash
# Test 7-day reminder
ddev drush mel:reminder-test 7d test@example.com 123

# Test 24-hour reminder
ddev drush mel:reminder-test 24h test@example.com 123
```

**Parameters:**
- `7d` or `24h` - Reminder type
- `test@example.com` - Email address to send to
- `123` - Order ID to use for context

**Note:** This queues the email. Run `ddev drush mel:msg-run` to actually send it.

### 4. Send All Queued Messages
After queuing reminders, send all queued messages:

```bash
ddev drush mel:msg-run
```

## Complete Test Workflow

### Test Reminder System End-to-End

1. **Create test data:**
   - Create an event with start date 7 days from now
   - Create a completed order for that event
   - Ensure order has valid email address

2. **Scan for reminders:**
   ```bash
   ddev drush mel:reminder-scan
   ```
   Expected: Shows reminders queued

3. **Process reminder queues:**
   ```bash
   ddev drush mel:reminder-run
   ```
   Expected: Processes reminders, queues emails

4. **Send queued emails:**
   ```bash
   ddev drush mel:msg-run
   ```
   Expected: Emails sent successfully

5. **Verify:**
   - Check email inbox
   - Verify ICS attachment included
   - Check logs: `ddev drush watchdog-show --filter=myeventlane_messaging`

### Test Individual Reminder Types

**Test 7-day reminder:**
```bash
# Create event 7 days from now, then:
ddev drush mel:reminder-scan
ddev drush mel:reminder-run 7d
ddev drush mel:msg-run
```

**Test 24-hour reminder:**
```bash
# Create event 24 hours from now, then:
ddev drush mel:reminder-scan
ddev drush mel:reminder-run 24h
ddev drush mel:msg-run
```

### Test with Existing Order

If you have an existing order, you can test directly:

```bash
# Find order ID first
ddev drush sql-query "SELECT order_id, mail FROM commerce_order WHERE state = 'completed' LIMIT 1"

# Then test (replace ORDER_ID with actual ID)
ddev drush mel:reminder-test 7d your-email@example.com ORDER_ID
ddev drush mel:msg-run
```

## Check Queue Status

```bash
# List all queues
ddev drush queue:list

# Check specific queue item count
ddev drush sql-query "SELECT COUNT(*) FROM queue WHERE name = 'event_reminder_7d'"
ddev drush sql-query "SELECT COUNT(*) FROM queue WHERE name = 'event_reminder_24h'"
```

## Check Logs

```bash
# View all messaging logs
ddev drush watchdog-show --filter=myeventlane_messaging

# View recent logs
ddev drush watchdog-show --count=50 --filter=myeventlane_messaging

# Clear logs (optional)
ddev drush watchdog-delete all
```

## Troubleshooting

### No reminders queued
- Check event has `field_event_start` set
- Verify event start date is in reminder window (7 days ± 1 hour or 24 hours ± 1 hour)
- Check event is published (`status = 1`)
- Verify orders exist for the event
- Check orders are in valid state (`completed`, `placed`, `fulfilled`)

### Reminders queued but not sent
- Run `ddev drush mel:msg-run` to process messaging queue
- Check messaging queue: `ddev drush queue:list`
- Verify email template exists: `ddev drush config:get myeventlane_messaging.template.event_reminder_7d`
- Check logs for errors

### Email not received
- Verify email address in order is correct
- Check mail system configuration
- Check spam folder
- Verify mail system is working: `ddev drush mel:msg-test generic test@example.com`

## All-in-One Test Script

Run this to test everything:

```bash
# 1. Scan for reminders
ddev drush mel:reminder-scan

# 2. Process reminder queues
ddev drush mel:reminder-run

# 3. Send all queued messages
ddev drush mel:msg-run

# 4. Check logs
ddev drush watchdog-show --count=20 --filter=myeventlane_messaging
```

## Testing Idempotency

To verify reminders aren't sent twice:

1. Run reminder scan and process:
   ```bash
   ddev drush mel:reminder-scan
   ddev drush mel:reminder-run
   ddev drush mel:msg-run
   ```

2. Run again immediately:
   ```bash
   ddev drush mel:reminder-scan
   ddev drush mel:reminder-run
   ```

3. Check logs - should see "Reminder already sent" messages
4. Verify no duplicate emails sent

---

**END OF TESTING GUIDE**

