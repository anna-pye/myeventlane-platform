/**
 * @file
 * Workspace Event Studio: populate hidden location fields after Places selection.
 *
 * Mirrors the place_selected handler in mel-event-studio.js without loading the
 * full legacy wizard bundle on workspace routes.
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
          const row = {
            address_line1: components.address_line1 || formatted || '',
            address_line2: components.address_line2 || '',
            locality: components.locality || '',
            administrative_area: components.administrative_area || '',
            postal_code: components.postal_code || '',
            country_code: components.country_code || 'AU',
          };
          const form = input.closest('form');
          if (!form) {
            return;
          }
          const hLoc = form.querySelector('input[name="mel[field_location]"]');
          const hLat = form.querySelector('input[name="mel[field_location_latitude]"]');
          const hLng = form.querySelector('input[name="mel[field_location_longitude]"]');
          if (hLoc) {
            hLoc.value = JSON.stringify(row);
          }
          if (hLat && detail.lat != null) {
            hLat.value = String(detail.lat);
          }
          if (hLng && detail.lng != null) {
            hLng.value = String(detail.lng);
          }

          // Programmatic hidden-field writes do not emit native events; flag the
          // workspace shell dirty and schedule autosave (see mel-event-studio.js).
          form.dispatchEvent(new Event('input', { bubbles: true }));
          form.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    },
  };
})(Drupal, once);
