# Files Created/Modified

## New Modules

### myeventlane_event_state
- `web/modules/custom/myeventlane_event_state/myeventlane_event_state.info.yml`
- `web/modules/custom/myeventlane_event_state/myeventlane_event_state.services.yml`
- `web/modules/custom/myeventlane_event_state/myeventlane_event_state.module`
- `web/modules/custom/myeventlane_event_state/myeventlane_event_state.install`
- `web/modules/custom/myeventlane_event_state/myeventlane_event_state.routing.yml`
- `web/modules/custom/myeventlane_event_state/myeventlane_event_state.permissions.yml`
- `web/modules/custom/myeventlane_event_state/src/Service/EventStateResolverInterface.php`
- `web/modules/custom/myeventlane_event_state/src/Service/EventStateResolver.php`
- `web/modules/custom/myeventlane_event_state/src/Form/EventCancelForm.php`
- `web/modules/custom/myeventlane_event_state/src/Form/EventRefundRequestForm.php`
- `web/modules/custom/myeventlane_event_state/src/Controller/EventRefundsController.php`

### myeventlane_capacity
- `web/modules/custom/myeventlane_capacity/myeventlane_capacity.info.yml`
- `web/modules/custom/myeventlane_capacity/myeventlane_capacity.services.yml`
- `web/modules/custom/myeventlane_capacity/myeventlane_capacity.module`
- `web/modules/custom/myeventlane_capacity/src/Service/EventCapacityServiceInterface.php`
- `web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php`
- `web/modules/custom/myeventlane_capacity/src/Exception/CapacityExceededException.php`

### myeventlane_checkin
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.info.yml`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.services.yml`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.module`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.routing.yml`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.permissions.yml`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.libraries.yml`
- `web/modules/custom/myeventlane_checkin/src/Service/CheckInStorageInterface.php`
- `web/modules/custom/myeventlane_checkin/src/Service/CheckInStorage.php`
- `web/modules/custom/myeventlane_checkin/src/Controller/CheckInController.php`
- `web/modules/custom/myeventlane_checkin/templates/checkin-page.html.twig`
- `web/modules/custom/myeventlane_checkin/templates/checkin-list.html.twig`
- `web/modules/custom/myeventlane_checkin/templates/checkin-scan.html.twig`
- `web/modules/custom/myeventlane_checkin/css/checkin.css`
- `web/modules/custom/myeventlane_checkin/css/scan.css`
- `web/modules/custom/myeventlane_checkin/js/checkin.js`

## Modified Files

- `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php` - Added capacity validation
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php` - Added capacity validation
- `web/sites/default/config/sync/user.role.vendor.yml` - Added new permissions

## Documentation

- `IMPLEMENTATION_SUMMARY.md` - Complete implementation documentation
- `FILES_CREATED.md` - This file

## Installation Commands

```bash
# Enable modules
ddev drush en myeventlane_event_state myeventlane_capacity myeventlane_checkin -y

# Run updates (creates fields)
ddev drush updb -y

# Import configuration (adds permissions)
ddev drush cim -y

# Clear cache
ddev drush cr
```
