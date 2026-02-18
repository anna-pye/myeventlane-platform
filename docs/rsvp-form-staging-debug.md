# RSVP Form Staging Debug

If the RSVP form on `/event/{nid}/book` shows an empty or incorrect form on staging, run these checks.

## 1. Verify modules and cache

```bash
# From project root (or via ddev)
drush pm:list --status=enabled | grep -E "myeventlane_rsvp|myeventlane_commerce|myeventlane_legal"

# Clear all caches (essential after deploy)
drush cr
```

## 2. Check event mode

The RSVP form is used when the event's CTA type is "rsvp". If it's "paid", "external", or "none", a different form/placeholder is shown.

```bash
drush ev "
\$node = \Drupal::entityTypeManager()->getStorage('node')->load(1099);
if (\$node) {
  \$resolver = \Drupal::service('myeventlane_event.cta_resolver');
  \$mode = \Drupal::service('myeventlane_event.event_mode_manager')->getEffectiveMode(\$node);
  echo 'CTA: ' . \$resolver->getCtaType(\$node) . ', Mode: ' . \$mode;
}
"
```

## 3. Verify form class resolution

Ensure the book page uses the correct form:

```bash
# Visit /event/1099/book, then inspect the form element's class.
# It should show: myeventlane-rsvp-public-form
# If it shows myeventlane-commerce-rsvp-booking-form, the event has a free product.
```

## 4. Recent fixes applied

- **Form ID conflict removed**: `myeventlane_event_attendees` had a duplicate `RsvpPublicForm` with the same form ID as `myeventlane_rsvp`. Renamed to `myeventlane_event_attendees_public_rsvp_form` to avoid conflicts.
- **Session cache context**: Added to the book page build so cached output varies per session.
- **Template fallbacks**: Form template supports both `myeventlane_rsvp` (name, email, phone, guests) and `myeventlane_event_attendees` structures, plus a fallback for unexpected forms.
