# Phase 10: Automated Event Reminders

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Implement Humanitix-level automated event reminders sent to attendees before events

---

## Summary

Phase 10 implements an automated reminder system that sends email reminders to attendees:

1. **7 days before event start** - Early reminder
2. **24 hours before event start** - Final reminder

Reminders are:
- **Transactional** - Sent to order email addresses
- **Idempotent** - Each order receives reminders only once per window
- **Respectful** - Excludes cancelled/refunded orders
- **Branded** - Uses MEL email templates with calendar attachments

---

## Implementation Details

### Task 1: Reminder Scheduling ✅

**File:** `web/modules/custom/myeventlane_messaging/src/Service/EventReminderScheduler.php`

**Service:** `myeventlane_messaging.event_reminder_scheduler`

**Functionality:**
- Scans for events starting in reminder windows (7 days ± 1 hour, 24 hours ± 1 hour)
- Finds orders for those events via `field_target_event` on order items
- Filters orders by state:
  - **Included:** `completed`, `placed`, `fulfilled`
  - **Excluded:** `canceled`, `refunded`
- Enqueues reminder jobs to queue workers
- Uses idempotency tracking to prevent duplicate sends

**Cron Integration:**
- Added to `hook_cron()` in `myeventlane_messaging.module`
- Runs on every cron execution
- Scans both 7-day and 24-hour reminder windows

**Idempotency:**
- Uses Drupal State API to track sent reminders
- Key format: `reminder:{type}:order:{order_id}:event:{event_id}`
- Prevents duplicate emails per order/event/reminder type

---

### Task 2: Reminder Email Templates ✅

**Files:**
- `config/install/myeventlane_messaging.template.event_reminder_7d.yml`
- `config/install/myeventlane_messaging.template.event_reminder_24h.yml`

**Template Features:**
- **Branded HTML** - MEL pastel styling, mobile-friendly
- **Event Details** - Date, time, location
- **Attendee Names** - Lists all attendees from order
- **CTAs** - "View My Tickets" and "View Event Details" links
- **Calendar Attachment** - ICS file attached automatically
- **AU English** - Friendly, neutral tone

**Template Variables:**
- `event_title` - Event name
- `event_start_date` - Formatted date (e.g., "December 27, 2024")
- `event_start_time` - Formatted time (e.g., "2:00pm AEST")
- `event_location` - Event location/venue
- `attendee_names` - Array of attendee names
- `attendee_count` - Number of attendees
- `order_number` - Order number
- `my_tickets_url` - Link to order detail page
- `event_url` - Link to event page

---

### Task 3: Queue Workers ✅

**Files:**
- `src/Plugin/QueueWorker/EventReminder7dWorker.php`
- `src/Plugin/QueueWorker/EventReminder24hWorker.php`

**Queue IDs:**
- `event_reminder_7d` - 7-day reminder queue
- `event_reminder_24h` - 24-hour reminder queue

**Worker Functionality:**
1. Loads order and event entities
2. Verifies order state (must be completed/placed/fulfilled)
3. Checks idempotency (prevents duplicate sends)
4. Builds email context from order and event data
5. Generates ICS calendar attachment
6. Queues email via MessagingManager
7. Marks reminder as sent

**Error Handling:**
- Logs errors for missing orders/events
- Skips orders with invalid states
- Handles ICS generation failures gracefully
- Logs all reminder sends for audit

---

### Task 4: Opt-Out Safety ✅

**Transactional Emails:**
- Reminders are transactional, not marketing
- Always sent to order email address
- No opt-out mechanism (transactional requirement)

**Order State Filtering:**
- Only processes orders in states: `completed`, `placed`, `fulfilled`
- Excludes: `canceled`, `refunded`
- Verifies order state at both scheduler and worker level

**Event State Filtering:**
- Skips cancelled or ended events
- Checks `field_event_state` if available

---

### Task 5: Logging & Testing ✅

**Logging:**
- All reminder sends logged with order ID, event ID, and email
- Errors logged with context
- Idempotency skips logged as info

**Log Channels:**
- `myeventlane_messaging` logger channel

**Documentation:**
- This file (`phase-10-reminders.md`)
- Manual testing instructions included

---

## Files Created/Modified

### New Files:
1. `src/Service/EventReminderScheduler.php` - Scheduler service
2. `src/Plugin/QueueWorker/EventReminder7dWorker.php` - 7-day reminder worker
3. `src/Plugin/QueueWorker/EventReminder24hWorker.php` - 24-hour reminder worker
4. `config/install/myeventlane_messaging.template.event_reminder_7d.yml` - 7-day template
5. `config/install/myeventlane_messaging.template.event_reminder_24h.yml` - 24-hour template
6. `docs/phase-10-reminders.md` - This documentation

### Modified Files:
1. `myeventlane_messaging.services.yml` - Added scheduler service
2. `myeventlane_messaging.module` - Added cron hook

---

## Service Configuration

```yaml
myeventlane_messaging.event_reminder_scheduler:
  class: Drupal\myeventlane_messaging\Service\EventReminderScheduler
  arguments:
    - '@entity_type.manager'
    - '@queue'
    - '@datetime.time'
    - '@logger.channel.myeventlane_messaging'
```

---

## Queue Configuration

**Queue Names:**
- `event_reminder_7d` - Processed by `EventReminder7dWorker`
- `event_reminder_24h` - Processed by `EventReminder24hWorker`

**Cron Time:**
- Both workers configured with `cron = {"time" = 60}`

---

## Idempotency Tracking

**State API Keys:**
- Format: `myeventlane_messaging.reminder:{type}:order:{order_id}:event:{event_id}`
- Example: `myeventlane_messaging.reminder:reminder_7d:order:123:event:456`
- Value: `TRUE` when reminder sent

**Benefits:**
- Prevents duplicate emails
- Survives queue retries
- Survives cron re-runs
- Simple, reliable tracking

---

## Email Template Structure

### 7-Day Reminder
- **Subject:** "Your event is coming up in 7 days – {Event Title}"
- **Tone:** Friendly, informative
- **Content:** Event details, attendee names, CTAs

### 24-Hour Reminder
- **Subject:** "Your event is tomorrow – {Event Title}"
- **Tone:** Urgent but friendly
- **Content:** Event details, attendee names, CTAs

**Both Include:**
- Calendar (.ics) attachment
- "View My Tickets" link
- "View Event Details" link
- Order number reference

---

## Manual Test Steps

### 1. Setup Test Event
1. Create an event with start date:
   - 7 days from now (for 7-day reminder)
   - 24 hours from now (for 24-hour reminder)
2. Create a completed order for the event
3. Ensure order has valid email address

### 2. Test Scheduler
```bash
# Run cron manually
ddev drush cron

# Check logs for scheduler activity
ddev drush watchdog-show --filter=myeventlane_messaging
```

**Expected:**
- Scheduler logs: "Scheduled reminder_7d reminder for order X, event Y"
- Queue items created

### 3. Test Queue Workers
```bash
# Process queue manually
ddev drush queue:run event_reminder_7d
ddev drush queue:run event_reminder_24h

# Check queue status
ddev drush queue:list
```

**Expected:**
- Queue items processed
- Emails queued to messaging queue
- Logs: "Sent 7-day reminder for order X, event Y to email@example.com"

### 4. Test Email Delivery
```bash
# Process messaging queue
ddev drush queue:run myeventlane_messaging

# Check email logs
ddev drush watchdog-show --filter=myeventlane_messaging
```

**Expected:**
- Email sent successfully
- ICS attachment included
- Email received at order email address

### 5. Test Idempotency
1. Run cron again
2. Verify no duplicate reminders sent
3. Check logs for "Reminder already sent" messages

**Expected:**
- No duplicate emails
- Idempotency checks prevent re-sending

### 6. Test Order State Filtering
1. Create order in `canceled` state
2. Run cron
3. Verify no reminder sent

**Expected:**
- Canceled orders excluded
- No reminder queued

### 7. Test Event State Filtering
1. Create cancelled event
2. Run cron
3. Verify no reminders sent

**Expected:**
- Cancelled events excluded
- No reminders queued

---

## Edge Cases Handled

✅ **No orders for event** - Scheduler skips gracefully

✅ **Order without email** - Worker logs warning, skips

✅ **Event without start date** - Scheduler excludes from scan

✅ **Multiple order items per order** - Scheduler processes order only once

✅ **ICS generation failure** - Worker continues, logs warning

✅ **Queue processing failure** - Worker logs error, item remains in queue for retry

✅ **Cron re-run** - Idempotency prevents duplicate sends

---

## Integration Points

- **MessagingManager** - Queues and sends emails
- **IcsGenerator** - Generates calendar attachments
- **State API** - Tracks sent reminders (idempotency)
- **Commerce Orders** - Source of order data
- **Event Nodes** - Source of event data
- **Order Items** - Links orders to events via `field_target_event`

---

## Security Considerations

1. **Email Privacy:**
   - Reminders sent only to order email
   - No email addresses in logs (only hashes if needed)

2. **Access Control:**
   - No customer-facing changes
   - No access control modifications
   - Uses existing order/event access

3. **Data Integrity:**
   - Verifies order state before sending
   - Verifies event state before sending
   - Idempotency prevents duplicate sends

---

## Performance Considerations

1. **Cron Efficiency:**
   - Scans only events in reminder windows (±1 hour)
   - Processes orders in batches
   - Uses entity queries efficiently

2. **Queue Processing:**
   - Workers process one item at a time
   - Errors don't block other reminders
   - Retry mechanism for failed items

3. **State API:**
   - Lightweight tracking
   - No database queries for idempotency checks
   - Fast lookups

---

## Future Enhancements

- **SMS Reminders** - Add SMS option for 24-hour reminder
- **Vendor Configuration** - Allow vendors to enable/disable reminders per event
- **Custom Schedules** - Allow vendors to set custom reminder times
- **Reminder Preferences** - Allow customers to opt-out of specific reminder types
- **Analytics** - Track reminder open rates and click-through rates

---

**END OF PHASE 10 DOCUMENTATION**

