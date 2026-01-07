# Testing Vendor Communications

Quick guide to test the vendor communications system.

## Setup

1. **Enable the module:**
   ```bash
   ddev drush en myeventlane_vendor_comms -y
   ```

2. **Clear cache:**
   ```bash
   ddev drush cr
   ```

3. **Verify commands are available:**
   ```bash
   ddev drush list | grep comms
   ```

## Test Commands

### 1. Test Recipient Resolution
```bash
ddev drush mel:comms-test-recipients 123
```
Replace `123` with your event ID.

**Output:**
- Event name and ID
- Recipient count
- List of email addresses

### 2. Test Rate Limiting
```bash
ddev drush mel:comms-test-rate-limit 123
```

**Output:**
- Whether sending is allowed
- Current count vs limit
- Reason if blocked

### 3. Queue Test Communication
```bash
ddev drush mel:comms-queue-test 123 update "Test Subject" "Test message body"
```

**Parameters:**
- Event ID
- Message type: `update`, `important_change`, or `cancellation`
- Subject (in quotes)
- Body (in quotes)

### 4. Process Communications Queue
```bash
ddev drush mel:comms-run
```

Processes queued communications and queues emails.

### 5. Send Queued Emails
```bash
ddev drush mel:msg-run
```

Sends all queued emails via messaging system.

### 6. List Recent Communications
```bash
# List all recent
ddev drush mel:comms-list

# List for specific event
ddev drush mel:comms-list 123

# List more entries
ddev drush mel:comms-list --limit=20
```

## Complete Test Workflow

```bash
# 1. Enable module (if not already)
ddev drush en myeventlane_vendor_comms -y
ddev drush cr

# 2. Find an event with orders
# (Use your event ID, or find one with:)
ddev drush sql-query "SELECT DISTINCT n.nid, n.title FROM node_field_data n INNER JOIN commerce_order_item__field_target_event oi ON n.nid = oi.field_target_event_target_id WHERE n.type = 'event' LIMIT 5"

# 3. Test recipients (replace EVENT_ID)
ddev drush mel:comms-test-recipients EVENT_ID

# 4. Test rate limit
ddev drush mel:comms-test-rate-limit EVENT_ID

# 5. Queue test message
ddev drush mel:comms-queue-test EVENT_ID update "Test Update" "This is a test message"

# 6. Process queue
ddev drush mel:comms-run

# 7. Send emails
ddev drush mel:msg-run

# 8. Check logs
ddev drush mel:comms-list
```

## Troubleshooting

### Commands Not Found

If commands don't appear:

1. **Check module is enabled:**
   ```bash
   ddev drush pm:list --type=module --status=enabled --filter=myeventlane_vendor_comms
   ```

2. **Clear cache:**
   ```bash
   ddev drush cr
   ```

3. **Check service registration:**
   ```bash
   ddev drush eval "print_r(\Drupal::service('myeventlane_vendor_comms.recipient_resolver'));"
   ```

### No Recipients Found

- Verify event has completed orders
- Check orders have valid email addresses
- Verify `field_target_event` is set on order items

### Rate Limit Issues

- Check current limits: `ddev drush config:get myeventlane_vendor_comms.settings`
- Adjust limits if needed
- Use `mel:comms-queue-test` to bypass rate limits for testing

### Queue Not Processing

- Check queue status: `ddev drush queue:list`
- Process manually: `ddev drush queue:run vendor_event_comms`
- Check logs: `ddev drush watchdog-show --filter=myeventlane_vendor_comms`

---

**END OF TESTING GUIDE**

