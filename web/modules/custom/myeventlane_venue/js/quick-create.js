/**
 * @file
 * Venue quick create modal functionality.
 *
 * Integrates with myeventlane_location autocomplete:
 * - Listens for place_selected events from location search.
 * - Populates venue name and address fields from selected place.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Handle venue quick create form interactions.
   */
  Drupal.behaviors.venueQuickCreate = {
    attach: function (context) {
      // Listen for venueCreated event dispatched from the modal.
      var $body = $('body', context);
      if (!$body.data('venue-quick-create-attached')) {
        $body.data('venue-quick-create-attached', true);
        $body.on('venueCreated', function (e, data) {
          if (data && data.venue_id) {
            Drupal.behaviors.venueQuickCreate.handleVenueCreated(data);
          }
        });
      }

      // Initialize place selection handling for quick create forms.
      var formWrappers = once('venue-quick-create-place', '.mel-venue-quick-create', context);
      formWrappers.forEach(function (wrapper) {
        Drupal.behaviors.venueQuickCreate.initPlaceSelection(wrapper);
      });

      // Handle delete confirmation
      var deleteButtons = once('venue-delete-confirm', '.mel-venue-delete-btn', context);
      deleteButtons.forEach(function (btn) {
        $(btn).on('click', function (e) {
          var venueName = $(this).data('venue-name');
          if (!confirm(Drupal.t('Are you sure you want to delete "@name"? This cannot be undone.', { '@name': venueName }))) {
            e.preventDefault();
            return false;
          }
        });
      });
    },

    /**
     * Initialize place selection handling.
     *
     * Listens for place_selected event from myeventlane_location autocomplete
     * and populates the venue name and address fields.
     *
     * @param {HTMLElement} wrapper
     *   The form wrapper element.
     */
    initPlaceSelection: function (wrapper) {
      var $wrapper = $(wrapper);
      var $searchInput = $wrapper.find('.myeventlane-location-address-search');
      var $venueNameField = $wrapper.find('.myeventlane-venue-name-field');
      var $addressField = $wrapper.find('.mel-venue-address-field');
      var $latField = $wrapper.find('.myeventlane-location-latitude-field');
      var $lngField = $wrapper.find('.myeventlane-location-longitude-field');

      console.log('[Venue Quick Create] Initializing, search input found:', $searchInput.length > 0);

      if (!$searchInput.length) {
        return;
      }

      // Store reference for populateFields
      var self = this;

      // Listen for place_selected custom event from location autocomplete.
      $searchInput[0].addEventListener('place_selected', function (e) {
        console.log('[Venue Quick Create] place_selected event received:', e.detail);
        self.populateFields(e.detail, $venueNameField, $addressField, $latField, $lngField);
      });

      // Also listen via jQuery for compatibility.
      $searchInput.on('place_selected', function (e) {
        var detail = e.originalEvent && e.originalEvent.detail;
        if (detail) {
          console.log('[Venue Quick Create] place_selected (jQuery) event received:', detail);
          self.populateFields(detail, $venueNameField, $addressField, $latField, $lngField);
        }
      });

      // Fallback: Check if Google Places Autocomplete is attached and hook into it.
      setTimeout(function () {
        if (window.google && window.google.maps && window.google.maps.places) {
          var existingAutocomplete = $searchInput.data('google-autocomplete');
          if (!existingAutocomplete && !$searchInput.data('mel-autocomplete-attached')) {
            console.log('[Venue Quick Create] Setting up Google autocomplete fallback listener');
            // The autocomplete is managed by myeventlane_location, but we can
            // observe value changes as a fallback.
            $searchInput.on('blur', function () {
              // Small delay to let autocomplete populate
              setTimeout(function () {
                var searchVal = $searchInput.val();
                if (searchVal && !$addressField.val()) {
                  // Use search value as address if nothing else is populated
                  $addressField.val(searchVal).trigger('change');
                }
              }, 100);
            });
          }
        }
      }, 500);
    },

    /**
     * Populate form fields from place data.
     */
    populateFields: function (detail, $venueNameField, $addressField, $latField, $lngField) {
      if (!detail) return;

      var placeName = '';
      var formattedAddress = '';
      var lat = null;
      var lng = null;

      // Extract data based on provider (Google or Apple).
      if (detail.place) {
        // Google Maps: place has name and formatted_address.
        if (detail.place.name) {
          placeName = detail.place.name;
        }
        if (detail.place.formatted_address) {
          formattedAddress = detail.place.formatted_address;
        }
        // Apple Maps: place has formattedAddressLines array.
        if (detail.place.formattedAddressLines && detail.place.formattedAddressLines.length) {
          formattedAddress = detail.place.formattedAddressLines.join(', ');
        }
      }

      // Get coordinates.
      if (typeof detail.lat === 'number') {
        lat = detail.lat;
      }
      if (typeof detail.lng === 'number') {
        lng = detail.lng;
      }

      // Build address from components if available.
      if (detail.components) {
        var c = detail.components;
        var addressParts = [];
        if (c.address_line1) {
          addressParts.push(c.address_line1);
        }
        if (c.locality) {
          addressParts.push(c.locality);
        }
        if (c.administrative_area) {
          addressParts.push(c.administrative_area);
        }
        if (c.postal_code) {
          addressParts.push(c.postal_code);
        }
        if (addressParts.length) {
          formattedAddress = addressParts.join(', ');
        }

        // Use component name if place name is empty.
        if (!placeName && c.name) {
          placeName = c.name;
        }
      }

      // Populate venue name field if empty.
      if (placeName && $venueNameField.length) {
        var currentName = $venueNameField.val().trim();
        if (!currentName) {
          $venueNameField.val(placeName);
          $venueNameField.trigger('change');
          console.log('[Venue Quick Create] Populated venue name:', placeName);
        }
      }

      // Populate address field.
      if (formattedAddress && $addressField.length) {
        $addressField.val(formattedAddress);
        $addressField.trigger('change');
        console.log('[Venue Quick Create] Populated address:', formattedAddress);
      }

      // Populate coordinates.
      if (lat !== null && $latField.length) {
        $latField.val(lat.toFixed(7));
      }
      if (lng !== null && $lngField.length) {
        $lngField.val(lng.toFixed(7));
      }

      console.log('[Venue Quick Create] Place fields populated:', {
        name: placeName,
        address: formattedAddress,
        lat: lat,
        lng: lng
      });
    },

    /**
     * Handle successful venue creation.
     *
     * @param {Object} data
     *   The venue data with venue_id and venue_name.
     */
    handleVenueCreated: function (data) {
      // Check if we're in the event wizard context.
      var $venueField = $('[data-venue-autocomplete]');
      if ($venueField.length) {
        // Update the venue autocomplete field.
        $venueField.val(data.venue_id + ': ' + data.venue_name);
        $venueField.trigger('change');

        // Trigger AJAX to reload locations.
        var $locationsSelect = $('[data-venue-locations-select]');
        if ($locationsSelect.length) {
          $locationsSelect.trigger('venue:selected', [data.venue_id]);
        }
      }

      // If we're on the venues list page, reload.
      if (window.location.pathname.indexOf('/vendor/settings/venues') !== -1) {
        window.location.reload();
      }
    }
  };

})(jQuery, Drupal, once);
