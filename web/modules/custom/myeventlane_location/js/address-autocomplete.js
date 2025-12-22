/**
 * @file
 * Address autocomplete functionality for Google Maps and Apple Maps.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  // Immediate console log to verify script is loading.
  console.log('MyEventLane Location: Script file loaded and executing');

  // Track initialization to prevent double initialization.
  const initializedForms = new WeakSet();

  /**
   * Initializes address autocomplete on Event forms.
   */
  function initAddressAutocomplete() {
    console.log('MyEventLane Location: Initializing address autocomplete');
    const settings = drupalSettings.myeventlaneLocation || {};
    const provider = settings.provider || 'google_maps';
    
    console.log('MyEventLane Location: Settings', settings);
    
    if (provider === 'google_maps' && !settings.google_maps_api_key) {
      console.warn('MyEventLane Location: Google Maps API key not found');
      return;
    }

    // Find the address search input - check multiple locations.
    let searchInput = document.querySelector('.myeventlane-location-address-search');
    console.log('MyEventLane Location: Search input (direct)', searchInput);
    
    if (!searchInput) {
      // Check in wizard content areas.
      const wizardContent = document.querySelector('.mel-event-form__wizard-content, .mel-wizard-step, .mel-event-wizard__content');
      if (wizardContent) {
        searchInput = wizardContent.querySelector('.myeventlane-location-address-search');
        console.log('MyEventLane Location: Search input (wizard)', searchInput);
      }
    }
    if (!searchInput) {
      // Check in location containers.
      const locationContainer = document.querySelector('.location, .location-fields-container, .mel-form-card:has(.field--name-field-location), .field--name-field-location');
      if (locationContainer) {
        searchInput = locationContainer.querySelector('.myeventlane-location-address-search');
        console.log('MyEventLane Location: Search input (container)', searchInput);
      }
    }
    if (!searchInput) {
      // Check by name attribute.
      searchInput = document.querySelector('input[name*="address_search"], input[name*="field_location"][name*="address_search"], input[name*="field_location_address_search"]');
      console.log('MyEventLane Location: Search input (by name)', searchInput);
    }
    if (!searchInput) {
      // Check in any form (including wizard).
      const form = document.querySelector('form.node-event-form, form.node-event-edit-form, form#event-wizard-form, form[id="event-wizard-form"], form[id*="event_wizard"], form.mel-event-wizard, form');
      if (form) {
        searchInput = form.querySelector('.myeventlane-location-address-search');
        console.log('MyEventLane Location: Search input (form)', searchInput);
      }
    }
    
    if (!searchInput) {
      console.warn('MyEventLane Location: Search input not found. Available inputs:', 
        Array.from(document.querySelectorAll('input[type="text"]')).map(el => ({
          name: el.name,
          class: el.className,
          id: el.id,
          visible: el.offsetParent !== null
        }))
      );
      // For wizard forms, the field might be hidden initially. Try again after a delay.
      const isWizard = document.querySelector('.mel-event-wizard, form#event-wizard-form, form[id*="event_wizard"]');
      if (isWizard) {
        console.log('MyEventLane Location: Wizard form detected, will retry initialization after delay');
        setTimeout(function() {
          initAddressAutocomplete();
        }, 1000);
      }
      return;
    }
    
    console.log('MyEventLane Location: Search input found', searchInput.name, searchInput.className, 'visible:', searchInput.offsetParent !== null);
    
    // If the search input is hidden (e.g., location_mode is 'online'), wait for it to become visible.
    if (searchInput.offsetParent === null) {
      console.log('MyEventLane Location: Search input is hidden, setting up observer to initialize when visible');
      const observer = new MutationObserver(function(mutations, obs) {
        if (searchInput.offsetParent !== null) {
          console.log('MyEventLane Location: Search input is now visible, initializing');
          obs.disconnect();
          // Clear initialization flag to allow re-init.
          const form = searchInput.closest('form');
          if (form && initializedForms.has(form)) {
            initializedForms.delete(form);
          }
          setTimeout(function() {
            initAddressAutocomplete();
          }, 100);
        }
      });
      
      // Observe the input and its parent containers for visibility changes.
      observer.observe(searchInput, { attributes: true, attributeFilter: ['style', 'class'] });
      if (searchInput.parentElement) {
        observer.observe(searchInput.parentElement, { attributes: true, attributeFilter: ['style', 'class'] });
      }
      
      // Also check periodically (fallback).
      let checkCount = 0;
      const checkInterval = setInterval(function() {
        checkCount++;
        if (searchInput.offsetParent !== null) {
          console.log('MyEventLane Location: Search input became visible (periodic check)');
          clearInterval(checkInterval);
          observer.disconnect();
          const form = searchInput.closest('form');
          if (form && initializedForms.has(form)) {
            initializedForms.delete(form);
          }
          setTimeout(function() {
            initAddressAutocomplete();
          }, 100);
        } else if (checkCount > 20) {
          // Stop checking after 10 seconds.
          clearInterval(checkInterval);
          observer.disconnect();
          console.warn('MyEventLane Location: Search input did not become visible after 10 seconds');
        }
      }, 500);
      
      return;
    }

    // Prevent double initialization.
    const form = searchInput.closest('form');
    if (form && initializedForms.has(form)) {
      return;
    }
    if (form) {
      initializedForms.add(form);
    }
    
    // Find the widget container.
    let widget = form.querySelector('[data-mel-address="field_location"], .myeventlane-location-address-widget, .field--name-field-location, fieldset[data-drupal-selector*="field-location"]');
    if (!widget) {
      const wizardContent = form.querySelector('.mel-event-form__wizard-content, .mel-wizard-step, .mel-event-form__section, .mel-event-wizard__content, .location-fields-container');
      if (wizardContent) {
        widget = wizardContent.querySelector('[data-mel-address="field_location"], .myeventlane-location-address-widget, .field--name-field-location, fieldset[data-drupal-selector*="field-location"]');
      }
    }
    if (!widget) {
      const locationContainer = form.querySelector('.location, .mel-form-card:has(.field--name-field-location)');
      if (locationContainer) {
        widget = locationContainer.querySelector('[data-mel-address="field_location"], .myeventlane-location-address-widget, .field--name-field-location, fieldset[data-drupal-selector*="field-location"]');
      }
    }
    if (!widget) {
      widget = form.querySelector('fieldset:has(input[name*="address_line1"]), [data-drupal-selector*="field-location"]');
    }
    if (!widget) {
      if (searchInput) {
        widget = searchInput.closest('[data-mel-address="field_location"], .field--name-field-location, fieldset, .myeventlane-location-address-widget, .form-item');
      }
    }
    if (!widget) {
      widget = form;
    }

    // Initialize based on provider.
    if (provider === 'google_maps') {
      initGoogleMapsAutocomplete(widget, searchInput, settings);
    } else if (provider === 'apple_maps') {
      initAppleMapsAutocomplete(widget, searchInput, settings);
    }
    
    // For wizard forms, also listen for location_mode changes to re-initialize.
    const locationModeInputs = form.querySelectorAll('input[name="location_mode"]');
    if (locationModeInputs.length > 0) {
      console.log('MyEventLane Location: Found location_mode inputs, setting up change listener');
      locationModeInputs.forEach(function(radio) {
        radio.addEventListener('change', function() {
          if (radio.value === 'in_person') {
            console.log('MyEventLane Location: Location mode changed to in_person, re-initializing in 500ms');
            // Clear initialization flag.
            if (initializedForms.has(form)) {
              initializedForms.delete(form);
            }
            setTimeout(function() {
              initAddressAutocomplete();
            }, 500);
          }
        });
      });
    }
  }

  /**
   * Initializes Google Maps Places Autocomplete.
   */
  function initGoogleMapsAutocomplete(widget, searchInput, settings) {
    console.log('MyEventLane Location: initGoogleMapsAutocomplete called', 'google defined:', typeof google !== 'undefined');
    
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
      console.log('MyEventLane Location: Google Maps API not loaded, loading script');
      
      // Check if script is already being loaded.
      const existingScript = document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]');
      if (existingScript) {
        console.log('MyEventLane Location: Google Maps script already exists, waiting for it to load');
        // Wait for existing script to load.
        const checkGoogle = setInterval(function() {
          if (typeof google !== 'undefined' && google.maps && google.maps.places) {
            clearInterval(checkGoogle);
            console.log('MyEventLane Location: Google Maps API loaded, setting up autocomplete');
            setupGoogleMapsAutocomplete(widget, searchInput);
          }
        }, 100);
        
        // Timeout after 10 seconds.
        setTimeout(function() {
          clearInterval(checkGoogle);
          if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
            console.error('MyEventLane Location: Google Maps API failed to load after 10 seconds');
          }
        }, 10000);
        return;
      }
      
      // Create unique callback name to avoid conflicts.
      const callbackName = 'myeventlaneLocationGoogleMapsReady_' + Date.now();
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${settings.google_maps_api_key}&libraries=places&callback=${callbackName}`;
      script.async = true;
      script.defer = true;
      
      window[callbackName] = function () {
        console.log('MyEventLane Location: Google Maps API callback fired');
        delete window[callbackName];
        setupGoogleMapsAutocomplete(widget, searchInput);
      };
      
      script.onerror = function() {
        console.error('MyEventLane Location: Failed to load Google Maps API script');
        delete window[callbackName];
      };
      
      document.head.appendChild(script);
      console.log('MyEventLane Location: Google Maps script added to page');
    } else {
      console.log('MyEventLane Location: Google Maps API already loaded, setting up autocomplete');
      setupGoogleMapsAutocomplete(widget, searchInput);
    }
  }

  /**
   * Sets up Google Maps autocomplete after API is loaded.
   */
  function setupGoogleMapsAutocomplete(widget, searchInput) {
    console.log('MyEventLane Location: Setting up Google Maps autocomplete', searchInput);
    
    if (!searchInput) {
      console.error('MyEventLane Location: Search input not found for autocomplete setup');
      return;
    }
    
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
      console.error('MyEventLane Location: Google Maps Places API not loaded');
      return;
    }
    
    const autocomplete = new google.maps.places.Autocomplete(searchInput, {
      types: ['establishment', 'geocode'],
      componentRestrictions: { country: 'au' },
    });

    console.log('MyEventLane Location: Autocomplete created, adding place_changed listener');

    autocomplete.addListener('place_changed', function () {
      console.log('MyEventLane Location: place_changed event fired');
      const place = autocomplete.getPlace();
      
      console.log('MyEventLane Location: Place selected', place);
      
      if (!place.geometry) {
        console.warn('MyEventLane Location: Place has no geometry', place);
        return;
      }

      const addressComponents = extractGoogleAddressComponents(place);
      console.log('MyEventLane Location: Extracted address components', addressComponents);
      
      // Populate address fields FIRST - this is the critical functionality.
      populateAddressFields(widget, addressComponents, searchInput);

      // Then set coordinates and show map preview (non-critical).
      const lat = place.geometry.location.lat();
      const lng = place.geometry.location.lng();
      setCoordinates(widget, lat, lng, searchInput);

      // Map preview is optional - don't let it block address population.
      try {
        showMapPreview(widget, lat, lng, 'google_maps', searchInput);
      } catch (error) {
        console.warn('MyEventLane Location: Map preview failed, but address fields were populated:', error);
      }
    });
    
    console.log('MyEventLane Location: Autocomplete setup complete');
  }

  /**
   * Initializes Apple Maps autocomplete.
   */
  function initAppleMapsAutocomplete(widget, searchInput, settings) {
    if (typeof mapkit === 'undefined') {
      const script = document.createElement('script');
      script.src = 'https://cdn.apple-mapkit.com/mk/5.x.x/mapkit.js';
      script.async = true;
      script.onload = function () {
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
    let searchTimeout;
    const searchService = new mapkit.Search({
      region: new mapkit.CoordinateRegion(
        new mapkit.Coordinate(-25.2744, 133.7751),
        new mapkit.CoordinateSpan(40, 40)
      ),
    });

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

    const addressComponents = extractAppleAddressComponents(place);
    // Populate address fields FIRST - this is the critical functionality.
    populateAddressFields(widget, addressComponents, searchInput);
    
    const lat = place.coordinate.latitude;
    const lng = place.coordinate.longitude;
    setCoordinates(widget, lat, lng, searchInput);

    // Map preview is optional - don't let it block address population.
    try {
      showMapPreview(widget, lat, lng, 'apple_maps', searchInput);
    } catch (error) {
      console.warn('MyEventLane Location: Map preview failed, but address fields were populated:', error);
    }
  }

  /**
   * Extracts address components from Google Places result.
   */
  function extractGoogleAddressComponents(place) {
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

    components.address_line1 = components.address_line1.trim();
    
    if (!components.address_line1 && place.formatted_address) {
      const lines = place.formatted_address.split(',');
      components.address_line1 = lines[0] || place.formatted_address;
    }

    return components;
  }

  /**
   * Extracts address components from Apple Maps place.
   */
  function extractAppleAddressComponents(place) {
    return {
      name: place.name || '',
      address_line1: place.formattedAddressLines ? place.formattedAddressLines[0] || '' : '',
      address_line2: place.formattedAddressLines && place.formattedAddressLines[1] ? place.formattedAddressLines[1] : '',
      locality: place.locality || '',
      administrative_area: place.administrativeArea || '',
      postal_code: place.postalCode || '',
      country_code: place.countryCode || 'AU',
    };
  }

  /**
   * Populates address fields in the widget.
   */
  function populateAddressFields(widget, components, searchInput) {
    const form = (searchInput && searchInput.closest('form')) ||
                 widget.closest('form') ||
                 document.querySelector('form.node-event-form, form.node-event-edit-form, form[id*="node-event"], form#event-wizard-form');

    if (!form) {
      console.warn('MyEventLane Location: Form not found for address autocomplete');
      return;
    }
    
    // Debug: Log that we're trying to populate fields.
    console.log('MyEventLane Location: Populating address fields', components);
    
    // Find the location container to scope our search.
    const locationContainer = form.querySelector('[data-mel-address="field_location"], .location-fields-container, .myeventlane-location-address-widget, .field--name-field-location, [class*="location"]') || form;
    console.log('MyEventLane Location: Location container found', locationContainer);
    
    // Check for widget container with data-mel-address attribute.
    const widgetContainer = form.querySelector('[data-mel-address="field_location"]');
    if (widgetContainer) {
      console.log('MyEventLane Location: Widget container with data-mel-address found', widgetContainer);
    } else {
      console.warn('MyEventLane Location: Widget container with data-mel-address="field_location" not found');
    }
    
    // Debug: List all address-related fields in the form.
    const allAddressInputs = locationContainer.querySelectorAll('input[name*="address"], input[name*="locality"], input[name*="postal"], select[name*="administrative"], select[name*="country"]');
    console.log('MyEventLane Location: Found address inputs:', Array.from(allAddressInputs).map(el => ({
      name: el.name,
      id: el.id,
      type: el.type,
      value: el.value,
      visible: el.offsetParent !== null
    })));
    
    // Alternative: Try to find fields by walking the DOM from the search input.
    // Sometimes fields are siblings or in nearby containers.
    if (searchInput) {
      const searchParent = searchInput.closest('fieldset, .form-item, .form-wrapper, [data-mel-address]') || searchInput.parentElement;
      console.log('MyEventLane Location: Search input parent:', searchParent);
      
      // Look for address fields near the search input.
      const nearbyFields = searchParent.querySelectorAll('input, select');
      console.log('MyEventLane Location: Fields near search input:', Array.from(nearbyFields).map(el => ({
        name: el.name,
        id: el.id,
        type: el.type
      })));
    }
    
    const findField = function(selectors, labelText) {
      // PRIORITY 1: Search within the widget container with data-mel-address attribute first.
      const widgetContainer = form.querySelector('[data-mel-address="field_location"]');
      if (widgetContainer) {
        for (const selector of selectors) {
          try {
            const field = widgetContainer.querySelector(selector);
            if (field && (field.offsetParent !== null || field.type === 'hidden')) {
              console.log('MyEventLane Location: Found field via widget container', selector, field.name);
              return field;
            }
          } catch (e) {
            // Invalid selector, skip
          }
        }
      }
      
      // PRIORITY 2: Try selectors scoped to location container.
      for (const selector of selectors) {
        try {
          let field = locationContainer.querySelector(selector);
          if (!field && locationContainer !== form) {
            // Fallback to form-wide search.
            field = form.querySelector(selector);
          }
          // Also try searching from searchInput parent if available.
          if (!field && searchInput) {
            const searchParent = searchInput.closest('fieldset, .form-item, .form-wrapper, [data-mel-address]') || searchInput.parentElement;
            if (searchParent) {
              field = searchParent.querySelector(selector);
            }
          }
          if (field && (field.offsetParent !== null || field.type === 'hidden')) {
            console.log('MyEventLane Location: Found field via selector', selector, field.name);
            return field;
          }
        } catch (e) {
          // Invalid selector, skip
          console.warn('MyEventLane Location: Invalid selector', selector, e);
        }
      }
      
      // PRIORITY 3: Try finding by name pattern matching (more aggressive search).
      if (labelText) {
        const namePatterns = {
          'suburb': ['locality'],
          'state': ['administrative_area', 'administrativeArea'],
          'postal code': ['postal_code', 'postalCode'],
          'street address': ['address_line1', 'addressLine1'],
        };
        
        const patterns = namePatterns[labelText.toLowerCase()] || [];
        for (const pattern of patterns) {
          // Search in widget container first if available.
          let searchScope = widgetContainer || locationContainer;
          let exactMatch = null;
          
          // Try widget container first.
          if (widgetContainer) {
            exactMatch = Array.from(widgetContainer.querySelectorAll('input, select')).find(el => {
              return el.name && (
                el.name.includes(pattern) || 
                el.name.endsWith('[' + pattern + ']') ||
                el.name.includes('[' + pattern + ']')
              );
            });
          }
          
          // Fallback to location container.
          if (!exactMatch) {
            exactMatch = Array.from(locationContainer.querySelectorAll('input, select')).find(el => {
              return el.name && (
                el.name.includes(pattern) || 
                el.name.endsWith('[' + pattern + ']') ||
                el.name.includes('[' + pattern + ']')
              );
            });
          }
          
          // Final fallback: search entire form.
          if (!exactMatch) {
            exactMatch = Array.from(form.querySelectorAll('input, select')).find(el => {
              return el.name && (
                el.name.includes(pattern) || 
                el.name.endsWith('[' + pattern + ']') ||
                el.name.includes('[' + pattern + ']')
              );
            });
          }
          
          if (exactMatch && (exactMatch.offsetParent !== null || exactMatch.type === 'hidden')) {
            console.log('MyEventLane Location: Found field via name pattern', pattern, exactMatch.name);
            return exactMatch;
          }
        }
      }
      
      // PRIORITY 4: Try finding by label text.
      if (labelText) {
        const searchScopes = [];
        if (widgetContainer) searchScopes.push(widgetContainer);
        searchScopes.push(locationContainer);
        searchScopes.push(form);
        
        for (const scope of searchScopes) {
          const labels = Array.from(scope.querySelectorAll('label'));
          for (const label of labels) {
            const labelTextLower = label.textContent.trim().toLowerCase();
            const searchTextLower = labelText.toLowerCase();
            if (labelTextLower.includes(searchTextLower) || searchTextLower.includes(labelTextLower)) {
              const inputId = label.getAttribute('for');
              if (inputId) {
                const field = scope.querySelector('#' + inputId) || form.querySelector('#' + inputId);
                if (field) {
                  console.log('MyEventLane Location: Found field via label', labelText, field.name);
                  return field;
                }
              }
              let field = label.parentElement.querySelector('input, select');
              if (!field) {
                let next = label.nextElementSibling;
                while (next && !field) {
                  field = next.querySelector('input, select');
                  if (!field) next = next.nextElementSibling;
                }
              }
              if (field) {
                console.log('MyEventLane Location: Found field via label (sibling)', labelText, field.name);
                return field;
              }
            }
          }
        }
      }
      
      console.warn('MyEventLane Location: Field not found for', labelText || selectors[0]);
      return null;
    };
    
    const countryCode = findField([
      // Wizard-specific selectors first (highest priority).
      '[data-mel-address="field_location"] select[name*="country_code"]',
      'select[name="location_fields[field_location][country_code]"]',
      '.location-fields-container select[name*="country_code"]',
      '.mel-wizard-step select[name*="country_code"]',
      '.mel-event-wizard__content select[name*="country_code"]',
      // Standard form selectors.
      '[data-drupal-selector="edit-field-location-0-address-country-code"]',
      'select[name="field_location[0][address][country_code]"]',
      'select[name*="field_location"][name*="country_code"]',
      '.field--name-field-location select[name*="country_code"]',
      '.myeventlane-location-address-widget select[name*="country_code"]',
      '.mel-event-form__wizard-content select[name*="country_code"]'
    ], 'country');

    const administrativeArea = findField([
      // Wizard-specific selectors first (highest priority).
      '[data-mel-address="field_location"] select[name*="administrative_area"]',
      'select[name="location_fields[field_location][administrative_area]"]',
      'select[name="location_fields[field_location][0][address][administrative_area]"]',
      'select[name*="location_fields"][name*="field_location"][name*="administrative_area"]',
      '.location-fields-container select[name*="administrative_area"]',
      '.mel-wizard-step select[name*="administrative_area"]',
      '.mel-event-wizard__content select[name*="administrative_area"]',
      // Standard form selectors.
      '[data-drupal-selector="edit-field-location-0-address-administrative-area"]',
      'select[name="field_location[0][address][administrative_area]"]',
      'select[name*="field_location"][name*="administrative_area"]',
      '.field--name-field-location select[name*="administrative_area"]',
      '.myeventlane-location-address-widget select[name*="administrative_area"]',
      '.mel-event-form__wizard-content select[name*="administrative_area"]',
      'select[aria-label*="state" i]',
      'select[aria-label*="administrative" i]'
    ], 'state');

    const locality = findField([
      // Wizard-specific selectors first (highest priority).
      '[data-mel-address="field_location"] input[name*="locality"]',
      'input[name="location_fields[field_location][locality]"]',
      'input[name="location_fields[field_location][0][address][locality]"]',
      'input[name*="location_fields"][name*="field_location"][name*="locality"]',
      '.location-fields-container input[name*="locality"]',
      '.mel-wizard-step input[name*="locality"]',
      '.mel-event-wizard__content input[name*="locality"]',
      // Standard form selectors.
      '[data-drupal-selector="edit-field-location-0-address-locality"]',
      'input[name="field_location[0][address][locality]"]',
      'input[name*="field_location"][name*="locality"]',
      '.field--name-field-location input[name*="locality"]',
      '.myeventlane-location-address-widget input[name*="locality"]',
      '.mel-event-form__wizard-content input[name*="locality"]',
      'input[aria-label*="suburb" i]',
      'input[aria-label*="locality" i]'
    ], 'suburb');

    const postalCode = findField([
      // Wizard-specific selectors first (highest priority).
      '[data-mel-address="field_location"] input[name*="postal_code"]',
      'input[name="location_fields[field_location][postal_code]"]',
      'input[name="location_fields[field_location][0][address][postal_code]"]',
      'input[name*="location_fields"][name*="field_location"][name*="postal_code"]',
      '.location-fields-container input[name*="postal_code"]',
      '.mel-wizard-step input[name*="postal_code"]',
      '.mel-event-wizard__content input[name*="postal_code"]',
      // Standard form selectors.
      '[data-drupal-selector="edit-field-location-0-address-postal-code"]',
      'input[name="field_location[0][address][postal_code]"]',
      'input[name*="field_location"][name*="postal_code"]',
      '.field--name-field-location input[name*="postal_code"]',
      '.myeventlane-location-address-widget input[name*="postal_code"]',
      '.mel-event-form__wizard-content input[name*="postal_code"]',
      'input[aria-label*="postal" i]',
      'input[aria-label*="postcode" i]'
    ], 'postal code');

    const addressLine1 = findField([
      // Wizard-specific selectors first (highest priority).
      '[data-mel-address="field_location"] input[name*="address_line1"]',
      'input[name="location_fields[field_location][address_line1]"]',
      'input[name="location_fields[field_location][0][address][address_line1]"]',
      'input[name*="location_fields"][name*="field_location"][name*="address_line1"]',
      '.location-fields-container input[name*="address_line1"]',
      '.mel-wizard-step input[name*="address_line1"]',
      '.mel-event-wizard__content input[name*="address_line1"]',
      // Standard form selectors.
      '[data-drupal-selector="edit-field-location-0-address-address-line1"]',
      'input[name="field_location[0][address][address_line1]"]',
      'input[name*="field_location"][name*="address_line1"]',
      '.field--name-field-location input[name*="address_line1"]',
      '.myeventlane-location-address-widget input[name*="address_line1"]',
      '.mel-event-form__wizard-content input[name*="address_line1"]',
      'input[aria-label*="street" i]',
      'input[aria-label*="address" i]'
    ], 'street address');

    const addressLine2 = findField([
      '[data-drupal-selector="edit-field-location-0-address-address-line2"]',
      'input[name="field_location[0][address][address_line2]"]',
      'input[name*="field_location"][name*="address_line2"]',
      '.field--name-field-location input[name*="address_line2"]',
      '.mel-event-form__wizard-content input[name*="address_line2"]',
      '.mel-wizard-step input[name*="address_line2"]'
    ], 'address line 2');

    const venueNameField = findField([
      '[data-drupal-selector="edit-field-venue-name-0-value"]',
      'input[name="field_venue_name[0][value]"]',
      'input[name*="field_venue_name"]',
      '.field--name-field-venue-name input',
      '.mel-event-form__wizard-content input[name*="field_venue_name"]',
      '.mel-wizard-step input[name*="field_venue_name"]'
    ], 'venue name');

    // CRITICAL: Set country_code FIRST, then wait for dependent state options.
    if (countryCode) {
      countryCode.value = components.country_code || 'AU';
      countryCode.dispatchEvent(new Event('input', { bubbles: true }));
      countryCode.dispatchEvent(new Event('change', { bubbles: true }));
      countryCode.dispatchEvent(new Event('blur', { bubbles: true }));
    }

    // Wait briefly for state options to populate.
    setTimeout(() => {
      // Populate administrative_area with retry logic.
      if (administrativeArea) {
        const setAdministrativeArea = (retry = false) => {
          if (administrativeArea.tagName === 'SELECT') {
            const options = administrativeArea.options;
            let found = false;
            for (let i = 0; i < options.length; i++) {
              const opt = options[i];
              if (
                opt.value === components.administrative_area ||
                opt.text === components.administrative_area ||
                (components.administrative_area && opt.text.toLowerCase().includes(components.administrative_area.toLowerCase()))
              ) {
                administrativeArea.value = opt.value;
                found = true;
                break;
              }
            }
            if (!found && !retry && options.length <= 1) {
              // Options not ready yet, retry once.
              setTimeout(() => setAdministrativeArea(true), 200);
              return;
            }
          } else {
            administrativeArea.value = components.administrative_area || '';
          }
          administrativeArea.dispatchEvent(new Event('input', { bubbles: true }));
          administrativeArea.dispatchEvent(new Event('change', { bubbles: true }));
          administrativeArea.dispatchEvent(new Event('blur', { bubbles: true }));
        };
        setAdministrativeArea();
      }

      // Populate other fields with a small delay to ensure DOM is ready.
      setTimeout(() => {
        if (locality) {
          locality.value = components.locality || '';
          locality.dispatchEvent(new Event('input', { bubbles: true }));
          locality.dispatchEvent(new Event('change', { bubbles: true }));
          locality.dispatchEvent(new Event('blur', { bubbles: true }));
          console.log('MyEventLane Location: Populated locality', components.locality, 'in field', locality.name);
        } else {
          console.warn('MyEventLane Location: Locality field not found. Available fields:', 
            Array.from(locationContainer.querySelectorAll('input, select')).map(el => ({name: el.name, id: el.id, type: el.type})));
        }

        if (postalCode) {
          postalCode.value = components.postal_code || '';
          postalCode.dispatchEvent(new Event('input', { bubbles: true }));
          postalCode.dispatchEvent(new Event('change', { bubbles: true }));
          postalCode.dispatchEvent(new Event('blur', { bubbles: true }));
          console.log('MyEventLane Location: Populated postal code', components.postal_code, 'in field', postalCode.name);
        } else {
          console.warn('MyEventLane Location: Postal code field not found. Available fields:', 
            Array.from(locationContainer.querySelectorAll('input, select')).map(el => ({name: el.name, id: el.id, type: el.type})));
        }

        if (addressLine1) {
          addressLine1.value = components.address_line1 || '';
          addressLine1.dispatchEvent(new Event('input', { bubbles: true }));
          addressLine1.dispatchEvent(new Event('change', { bubbles: true }));
          addressLine1.dispatchEvent(new Event('blur', { bubbles: true }));
          console.log('MyEventLane Location: Populated address_line1', components.address_line1, 'in field', addressLine1.name);
        } else {
          console.warn('MyEventLane Location: Address line 1 field not found. Available fields:', 
            Array.from(locationContainer.querySelectorAll('input, select')).map(el => ({name: el.name, id: el.id, type: el.type})));
        }
        
        if (administrativeArea) {
          console.log('MyEventLane Location: Populated administrative_area', administrativeArea.value, 'in field', administrativeArea.name);
        } else {
          console.warn('MyEventLane Location: Administrative area field not found. Available fields:', 
            Array.from(locationContainer.querySelectorAll('input, select')).map(el => ({name: el.name, id: el.id, type: el.type})));
        }
      }, 50);

      if (addressLine2 && components.address_line2) {
        addressLine2.value = components.address_line2;
        addressLine2.dispatchEvent(new Event('input', { bubbles: true }));
        addressLine2.dispatchEvent(new Event('change', { bubbles: true }));
        addressLine2.dispatchEvent(new Event('blur', { bubbles: true }));
      }

      if (venueNameField && components.name) {
        let venueName = components.name;
        if (venueName.includes(',') && venueName.length > 50) {
          const parts = venueName.split(',');
          venueName = parts[0].trim();
        }
        venueNameField.value = venueName;
        venueNameField.dispatchEvent(new Event('input', { bubbles: true }));
        venueNameField.dispatchEvent(new Event('change', { bubbles: true }));
        venueNameField.dispatchEvent(new Event('blur', { bubbles: true }));
      }
      
      // Summary log of what was populated.
      const populatedFields = [];
      if (addressLine1 && addressLine1.value) populatedFields.push('address_line1');
      if (locality && locality.value) populatedFields.push('locality');
      if (administrativeArea && administrativeArea.value) populatedFields.push('administrative_area');
      if (postalCode && postalCode.value) populatedFields.push('postal_code');
      if (addressLine2 && addressLine2.value) populatedFields.push('address_line2');
      
      const missingFields = [];
      if (!addressLine1) missingFields.push('address_line1');
      if (!locality) missingFields.push('locality');
      if (!administrativeArea) missingFields.push('administrative_area');
      if (!postalCode) missingFields.push('postal_code');
      
      console.log('MyEventLane Location: Address population summary - Populated:', populatedFields.join(', '));
      if (missingFields.length > 0) {
        console.warn('MyEventLane Location: Missing fields:', missingFields.join(', '));
        // Log all available fields for debugging.
        const widgetContainer = form.querySelector('[data-mel-address="field_location"]');
        const searchScope = widgetContainer || locationContainer;
        const allFields = Array.from(searchScope.querySelectorAll('input, select'));
        console.log('MyEventLane Location: All available fields in scope:', allFields.map(el => ({
          name: el.name,
          id: el.id,
          type: el.type,
          value: el.value,
          visible: el.offsetParent !== null
        })));
      }
    }, 100);
  }

  /**
   * Sets latitude and longitude in hidden fields.
   */
  function setCoordinates(widget, lat, lng, searchInput) {
    const form = searchInput ? searchInput.closest('form') : (widget.closest('form') || document.querySelector('form'));
    if (form) {
      let eventLatField = form.querySelector('input.myeventlane-location-latitude-field[type="hidden"]');
      let eventLngField = form.querySelector('input.myeventlane-location-longitude-field[type="hidden"]');

      if (!eventLatField) {
        eventLatField = form.querySelector('.myeventlane-location-latitude[type="hidden"]');
      }
      if (!eventLngField) {
        eventLngField = form.querySelector('.myeventlane-location-longitude[type="hidden"]');
      }
      
      if (!eventLatField) {
        const hiddenLatFields = form.querySelectorAll('input[type="hidden"][name*="field_location_latitude"], input[type="hidden"][name*="field_event_lat"]');
        if (hiddenLatFields.length > 0) {
          eventLatField = hiddenLatFields[0];
        }
      }
      if (!eventLngField) {
        const hiddenLngFields = form.querySelectorAll('input[type="hidden"][name*="field_location_longitude"], input[type="hidden"][name*="field_event_lng"]');
        if (hiddenLngFields.length > 0) {
          eventLngField = hiddenLngFields[0];
        }
      }
      
      const wizardContent = form.querySelector('.mel-event-form__wizard-content, .mel-wizard-step, .mel-event-form__section');
      if (wizardContent) {
        if (!eventLatField) {
          eventLatField = wizardContent.querySelector('input[type="hidden"].myeventlane-location-latitude-field, input[type="hidden"][name*="field_location_latitude"]');
        }
        if (!eventLngField) {
          eventLngField = wizardContent.querySelector('input[type="hidden"].myeventlane-location-longitude-field, input[type="hidden"][name*="field_location_longitude"]');
        }
      }
      
      if (eventLatField) {
        const formattedLat = parseFloat(lat.toFixed(7));
        eventLatField.value = formattedLat.toString();
        eventLatField.dispatchEvent(new Event('input', { bubbles: true }));
        eventLatField.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (eventLngField) {
        const formattedLng = parseFloat(lng.toFixed(7));
        eventLngField.value = formattedLng.toString();
        eventLngField.dispatchEvent(new Event('input', { bubbles: true }));
        eventLngField.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

  /**
   * Shows a map preview with a marker.
   */
  /**
   * Shows a map preview of the selected location.
   * Gracefully skips if container doesn't exist (e.g., in wizard forms).
   */
  function showMapPreview(widget, lat, lng, provider, searchInput) {
    const form = searchInput ? searchInput.closest('form') : (widget.closest('form') || document.querySelector('form'));
    if (!form) {
      console.log('MyEventLane Location: showMapPreview - no form found');
      return;
    }
    
    let previewContainer = form.querySelector('.myeventlane-location-map-preview');
    
    if (!previewContainer) {
      const wizardContent = form.querySelector('.mel-event-form__wizard-content, .mel-wizard-step, .mel-event-form__section, .mel-event-wizard__content, .location-fields-container');
      if (wizardContent) {
        previewContainer = wizardContent.querySelector('.myeventlane-location-map-preview');
      }
    }
    
    if (!previewContainer && searchInput) {
      const searchContainer = searchInput.closest('[data-mel-address="field_location"], .field--name-field-location, fieldset, .myeventlane-location-address-widget, .form-item');
      if (searchContainer) {
        previewContainer = searchContainer.querySelector('.myeventlane-location-map-preview');
      }
    }
    
    // If no preview container exists (e.g., in wizard forms), skip map preview.
    // This is fine - the address autocomplete still works without the preview.
    if (!previewContainer) {
      console.log('MyEventLane Location: showMapPreview - no preview container found, skipping (this is OK for wizard forms)');
      return;
    }

    // Only show map if container exists and has dimensions.
    if (previewContainer.offsetWidth === 0 || previewContainer.offsetHeight === 0) {
      console.log('MyEventLane Location: showMapPreview - container has no dimensions, skipping');
      return;
    }

    try {
      previewContainer.style.display = 'block';

      if (provider === 'google_maps') {
        if (typeof google !== 'undefined' && google.maps && google.maps.Map) {
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
          console.log('MyEventLane Location: Map preview displayed');
        } else {
          console.warn('MyEventLane Location: Google Maps API not fully loaded');
        }
      } else if (provider === 'apple_maps') {
        if (typeof mapkit !== 'undefined' && mapkit.Map) {
          const map = new mapkit.Map(previewContainer);
          const coordinate = new mapkit.Coordinate(lat, lng);
          map.region = new mapkit.CoordinateRegion(coordinate, new mapkit.CoordinateSpan(0.01, 0.01));
          const marker = new mapkit.MarkerAnnotation(coordinate);
          map.addAnnotation(marker);
          console.log('MyEventLane Location: Apple Maps preview displayed');
        } else {
          console.warn('MyEventLane Location: MapKit not fully loaded');
        }
      }
    } catch (error) {
      console.error('MyEventLane Location: Error displaying map preview:', error);
      // Don't let map preview errors break address autocomplete.
    }
  }

  // Initialize on DOM ready, but ONLY on event forms.
  function initialize() {
    console.log('MyEventLane Location: Initialize function called');
    const isEventForm = document.querySelector('.myeventlane-location-address-search') ||
                        document.querySelector('form.node-event-form, form.node-event-edit-form, form[id*="node-event"]') ||
                        document.querySelector('form.mel-event-form-vendor') ||
                        document.querySelector('.mel-event-form--wizard') ||
                        document.querySelector('form#event-wizard-form, form[id="event-wizard-form"], form[id*="event_wizard"]') ||
                        document.querySelector('.mel-event-wizard') ||
                        document.querySelector('.mel-event-wizard__content');
    
    console.log('MyEventLane Location: Is event form?', !!isEventForm);
    
    if (!isEventForm) {
      return;
    }
    
    console.log('MyEventLane Location: Calling initAddressAutocomplete in 300ms');
    setTimeout(initAddressAutocomplete, 300);
  }

  console.log('MyEventLane Location: Script loaded, document readyState:', document.readyState);
  console.log('MyEventLane Location: Drupal available?', typeof Drupal !== 'undefined');
  console.log('MyEventLane Location: drupalSettings available?', typeof drupalSettings !== 'undefined');
  
  // Initialize immediately if document is ready, otherwise wait.
  if (document.readyState === 'loading') {
    console.log('MyEventLane Location: Adding DOMContentLoaded listener');
    document.addEventListener('DOMContentLoaded', function() {
      console.log('MyEventLane Location: DOMContentLoaded fired');
      initialize();
    });
  } else {
    console.log('MyEventLane Location: Document already ready, calling initialize');
    // Use setTimeout to ensure DOM is fully ready.
    setTimeout(initialize, 100);
  }
  
  // Use Drupal behaviors for proper integration (especially for AJAX).
  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    console.log('MyEventLane Location: Registering Drupal behavior');
    Drupal.behaviors.myeventlaneLocationAutocomplete = {
      attach: function (context, settings) {
        console.log('MyEventLane Location: Behavior attach called', context, 'settings:', settings);
        
        // Check if we're in an event form context.
        const isEventForm = context.querySelector('.myeventlane-location-address-search') ||
                            context.querySelector('form.node-event-form, form.node-event-edit-form, form[id*="node-event"]') ||
                            context.querySelector('form.mel-event-form-vendor') ||
                            context.querySelector('.mel-event-form--wizard') ||
                            context.querySelector('form#event-wizard-form, form[id="event-wizard-form"], form[id*="event_wizard"]') ||
                            context.querySelector('.mel-event-wizard') ||
                            context.querySelector('.mel-event-wizard__content') ||
                            context.querySelector('.location-fields-container');
        
        // Also check if the full document contains wizard elements (for initial page load).
        const hasWizard = document.querySelector('.mel-event-wizard') || 
                         document.querySelector('form#event-wizard-form, form[id="event-wizard-form"]') ||
                         document.querySelector('.mel-event-wizard__content');
        
        console.log('MyEventLane Location: Behavior - Is event form?', !!isEventForm, 'Has wizard?', !!hasWizard, 'context === document?', context === document);
        
        // Initialize if we're in an event form context, or if we're on the full document and there's a wizard.
        if (isEventForm || (context === document && hasWizard)) {
          console.log('MyEventLane Location: Behavior - Calling initAddressAutocomplete in 200ms');
          setTimeout(function() {
            // Clear the initialized forms set for this context to allow re-initialization after AJAX.
            // Only clear if we're dealing with a specific context (not the full document).
            if (context !== document) {
              const form = context.closest('form') || context.querySelector('form');
              if (form && initializedForms.has(form)) {
                console.log('MyEventLane Location: Clearing initialization flag for form to allow re-init');
                initializedForms.delete(form);
              }
            }
            initAddressAutocomplete();
          }, 200);
        }
      }
    };
  } else {
    console.warn('MyEventLane Location: Drupal behaviors not available, using fallback initialization');
    // Fallback: try to initialize after a delay if Drupal behaviors aren't available.
    setTimeout(function() {
      if (document.querySelector('.myeventlane-location-address-search')) {
        console.log('MyEventLane Location: Fallback initialization - search input found');
        initialize();
      }
    }, 1000);
  }
  
  // Also re-initialize after AJAX (for wizard step changes).
  if (typeof Drupal !== 'undefined' && Drupal.ajax) {
    // Use once() to avoid multiple wrappers if script loads multiple times.
    if (!Drupal.ajax.prototype._myeventlaneLocationWrapped) {
      const originalSuccess = Drupal.ajax.prototype.success;
      Drupal.ajax.prototype.success = function(response, status) {
        if (originalSuccess) {
          originalSuccess.call(this, response, status);
        }
        // Re-initialize address autocomplete after AJAX completes (for wizard steps).
        console.log('MyEventLane Location: AJAX success, re-initializing');
        // Clear initialization flags for forms in the response.
        if (response && response.commands) {
          response.commands.forEach(function(cmd) {
            if (cmd.selector) {
              const elements = document.querySelectorAll(cmd.selector);
              elements.forEach(function(el) {
                const form = el.closest('form');
                if (form && initializedForms.has(form)) {
                  console.log('MyEventLane Location: Clearing initialization flag after AJAX');
                  initializedForms.delete(form);
                }
              });
            }
          });
        }
        setTimeout(function() {
          initAddressAutocomplete();
        }, 300);
      };
      Drupal.ajax.prototype._myeventlaneLocationWrapped = true;
    }
  }

})(typeof Drupal !== 'undefined' ? Drupal : {}, typeof drupalSettings !== 'undefined' ? drupalSettings : {});
