/**
 * @file
 * MyEventLane Location autocomplete (Google Places / Apple MapKit).
 *
 * Goals:
 * - Drupal-behaviors compatible (AJAX-safe).
 * - No prototype patching / no duplicate listeners.
 * - No invalid selectors (e.g. :has()).
 * - Populate Drupal Address field widgets reliably.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const SETTINGS = (drupalSettings && drupalSettings.myeventlaneLocation) ? drupalSettings.myeventlaneLocation : {};
  const DEBUG = !!SETTINGS.debug || true; // Temporarily enable debug for diagnosis

  function log(...args) {
    if (DEBUG && window.console) console.log('[MEL Location]', ...args);
  }
  function warn(...args) {
    if (window.console) console.warn('[MEL Location]', ...args);
  }
  function error(...args) {
    if (window.console) console.error('[MEL Location]', ...args);
  }
  
  // Always log critical initialization info
  if (window.console && !SETTINGS.provider && !SETTINGS.debug) {
    console.warn('[MEL Location] SETTINGS.provider is not set. Provider:', SETTINGS.provider, 'Full SETTINGS:', SETTINGS);
  }

  /**
   * ------------------------------------------------------------------------
   * Utilities
   * ------------------------------------------------------------------------
   */

  function isVisible(el) {
    return !!(el && (el.offsetParent !== null || el.getClientRects().length));
  }

  function closestForm(el) {
    return el ? el.closest('form') : null;
  }

  /**
   * Return the best "widget root" for field_location or field_venue_address.
   *
   * Priority (VISIBLE widgets preferred):
   * 1) Explicit wrapper: [data-mel-address="field_venue_address"] (PHASE 5: prefer venue address)
   * 2) Explicit wrapper: [data-mel-address="field_location"]
   * 3) Drupal field wrapper: .field--name-field-venue-address
   * 4) Drupal field wrapper: .field--name-field-location
   * 5) Closest fieldset containing address inputs
   * 6) Form fallback
   *
   * IMPORTANT: Only return visible widgets. Hidden widgets (like field_location
   * in the Event Wizard) should be skipped.
   */
  function getLocationWidgetRoot(form) {
    if (!form) return null;

    // PHASE 5: Prefer field_venue_address if it exists AND is visible.
    let root = form.querySelector('[data-mel-address="field_venue_address"]');
    if (root && isVisible(root)) {
      log('Found field_venue_address widget root by data-mel-address attribute');
      return root;
    }

    root = form.querySelector('[data-mel-address="field_location"]');
    if (root && isVisible(root)) {
      log('Found field_location widget root by data-mel-address attribute');
      return root;
    }

    root = form.querySelector('.field--name-field-venue-address');
    if (root && isVisible(root)) {
      log('Found field_venue_address widget root by class');
      return root;
    }

    root = form.querySelector('.field--name-field-location');
    if (root && isVisible(root)) {
      log('Found field_location widget root by class');
      return root;
    }

    // Fieldset containing VISIBLE address inputs (prefer field_venue_address).
    const fieldsets = form.querySelectorAll('fieldset');
    for (const fs of fieldsets) {
      if (!isVisible(fs)) continue;
      if (fs.querySelector('input[name*="field_venue_address"][name*="[address][address_line1]"]') ||
          fs.querySelector('input[name*="field_venue_address"][name*="[address][locality]"]')) {
        log('Found field_venue_address widget root in fieldset');
        return fs;
      }
    }
    for (const fs of fieldsets) {
      if (!isVisible(fs)) continue;
      if (fs.querySelector('input[name*="[address][address_line1]"]') ||
          fs.querySelector('input[name*="[address][locality]"]') ||
          fs.querySelector('input[name*="[address][postal_code]"]')) {
        log('Found address widget root in fieldset');
        return fs;
      }
    }

    // Fall back to form but log it.
    log('Using form as widget root fallback (no visible address widget found)');
    return form;
  }

  /**
   * Finds the search input that the vendor types into.
   * Returns the FIRST VISIBLE input matching any of these selectors:
   * - .myeventlane-location-address-search
   * - input[data-address-search="true"]
   * - name contains field_location_address_search
   *
   * IMPORTANT: We search for ALL matches and return the first visible one
   * because forms may have multiple search inputs (e.g., one hidden in
   * field_location widget, one visible at the top).
   */
  function findSearchInput(context) {
    if (!context) return null;

    // Collect all possible search inputs.
    const selectors = [
      '.myeventlane-location-address-search',
      'input[data-address-search="true"]',
      'input[name*="field_location_address_search"]',
      'input[name*="address_search"]'
    ];

    // First pass: try to find a visible input.
    for (const selector of selectors) {
      const inputs = context.querySelectorAll(selector);
      for (const input of inputs) {
        if (isVisible(input)) {
          log('Found visible search input with selector:', selector);
          return input;
        }
      }
    }

    // Second pass: if no visible input, return the first match (for backwards compat).
    for (const selector of selectors) {
      const input = context.querySelector(selector);
      if (input) {
        log('No visible search input found, falling back to first match:', selector);
        return input;
      }
    }

    return null;
  }

  /**
   * Safely set a field value and notify Drupal.
   */
  function setFieldValue(field, value) {
    if (!field) return;
    field.value = value;

    // Ensure Drupal states & widgets react.
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    field.dispatchEvent(new Event('blur', { bubbles: true }));
  }

  /**
   * Attempt to find address component field by name patterns within widget root.
   * 
   * @param {HTMLElement} widgetRoot - Root element to search within
   * @param {string} componentName - Component name (e.g., 'address_line1', 'locality')
   * @param {boolean} allowSelect - Whether to allow select elements
   * @param {string} fieldPrefix - Optional field name prefix (e.g., 'field_venue_address')
   */
  function findAddressComponent(widgetRoot, componentName, allowSelect = true, fieldPrefix = null) {
    if (!widgetRoot) {
      log('findAddressComponent: No widgetRoot provided');
      return null;
    }

    // Standard Drupal address naming:
    // ...[address][address_line1]
    // ...[address][locality]
    // ...[address][administrative_area]
    // ...[address][postal_code]
    // ...[address][country_code]
    const baseSelector = `[name*="[address][${componentName}]"]`;
    let field = widgetRoot.querySelector(`input${baseSelector}`);

    if (!field && allowSelect) {
      field = widgetRoot.querySelector(`select${baseSelector}`);
    }
    
    // If fieldPrefix is specified, ensure the field name includes it.
    if (field && fieldPrefix && !field.name.includes(fieldPrefix)) {
      log('findAddressComponent: Field found but prefix mismatch', { fieldName: field.name, fieldPrefix });
      field = null;
    }
    
    if (field) {
      log('findAddressComponent: Found field via baseSelector', { componentName, fieldName: field.name, fieldType: field.tagName });
      return field;
    }

    // Fallback patterns (some widgets differ slightly):
    const fallbackSelector = `[name*="${componentName}"]`;
    field = widgetRoot.querySelector(`input${fallbackSelector}`);
    
    if (!field && allowSelect) {
      field = widgetRoot.querySelector(`select${fallbackSelector}`);
    }
    
    // If fieldPrefix is specified, ensure the field name includes it.
    if (field && fieldPrefix && !field.name.includes(fieldPrefix)) {
      log('findAddressComponent: Field found via fallback but prefix mismatch', { fieldName: field.name, fieldPrefix });
      field = null;
    }

    if (field) {
      log('findAddressComponent: Found field via fallbackSelector', { componentName, fieldName: field.name, fieldType: field.tagName });
    } else {
      log('findAddressComponent: Field NOT FOUND', { componentName, widgetRoot: widgetRoot.tagName, widgetRootClass: widgetRoot.className });
      // Debug: List all inputs in widgetRoot
      const allInputs = widgetRoot.querySelectorAll('input, select');
      log('findAddressComponent: All inputs in widgetRoot:', Array.from(allInputs).map(el => ({ name: el.name, type: el.type || el.tagName })));
    }

    return field || null;
  }

  /**
   * Attempt to find lat/lng hidden fields by common MEL patterns.
   */
  function findLatLngFields(form, widgetRoot) {
    const scope = widgetRoot || form || document;

    // Prefer explicit classes.
    let lat = scope.querySelector('input.myeventlane-location-latitude-field[type="hidden"], input.myeventlane-location-latitude[type="hidden"]');
    let lng = scope.querySelector('input.myeventlane-location-longitude-field[type="hidden"], input.myeventlane-location-longitude[type="hidden"]');

    // Fallback by name patterns.
    if (!lat) lat = scope.querySelector('input[type="hidden"][name*="field_location_latitude"], input[type="hidden"][name*="field_event_lat"], input[type="hidden"][name*="latitude"]');
    if (!lng) lng = scope.querySelector('input[type="hidden"][name*="field_location_longitude"], input[type="hidden"][name*="field_event_lng"], input[type="hidden"][name*="longitude"]');

    // If still missing, try whole form.
    if (form) {
      if (!lat) lat = form.querySelector('input[type="hidden"][name*="latitude"]');
      if (!lng) lng = form.querySelector('input[type="hidden"][name*="longitude"]');
    }

    return { lat, lng };
  }

  /**
   * Place ID hidden field (optional).
   */
  function findPlaceIdField(form, widgetRoot) {
    const scope = widgetRoot || form || document;

    let f = scope.querySelector('input.mel-place-id[type="hidden"]');
    if (f) return f;

    f = scope.querySelector('input[type="hidden"][name*="field_location_place_id"], input[type="hidden"][name*="place_id"]');
    if (f) return f;

    if (form) {
      f = form.querySelector('input[type="hidden"][name*="field_location_place_id"], input[type="hidden"][name*="place_id"]');
    }

    return f || null;
  }

  /**
   * Venue name field (optional).
   * Supports both field_venue_name and the new venue_name field in wizard.
   */
  function findVenueNameField(form) {
    if (!form) return null;
    // Try wizard venue name field first.
    let field = form.querySelector('input.myeventlane-venue-name-field');
    if (field) return field;
    // Fallback to field_venue_name.
    return form.querySelector('input[name*="field_venue_name"]') || null;
  }

  /**
   * Normalize AU state values to short codes if needed.
   */
  function normalizeAUState(value) {
    if (!value) return '';
    const v = String(value).trim();

    // Already a short code.
    const short = ['NSW','VIC','QLD','SA','WA','TAS','ACT','NT'];
    if (short.includes(v.toUpperCase())) return v.toUpperCase();

    // Map common full names and variations.
    const map = {
      'new south wales': 'NSW',
      'nsw': 'NSW',
      'victoria': 'VIC',
      'vic': 'VIC',
      'queensland': 'QLD',
      'qld': 'QLD',
      'south australia': 'SA',
      'sa': 'SA',
      'western australia': 'WA',
      'wa': 'WA',
      'tasmania': 'TAS',
      'tas': 'TAS',
      'australian capital territory': 'ACT',
      'act': 'ACT',
      'northern territory': 'NT',
      'nt': 'NT',
    };
    const key = v.toLowerCase();
    const mapped = map[key];
    if (mapped) {
      log('Normalized state:', v, '->', mapped);
      return mapped;
    }
    // Return original if no mapping found (might be a valid value)
    log('State value not normalized:', v);
    return v;
  }

  /**
   * Populate Drupal Address widget fields.
   */
  function populateAddressWidget(form, widgetRoot, components) {
    if (!form || !widgetRoot || !components) {
      log('populateAddressWidget: Missing required params', { form: !!form, widgetRoot: !!widgetRoot, components: !!components });
      return;
    }

    log('populateAddressWidget: Starting, widgetRoot:', widgetRoot, 'components:', components);
    
    const country = findAddressComponent(widgetRoot, 'country_code', true);
    const state = findAddressComponent(widgetRoot, 'administrative_area', true);
    const suburb = findAddressComponent(widgetRoot, 'locality', false);
    const postcode = findAddressComponent(widgetRoot, 'postal_code', false);
    const line1 = findAddressComponent(widgetRoot, 'address_line1', false);
    const line2 = findAddressComponent(widgetRoot, 'address_line2', false);
    
    log('populateAddressWidget: Found fields', {
      country: country ? country.name : 'NOT FOUND',
      state: state ? state.name : 'NOT FOUND',
      suburb: suburb ? suburb.name : 'NOT FOUND',
      postcode: postcode ? postcode.name : 'NOT FOUND',
      line1: line1 ? line1.name : 'NOT FOUND',
      line2: line2 ? line2.name : 'NOT FOUND',
    });

    // Country first (drives dynamic state list in many configs).
    // Default to AU if empty or not provided.
    // IMPORTANT: Setting country may trigger AJAX refresh, so we need to wait for it.
    if (country) {
      const countryValue = components.country_code || 'AU';
      const currentCountryValue = country.value || '';
      
      log('Setting country:', countryValue, 'on field:', country.name, 'current value:', currentCountryValue);
      
      // Only set country if it's different (to avoid unnecessary AJAX)
      if (currentCountryValue !== countryValue) {
        setFieldValue(country, countryValue);
        log('Country value after set:', country.value);
        
        // Wait for AJAX to complete if country change triggers it
        // Check if there's an AJAX wrapper that will refresh
        const ajaxWrapper = widgetRoot.querySelector('[data-drupal-selector*="ajax"], .ajax-wrapper, [id*="ajax"]');
        if (ajaxWrapper) {
          log('Detected AJAX wrapper, waiting for AJAX to complete before setting other fields');
          
          // Listen for AJAX completion
          const ajaxComplete = () => {
            log('AJAX complete, now setting remaining address fields');
            populateRemainingFields();
          };
          
          // Use jQuery AJAX complete if available, otherwise use MutationObserver
          if (window.jQuery) {
            window.jQuery(document).one('ajaxComplete', ajaxComplete);
            // Also set a timeout fallback
            setTimeout(() => {
              window.jQuery(document).off('ajaxComplete', ajaxComplete);
              populateRemainingFields();
            }, 2000);
          } else {
            // Fallback: use MutationObserver to detect when AJAX wrapper content changes
            const observer = new MutationObserver((mutations) => {
              log('AJAX wrapper content changed, setting remaining fields');
              observer.disconnect();
              setTimeout(populateRemainingFields, 100);
            });
            observer.observe(ajaxWrapper, { childList: true, subtree: true });
            // Fallback timeout
            setTimeout(() => {
              observer.disconnect();
              populateRemainingFields();
            }, 2000);
          }
          
          return; // Exit early, remaining fields will be set after AJAX
        }
      } else {
        log('Country already set to', countryValue, ', skipping');
      }
    } else {
      warn('Country field not found!');
    }
    
    // If no AJAX was triggered, set remaining fields immediately
    populateRemainingFields();
    
    function populateRemainingFields() {
      // Re-find fields in case AJAX refreshed them
      const refreshedLine1 = findAddressComponent(widgetRoot, 'address_line1', false);
      const refreshedLine2 = findAddressComponent(widgetRoot, 'address_line2', false);
      const refreshedSuburb = findAddressComponent(widgetRoot, 'locality', false);
      const refreshedPostcode = findAddressComponent(widgetRoot, 'postal_code', false);
      const refreshedState = findAddressComponent(widgetRoot, 'administrative_area', true);
      
      // line1
      const fieldToUse = refreshedLine1 || line1;
      if (fieldToUse) {
        const line1Value = components.address_line1 || '';
        log('Setting address_line1:', line1Value, 'on field:', fieldToUse.name);
        setFieldValue(fieldToUse, line1Value);
        log('Address_line1 value after set:', fieldToUse.value);
      } else {
        warn('Address line1 field not found!');
      }

      // line2 optional
      const fieldToUse2 = refreshedLine2 || line2;
      if (fieldToUse2 && components.address_line2) {
        log('Setting address_line2:', components.address_line2, 'on field:', fieldToUse2.name);
        setFieldValue(fieldToUse2, components.address_line2);
      }

      // suburb + postcode
      const fieldToUseSuburb = refreshedSuburb || suburb;
      if (fieldToUseSuburb) {
        const suburbValue = components.locality || '';
        log('Setting locality:', suburbValue, 'on field:', fieldToUseSuburb.name);
        setFieldValue(fieldToUseSuburb, suburbValue);
        log('Locality value after set:', fieldToUseSuburb.value);
      } else {
        warn('Locality field not found!');
      }
      
      const fieldToUsePostcode = refreshedPostcode || postcode;
      if (fieldToUsePostcode) {
        const postcodeValue = components.postal_code || '';
        log('Setting postal_code:', postcodeValue, 'on field:', fieldToUsePostcode.name);
        setFieldValue(fieldToUsePostcode, postcodeValue);
        log('Postal_code value after set:', fieldToUsePostcode.value);
      } else {
        warn('Postal_code field not found!');
      }

      // state: if select, prefer matching option.
      const fieldToUseState = refreshedState || state;
      if (fieldToUseState) {
        const desired = normalizeAUState(components.administrative_area || '');
        if (fieldToUseState.tagName === 'SELECT') {
          // Try direct match, else match label.
          let matched = false;
          for (const opt of fieldToUseState.options) {
            if (opt.value === desired || opt.text === desired) {
              fieldToUseState.value = opt.value;
              matched = true;
              log('State matched exactly:', opt.value);
              break;
            }
          }
          if (!matched && desired) {
            for (const opt of fieldToUseState.options) {
              if ((opt.text || '').toUpperCase().includes(desired.toUpperCase())) {
                fieldToUseState.value = opt.value;
                matched = true;
                log('State matched partially:', opt.value);
                break;
              }
            }
          }
          if (matched) {
            fieldToUseState.dispatchEvent(new Event('change', { bubbles: true }));
            fieldToUseState.dispatchEvent(new Event('input', { bubbles: true }));
          } else {
            warn('State value not matched:', desired, 'Available options:', Array.from(fieldToUseState.options).map(o => o.value + '=' + o.text));
          }
        }
        else {
          setFieldValue(fieldToUseState, desired);
        }
      }
    }

    // Trigger Drupal formUpdated if jQuery exists, otherwise input/change is enough.
    if (window.jQuery) {
      window.jQuery(form).trigger('formUpdated');
    }

    // Hard verify log (debug only).
    log('Populated address:', {
      address_line1: line1 ? line1.value : null,
      locality: suburb ? suburb.value : null,
      administrative_area: state ? state.value : null,
      postal_code: postcode ? postcode.value : null,
      country_code: country ? country.value : null,
    });
  }

  /**
   * Populate venue name (optional).
   * 
   * @param {HTMLFormElement} form
   * @param {string} name - Place/venue name from autocomplete
   */
  function populateVenueName(form, name) {
    if (!form || !name) return;
    const field = findVenueNameField(form);
    if (!field) {
      log('Venue name field not found in form');
      // Try to find it by name pattern as fallback
      const fallbackField = form.querySelector('input[name*="venue_name"]');
      if (fallbackField) {
        log('Found venue name field via fallback:', fallbackField.name);
        const clean = String(name).trim().split(',')[0].trim();
        setFieldValue(fallbackField, clean);
        log('Populated venue name via fallback:', clean);
        return;
      }
      return;
    }

    let clean = String(name).trim();
    // Trim at first comma if very long.
    if (clean.includes(',')) {
      clean = clean.split(',')[0].trim();
    }
    
    log('Populating venue name:', clean, 'on field:', field.name || field.className);
    setFieldValue(field, clean);
    log('Venue name value after set:', field.value);
    
    // Ensure Drupal recognizes this change by triggering formUpdated
    if (window.jQuery) {
      window.jQuery(form).trigger('formUpdated');
      // Also trigger on the field specifically
      window.jQuery(field).trigger('change').trigger('input');
    }
    
    log('Populated venue name:', clean);
  }

  /**
   * Populate field_venue_address widget with address components.
   * 
   * @param {HTMLFormElement} form
   * @param {Object} components - Address components object
   */
  function populateVenueAddress(form, components) {
    if (!form || !components) return;

    log('populateVenueAddress called with components:', components);

    // First, try to find field_venue_address widget root using the same logic as getLocationWidgetRoot
    // but specifically for field_venue_address
    let widgetRoot = form.querySelector('[data-mel-address="field_venue_address"]');
    
    if (!widgetRoot) {
      // Try finding by class
      widgetRoot = form.querySelector('.field--name-field-venue-address');
    }
    
    if (!widgetRoot) {
      // Try finding by fieldset containing field_venue_address inputs
      const fieldsets = form.querySelectorAll('fieldset');
      for (const fs of fieldsets) {
        if (fs.querySelector('input[name*="field_venue_address"][name*="[address][address_line1]"]') ||
            fs.querySelector('input[name*="field_venue_address"][name*="[address][locality]"]')) {
          widgetRoot = fs;
          break;
        }
      }
    }
    
    if (!widgetRoot) {
      // Search for any input with field_venue_address in name to find the container
      const venueAddressInputs = form.querySelectorAll('[name*="field_venue_address"]');
      if (venueAddressInputs && venueAddressInputs.length > 0) {
        const firstInput = venueAddressInputs[0];
        widgetRoot = firstInput.closest('[data-mel-address="field_venue_address"]') ||
                     firstInput.closest('.field--widget-address-default') ||
                     firstInput.closest('.field--name-field-venue-address') ||
                     firstInput.closest('[data-drupal-selector*="field-venue-address"]') ||
                     firstInput.closest('.js-form-item-field-venue-address') ||
                     firstInput.closest('.field--type-address');
        
        // If still not found, traverse up
        if (!widgetRoot) {
          let element = firstInput.parentElement;
          let depth = 0;
          while (element && element !== form && depth < 10) {
            if (element.classList && (
              element.classList.contains('field') ||
              element.classList.contains('js-form-item') ||
              element.getAttribute('data-drupal-selector')?.includes('field-venue-address')
            )) {
              widgetRoot = element;
              break;
            }
            element = element.parentElement;
            depth++;
          }
        }
      }
    }

    if (widgetRoot) {
      log('Found field_venue_address widget root, using populateAddressWidget');
      // Use the standard populateAddressWidget function which handles all the logic
      populateAddressWidget(form, widgetRoot, components);
      return;
    }

    // Fallback: search entire form for field_venue_address inputs and populate directly
    log('Widget root not found, using form-wide search for field_venue_address');
    const allInputs = form.querySelectorAll('input, select');
    let foundAny = false;
    for (const input of allInputs) {
      const name = input.name || '';
      if (name.includes('field_venue_address') && name.includes('[address]')) {
        foundAny = true;
        if (name.includes('country_code')) {
          setFieldValue(input, components.country_code || 'AU');
          log('Set country_code via form-wide search:', components.country_code || 'AU');
        } else if (name.includes('address_line1')) {
          setFieldValue(input, components.address_line1 || '');
          log('Set address_line1 via form-wide search:', components.address_line1 || '');
        } else if (name.includes('address_line2') && components.address_line2) {
          setFieldValue(input, components.address_line2);
          log('Set address_line2 via form-wide search:', components.address_line2);
        } else if (name.includes('locality')) {
          setFieldValue(input, components.locality || '');
          log('Set locality via form-wide search:', components.locality || '');
        } else if (name.includes('postal_code')) {
          setFieldValue(input, components.postal_code || '');
          log('Set postal_code via form-wide search:', components.postal_code || '');
        } else if (name.includes('administrative_area')) {
          const desired = normalizeAUState(components.administrative_area || '');
          if (input.tagName === 'SELECT') {
            let matched = false;
            // Try exact value match first
            for (const opt of input.options) {
              if (opt.value === desired || opt.text === desired) {
                input.value = opt.value;
                matched = true;
                log('Set administrative_area via form-wide search (exact match):', opt.value);
                break;
              }
            }
            // Try case-insensitive partial match
            if (!matched && desired) {
              for (const opt of input.options) {
                const optTextUpper = (opt.text || '').toUpperCase();
                const optValueUpper = (opt.value || '').toUpperCase();
                const desiredUpper = desired.toUpperCase();
                if (optTextUpper.includes(desiredUpper) || optValueUpper.includes(desiredUpper) || 
                    desiredUpper.includes(optTextUpper) || desiredUpper.includes(optValueUpper)) {
                  input.value = opt.value;
                  matched = true;
                  log('Set administrative_area via form-wide search (partial match):', opt.value, 'for desired:', desired);
                  break;
                }
              }
            }
            if (!matched) {
              warn('Could not match administrative_area:', desired, 'Available:', Array.from(input.options).map(o => o.value + '=' + o.text).join(', '));
            }
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input', { bubbles: true }));
          } else {
            setFieldValue(input, desired);
            log('Set administrative_area via form-wide search (text input):', desired);
          }
        }
      }
    }
    
    if (!foundAny) {
      warn('No field_venue_address inputs found in form');
    } else {
      // Trigger form update
      if (window.jQuery) {
        window.jQuery(form).trigger('formUpdated');
      }
    }
    
    log('Populated field_venue_address:', {
      address_line1: components.address_line1 || '',
      locality: components.locality || '',
      administrative_area: components.administrative_area || '',
      postal_code: components.postal_code || '',
      country_code: components.country_code || 'AU',
    });
  }

  /**
   * Set coordinates (optional).
   */
  function populateLatLng(form, widgetRoot, lat, lng) {
    const fields = findLatLngFields(form, widgetRoot);
    if (fields.lat) setFieldValue(fields.lat, String(Number(lat).toFixed(7)));
    if (fields.lng) setFieldValue(fields.lng, String(Number(lng).toFixed(7)));
  }

  /**
   * Set Place ID (Google only).
   */
  function populatePlaceId(form, widgetRoot, placeId) {
    if (!placeId) return;
    const f = findPlaceIdField(form, widgetRoot);
    if (!f) return;
    setFieldValue(f, placeId);
  }

  /**
   * ------------------------------------------------------------------------
   * Google Maps loader + autocomplete
   * ------------------------------------------------------------------------
   */

  let googleMapsPromise = null;

  function loadGoogleMapsPlaces(apiKey) {
    if (!apiKey) {
      return Promise.reject(new Error('Google Maps API key missing.'));
    }

    // Already available.
    if (window.google && window.google.maps && window.google.maps.places) {
      return Promise.resolve(window.google);
    }

    // Already loading.
    if (googleMapsPromise) {
      return googleMapsPromise;
    }

    googleMapsPromise = new Promise((resolve, reject) => {
      // If a script already exists, wait for it.
      const existing = document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]');
      if (existing) {
        const start = Date.now();
        const timer = setInterval(() => {
          if (window.google && window.google.maps && window.google.maps.places) {
            clearInterval(timer);
            resolve(window.google);
          } else if (Date.now() - start > 10000) {
            clearInterval(timer);
            reject(new Error('Google Maps API did not become available (existing script).'));
          }
        }, 100);
        return;
      }

      const cb = '__melGoogleMapsReady__' + Date.now();
      window[cb] = () => {
        delete window[cb];
        if (window.google && window.google.maps && window.google.maps.places) {
          resolve(window.google);
        } else {
          reject(new Error('Google Maps callback fired but Places API missing.'));
        }
      };

      const script = document.createElement('script');
      script.async = true;
      script.defer = true;
      script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&libraries=places&callback=${encodeURIComponent(cb)}`;
      script.onerror = () => {
        delete window[cb];
        reject(new Error('Failed to load Google Maps script.'));
      };

      document.head.appendChild(script);
    });

    return googleMapsPromise;
  }

  function extractGoogleComponents(place) {
    const out = {
      name: place && place.name ? place.name : '',
      address_line1: '',
      address_line2: '',
      locality: '',
      administrative_area: '',
      postal_code: '',
      country_code: 'AU',
    };

    if (!place) return out;

    const comps = place.address_components || [];
    let streetNumber = '';
    let route = '';
    
    for (const c of comps) {
      const types = c.types || [];
      if (types.includes('street_number')) {
        streetNumber = c.long_name || '';
      }
      if (types.includes('route')) {
        route = c.long_name || '';
      }
      if (types.includes('subpremise')) out.address_line2 = c.long_name || '';
      if (types.includes('locality')) out.locality = c.long_name || '';
      if (types.includes('administrative_area_level_1')) out.administrative_area = c.short_name || c.long_name || '';
      if (types.includes('postal_code')) out.postal_code = c.long_name || '';
      if (types.includes('country')) out.country_code = c.short_name || 'AU';
    }

    // Build address_line1 from street_number + route
    if (streetNumber && route) {
      out.address_line1 = streetNumber + ' ' + route;
    } else if (route) {
      out.address_line1 = route;
    } else if (streetNumber) {
      out.address_line1 = streetNumber;
    }
    
    out.address_line1 = String(out.address_line1 || '').trim();
    if (!out.address_line1 && place.formatted_address) {
      // Fallback: use first part of formatted address
      out.address_line1 = String(place.formatted_address).split(',')[0].trim();
    }

    return out;
  }

  function setupGoogleAutocomplete(searchInput, form, widgetRoot) {
    const apiKey = SETTINGS.google_maps_api_key;
    if (!apiKey) {
      error('Google provider selected but SETTINGS.google_maps_api_key missing.');
      return;
    }

    loadGoogleMapsPlaces(apiKey)
      .then((google) => {
        if (!searchInput || !form) return;

        // Avoid double-instantiating on the same input.
        if (searchInput.dataset.melAutocompleteAttached === '1') {
          return;
        }
        searchInput.dataset.melAutocompleteAttached = '1';

        log('Google Places available, attaching autocomplete', searchInput);

        const autocomplete = new google.maps.places.Autocomplete(searchInput, {
          types: ['establishment', 'geocode'],
          componentRestrictions: { country: 'au' },
          fields: ['place_id', 'name', 'formatted_address', 'geometry', 'address_components'],
        });

        // Trigger autocomplete suggestions on focus (before typing)
        // This makes the dropdown appear when the field is focused
        searchInput.addEventListener('focus', () => {
          // If field is empty, trigger autocomplete by simulating a minimal input
          if (!searchInput.value || searchInput.value.trim() === '') {
            // Trigger a small input event to show suggestions
            // Google Places Autocomplete will show suggestions based on location bias
            const event = new Event('input', { bubbles: true });
            searchInput.dispatchEvent(event);
            // Also trigger keydown to ensure autocomplete activates
            const keyEvent = new KeyboardEvent('keydown', { 
              bubbles: true, 
              key: 'ArrowDown',
              code: 'ArrowDown'
            });
            searchInput.dispatchEvent(keyEvent);
          }
        });

        autocomplete.addListener('place_changed', () => {
          const place = autocomplete.getPlace();
          if (!place || !place.geometry) {
            warn('Selected place missing geometry. Place:', place);
            return;
          }

          const comps = extractGoogleComponents(place);
          const lat = place.geometry.location.lat();
          const lng = place.geometry.location.lng();

          populateAddressWidget(form, widgetRoot, comps);
          // Populate venue name from place.name (not comps.name, which may be empty).
          populateVenueName(form, place.name || comps.name || '');
          // Populate field_venue_address with the same address components.
          populateVenueAddress(form, comps);
          populateLatLng(form, widgetRoot, lat, lng);
          populatePlaceId(form, widgetRoot, place.place_id || '');

          // Dispatch custom event for venue-selection.js to handle venue-specific logic
          const placeSelectedEvent = new CustomEvent('place_selected', {
            detail: {
              place: place,
              provider: 'google_maps',
              components: comps,
              lat: lat,
              lng: lng,
            },
            bubbles: true,
          });
          searchInput.dispatchEvent(placeSelectedEvent);

          // Optional: map preview container can be handled elsewhere; do not block.
          log('Google place selected + populated', { comps, lat, lng, place_id: place.place_id, place_name: place.name });
        });
      })
      .catch((e) => {
        error(e);
      });
  }

  /**
   * ------------------------------------------------------------------------
   * Apple Maps (MapKit) - optional
   * ------------------------------------------------------------------------
   */

  let appleMapKitPromise = null;

  function loadAppleMapKit(token) {
    if (!token) {
      return Promise.reject(new Error('Apple Maps token missing.'));
    }

    if (window.mapkit && window.mapkit.Map) {
      return Promise.resolve(window.mapkit);
    }

    if (appleMapKitPromise) return appleMapKitPromise;

    appleMapKitPromise = new Promise((resolve, reject) => {
      const existing = document.querySelector('script[src*="apple-mapkit.com/mk/"]');
      if (existing) {
        const start = Date.now();
        const timer = setInterval(() => {
          if (window.mapkit && window.mapkit.Map) {
            clearInterval(timer);
            try {
              window.mapkit.init({
                authorizationCallback: (done) => done(token),
              });
            } catch (e) {}
            resolve(window.mapkit);
          } else if (Date.now() - start > 10000) {
            clearInterval(timer);
            reject(new Error('MapKit did not become available (existing script).'));
          }
        }, 100);
        return;
      }

      const script = document.createElement('script');
      script.async = true;
      script.src = 'https://cdn.apple-mapkit.com/mk/5.x.x/mapkit.js';
      script.onload = () => {
        if (!window.mapkit) {
          reject(new Error('MapKit script loaded but mapkit missing.'));
          return;
        }
        try {
          window.mapkit.init({
            authorizationCallback: (done) => done(token),
          });
        } catch (e) {
          // init can throw if called twice; ignore.
        }
        resolve(window.mapkit);
      };
      script.onerror = () => reject(new Error('Failed to load MapKit script.'));
      document.head.appendChild(script);
    });

    return appleMapKitPromise;
  }

  function extractAppleComponents(place) {
    return {
      name: place && place.name ? place.name : '',
      address_line1: (place && place.formattedAddressLines && place.formattedAddressLines[0]) ? place.formattedAddressLines[0] : '',
      address_line2: (place && place.formattedAddressLines && place.formattedAddressLines[1]) ? place.formattedAddressLines[1] : '',
      locality: (place && place.locality) ? place.locality : '',
      administrative_area: (place && place.administrativeArea) ? place.administrativeArea : '',
      postal_code: (place && place.postalCode) ? place.postalCode : '',
      country_code: (place && place.countryCode) ? place.countryCode : 'AU',
    };
  }

  function setupAppleAutocomplete(searchInput, form, widgetRoot) {
    const token = SETTINGS.apple_maps_token;
    if (!token) {
      error('Apple provider selected but SETTINGS.apple_maps_token missing.');
      return;
    }

    loadAppleMapKit(token)
      .then((mapkit) => {
        if (!searchInput || !form) return;

        if (searchInput.dataset.melAppleAttached === '1') return;
        searchInput.dataset.melAppleAttached = '1';

        // Simple suggestion dropdown
        const wrapper = searchInput.parentElement;
        if (!wrapper) return;

        wrapper.style.position = wrapper.style.position || 'relative';

        const list = document.createElement('div');
        list.className = 'myeventlane-location-suggestions';
        list.style.cssText = 'position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #ccc;max-height:240px;overflow:auto;z-index:1000;display:none;';
        wrapper.appendChild(list);

        const searchService = new mapkit.Search({
          region: new mapkit.CoordinateRegion(
            new mapkit.Coordinate(-25.2744, 133.7751),
            new mapkit.CoordinateSpan(40, 40)
          ),
        });

        let t = null;
        searchInput.addEventListener('input', () => {
          const q = String(searchInput.value || '').trim();
          if (q.length < 3) {
            list.style.display = 'none';
            list.innerHTML = '';
            return;
          }

          clearTimeout(t);
          t = setTimeout(() => {
            searchService.search(q, (err, data) => {
              if (err || !data || !data.places) {
                list.style.display = 'none';
                list.innerHTML = '';
                return;
              }

              list.innerHTML = '';
              const places = data.places.slice(0, 6);
              for (const p of places) {
                const item = document.createElement('div');
                item.style.cssText = 'padding:10px;border-bottom:1px solid #eee;cursor:pointer;';
                item.textContent = p.name + (p.formattedAddressLines ? ' — ' + p.formattedAddressLines.join(', ') : '');
                item.addEventListener('click', () => {
                  list.style.display = 'none';
                  list.innerHTML = '';
                  searchInput.value = p.name;

                  const comps = extractAppleComponents(p);
                  const lat = p.coordinate ? p.coordinate.latitude : null;
                  const lng = p.coordinate ? p.coordinate.longitude : null;

                  populateAddressWidget(form, widgetRoot, comps);
                  // Populate venue name from place.name (not comps.name, which may be empty).
                  populateVenueName(form, p.name || comps.name || '');
                  // Populate field_venue_address with the same address components.
                  populateVenueAddress(form, comps);
                  if (lat !== null && lng !== null) {
                    populateLatLng(form, widgetRoot, lat, lng);
                  }

                  // Dispatch custom event for venue-selection.js to handle venue-specific logic
                  const placeSelectedEvent = new CustomEvent('place_selected', {
                    detail: {
                      place: p,
                      provider: 'apple_maps',
                      components: comps,
                      lat: lat,
                      lng: lng,
                    },
                    bubbles: true,
                  });
                  searchInput.dispatchEvent(placeSelectedEvent);

                  log('Apple place selected + populated', { comps, lat, lng, place_name: p.name });
                });

                list.appendChild(item);
              }

              list.style.display = places.length ? 'block' : 'none';
            });
          }, 250);
        });

        document.addEventListener('click', (e) => {
          if (!wrapper.contains(e.target)) {
            list.style.display = 'none';
          }
        });

        log('Apple autocomplete attached', searchInput);
      })
      .catch((e) => {
        error(e);
      });
  }

  /**
   * Set default country to AU if empty.
   */
  function ensureDefaultCountry(form, widgetRoot) {
    if (!form || !widgetRoot) return;
    
    const country = findAddressComponent(widgetRoot, 'country_code', true);
    if (!country) return;
    
    // Only set default if field is empty.
    if (!country.value || country.value === '') {
      // Check if AU is available in options (for select fields).
      if (country.tagName === 'SELECT') {
        for (const opt of country.options) {
          if (opt.value === 'AU') {
            setFieldValue(country, 'AU');
            log('Set default country to AU');
            break;
          }
        }
      } else {
        // For text inputs, just set the value.
        setFieldValue(country, 'AU');
        log('Set default country to AU');
      }
    }
  }

  /**
   * ------------------------------------------------------------------------
   * Main initializer (called per form)
   * ------------------------------------------------------------------------
   */
  function initForForm(form) {
    if (!form) return;

    const widgetRoot = getLocationWidgetRoot(form);
    const searchInput = findSearchInput(form);

    // If search input doesn't exist on this step yet, don't do anything.
    // Behavior will re-run on the next AJAX step render.
    if (!searchInput) {
      log('No search input found in this form context (yet).');
      return;
    }

    // If hidden (e.g. online mode), skip until visible.
    if (!isVisible(searchInput)) {
      log('Search input hidden; skipping until visible.');
      return;
    }

    // Set default country to AU if empty.
    ensureDefaultCountry(form, widgetRoot);

    const provider = SETTINGS.provider || 'google_maps';

    log('Initializing provider:', provider, 'Form:', form);

    if (provider === 'google_maps') {
      setupGoogleAutocomplete(searchInput, form, widgetRoot);
    } else if (provider === 'apple_maps') {
      setupAppleAutocomplete(searchInput, form, widgetRoot);
    } else {
      warn('Unknown provider:', provider);
    }
  }

  /**
   * ------------------------------------------------------------------------
   * Drupal behavior
   * ------------------------------------------------------------------------
   */
  Drupal.behaviors.myeventlaneLocationAutocomplete = {
    attach(context) {
      log('Drupal.behaviors.myeventlaneLocationAutocomplete.attach called, context:', context);
      log('SETTINGS:', SETTINGS);
      
      // Target forms that contain our address search field OR field_location wrapper.
      const candidates = [];

      // If context itself is a form.
      if (context && context.tagName === 'FORM') {
        candidates.push(context);
        log('Context is a form, added to candidates');
      } else if (context && context.querySelectorAll) {
        // Any forms containing our field.
        const forms = context.querySelectorAll('form');
        log('Found', forms.length, 'forms in context');
        for (const f of forms) {
          // Quick filter: only those that look relevant.
          const hasSearch = f.querySelector('.myeventlane-location-address-search');
          const hasDataAttr = f.querySelector('[data-mel-address="field_location"]');
          const hasFieldLocation = f.querySelector('.field--name-field-location');
          const hasVenueWidget = f.querySelector('.myeventlane-venue-selection-widget');
          
          if (hasSearch || hasDataAttr || hasFieldLocation || hasVenueWidget) {
            candidates.push(f);
            log('Found relevant form, has search:', !!hasSearch, 'has data-attr:', !!hasDataAttr, 'has field-location:', !!hasFieldLocation, 'has venue-widget:', !!hasVenueWidget);
          }
        }
      }

      log('Total candidate forms:', candidates.length);

      // Run once per form per attach cycle.
      // Use a unique key per form to handle AJAX rebuilds properly.
      for (const form of once('mel-location-autocomplete', candidates, context)) {
        log('Initializing autocomplete for form, ID:', form.id || 'no-id');
        // Small delay helps when wizard step is injected via AJAX.
        setTimeout(() => {
          log('Calling initForForm after delay');
          initForForm(form);
        }, 50);
      }
    }
  };

})(window.Drupal || {}, window.drupalSettings || {}, window.once);