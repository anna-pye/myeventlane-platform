/**
 * @file
 * Address autocomplete functionality for Google Maps and Apple Maps.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Initializes address autocomplete on Event forms.
   */
  function initAddressAutocomplete() {
    console.log('MyEventLane Location: Initializing address autocomplete...');
    const settings = drupalSettings.myeventlaneLocation || {};
    const provider = settings.provider || 'google_maps';
    
    console.log('MyEventLane Location: Settings:', settings);
    console.log('MyEventLane Location: Provider:', provider);

    // Check if API key is configured.
    if (provider === 'google_maps' && !settings.google_maps_api_key) {
      console.error('MyEventLane Location: Google Maps API key not configured.');
      return;
    }

    // Find the address search input.
    const searchInput = document.querySelector('.myeventlane-location-address-search');
    if (!searchInput) {
      console.warn('MyEventLane Location: Address search input not found. Available inputs:', 
        Array.from(document.querySelectorAll('input[type="text"]')).map(i => ({ class: i.className, name: i.name })));
      return;
    }
    
    console.log('MyEventLane Location: Found search input:', searchInput);

    // Find the widget container (field_location).
    // The search field is now a sibling, so we need to find field_location.
    const form = searchInput.closest('form');
    if (!form) {
      console.warn('MyEventLane Location: Form not found.');
      return;
    }
    
    console.log('MyEventLane Location: Found form:', form);
    
    // Find the field_location widget container.
    let widget = form.querySelector('.myeventlane-location-address-widget, .field--name-field-location, fieldset[data-drupal-selector*="field-location"]');
    if (!widget) {
      // Fallback: find any fieldset containing address fields.
      widget = form.querySelector('fieldset:has(input[name*="address_line1"])');
    }
    if (!widget) {
      // Last resort: use the form itself
      widget = form;
      console.warn('MyEventLane Location: Widget container not found, using form as fallback');
    } else {
      console.log('MyEventLane Location: Found widget container:', widget);
    }

    // Initialize based on provider.
    if (provider === 'google_maps') {
      console.log('MyEventLane Location: Initializing Google Maps autocomplete...');
      initGoogleMapsAutocomplete(widget, searchInput, settings);
    } else if (provider === 'apple_maps') {
      console.log('MyEventLane Location: Initializing Apple Maps autocomplete...');
      initAppleMapsAutocomplete(widget, searchInput, settings);
    }
  }

  /**
   * Initializes Google Maps Places Autocomplete.
   */
  function initGoogleMapsAutocomplete(widget, searchInput, settings) {
    // Check if Google Maps API is loaded.
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
      // Load Google Maps API.
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${settings.google_maps_api_key}&libraries=places&callback=myeventlaneLocationGoogleMapsReady`;
      script.async = true;
      script.defer = true;
      window.myeventlaneLocationGoogleMapsReady = function () {
        setupGoogleMapsAutocomplete(widget, searchInput);
      };
      document.head.appendChild(script);
    } else {
      setupGoogleMapsAutocomplete(widget, searchInput);
    }
  }

  /**
   * Sets up Google Maps autocomplete after API is loaded.
   */
  function setupGoogleMapsAutocomplete(widget, searchInput) {
    console.log('MyEventLane Location: Setting up Google Maps autocomplete');
    
    const autocomplete = new google.maps.places.Autocomplete(searchInput, {
      types: ['establishment', 'geocode'],
      componentRestrictions: { country: 'au' }, // Restrict to Australia by default.
    });

    console.log('MyEventLane Location: Google Maps autocomplete created, adding place_changed listener');

    autocomplete.addListener('place_changed', function () {
      console.log('MyEventLane Location: place_changed event fired!');
      const place = autocomplete.getPlace();
      console.log('MyEventLane Location: Place object:', place);
      
      if (!place.geometry) {
        console.warn('MyEventLane Location: Place has no geometry, skipping');
        return;
      }

      // Extract address components.
      const addressComponents = extractGoogleAddressComponents(place);
      console.log('MyEventLane Location: Extracted address components:', addressComponents);
      
      // Populate address fields - pass searchInput so it can find the form.
      console.log('MyEventLane Location: Calling populateAddressFields...');
      populateAddressFields(widget, addressComponents, searchInput);

      // Set coordinates.
      const lat = place.geometry.location.lat();
      const lng = place.geometry.location.lng();
      console.log('MyEventLane Location: Coordinates:', lat, lng);
      setCoordinates(widget, lat, lng, searchInput);

      // Show map preview.
      showMapPreview(widget, lat, lng, 'google_maps', searchInput);
      
      console.log('MyEventLane Location: Place selection complete');
    });
    
    console.log('MyEventLane Location: Autocomplete setup complete');
  }

  /**
   * Initializes Apple Maps autocomplete.
   */
  function initAppleMapsAutocomplete(widget, searchInput, settings) {
    // Check if MapKit JS is loaded.
    if (typeof mapkit === 'undefined') {
      // Load MapKit JS.
      const script = document.createElement('script');
      script.src = 'https://cdn.apple-mapkit.com/mk/5.x.x/mapkit.js';
      script.async = true;
      script.onload = function () {
        // Initialize MapKit with token.
        if (settings.apple_maps_token) {
          mapkit.init({
            authorizationCallback: function (done) {
              done(settings.apple_maps_token);
            },
          });
          setupAppleMapsAutocomplete(widget, searchInput);
        }
      };
      document.head.appendChild(script);
    } else {
      setupAppleMapsAutocomplete(widget, searchInput);
    }
  }

  /**
   * Sets up Apple Maps autocomplete after API is loaded.
   */
  function setupAppleMapsAutocomplete(widget, searchInput) {
    // Apple Maps doesn't have a built-in autocomplete like Google.
    // We'll use a search service with debouncing.
    let searchTimeout;
    const searchService = new mapkit.Search({
      region: new mapkit.CoordinateRegion(
        new mapkit.Coordinate(-25.2744, 133.7751), // Center of Australia
        new mapkit.CoordinateSpan(40, 40)
      ),
    });

    // Create a suggestions container.
    const suggestionsContainer = document.createElement('div');
    suggestionsContainer.className = 'myeventlane-location-suggestions';
    suggestionsContainer.style.cssText = 'position: absolute; background: white; border: 1px solid #ccc; max-height: 200px; overflow-y: auto; z-index: 1000; width: 100%; display: none;';
    searchInput.parentNode.style.position = 'relative';
    searchInput.parentNode.appendChild(suggestionsContainer);

    searchInput.addEventListener('input', function () {
      const query = this.value.trim();
      if (query.length < 3) {
        suggestionsContainer.style.display = 'none';
        return;
      }

      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(function () {
        searchService.search(query, function (error, data) {
          if (error || !data || !data.places) {
            suggestionsContainer.style.display = 'none';
            return;
          }

          // Display suggestions.
          suggestionsContainer.innerHTML = '';
          data.places.slice(0, 5).forEach(function (place) {
            const item = document.createElement('div');
            item.className = 'myeventlane-location-suggestion-item';
            item.style.cssText = 'padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;';
            item.textContent = place.name + (place.formattedAddressLines ? ' - ' + place.formattedAddressLines.join(', ') : '');
            item.addEventListener('click', function () {
              selectAppleMapsPlace(widget, place, searchInput, suggestionsContainer);
            });
            suggestionsContainer.appendChild(item);
          });
          suggestionsContainer.style.display = 'block';
        });
      }, 300);
    });

    // Hide suggestions when clicking outside.
    document.addEventListener('click', function (e) {
      if (!widget.contains(e.target)) {
        suggestionsContainer.style.display = 'none';
      }
    });
  }

  /**
   * Handles selection of an Apple Maps place.
   */
  function selectAppleMapsPlace(widget, place, searchInput, suggestionsContainer) {
    searchInput.value = place.name + (place.formattedAddressLines ? ' - ' + place.formattedAddressLines.join(', ') : '');
    suggestionsContainer.style.display = 'none';

    // Extract address components from Apple Maps place.
    const addressComponents = extractAppleAddressComponents(place);
    
    // Populate address fields.
    populateAddressFields(widget, addressComponents, searchInput);
    
    // Set coordinates.
    const lat = place.coordinate.latitude;
    const lng = place.coordinate.longitude;
    setCoordinates(widget, lat, lng, searchInput);

    // Show map preview.
    showMapPreview(widget, lat, lng, 'apple_maps', searchInput);
  }

  /**
   * Extracts address components from Google Places result.
   */
  function extractGoogleAddressComponents(place) {
    console.log('MyEventLane Location: Extracting address components from place:', place);
    
    const components = {
      name: place.name || '',
      address_line1: '',
      address_line2: '',
      locality: '',
      administrative_area: '',
      postal_code: '',
      country_code: 'AU',
    };

    if (!place.address_components || place.address_components.length === 0) {
      console.warn('MyEventLane Location: Place has no address_components');
      // Try to use formatted_address as fallback
      if (place.formatted_address) {
        components.address_line1 = place.formatted_address;
      }
      return components;
    }

    place.address_components.forEach(function (component) {
      const types = component.types;
      if (types.includes('street_number')) {
        components.address_line1 = component.long_name + ' ';
      } else if (types.includes('route')) {
        components.address_line1 += component.long_name;
      } else if (types.includes('subpremise')) {
        components.address_line2 = component.long_name;
      } else if (types.includes('locality')) {
        components.locality = component.long_name;
      } else if (types.includes('administrative_area_level_1')) {
        components.administrative_area = component.short_name;
      } else if (types.includes('postal_code')) {
        components.postal_code = component.long_name;
      } else if (types.includes('country')) {
        components.country_code = component.short_name;
      }
    });

    // Clean up address_line1 (remove trailing space if no route was found)
    components.address_line1 = components.address_line1.trim();
    
    // If address_line1 is still empty, try using formatted_address
    if (!components.address_line1 && place.formatted_address) {
      // Extract first line from formatted address
      const lines = place.formatted_address.split(',');
      components.address_line1 = lines[0] || place.formatted_address;
    }

    console.log('MyEventLane Location: Extracted components:', components);
    return components;
  }

  /**
   * Extracts address components from Apple Maps place.
   */
  function extractAppleAddressComponents(place) {
    const components = {
      name: place.name || '',
      address_line1: place.formattedAddressLines ? place.formattedAddressLines[0] || '' : '',
      address_line2: place.formattedAddressLines && place.formattedAddressLines[1] ? place.formattedAddressLines[1] : '',
      locality: place.locality || '',
      administrative_area: place.administrativeArea || '',
      postal_code: place.postalCode || '',
      country_code: place.countryCode || 'AU',
    };

    return components;
  }

  /**
   * Populates address fields in the widget.
   *
   * NOTE: We now target the *specific* Drupal address widget for field_location
   * by using its known data-drupal-selector attributes. This is far more
   * reliable than trying to guess patterns.
   */
  function populateAddressFields(widget, components, searchInput) {
    const form =
      (searchInput && searchInput.closest('form')) ||
      widget.closest('form') ||
      document.querySelector('form.node-event-form, form.node-event-edit-form');

    if (!form) {
      console.warn('MyEventLane Location: Form not found for populating address fields.');
      return;
    }

    // Direct selectors for the address widget of field_location.
    const addressLine1 =
      form.querySelector('[data-drupal-selector="edit-field-location-0-address-address-line1"]') ||
      form.querySelector('input[name="field_location[0][address][address_line1]"]');

    const addressLine2 =
      form.querySelector('[data-drupal-selector="edit-field-location-0-address-address-line2"]') ||
      form.querySelector('input[name="field_location[0][address][address_line2]"]');

    const locality =
      form.querySelector('[data-drupal-selector="edit-field-location-0-address-locality"]') ||
      form.querySelector('input[name="field_location[0][address][locality]"]');

    const administrativeArea =
      form.querySelector('[data-drupal-selector="edit-field-location-0-address-administrative-area"]') ||
      form.querySelector('select[name="field_location[0][address][administrative_area]"]');

    const postalCode =
      form.querySelector('[data-drupal-selector="edit-field-location-0-address-postal-code"]') ||
      form.querySelector('input[name="field_location[0][address][postal_code]"]');

    const countryCode =
      form.querySelector('[data-drupal-selector="edit-field-location-0-address-country-code"]') ||
      form.querySelector('select[name="field_location[0][address][country_code]"]');

    // Venue name field (simple text field).
    const venueNameField =
      form.querySelector('[data-drupal-selector="edit-field-venue-name-0-value"]') ||
      form.querySelector('input[name="field_venue_name[0][value]"]');

    // Log ALL inputs and selects in the form to debug.
    const allFields = Array.from(form.querySelectorAll('input, select'));
    console.log('MyEventLane Location: ALL form fields:', allFields.map(f => ({
      name: f.name,
      id: f.id,
      type: f.type || f.tagName,
      dataSelector: f.getAttribute('data-drupal-selector'),
      label: f.labels && f.labels.length > 0 ? f.labels[0].textContent.trim() : 'no label',
      value: f.value
    })));

    console.log('MyEventLane Location: Field detection (direct selectors)', {
      addressLine1: addressLine1 ? (addressLine1.name || addressLine1.id) : 'NOT FOUND',
      addressLine2: addressLine2 ? (addressLine2.name || addressLine2.id) : 'NOT FOUND',
      locality: locality ? (locality.name || locality.id) : 'NOT FOUND',
      administrativeArea: administrativeArea ? (administrativeArea.name || administrativeArea.id) : 'NOT FOUND',
      postalCode: postalCode ? (postalCode.name || postalCode.id) : 'NOT FOUND',
      countryCode: countryCode ? (countryCode.name || countryCode.id) : 'NOT FOUND',
      venueName: venueNameField ? (venueNameField.name || venueNameField.id) : 'NOT FOUND',
      components
    });

    // Populate venue name from the place name if available.
    if (venueNameField && components.name) {
      venueNameField.value = components.name;
      venueNameField.dispatchEvent(new Event('input', { bubbles: true }));
      venueNameField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Populate street address.
    if (addressLine1) {
      addressLine1.value = components.address_line1 || '';
      addressLine1.dispatchEvent(new Event('input', { bubbles: true }));
      addressLine1.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
      console.warn('MyEventLane Location: address_line1 field not found with direct selector');
    }

    if (addressLine2 && components.address_line2) {
      addressLine2.value = components.address_line2;
      addressLine2.dispatchEvent(new Event('input', { bubbles: true }));
      addressLine2.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (locality) {
      locality.value = components.locality || '';
      locality.dispatchEvent(new Event('input', { bubbles: true }));
      locality.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (administrativeArea) {
      if (administrativeArea.tagName === 'SELECT') {
        const options = administrativeArea.options;
        for (let i = 0; i < options.length; i++) {
          const opt = options[i];
          if (
            opt.value === components.administrative_area ||
            opt.text === components.administrative_area ||
            opt.text.indexOf(components.administrative_area) !== -1
          ) {
            administrativeArea.value = opt.value;
            break;
          }
        }
      } else {
        administrativeArea.value = components.administrative_area || '';
      }
      administrativeArea.dispatchEvent(new Event('input', { bubbles: true }));
      administrativeArea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (postalCode) {
      postalCode.value = components.postal_code || '';
      postalCode.dispatchEvent(new Event('input', { bubbles: true }));
      postalCode.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (countryCode) {
      countryCode.value = components.country_code || 'AU';
      countryCode.dispatchEvent(new Event('input', { bubbles: true }));
      countryCode.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  /**
   * Sets latitude and longitude in hidden fields.
   */
  function setCoordinates(widget, lat, lng, searchInput) {
    const latField = widget.querySelector('.myeventlane-location-latitude');
    const lngField = widget.querySelector('.myeventlane-location-longitude');

    if (latField) {
      latField.value = lat.toString();
    }
    if (lngField) {
      lngField.value = lng.toString();
    }

    // Set values in hidden coordinate fields that are part of the form.
    // These will be saved via the form submit handler.
    const form = searchInput ? searchInput.closest('form') : (widget.closest('form') || document.querySelector('form'));
    if (form) {
      // Try our custom hidden fields first (these avoid validation issues).
      let eventLatField = form.querySelector('input.myeventlane-location-latitude-field');
      let eventLngField = form.querySelector('input.myeventlane-location-longitude-field');

      // Fallback to field_location_latitude/longitude or field_event_lat/lng.
      if (!eventLatField) {
        eventLatField = form.querySelector('input[name*="field_location_latitude"], input[name*="field_event_lat"]');
      }
      if (!eventLngField) {
        eventLngField = form.querySelector('input[name*="field_location_longitude"], input[name*="field_event_lng"]');
      }
      
      console.log('MyEventLane Location: Setting coordinates:', lat, lng, 'to fields:', {
        latField: eventLatField ? eventLatField.name : 'NOT FOUND',
        lngField: eventLngField ? eventLngField.name : 'NOT FOUND'
      });

      if (eventLatField) {
        eventLatField.value = lat.toString();
        // Trigger change event to ensure Drupal form system recognizes the change.
        eventLatField.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (eventLngField) {
        eventLngField.value = lng.toString();
        eventLngField.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

  /**
   * Shows a map preview with a marker.
   */
  function showMapPreview(widget, lat, lng, provider, searchInput) {
    // The map preview is now a sibling element, find it in the form.
    const form = searchInput ? searchInput.closest('form') : (widget.closest('form') || document.querySelector('form'));
    if (!form) {
      console.warn('MyEventLane Location: Form not found for map preview');
      return;
    }
    
    const previewContainer = form.querySelector('.myeventlane-location-map-preview');
    if (!previewContainer) {
      return;
    }

    previewContainer.style.display = 'block';

    if (provider === 'google_maps') {
      if (typeof google !== 'undefined' && google.maps) {
        const map = new google.maps.Map(previewContainer, {
          center: { lat: lat, lng: lng },
          zoom: 15,
          mapTypeControl: false,
          streetViewControl: false,
        });
        new google.maps.Marker({
          position: { lat: lat, lng: lng },
          map: map,
        });
      }
    } else if (provider === 'apple_maps') {
      if (typeof mapkit !== 'undefined') {
        const map = new mapkit.Map(previewContainer);
        const coordinate = new mapkit.Coordinate(lat, lng);
        map.region = new mapkit.CoordinateRegion(coordinate, new mapkit.CoordinateSpan(0.01, 0.01));
        const marker = new mapkit.MarkerAnnotation(coordinate);
        map.addAnnotation(marker);
      }
    }
  }

  // Initialize on DOM ready, but ONLY on event forms.
  function initialize() {
    // Only initialize if we're on an event form (check for the search input or form ID).
    const isEventForm = document.querySelector('.myeventlane-location-address-search') ||
                        document.querySelector('form.node-event-form, form.node-event-edit-form, form[id*="node-event"]');
    
    if (!isEventForm) {
      // Not on an event form, don't initialize or interfere.
      return;
    }
    
    // Small delay to ensure form is fully rendered.
    setTimeout(initAddressAutocomplete, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }

  // Re-initialize after AJAX form updates, but ONLY if we're on an event form.
  if (typeof Drupal !== 'undefined' && Drupal.ajax && Drupal.ajax.prototype) {
    // Re-initialize after AJAX completes, but check if we're on event form first.
    if (typeof jQuery !== 'undefined') {
      jQuery(document).ajaxComplete(function (event, xhr, settings) {
        // Only re-initialize if this is likely an event form AJAX update.
        // Check if the search input exists or if the form is an event form.
        const isEventForm = document.querySelector('.myeventlane-location-address-search') ||
                            document.querySelector('form.node-event-form, form.node-event-edit-form, form[id*="node-event"]');
        
        if (isEventForm) {
          setTimeout(initAddressAutocomplete, 200);
        }
      });
    }
  }

})(Drupal, drupalSettings);

