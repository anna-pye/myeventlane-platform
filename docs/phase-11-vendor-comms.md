# Phase 11: Vendor Event Communications

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Implement vendor-safe event communications system (Humanitix-style)

---

## Summary

Phase 11 implements a secure, rate-limited system that allows vendors to send essential event updates to attendees:

1. **Event Updates** - General updates about the event
2. **Important Changes** - Critical changes (time, location, etc.)
3. **Cancellations** - Event cancellation notices

All communications are:
- **Vendor-scoped** - Only vendors who own events can send
- **Rate-limited** - Prevents spam (5 per hour, 20 per day per event)
- **Audit-logged** - All sends tracked in database
- **Transactional** - Essential event information only

---

## Implementation Details

### Task 1: Data + Audit Log ✅

**File:** `myeventlane_vendor_comms.install` - `hook_schema()`

**Table:** `myeventlane_event_comms_log`

**Fields:**
- `id` (serial) - Primary key
- `event_id` (int) - Event node ID
- `vendor_uid` (int) - Vendor user ID
- `message_type` (varchar 64) - Type: `update`, `important_change`, `cancellation`
- `subject` (varchar 255) - Email subject line
- `body` (text) - Message body (HTML)
- `recipient_count` (int) - Number of recipients
- `sent_count` (int) - Successfully sent count
- `failed_count` (int) - Failed send count
- `status` (varchar 32) - `pending`, `sending`, `completed`, `failed`
- `sent_at` (int) - Timestamp when send initiated
- `completed_at` (int) - Timestamp when send completed

**Indexes:**
- `event_id` - For event lookups
- `vendor_uid` - For vendor lookups
- `sent_at` - For rate limiting queries
- `status` - For status filtering

---

### Task 2: Vendor UI ✅

**File:** `src/Form/VendorEventCommsForm.php`

**Route:** `/vendor/events/{node}/comms`

**Features:**
- **Message Type Selection** - Dropdown: Update, Important Change, Cancellation
- **Subject Field** - Required, max 255 characters
- **Message Body** - Textarea, max 5000 characters, supports safe HTML
- **Preview** - Shows formatted preview before sending
- **Confirmation Checkbox** - "I confirm this is essential event information"
- **Recipient Count** - Shows number of recipients before sending
- **Rate Limit Warnings** - Shows if rate limit reached
- **Past Sends Table** - Shows recent messages sent (last 10)

**Access Control:**
- Uses `VendorCommsController::checkAccess()`
- Verifies vendor owns event via `VendorOwnershipResolver::vendorOwnsEvent()`
- Admin override allowed

**Safety Controls:**
- Prevents sending if `recipient_count = 0`
- Requires confirmation checkbox
- Shows rate limit status
- Double confirmation (preview then send)

---

### Task 3: Recipient Resolution ✅

**File:** `src/Service/EventRecipientResolver.php`

**Service:** `myeventlane_vendor_comms.recipient_resolver`

**Functionality:**
- Finds orders for event via `field_target_event` on order items
- Filters by order state:
  - **Included:** `completed`, `placed`, `fulfilled`
  - **Excluded:** `canceled`, `refunded`
- De-duplicates emails per event (case-insensitive)
- Validates email addresses
- Returns unique email array

**Methods:**
- `getRecipientEmails(NodeInterface $event): array` - Returns email addresses
- `getRecipientCount(NodeInterface $event): int` - Returns count

---

### Task 4: Sending Pipeline ✅

**File:** `src/Plugin/QueueWorker/VendorEventCommsWorker.php`

**Queue ID:** `vendor_event_comms`

**Process:**
1. Form submission creates log entry with status `pending`
2. Enqueues job to `vendor_event_comms` queue
3. Worker processes item:
   - Updates status to `sending`
   - Loads event
   - Gets recipients via `EventRecipientResolver`
   - Queues emails via `MessagingManager`
   - Tracks sent/failed counts
   - Updates status to `completed` or `failed`

**Email Templates:**
- `vendor_event_update` - For general updates
- `vendor_event_important_change` - For important changes
- `vendor_event_cancellation` - For cancellations

**Templates Location:**
- `myeventlane_messaging/config/install/myeventlane_messaging.template.vendor_event_*.yml`

---

### Task 5: Safety Controls ✅

**File:** `src/Service/CommsRateLimiter.php`

**Service:** `myeventlane_vendor_comms.rate_limiter`

**Rate Limits (configurable):**
- **Hourly:** 5 messages per event per vendor (default)
- **Daily:** 20 messages per event per vendor (default)

**Configuration:**
- `myeventlane_vendor_comms.settings.yml`
- `rate_limit_hourly` - Hourly limit
- `rate_limit_daily` - Daily limit

**Other Safety Controls:**
- **Confirmation Required** - Checkbox must be checked
- **Recipient Count Check** - Prevents sending if 0 recipients
- **Access Control** - Only event owner can send
- **Status Tracking** - All sends logged with status

---

## Files Created/Modified

### New Files:
1. `myeventlane_vendor_comms.info.yml` - Module info
2. `myeventlane_vendor_comms.install` - Schema definition
3. `myeventlane_vendor_comms.services.yml` - Service definitions
4. `myeventlane_vendor_comms.routing.yml` - Route definitions
5. `src/Service/EventRecipientResolver.php` - Recipient resolution
6. `src/Service/CommsRateLimiter.php` - Rate limiting
7. `src/Form/VendorEventCommsForm.php` - Vendor form
8. `src/Controller/VendorCommsController.php` - Access control
9. `src/Plugin/QueueWorker/VendorEventCommsWorker.php` - Queue worker
10. `config/schema/myeventlane_vendor_comms.schema.yml` - Config schema
11. `config/install/myeventlane_vendor_comms.settings.yml` - Default settings
12. `config/install/myeventlane_messaging.template.vendor_event_update.yml` - Update template
13. `config/install/myeventlane_messaging.template.vendor_event_important_change.yml` - Important change template
14. `config/install/myeventlane_messaging.template.vendor_event_cancellation.yml` - Cancellation template
15. `docs/phase-11-vendor-comms.md` - This documentation

---

## Route Configuration

```yaml
myeventlane_vendor_comms.send:
  path: '/vendor/events/{node}/comms'
  defaults:
    _form: '\Drupal\myeventlane_vendor_comms\Form\VendorEventCommsForm'
    _title: 'Send Event Update'
  requirements:
    _entity_access: 'node.view'
    _custom_access: '\Drupal\myeventlane_vendor_comms\Controller\VendorCommsController::checkAccess'
    node: \d+
```

---

## Service Configuration

```yaml
myeventlane_vendor_comms.recipient_resolver:
  class: Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver
  arguments: ['@entity_type.manager']

myeventlane_vendor_comms.rate_limiter:
  class: Drupal\myeventlane_vendor_comms\Service\CommsRateLimiter
  arguments: ['@database', '@datetime.time']
```

---

## Email Templates

### Template Variables:
- `event` - Event node object
- `event_title` - Event name
- `event_url` - Event canonical URL
- `message_body` - Vendor's message body (HTML)
- `message_type` - Message type
- `custom_subject` - Custom subject line

### Templates:
1. **vendor_event_update** - General updates (pink accent)
2. **vendor_event_important_change** - Important changes (yellow warning)
3. **vendor_event_cancellation** - Cancellations (red alert)

---

## Access Control

### Vendor Verification:
- Uses `VendorOwnershipResolver::getStoreForUser()`
- Verifies `VendorOwnershipResolver::vendorOwnsEvent()`
- Admin override: Admins always allowed

### Result:
- Only vendors who own the event can send communications
- Access denied for other vendors
- Access denied for customers/anonymous

---

## Rate Limiting

### Limits:
- **Hourly:** 5 messages per event per vendor (default)
- **Daily:** 20 messages per event per vendor (default)

### Configuration:
```yaml
# config/install/myeventlane_vendor_comms.settings.yml
rate_limit_hourly: 5
rate_limit_daily: 20
```

### Behavior:
- Checks both hourly and daily limits
- Returns clear error message if limit reached
- Prevents form submission if limit exceeded
- Limits are per-event (vendor can send to different events)

---

## Manual Test Steps

### 1. Setup
```bash
# Enable module
ddev drush en myeventlane_vendor_comms -y

# Clear cache
ddev drush cr
```

### Quick Test Commands

**Test recipient resolution:**
```bash
ddev drush mel:comms-test-recipients 123
```

**Test rate limiting:**
```bash
ddev drush mel:comms-test-rate-limit 123
```

**Queue a test communication:**
```bash
ddev drush mel:comms-queue-test 123 update "Test Subject" "Test message body"
```

**Process communications queue:**
```bash
ddev drush mel:comms-run
```

**List recent communications:**
```bash
ddev drush mel:comms-list
ddev drush mel:comms-list 123  # For specific event
```

**Complete test workflow:**
```bash
# 1. Test recipients
ddev drush mel:comms-test-recipients 123

# 2. Test rate limit
ddev drush mel:comms-test-rate-limit 123

# 3. Queue test message
ddev drush mel:comms-queue-test 123 update "Test Update" "This is a test message"

# 4. Process queue
ddev drush mel:comms-run

# 5. Send emails
ddev drush mel:msg-run

# 6. Check logs
ddev drush mel:comms-list
```

### 2. Test Access Control
1. Log in as vendor
2. Navigate to `/vendor/events/{event_id}/comms`
3. Verify form loads for owned events
4. Try accessing another vendor's event (should be denied)

### 3. Test Form
1. Select message type
2. Enter subject
3. Enter message body
4. Check confirmation checkbox
5. Click "Preview"
6. Verify preview displays correctly
7. Click "Confirm and Send"
8. Verify success message

### 4. Test Rate Limiting
1. Send 5 messages quickly (within 1 hour)
2. Try to send 6th message
3. Verify rate limit warning appears
4. Verify form is disabled

### 5. Test Queue Processing
```bash
# Process queue
ddev drush queue:run vendor_event_comms

# Send queued emails
ddev drush mel:msg-run

# Check logs
ddev drush watchdog-show --filter=myeventlane_vendor_comms
```

### 6. Test Email Delivery
1. Check email inbox
2. Verify email received
3. Verify subject matches
4. Verify body content matches
5. Verify template styling correct

### 7. Test Audit Log
```bash
# Check log table
ddev drush sql-query "SELECT * FROM myeventlane_event_comms_log ORDER BY sent_at DESC LIMIT 5"
```

**Expected:**
- Log entries created
- Status updates correctly
- Sent/failed counts accurate
- Timestamps correct

### 8. Test Recipient Resolution
1. Create test orders for event
2. Verify recipient count displays correctly
3. Send message
4. Verify all recipients receive email
5. Verify no duplicates

---

## Edge Cases Handled

✅ **No recipients** - Form disabled, clear warning

✅ **Rate limit reached** - Form disabled, clear message

✅ **Invalid order states** - Excluded from recipients

✅ **Duplicate emails** - De-duplicated per event

✅ **Queue failure** - Status marked as failed, logged

✅ **Email send failure** - Tracked in failed_count

✅ **Access denied** - Clear error message

---

## Security Considerations

1. **Access Control:**
   - Vendor ownership verified at route level
   - Vendor ownership verified in form
   - Admin override for support

2. **Rate Limiting:**
   - Prevents spam/abuse
   - Per-event limits prevent vendor lockout
   - Configurable limits

3. **Data Privacy:**
   - Only order emails used (no attendee paragraph emails)
   - Email addresses not logged (only counts)
   - Respects order state filtering

4. **Content Safety:**
   - HTML allowed but should be sanitized by template
   - Max length limits prevent abuse
   - Confirmation required

---

## Integration Points

- **VendorOwnershipResolver** - Verifies vendor owns event
- **MessagingManager** - Queues and sends emails
- **EventRecipientResolver** - Resolves recipients from orders
- **CommsRateLimiter** - Enforces rate limits
- **Commerce Orders** - Source of recipient data
- **Event Nodes** - Target events

---

## Future Enhancements

- **Template Customization** - Allow vendors to customize templates
- **Scheduled Sends** - Schedule messages for future delivery
- **Draft Messages** - Save drafts before sending
- **Recipient Filtering** - Filter by ticket type, order date, etc.
- **Analytics** - Track open rates, click-through rates
- **Attachments** - Allow file attachments (with size limits)

---

**END OF PHASE 11 DOCUMENTATION**

