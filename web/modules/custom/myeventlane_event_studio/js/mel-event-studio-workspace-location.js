/**
 * @file
 * Event Studio workspace information — location search hidden-field sync.
 *
 * Legacy wizard bundles this in mel-event-studio.js; workspace routes attach this
 * library instead so address autocomplete and place selection still work.
 */
(function (Drupal, once) {
  'use strict';

  function setValue(field, value) {
    if (!field) {
      return;
    }
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function clearCanonicalLocation(form) {
    if (!form) {
      return;
    }
    setValue(form.querySelector('input[name="mel[field_location]"]'), '');
    setValue(form.querySelector('input[name="mel[field_location_latitude]"]'), '');
    setValue(form.querySelector('input[name="mel[field_location_longitude]"]'), '');
  }

  function resetVenue(form) {
    setValue(form.querySelector('input[name="mel[venue_saved]"]'), '');
    setValue(form.querySelector('input[name="mel[venue_create_name]"]'), '');
    setValue(form.querySelector('input[name="mel[location_search]"]'), '');
    clearCanonicalLocation(form);

    const summary = form.querySelector('[data-mel-location-summary]');
    if (summary) {
      summary.textContent = Drupal.t('Venue reset. Choose a new saved venue or address, then save.');
    }

    const mode = form.querySelector('input[name="mel[venue_mode]"]:checked');
    const nextField = mode && mode.value === 'saved'
      ? form.querySelector('input[name="mel[venue_saved]"]')
      : form.querySelector('input[name="mel[location_search]"]');
    if (nextField) {
      nextField.focus();
    }
  }

  Drupal.behaviors.melEventStudioWorkspaceLocation = {
    attach(context) {
      once('mel-event-studio-workspace-location', '.mel-location-search', context).forEach((input) => {
        let selectedValue = input.value;

        input.addEventListener('input', () => {
          if (input.value !== selectedValue) {
            clearCanonicalLocation(input.closest('form'));
          }
        });

        input.addEventListener('place_selected', (event) => {
          const detail = event.detail || {};
          const components = detail.components || {};
          const place = detail.place || {};
          const formatted =
            place.formatted_address ||
            (place.formattedAddressLines && place.formattedAddressLines.length
              ? place.formattedAddressLines.join(', ')
              : '');
          var row = {
            address_line1: components.address_line1 || formatted || '',
            address_line2: components.address_line2 || '',
            locality: components.locality || '',
            administrative_area: components.administrative_area || '',
            postal_code: components.postal_code || '',
            country_code: components.country_code || 'AU',
          };
          var form = input.closest('form');
          if (!form) {
            return;
          }
          var locationField = form.querySelector('input[name="mel[field_location]"]');
          var latitudeField = form.querySelector('input[name="mel[field_location_latitude]"]');
          var longitudeField = form.querySelector('input[name="mel[field_location_longitude]"]');
          if (locationField) {
            locationField.value = JSON.stringify(row);
          }
          if (latitudeField && detail.lat != null) {
            latitudeField.value = String(detail.lat);
          }
          if (longitudeField && detail.lng != null) {
            longitudeField.value = String(detail.lng);
          }
          selectedValue = input.value;
        });
      });

      once('mel-event-studio-venue-reset', '[data-mel-reset-venue]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const form = button.closest('form');
          if (form) {
            resetVenue(form);
          }
        });
      });
    },
  };
})(Drupal, once);
