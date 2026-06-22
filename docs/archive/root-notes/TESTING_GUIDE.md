# Testing Guide: Event State Machine + Capacity + Check-in

## Prerequisites

1. **Enable modules:**
   ```bash
   ddev drush en myeventlane_event_state myeventlane_capacity myeventlane_checkin -y
   ```

2. **Run updates (creates fields):**
   ```bash
   ddev drush updb -y
   ```

3. **Import configuration (adds permissions):**
   ```bash
   ddev drush cim -y
   ```

4. **Clear cache:**
   ```bash
   ddev drush cr
   ```

## Test 1: Event State Machine

### Test State Resolution

1. **Create a test event:**
   - Go to `/node/add/event`
   - Fill in basic details
   - Set `Event Start` to a future date (e.g., 2 weeks from now)
   - Set `Event End` to 1 day after start
   - **Leave `Sales Start` and `Sales End` empty** (should default to publish time and event end)
   - Save as published

2. **Check state:**
   ```bash
   ddev drush php:eval "
     \$event = \Drupal::entityTypeManager()->getStorage('node')->load(1); // Replace 1 with your event ID
     \$resolver = \Drupal::service('myeventlane_event_state.resolver');
     \$state = \$resolver->resolveState(\$event);
     echo 'Current state: ' . \$state . PHP_EOL;
     if (\$event->hasField('field_event_state')) {
       echo 'Materialized state: ' . \$event->get('field_event_state')->value . PHP_EOL;
     }
   "
   ```
   - Should show `scheduled` if sales haven't started yet
   - Should show `live` if sales are open

3. **Test sales windows:**
   - Edit the event
   - Set `Sales Start` to yesterday
   - Set `Sales End` to tomorrow
   - Save
   - Check state again - should be `live`

4. **Test state override:**
   - Edit the event
   - Set `State Override` to `cancelled`
   - Save
   - Check state - should be `cancelled` regardless of timing

5. **Test ended state:**
   - Edit the event
   - Set `Event End` to yesterday
   - Save
   - Check state - should be `ended`

## Test 2: Capacity Engine

### Test Capacity Tracking

1. **Set capacity on an event:**
   - Edit an event
   - Set `Total Capacity` to `10`
   - Save

2. **Check capacity:**
   ```bash
   ddev drush php:eval "
     \$event = \Drupal::entityTypeManager()->getStorage('node')->load(1); // Replace with event ID
     \$capacity = \Drupal::service('myeventlane_capacity.service');
     echo 'Total capacity: ' . (\$capacity->getCapacityTotal(\$event) ?? 'unlimited') . PHP_EOL;
     echo 'Sold count: ' . \$capacity->getSoldCount(\$event) . PHP_EOL;
     echo 'Remaining: ' . (\$capacity->getRemaining(\$event) ?? 'unlimited') . PHP_EOL;
     echo 'Sold out: ' . (\$capacity->isSoldOut(\$event) ? 'yes' : 'no') . PHP_EOL;
   "
   ```

3. **Test RSVP capacity enforcement:**
   - Create an RSVP event with capacity = 2
   - Submit 2 RSVPs (should work)
   - Try to submit a 3rd RSVP (should fail with capacity error)

4. **Test ticket capacity enforcement:**
   - Create a paid event with capacity = 5
   - Add 5 tickets to cart (should work)
   - Try to add 6th ticket (should fail in form validation)

## Test 3: Vendor Cancel Event

1. **As a vendor user:**
   - Go to `/vendor/events/{event_id}/cancel` (replace with your event ID)
   - Should see cancel form

2. **Cancel the event:**
   - Fill in cancellation reason
   - Check "Notify attendees" (optional)
   - Submit

3. **Verify cancellation:**
   ```bash
   ddev drush php:eval "
     \$event = \Drupal::entityTypeManager()->getStorage('node')->load(1); // Replace with event ID
     echo 'State override: ' . (\$event->get('field_event_state_override')->value ?? 'none') . PHP_EOL;
     echo 'Cancel reason: ' . (\$event->get('field_cancel_reason')->value ?? 'none') . PHP_EOL;
     echo 'Cancelled at: ' . (\$event->get('field_cancelled_at')->date ? \$event->get('field_cancelled_at')->date->format('Y-m-d H:i:s') : 'none') . PHP_EOL;
   "
   ```

4. **Check state:**
   - Event state should be `cancelled`
   - Try to RSVP/book tickets - should be blocked

## Test 4: Vendor Refund Request

1. **Create a paid event with completed orders:**
   - Create a paid event
   - Complete at least one order (purchase tickets)
   - Ensure order is in `completed` state

2. **View refunds page:**
   - As vendor, go to `/vendor/events/{event_id}/refunds`
   - Should see list of completed orders

3. **Request refund:**
   - Click "Request Refund" for an order
   - Fill in reason
   - Submit
   - Check logs:
     ```bash
     ddev drush wd-show --type=myeventlane_event_state --count=5
     ```

## Test 5: Check-in System

### Test Check-in Page

1. **Access check-in:**
   - As event owner, go to `/vendor/events/{event_id}/check-in`
   - Should see check-in dashboard

2. **View attendees:**
   - Should see list of RSVP and ticket attendees
   - Shows checked-in status

3. **Toggle check-in:**
   - Click "Check in" button for an attendee
   - Should toggle status (AJAX)
   - Refresh page - status should persist

4. **Search attendees:**
   - Type in search box
   - Should filter results (AJAX)

5. **Test QR scan page:**
   - Go to `/vendor/events/{event_id}/check-in/scan`
   - Should see QR scanner interface
   - (Note: QR library integration needed for actual scanning)

6. **Test list view:**
   - Go to `/vendor/events/{event_id}/check-in/list`
   - Should see table of all attendees

### Verify Check-in Data

```bash
ddev drush php:eval "
  // Check RSVP check-in
  \$rsvpStorage = \Drupal::entityTypeManager()->getStorage('rsvp_submission');
  \$rsvps = \$rsvpStorage->loadByProperties(['event_id' => 1]); // Replace with event ID
  foreach (\$rsvps as \$rsvp) {
    echo 'RSVP ' . \$rsvp->id() . ': ' . (\$rsvp->get('checked_in')->value ? 'Checked in' : 'Not checked in') . PHP_EOL;
  }
  
  // Check ticket attendee check-in
  \$attendeeStorage = \Drupal::entityTypeManager()->getStorage('event_attendee');
  \$attendees = \$attendeeStorage->loadByProperties(['event' => 1]); // Replace with event ID
  foreach (\$attendees as \$attendee) {
    echo 'Attendee ' . \$attendee->id() . ': ' . (\$attendee->get('checked_in')->value ? 'Checked in' : 'Not checked in') . PHP_EOL;
  }
"
```

## Test 6: State Materialization

### Test Automatic State Updates

1. **Create event and check state:**
   - Create event, save
   - State should be materialized in `field_event_state`

2. **Submit RSVP and check state:**
   - Submit an RSVP
   - If capacity reached, state should update to `sold_out`
   ```bash
   ddev drush php:eval "
     \$event = \Drupal::entityTypeManager()->getStorage('node')->load(1);
     echo 'State after RSVP: ' . \$event->get('field_event_state')->value . PHP_EOL;
   "
   ```

3. **Complete order and check state:**
   - Complete a ticket order
   - If capacity reached, state should update to `sold_out`

4. **Test cron resync:**
   ```bash
   ddev drush cron
   ddev drush php:eval "
     \$event = \Drupal::entityTypeManager()->getStorage('node')->load(1);
     echo 'State after cron: ' . \$event->get('field_event_state')->value . PHP_EOL;
   "
   ```

## Test 7: Integration Points

### Test RSVP Form with Capacity

1. **Create RSVP event with capacity = 1**
2. **Submit first RSVP** - should succeed
3. **Try to submit second RSVP** - should show error: "Event is sold out" or capacity exceeded

### Test Ticket Form with Capacity

1. **Create paid event with capacity = 3**
2. **Add 3 tickets to cart** - should succeed
3. **Try to add 4th ticket** - form validation should fail

### Test State-Based UI (Manual)

1. **Check event page:**
   - View event node
   - Check if state badge appears (if template updated)
   - Check if CTA buttons reflect state

2. **Check vendor dashboard:**
   - Go to vendor dashboard
   - Check if events show state pills
   - Check if "Cancel" and "Check-in" quick actions appear

## Quick Test Script

Run this to test all core functionality:

```bash
ddev drush php:eval "
  // Test 1: State Resolver
  echo '=== Testing State Resolver ===' . PHP_EOL;
  \$resolver = \Drupal::service('myeventlane_event_state.resolver');
  \$event = \Drupal::entityTypeManager()->getStorage('node')->load(1); // Replace with event ID
  if (\$event) {
    \$state = \$resolver->resolveState(\$event);
    echo 'State: ' . \$state . PHP_EOL;
  }
  
  // Test 2: Capacity Service
  echo PHP_EOL . '=== Testing Capacity Service ===' . PHP_EOL;
  \$capacity = \Drupal::service('myeventlane_capacity.service');
  if (\$event) {
    echo 'Total: ' . (\$capacity->getCapacityTotal(\$event) ?? 'unlimited') . PHP_EOL;
    echo 'Sold: ' . \$capacity->getSoldCount(\$event) . PHP_EOL;
    echo 'Remaining: ' . (\$capacity->getRemaining(\$event) ?? 'unlimited') . PHP_EOL;
    echo 'Sold out: ' . (\$capacity->isSoldOut(\$event) ? 'yes' : 'no') . PHP_EOL;
  }
  
  // Test 3: Check-in Storage
  echo PHP_EOL . '=== Testing Check-in Storage ===' . PHP_EOL;
  \$checkin = \Drupal::service('myeventlane_checkin.storage');
  if (\$event) {
    \$attendees = \$checkin->getAttendees(\$event);
    echo 'Total attendees: ' . count(\$attendees) . PHP_EOL;
    \$checkedIn = count(array_filter(\$attendees, fn(\$a) => \$a['checked_in']));
    echo 'Checked in: ' . \$checkedIn . PHP_EOL;
  }
  
  echo PHP_EOL . '=== All services loaded successfully ===' . PHP_EOL;
"
```

## Common Issues

### Fields not created
- Run `ddev drush updb -y` again
- Check if install hook ran: `ddev drush php:eval "echo function_exists('myeventlane_event_state_install') ? 'exists' : 'missing';"`

### Permissions not added
- Run `ddev drush cim -y`
- Or manually check: `ddev drush config:get user.role.vendor permissions`

### State not updating
- Clear cache: `ddev drush cr`
- Check if hooks are firing: Enable devel module and check logs
- Manually trigger: `ddev drush php:eval "\$event = node_load(1); \$event->save();"`

### Capacity not counting
- Check if RSVP/order entities have correct event reference
- Verify order state is `completed`
- Check cache: `ddev drush php:eval "\Drupal::service('cache.default')->delete('capacity_sold:1');"`

## Next Steps

After testing:
1. Update frontend templates to show state badges (TODO #9)
2. Update vendor dashboard with state pills and quick actions (TODO #10)
3. Integrate QR scanning library for check-in
4. Set up email notifications for cancellations
5. Implement vendor staff roles for check-in access
