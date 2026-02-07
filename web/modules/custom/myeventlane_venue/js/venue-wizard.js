/**
 * @file
 * Venue integration for Event Wizard.
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Handle venue selection in Event Wizard.
   */
  Drupal.behaviors.venueWizardIntegration = {
    attach: function (context) {
      // Initialize venue choice radio buttons.
      var venueChoices = once('venue-wizard-choice', '.mel-venue-choice', context);
      venueChoices.forEach(function (choiceGroup) {
        Drupal.behaviors.venueWizardIntegration.initVenueChoiceToggle(choiceGroup);
      });

      // Initialize existing venue selection.
      var existingVenues = once('venue-wizard-existing', '[data-venue-autocomplete]', context);
      existingVenues.forEach(function (venueField) {
        Drupal.behaviors.venueWizardIntegration.initExistingVenueSelection(venueField, context);
      });

      // Initialize create venue location search.
      var createSearches = once('venue-wizard-create', '.mel-venue-location-trigger', context);
      createSearches.forEach(function (searchField) {
        Drupal.behaviors.venueWizardIntegration.initCreateVenueSearch(searchField, context);
      });

      // Initialize manual entry location search.
      var manualSearches = once('venue-wizard-manual', '.mel-manual-location-search', context);
      manualSearches.forEach(function (searchField) {
        Drupal.behaviors.venueWizardIntegration.initManualEntrySearch(searchField, context);
      });

      // Listen for venue created events from modal (only once on body).
      var $body = $('body', context);
      if (!$body.data('venue-wizard-listener-attached')) {
        $body.data('venue-wizard-listener-attached', true);
        $body.on('venueCreated', function (e, data) {
          if (data && data.venue_id) {
            Drupal.behaviors.venueWizardIntegration.handleVenueCreated(data);
          }
        });
      }
    },

    /**
     * Initialize venue choice toggle to show/hide manual location fields.
     */
    initVenueChoiceToggle: function (choiceGroup) {
      var $choiceGroup = $(choiceGroup);
      var $form = $choiceGroup.closest('form');
      var $manualFields = $form.find('.mel-manual-location-fields, .field--name-field-location');
      var previousChoice = null;

      // Clear all venue-related data from a specific section.
      var clearVenueData = function (section) {
        console.log('[Venue Wizard] Clearing venue data for section:', section);

        if (section === 'existing' || section === 'all') {
          // Clear existing venue selection.
          $form.find('[data-venue-autocomplete]').val('');
          $form.find('[data-venue-locations-select]').html('<option value="">- Select a venue first -</option>');
          $form.find('.mel-selected-venue-location-id').val('');
        }

        if (section === 'create' || section === 'all') {
          // Clear create venue fields.
          $form.find('.mel-venue-location-trigger').val('');
          $form.find('.mel-new-venue-name').val('');
          $form.find('.mel-new-venue-address').val('');
          $form.find('.mel-new-venue-lat').val('');
          $form.find('.mel-new-venue-lng').val('');
          $form.find('.mel-created-venue-location-id').val('');
          $form.find('.mel-created-venue-id').val('');
          // Reset the complete venue button URL.
          var $completeBtn = $form.find('.mel-complete-venue-btn');
          if ($completeBtn.length) {
            var baseUrl = $completeBtn.attr('href').split('?')[0];
            $completeBtn.attr('href', baseUrl);
          }
        }

        if (section === 'skip' || section === 'all') {
          // Clear manual entry fields.
          $form.find('.mel-manual-location-search').val('');
          // Clear address widget fields.
          Drupal.behaviors.venueWizardIntegration.clearAddressWidget($form);
        }

        // Always clear all venue name fields when switching sections.
        // The form has both a visible venue_name field and hidden field_venue_name.

        // 1. Clear the custom wizard venue_name field (visible).
        var $wizardVenueName = $form.find('input[name="_venue_wrapper[venue_name]"]');
        if ($wizardVenueName.length) {
          $wizardVenueName.val('');
          console.log('[Venue Wizard] Cleared wizard venue name field');
        }

        // 2. Clear fields with the class we set.
        var $classedField = $form.find('input.myeventlane-venue-name-field');
        if (!$classedField.length) {
          $classedField = $form.find('.myeventlane-venue-name-field input[type="text"]');
        }
        if ($classedField.length) {
          $classedField.val('');
          console.log('[Venue Wizard] Cleared classed venue name field:', $classedField.attr('name'));
        }

        // 3. Also clear the hidden field_venue_name if it exists.
        $form.find('input[name*="field_venue_name"]').val('');
        console.log('[Venue Wizard] Cleared hidden field_venue_name fields');
      };

      // Initial state based on current selection.
      var updateVisibility = function (isInitial) {
        var selected = $choiceGroup.find('input:checked').val();
        console.log('[Venue Wizard] Venue choice changed to:', selected);

        // Clear data from OTHER sections when switching (not on initial load).
        if (!isInitial && previousChoice !== null && previousChoice !== selected) {
          console.log('[Venue Wizard] Switching from', previousChoice, 'to', selected, '- clearing previous data');

          // Clear all sections except the one we're switching to.
          if (selected !== 'existing') {
            clearVenueData('existing');
          }
          if (selected !== 'create') {
            clearVenueData('create');
          }
          if (selected !== 'skip') {
            clearVenueData('skip');
          }
        }

        previousChoice = selected;

        if (selected === 'skip') {
          $manualFields.removeClass('js-hide').show();
        } else {
          $manualFields.addClass('js-hide').hide();
        }

        // If switching to "create" or "skip", initialize location autocomplete for the search field.
        if (selected === 'create') {
          Drupal.behaviors.venueWizardIntegration.initLocationAutocomplete($form, 'create');
        } else if (selected === 'skip') {
          Drupal.behaviors.venueWizardIntegration.initLocationAutocomplete($form, 'skip');
        }
      };

      // Listen for changes.
      $choiceGroup.on('change', 'input', function () {
        updateVisibility(false);
      });

      // Set initial visibility (don't clear data on page load).
      updateVisibility(true);
    },

    /**
     * Clear the address widget fields in the form.
     */
    clearAddressWidget: function ($form) {
      // Find the address widget containers.
      var $addressWidget = $form.find('.field--name-field-location, .field--name-field-venue-address');

      // Clear common address field inputs.
      $addressWidget.find('input[name*="address_line1"]').val('');
      $addressWidget.find('input[name*="address_line2"]').val('');
      $addressWidget.find('input[name*="address_line3"]').val('');
      $addressWidget.find('input[name*="locality"]').val('');
      $addressWidget.find('input[name*="administrative_area"]').val('');
      $addressWidget.find('select[name*="administrative_area"]').val('');
      $addressWidget.find('input[name*="postal_code"]').val('');
      $addressWidget.find('input[name*="organization"]').val('');

      // Also clear any top-level address search field.
      $form.find('.mel-address-search-input, [data-address-search]').not('.mel-venue-location-trigger').not('.mel-manual-location-search').val('');

      // Clear hidden lat/lng fields throughout the form.
      $form.find('input[name*="latitude"]').val('');
      $form.find('input[name*="longitude"]').val('');
      $form.find('.myeventlane-location-latitude-field').val('');
      $form.find('.myeventlane-location-longitude-field').val('');

      // Clear field_location hidden fields that may contain coordinates.
      $form.find('input[name*="field_location"][name*="lat"]').val('');
      $form.find('input[name*="field_location"][name*="lng"]').val('');

      console.log('[Venue Wizard] Cleared address widget fields');
    },

    /**
     * Initialize location autocomplete for the venue creation or manual entry search field.
     *
     * @param {jQuery} $form - The form element.
     * @param {string} mode - 'create' for new venue, 'skip' for manual entry.
     */
    initLocationAutocomplete: function ($form, mode) {
      var searchField;

      if (mode === 'skip') {
        // Manual entry mode - use the manual location search field.
        searchField = $form.find('.mel-manual-location-search, .mel-venue-manual-container .myeventlane-location-address-search')[0];
      } else {
        // Create venue mode - use the venue creation search field.
        searchField = $form.find('.mel-venue-location-trigger, .mel-venue-create-container .myeventlane-location-address-search')[0];
      }

      if (!searchField) {
        console.log('[Venue Wizard] No search field found for location autocomplete in mode:', mode);
        return;
      }

      // Check if already initialized.
      if (searchField.dataset.melAutocompleteAttached === '1') {
        console.log('[Venue Wizard] Search field already has autocomplete attached');
        return;
      }

      console.log('[Venue Wizard] Initializing location autocomplete for search field, mode:', mode);

      // Get location settings.
      var locationSettings = drupalSettings.myeventlaneLocation || {};
      var provider = locationSettings.provider || 'google_maps';

      if (provider === 'google_maps') {
        // Wait for Google Maps API to be ready.
        Drupal.behaviors.venueWizardIntegration.waitForGoogleMaps(locationSettings, function () {
          Drupal.behaviors.venueWizardIntegration.setupGoogleAutocomplete(searchField);
        });
      } else {
        console.warn('[Venue Wizard] Provider not supported:', provider);
      }
    },

    /**
     * Wait for Google Maps API to be ready, loading it if necessary.
     */
    waitForGoogleMaps: function (settings, callback) {
      // Already available.
      if (window.google && window.google.maps && window.google.maps.places) {
        console.log('[Venue Wizard] Google Maps already available');
        callback();
        return;
      }

      // Check if script is already loading.
      var existingScript = document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]');
      if (existingScript) {
        console.log('[Venue Wizard] Google Maps script already loading, waiting...');
        var checkInterval = setInterval(function () {
          if (window.google && window.google.maps && window.google.maps.places) {
            clearInterval(checkInterval);
            console.log('[Venue Wizard] Google Maps now available');
            callback();
          }
        }, 100);
        return;
      }

      // Load the script.
      var apiKey = settings.google_maps_api_key;
      if (!apiKey) {
        console.error('[Venue Wizard] No Google Maps API key available');
        return;
      }

      console.log('[Venue Wizard] Loading Google Maps API');
      var callbackName = '__melVenueGoogleMapsReady__' + Date.now();
      window[callbackName] = function () {
        delete window[callbackName];
        if (window.google && window.google.maps && window.google.maps.places) {
          console.log('[Venue Wizard] Google Maps loaded successfully');
          callback();
        } else {
          console.error('[Venue Wizard] Google Maps loaded but Places API missing');
        }
      };

      var script = document.createElement('script');
      script.async = true;
      script.defer = true;
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey) + '&libraries=places&callback=' + encodeURIComponent(callbackName);
      script.onerror = function () {
        delete window[callbackName];
        console.error('[Venue Wizard] Failed to load Google Maps script');
      };
      document.head.appendChild(script);
    },

    /**
     * Set up Google Places autocomplete on a search field.
     */
    setupGoogleAutocomplete: function (searchField) {
      if (!searchField || !window.google || !window.google.maps || !window.google.maps.places) {
        console.error('[Venue Wizard] Cannot setup autocomplete - prerequisites not met');
        return;
      }

      // Initialize Google Places autocomplete.
      var autocomplete = new google.maps.places.Autocomplete(searchField, {
        componentRestrictions: { country: 'au' },
        fields: ['name', 'formatted_address', 'geometry', 'place_id', 'address_components'],
        types: ['establishment', 'geocode']
      });

      autocomplete.addListener('place_changed', function () {
        var place = autocomplete.getPlace();
        console.log('[Venue Wizard] place_changed event fired, place:', place ? place.name : 'null');

        if (!place.geometry) {
          console.log('[Venue Wizard] No geometry for selected place');
          return;
        }

        console.log('[Venue Wizard] Place selected via Google autocomplete:', place);
        console.log('[Venue Wizard] Dispatching place_selected event on:', searchField.className);

        // Extract data and dispatch place_selected event.
        var lat = place.geometry.location.lat();
        var lng = place.geometry.location.lng();

        var components = Drupal.behaviors.venueWizardIntegration.extractAddressComponents(place);

        // Dispatch custom event for venue-wizard.js to handle.
        console.log('[Venue Wizard] Creating place_selected event with data:', {
          name: place.name,
          formatted_address: place.formatted_address,
          lat: lat,
          lng: lng
        });
        var event = new CustomEvent('place_selected', {
          bubbles: true,
          detail: {
            place: place,
            lat: lat,
            lng: lng,
            components: components
          }
        });
        searchField.dispatchEvent(event);
        console.log('[Venue Wizard] place_selected event dispatched');
      });

      searchField.dataset.melAutocompleteAttached = '1';
      console.log('[Venue Wizard] Google Places autocomplete initialized on field');
    },

    /**
     * Extract address components from Google Places result.
     */
    extractAddressComponents: function (place) {
      var out = {
        name: place.name || '',
        address_line1: '',
        address_line2: '',
        locality: '',
        administrative_area: '',
        postal_code: '',
        country_code: 'AU'
      };

      if (place.address_components) {
        for (var i = 0; i < place.address_components.length; i++) {
          var c = place.address_components[i];
          var types = c.types || [];

          if (types.indexOf('street_number') !== -1) {
            out.address_line1 = (c.long_name || '') + ' ' + out.address_line1;
          }
          if (types.indexOf('route') !== -1) {
            out.address_line1 = (out.address_line1 || '') + (c.long_name || '');
          }
          if (types.indexOf('subpremise') !== -1) {
            out.address_line2 = c.long_name || '';
          }
          if (types.indexOf('locality') !== -1) {
            out.locality = c.long_name || '';
          }
          if (types.indexOf('administrative_area_level_1') !== -1) {
            out.administrative_area = c.short_name || c.long_name || '';
          }
          if (types.indexOf('postal_code') !== -1) {
            out.postal_code = c.long_name || '';
          }
          if (types.indexOf('country') !== -1) {
            out.country_code = c.short_name || 'AU';
          }
        }
      }

      out.address_line1 = String(out.address_line1 || '').trim();
      if (!out.address_line1 && place.formatted_address) {
        out.address_line1 = String(place.formatted_address).split(',')[0].trim();
      }

      return out;
    },

    /**
     * Initialize existing venue autocomplete selection.
     */
    initExistingVenueSelection: function (venueField, context) {
      var $venueField = $(venueField);
      var $locationsSelect = $(context).find('[data-venue-locations-select]');

      // Handle venue autocomplete selection.
      $venueField.on('autocompleteclose', function () {
        var value = $(this).val();
        var venueId = Drupal.behaviors.venueWizardIntegration.extractVenueId(value);

        if (venueId) {
          Drupal.behaviors.venueWizardIntegration.loadLocations(venueId, $locationsSelect);
        }
      });

      // Handle venue change (for manual entry or paste).
      $venueField.on('change', function () {
        var value = $(this).val();
        var venueId = Drupal.behaviors.venueWizardIntegration.extractVenueId(value);

        if (venueId) {
          Drupal.behaviors.venueWizardIntegration.loadLocations(venueId, $locationsSelect);
        } else {
          // Clear locations if no valid venue.
          $locationsSelect.html('<option value="">- Select a venue first -</option>');
        }
      });

      // Custom event for location selection.
      $locationsSelect.on('venue:selected', function (e, venueId) {
        if (venueId) {
          Drupal.behaviors.venueWizardIntegration.loadLocations(venueId, $locationsSelect);
        }
      });
    },

    /**
     * Initialize create venue location search.
     *
     * Captures place_selected events and stores data in hidden fields,
     * then enables the "Complete venue details" button.
     */
    initCreateVenueSearch: function (searchField, context) {
      var $searchField = $(searchField);

      console.log('[Venue Wizard] Initializing create venue search');
      console.log('[Venue Wizard] Adding place_selected listener to:', searchField.className);

      // Listen for place_selected event from location autocomplete.
      searchField.addEventListener('place_selected', function (e) {
        console.log('[Venue Wizard] place_selected event RECEIVED');
        var detail = e.detail;
        if (!detail) {
          console.log('[Venue Wizard] No detail in event');
          return;
        }

        console.log('[Venue Wizard] Place selected in create mode:', detail);

        // Find elements dynamically to avoid stale references.
        var $container = $searchField.closest('.mel-venue-create-container');
        var $nameField = $container.find('.mel-new-venue-name');
        var $addressField = $container.find('.mel-new-venue-address');
        var $latField = $container.find('.mel-new-venue-lat');
        var $lngField = $container.find('.mel-new-venue-lng');
        var $completeBtn = $container.find('.mel-complete-venue-btn');

        console.log('[Venue Wizard] Container found:', $container.length > 0);
        console.log('[Venue Wizard] Complete button found:', $completeBtn.length > 0);

        var placeName = '';
        var formattedAddress = '';
        var lat = null;
        var lng = null;

        // Extract data based on provider.
        if (detail.place) {
          if (detail.place.name) {
            placeName = detail.place.name;
          }
          // Use formatted_address as the primary source.
          if (detail.place.formatted_address) {
            formattedAddress = detail.place.formatted_address;
          }
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

        // Only use components as fallback if formatted_address is empty.
        if (!formattedAddress && detail.components) {
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
        }

        // Use name from components as fallback.
        if (!placeName && detail.components && detail.components.name) {
          placeName = detail.components.name;
        }

        console.log('[Venue Wizard] Extracted data:', {
          name: placeName,
          address: formattedAddress,
          lat: lat,
          lng: lng
        });

        // Store in hidden fields.
        $nameField.val(placeName);
        $addressField.val(formattedAddress);
        if (lat !== null) $latField.val(lat.toFixed(7));
        if (lng !== null) $lngField.val(lng.toFixed(7));

        // Update the complete venue button URL with pre-populated data.
        if ($completeBtn.length) {
          var baseUrl = $completeBtn.attr('href').split('?')[0];
          var params = new URLSearchParams();
          if (placeName) params.set('name', placeName);
          if (formattedAddress) params.set('address', formattedAddress);
          if (lat !== null) params.set('lat', lat.toFixed(7));
          if (lng !== null) params.set('lng', lng.toFixed(7));
          var newUrl = baseUrl + '?' + params.toString();
          $completeBtn.attr('href', newUrl);
          console.log('[Venue Wizard] Updated button URL:', newUrl);
        } else {
          console.log('[Venue Wizard] WARNING: Complete button not found, cannot update URL');
        }

        console.log('[Venue Wizard] Stored venue data:', {
          name: placeName,
          address: formattedAddress,
          lat: lat,
          lng: lng
        });
      });
    },

    /**
     * Initialize manual entry location search.
     *
     * Captures place_selected events and populates the address fields
     * in field_venue_address widget.
     */
    initManualEntrySearch: function (searchField, context) {
      var $searchField = $(searchField);
      var $form = $searchField.closest('form');

      console.log('[Venue Wizard] Initializing manual entry search');

      // Listen for place_selected event from location autocomplete.
      searchField.addEventListener('place_selected', function (e) {
        var detail = e.detail;
        if (!detail) return;

        console.log('[Venue Wizard] Place selected in manual entry mode:', detail);

        var lat = null;
        var lng = null;
        var placeName = '';

        // Get coordinates.
        if (typeof detail.lat === 'number') {
          lat = detail.lat;
        }
        if (typeof detail.lng === 'number') {
          lng = detail.lng;
        }

        // Get place name.
        if (detail.place && detail.place.name) {
          placeName = detail.place.name;
        }

        // Build address data from components.
        var addressData = {};
        if (detail.components) {
          addressData = {
            address_line1: detail.components.address_line1 || '',
            address_line2: detail.components.address_line2 || '',
            locality: detail.components.locality || '',
            administrative_area: detail.components.administrative_area || '',
            postal_code: detail.components.postal_code || '',
            country_code: detail.components.country_code || 'AU'
          };
        }

        // Populate the visible address fields.
        Drupal.behaviors.venueWizardIntegration.populateAddressFields($form, addressData, lat, lng);

        // Also populate venue name if we have one.
        if (placeName) {
          var $venueName = $form.find('.myeventlane-venue-name-field, [name*="venue_name"]');
          if ($venueName.length) {
            $venueName.val(placeName).trigger('change');
          }
        }

        console.log('[Venue Wizard] Populated address fields:', addressData);
      });
    },

    /**
     * Populate address fields from address data.
     * Targets field_venue_address widget if visible, falls back to other address widgets.
     */
    populateAddressFields: function ($form, addressData, lat, lng) {
      // Try field_venue_address first (visible in Event Wizard).
      var $widgetRoot = $form.find('.field--name-field-venue-address');
      if (!$widgetRoot.length) {
        // Fallback to field_location.
        $widgetRoot = $form.find('.field--name-field-location');
      }
      if (!$widgetRoot.length) {
        // Fallback to any fieldset with address fields.
        $widgetRoot = $form;
      }

      console.log('[Venue Wizard] Populating address fields in widget root:', $widgetRoot);

      // Country.
      var $country = $widgetRoot.find('[name*="[address][country_code]"]');
      if ($country.length && addressData.country_code) {
        $country.val(addressData.country_code).trigger('change');
      }

      // Address line 1.
      var $line1 = $widgetRoot.find('[name*="[address][address_line1]"]');
      if ($line1.length && addressData.address_line1) {
        $line1.val(addressData.address_line1).trigger('change');
      }

      // Address line 2.
      var $line2 = $widgetRoot.find('[name*="[address][address_line2]"]');
      if ($line2.length && addressData.address_line2) {
        $line2.val(addressData.address_line2).trigger('change');
      }

      // Locality (suburb/city).
      var $locality = $widgetRoot.find('[name*="[address][locality]"]');
      if ($locality.length && addressData.locality) {
        $locality.val(addressData.locality).trigger('change');
      }

      // Administrative area (state).
      var $state = $widgetRoot.find('[name*="[address][administrative_area]"]');
      if ($state.length && addressData.administrative_area) {
        $state.val(addressData.administrative_area).trigger('change');
      }

      // Postal code.
      var $postcode = $widgetRoot.find('[name*="[address][postal_code]"]');
      if ($postcode.length && addressData.postal_code) {
        $postcode.val(addressData.postal_code).trigger('change');
      }

      // Coordinates (if there are hidden fields for them).
      if (lat !== null) {
        var $latField = $widgetRoot.find('[name*="latitude"], [name*="[lat]"]');
        if ($latField.length) {
          $latField.val(lat).trigger('change');
        }
      }
      if (lng !== null) {
        var $lngField = $widgetRoot.find('[name*="longitude"], [name*="[lng]"]');
        if ($lngField.length) {
          $lngField.val(lng).trigger('change');
        }
      }
    },

    /**
     * Handle venue created event from modal.
     */
    handleVenueCreated: function (data) {
      console.log('[Venue Wizard] Venue created:', data);

      var $form = $('form[id*="event-wizard-when-where"]').first();
      if (!$form.length) {
        $form = $('[data-venue-autocomplete]').closest('form');
      }

      // Store created venue info in hidden fields.
      var $createdVenueId = $form.find('.mel-created-venue-id');
      var $createdLocationId = $form.find('.mel-created-venue-location-id');
      
      if ($createdVenueId.length) {
        $createdVenueId.val(data.venue_id || '');
      }
      if ($createdLocationId.length && data.location_id) {
        $createdLocationId.val(data.location_id);
      }

      // Populate field_location from venue data if provided.
      if (data.address) {
        Drupal.behaviors.venueWizardIntegration.populateFieldLocation(
          $form,
          data.address,
          data.latitude || null,
          data.longitude || null
        );
      }

      // Switch to "existing venue" mode and populate.
      var $venueChoice = $(':input[name="venue_choice"]');
      if ($venueChoice.length) {
        $venueChoice.filter('[value="existing"]').prop('checked', true).trigger('change');
      }

      // Update venue autocomplete field.
      var $venueField = $('[data-venue-autocomplete]');
      if ($venueField.length) {
        $venueField.val(data.venue_id + ': ' + data.venue_name);
        $venueField.trigger('change');
      }

      // Load locations for the new venue.
      var $locationsSelect = $('[data-venue-locations-select]');
      if ($locationsSelect.length) {
        Drupal.behaviors.venueWizardIntegration.loadLocations(data.venue_id, $locationsSelect);
      }

      console.log('[Venue Wizard] Venue creation handled, stored IDs:', {
        venue_id: data.venue_id,
        location_id: data.location_id
      });
    },

    /**
     * Extract venue ID from autocomplete value.
     *
     * @param {string} value
     *   The autocomplete value in format "ID: Name".
     *
     * @return {string|null}
     *   The venue ID or null.
     */
    extractVenueId: function (value) {
      if (!value) {
        return null;
      }
      var match = value.match(/^(\d+):/);
      return match ? match[1] : null;
    },

    /**
     * Load locations for a venue via AJAX.
     *
     * @param {string} venueId
     *   The venue ID.
     * @param {jQuery} $select
     *   The locations select element.
     */
    loadLocations: function (venueId, $select) {
      if (!venueId || !$select.length) {
        return;
      }

      $select.prop('disabled', true);
      $select.html('<option value="">Loading...</option>');

      $.ajax({
        url: Drupal.url('myeventlane/venues/' + venueId + '/locations'),
        type: 'GET',
        dataType: 'json',
        success: function (response) {
          var options = '<option value="">- Select a location -</option>';
          var primaryLocationId = null;

          if (response.locations && response.locations.length) {
            response.locations.forEach(function (location) {
              var selected = location.is_primary ? ' selected' : '';
              if (location.is_primary) {
                primaryLocationId = location.id;
              }
              options += '<option value="' + location.id + '"' + selected + 
                ' data-address="' + (location.address || '').replace(/"/g, '&quot;') + '"' +
                ' data-address-line1="' + (location.address_line1 || '').replace(/"/g, '&quot;') + '"' +
                ' data-locality="' + (location.locality || '').replace(/"/g, '&quot;') + '"' +
                ' data-state="' + (location.state || '').replace(/"/g, '&quot;') + '"' +
                ' data-postcode="' + (location.postcode || '').replace(/"/g, '&quot;') + '"' +
                ' data-lat="' + (location.latitude || '') + '"' +
                ' data-lng="' + (location.longitude || '') + '"' +
                '>' + location.title + ' (' + location.address + ')' +
                '</option>';
            });
          } else {
            options = '<option value="">No locations found</option>';
          }

          $select.html(options);
          $select.prop('disabled', false);

          // If there's a primary location, auto-select it.
          if (primaryLocationId) {
            Drupal.behaviors.venueWizardIntegration.handleLocationSelected($select, primaryLocationId);
          }

          // Trigger change to update any dependent logic.
          $select.trigger('change');
        },
        error: function () {
          $select.html('<option value="">Error loading locations</option>');
          $select.prop('disabled', false);
        }
      });
    },

    /**
     * Handle venue location selection.
     *
     * Updates hidden field and populates field_location widget.
     *
     * @param {jQuery} $select
     *   The locations select element.
     * @param {string} locationId
     *   The selected location ID.
     */
    handleLocationSelected: function ($select, locationId) {
      var $form = $select.closest('form');
      if (!$form.length) return;

      // Update hidden field for submit handler.
      var $hiddenField = $form.find('.mel-selected-venue-location-id');
      if ($hiddenField.length) {
        $hiddenField.val(locationId);
      }

      // Get address data from selected option.
      var $option = $select.find('option[value="' + locationId + '"]');
      if (!$option.length) return;

      var addressData = {
        address_line1: $option.data('address-line1') || '',
        locality: $option.data('locality') || '',
        administrative_area: $option.data('state') || '',
        postal_code: $option.data('postcode') || '',
        country_code: 'AU'
      };

      var lat = $option.data('lat');
      var lng = $option.data('lng');

      console.log('[Venue Wizard] Populating field_location from venue location:', locationId, addressData);

      // Populate the hidden field_location widget.
      Drupal.behaviors.venueWizardIntegration.populateFieldLocation($form, addressData, lat, lng);
    },

    /**
     * Populate the field_location address widget.
     *
     * @param {jQuery} $form
     *   The form element.
     * @param {object} addressData
     *   Address components object.
     * @param {number|string} lat
     *   Latitude.
     * @param {number|string} lng
     *   Longitude.
     */
    populateFieldLocation: function ($form, addressData, lat, lng) {
      if (!$form.length) return;

      // Find field_location inputs.
      var findField = function (name) {
        return $form.find('input[name*="field_location"][name*="[' + name + ']"]').first();
      };

      var findSelect = function (name) {
        return $form.find('select[name*="field_location"][name*="[' + name + ']"]').first();
      };

      // Set values.
      var $line1 = findField('address_line1');
      if ($line1.length) {
        $line1.val(addressData.address_line1 || '');
        $line1.trigger('change');
      }

      var $line2 = findField('address_line2');
      if ($line2.length && addressData.address_line2) {
        $line2.val(addressData.address_line2);
        $line2.trigger('change');
      }

      var $locality = findField('locality');
      if ($locality.length) {
        $locality.val(addressData.locality || '');
        $locality.trigger('change');
      }

      var $postcode = findField('postal_code');
      if ($postcode.length) {
        $postcode.val(addressData.postal_code || '');
        $postcode.trigger('change');
      }

      // State can be input or select.
      var $state = findField('administrative_area');
      if (!$state.length) {
        $state = findSelect('administrative_area');
      }
      if ($state.length) {
        $state.val(addressData.administrative_area || '');
        $state.trigger('change');
      }

      // Country.
      var $country = findSelect('country_code');
      if (!$country.length) {
        $country = findField('country_code');
      }
      if ($country.length) {
        $country.val(addressData.country_code || 'AU');
        $country.trigger('change');
      }

      // Coordinates.
      if (lat && lng) {
        var $lat = $form.find('input[name*="latitude"]').first();
        var $lng = $form.find('input[name*="longitude"]').first();
        if ($lat.length) {
          $lat.val(lat);
          $lat.trigger('change');
        }
        if ($lng.length) {
          $lng.val(lng);
          $lng.trigger('change');
        }
      }

      console.log('[Venue Wizard] Field location populated');
    }
  };

  // Attach handler for venue location select change.
  $(document).on('change', '[data-venue-locations-select]', function () {
    var $select = $(this);
    var locationId = $select.val();
    if (locationId) {
      Drupal.behaviors.venueWizardIntegration.handleLocationSelected($select, locationId);
    }
  });

})(jQuery, Drupal, drupalSettings, once);
