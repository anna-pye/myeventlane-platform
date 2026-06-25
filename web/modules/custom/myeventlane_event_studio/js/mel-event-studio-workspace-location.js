/**
 * @file
 * Event Studio workspace information — location search hidden-field sync.
 *
 * Legacy wizard bundles this in mel-event-studio.js; workspace routes attach this
 * library instead so address autocomplete and place selection still work.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melEventStudioWorkspaceLocation = {
    attach(context) {
      once('mel-event-studio-workspace-location', '.mel-location-search', context).forEach((input) => {
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
        });
      });
    },
  };
})(Drupal, once);
