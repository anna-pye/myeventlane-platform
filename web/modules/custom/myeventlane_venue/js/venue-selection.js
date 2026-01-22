/**
 * @file
 * Venue selection widget for Event Wizard.
 *
 * Handles:
 * - Venue autocomplete (saved venues + Google/Apple Maps)
 * - Venue creation on first use
 * - Map preview toggle
 * - Country lock to AU
 * - Auto-populate venue name but allow editing
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Venue selection widget behavior.
   */
  Drupal.behaviors.myeventlaneVenueSelection = {
    attach: function (context, settings) {
      // Use once() to ensure this only runs once per wrapper
      const wrapper = context.querySelector('.myeventlane-venue-selection-widget');
      if (!wrapper) {
        // Debug: Check if context contains forms that might have the wrapper
        if (context && context.querySelectorAll) {
          const forms = context.querySelectorAll('form');
          for (const form of forms) {
            const testWrapper = form.querySelector('.myeventlane-venue-selection-widget');
            if (testWrapper) {
              console.log('[MEL Venue] Found wrapper in form:', form.id);
            }
          }
        }
        return;
      }

      // Look for the search input - it can be either class name
      const venueSearch = wrapper.querySelector('.myeventlane-location-address-search') || 
                          wrapper.querySelector('.myeventlane-venue-search');
      const venueAutocomplete = wrapper.querySelector('input[name*="[target_id]"]');
      const newVenueName = wrapper.querySelector('.myeventlane-venue-name-field') ||
                          wrapper.querySelector('.myeventlane-new-venue-name');
      const newVenueAddress = wrapper.querySelector('.myeventlane-new-venue-address');
      const mapPreview = wrapper.querySelector('.myeventlane-venue-map-preview');
      const autocompleteUrl = wrapper.getAttribute('data-venue-autocomplete-url') || '/venue/autocomplete';

      console.log('[MEL Venue] Attach called, wrapper:', wrapper, 'venueSearch:', venueSearch, 'autocompleteUrl:', autocompleteUrl);

      if (!venueSearch) {
        console.warn('[MEL Venue] No venueSearch input found in wrapper');
        return;
      }

      // Initialize autocomplete for venue search.
      this.initVenueAutocomplete(venueSearch, venueAutocomplete, newVenueName, newVenueAddress, mapPreview, autocompleteUrl, settings);
    },

    /**
     * Initialize venue autocomplete with Google/Apple Maps support.
     */
    initVenueAutocomplete: function (venueSearch, venueAutocomplete, newVenueName, newVenueAddress, mapPreview, autocompleteUrl, settings) {
      let autocomplete = null;
      let selectedVenue = null;
      let selectedPlace = null;

      // Get location provider settings.
      const locationSettings = settings.myeventlaneLocation || {};
      const provider = locationSettings.provider || 'google_maps';

      // Check if address-autocomplete.js has already attached to this field
      // If so, we'll just listen for place selections via a custom event
      // Otherwise, initialize our own autocomplete
      const alreadyAttached = venueSearch.dataset.melAutocompleteAttached === '1';
      
      if (!alreadyAttached) {
        // Initialize Google Maps or Apple Maps autocomplete.
        if (provider === 'apple_maps' && window.MapKit) {
          // Apple Maps implementation.
          this.initAppleMapsAutocomplete(venueSearch, locationSettings);
        }
        else if (window.google && window.google.maps && window.google.maps.places) {
          // Google Maps implementation.
          this.initGoogleMapsAutocomplete(venueSearch, locationSettings);
        }
      } else {
        // address-autocomplete.js is handling the autocomplete
        // Listen for place selections and handle venue-specific logic
        venueSearch.addEventListener('place_selected', (event) => {
          if (event.detail && event.detail.place) {
            this.handlePlaceSelection(event.detail.place, provider);
          }
        });
      }

      // Also handle saved venue autocomplete.
      this.initSavedVenueAutocomplete(venueSearch, venueAutocomplete, autocompleteUrl);

      // Handle venue selection.
      venueSearch.addEventListener('input', () => {
        const query = venueSearch.value.trim();
        if (query.length < 2) {
          this.hideMapPreview(mapPreview);
          return;
        }
      });

      // Handle new venue name changes.
      if (newVenueName) {
        newVenueName.addEventListener('input', () => {
          // Keep venue name in sync.
        });
      }
    },

    /**
     * Initialize Google Maps Places Autocomplete.
     */
    initGoogleMapsAutocomplete: function (venueSearch, settings) {
      if (!window.google || !window.google.maps || !window.google.maps.places) {
        return;
      }

      // Mark as attached to prevent double initialization
      venueSearch.dataset.melVenueAutocompleteAttached = '1';

      const autocomplete = new google.maps.places.Autocomplete(venueSearch, {
        componentRestrictions: { country: 'au' },
        fields: ['name', 'formatted_address', 'geometry', 'place_id', 'address_components'],
        types: ['establishment', 'geocode'],
      });

      autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (!place.geometry) {
          return;
        }

        this.handlePlaceSelection(place, 'google_maps');
      });
    },

    /**
     * Initialize Apple Maps autocomplete.
     */
    initAppleMapsAutocomplete: function (venueSearch, settings) {
      if (!window.MapKit) {
        return;
      }

      // Apple Maps search implementation.
      // Note: Apple Maps doesn't have a built-in autocomplete widget like Google,
      // so we'd need to implement a custom search using MapKit Search API.
      // For now, this is a placeholder.
      console.log('Apple Maps autocomplete not yet fully implemented');
    },

    /**
     * Initialize saved venue autocomplete.
     */
    initSavedVenueAutocomplete: function (venueSearch, venueAutocomplete, autocompleteUrl) {
      console.log('[MEL Venue] initSavedVenueAutocomplete called', {
        venueSearch,
        venueAutocomplete,
        autocompleteUrl,
        hasDrupalAutocomplete: typeof Drupal.autocomplete !== 'undefined',
        hasDrupalJQuery: typeof Drupal.jQuery !== 'undefined',
        hasJQuery: typeof window.jQuery !== 'undefined',
      });

      // Use Drupal's autocomplete if available.
      // jQuery might not be ready immediately, so try both Drupal.jQuery and window.jQuery
      // Also check if jQuery UI autocomplete is available
      const tryInit = () => {
        const $ = Drupal.jQuery || (typeof window !== 'undefined' && window.jQuery) || null;
        if (!($ && $.fn && $.fn.autocomplete)) {
          console.warn('[MEL Venue] jQuery or jQuery UI autocomplete not available yet. Retrying...', {
            hasDrupalJQuery: typeof Drupal.jQuery !== 'undefined',
            hasWindowJQuery: typeof window !== 'undefined' && typeof window.jQuery !== 'undefined',
            hasAutocomplete: $ && $.fn && typeof $.fn.autocomplete !== 'undefined',
          });
          // Retry after a short delay if jQuery isn't ready yet
          setTimeout(tryInit, 100);
          return;
        }
        
        console.log('[MEL Venue] Initializing Drupal autocomplete on', venueSearch, 'using jQuery:', $);
        
        // Check if Google Places autocomplete is already attached
        // If so, we need to work with it, not replace it
        const hasGoogleAutocomplete = venueSearch.dataset.melAutocompleteAttached === '1';
        
        if (hasGoogleAutocomplete) {
          console.log('[MEL Venue] Google autocomplete already attached, will combine with saved venues');
          // We'll need to combine both autocompletes - this is complex
          // For now, let's just initialize Drupal autocomplete and see if they can coexist
        }
        
        try {
          $(venueSearch).autocomplete({
            source: function (request, response) {
              console.log('[MEL Venue] Saved venue autocomplete request:', request.term);
              $.ajax({
                url: autocompleteUrl,
                data: { q: request.term },
                dataType: 'json',
                success: function (data) {
                  console.log('[MEL Venue] Saved venue autocomplete response:', data);
                  response(data.map(function (item) {
                    return {
                      label: item.label,
                      value: item.value,
                      venue_id: item.venue_id,
                    };
                  }));
                },
                error: function(xhr, status, error) {
                  console.error('[MEL Venue] Saved venue autocomplete error:', error, xhr);
                }
              });
            },
            select: function (event, ui) {
              console.log('[MEL Venue] Saved venue selected:', ui.item);
              if (ui.item.venue_id) {
                // Set the venue autocomplete field value.
                if (venueAutocomplete) {
                  venueAutocomplete.value = ui.item.venue_id;
                  const $ = Drupal.jQuery;
                  if ($) {
                    $(venueAutocomplete).trigger('change');
                  }
                }
              }
              return false;
            },
            minLength: 0, // Show suggestions even when field is empty (on focus)
          });
          
          // Trigger autocomplete on focus to show saved venues
          $(venueSearch).on('focus', function() {
            if (!this.value || this.value.trim() === '') {
              $(this).autocomplete('search', '');
            }
          });
          
          console.log('[MEL Venue] Drupal autocomplete initialized successfully');
        } catch (e) {
          console.error('[MEL Venue] Error initializing Drupal autocomplete:', e);
        }
      };
      
      // Start trying to initialize - will retry if jQuery isn't ready
      tryInit();
    },

    /**
     * Handle place selection from Google/Apple Maps.
     */
    handlePlaceSelection: function (place, provider) {
      const wrapper = document.querySelector('.myeventlane-venue-selection-widget');
      if (!wrapper) {
        return;
      }

      const form = wrapper.closest('form');
      if (!form) {
        console.warn('Venue selection: Could not find form element');
        return;
      }

      const newVenueName = wrapper.querySelector('.myeventlane-venue-name-field') ||
                          wrapper.querySelector('.myeventlane-new-venue-name');
      const newVenueAddress = wrapper.querySelector('.myeventlane-new-venue-address');
      const mapPreview = wrapper.querySelector('.myeventlane-venue-map-preview');

      // Extract address components.
      const addressData = this.extractAddressComponents(place, provider);

      // Auto-populate venue name.
      if (newVenueName) {
        newVenueName.value = place.name || '';
        // Trigger change event so Drupal knows the value changed
        newVenueName.dispatchEvent(new Event('input', { bubbles: true }));
        newVenueName.dispatchEvent(new Event('change', { bubbles: true }));
      }

      // Store address data for venue creation.
      if (newVenueAddress) {
        newVenueAddress.dataset.addressData = JSON.stringify(addressData);
        newVenueAddress.style.display = 'block';
      }

      // Populate the hidden field_location address widget using address-autocomplete.js functions
      // Find the field_location widget root (it's hidden but still in DOM)
      const locationWidgetRoot = this.findLocationWidgetRoot(form);
      if (locationWidgetRoot) {
        // Use the same extraction logic as address-autocomplete.js
        const components = this.extractAddressComponentsForWidget(place, provider);
        this.populateAddressWidget(form, locationWidgetRoot, components);
        
        // Populate coordinates if available
        if (place.geometry && place.geometry.location) {
          const lat = place.geometry.location.lat();
          const lng = place.geometry.location.lng();
          this.populateLatLng(form, locationWidgetRoot, lat, lng);
        }
      } else {
        console.warn('Venue selection: Could not find field_location widget root');
      }

      // Show map preview if coordinates exist.
      if (place.geometry && place.geometry.location) {
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();
        this.showMapPreview(mapPreview, lat, lng, place.name);
      }
    },

    /**
     * Extract address components from place object (for dataset storage).
     */
    extractAddressComponents: function (place, provider) {
      const components = {
        country_code: 'AU',
        address_line1: '',
        locality: '',
        administrative_area: '',
        postal_code: '',
      };

      if (provider === 'google_maps' && place.address_components) {
        place.address_components.forEach((component) => {
          const types = component.types;
          if (types.includes('street_number') || types.includes('route')) {
            components.address_line1 = (components.address_line1 + ' ' + component.long_name).trim();
          }
          if (types.includes('locality')) {
            components.locality = component.long_name;
          }
          if (types.includes('administrative_area_level_1')) {
            components.administrative_area = component.short_name;
          }
          if (types.includes('postal_code')) {
            components.postal_code = component.postal_code;
          }
        });
      }

      return components;
    },

    /**
     * Extract address components for populating Drupal address widget (matches address-autocomplete.js format).
     */
    extractAddressComponentsForWidget: function (place, provider) {
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

      if (provider === 'google_maps' && place.address_components) {
        const comps = place.address_components || [];
        for (const c of comps) {
          const types = c.types || [];
          if (types.includes('street_number')) out.address_line1 = (c.long_name || '') + ' ' + out.address_line1;
          if (types.includes('route')) out.address_line1 = (out.address_line1 || '') + (c.long_name || '');
          if (types.includes('subpremise')) out.address_line2 = c.long_name || '';
          if (types.includes('locality')) out.locality = c.long_name || '';
          if (types.includes('administrative_area_level_1')) out.administrative_area = c.short_name || c.long_name || '';
          if (types.includes('postal_code')) out.postal_code = c.long_name || '';
          if (types.includes('country')) out.country_code = c.short_name || 'AU';
        }
      }

      out.address_line1 = String(out.address_line1 || '').trim();
      if (!out.address_line1 && place.formatted_address) {
        out.address_line1 = String(place.formatted_address).split(',')[0].trim();
      }

      return out;
    },

    /**
     * Find the field_location widget root in the form.
     */
    findLocationWidgetRoot: function (form) {
      if (!form) return null;

      // Try explicit wrapper first
      let root = form.querySelector('[data-mel-address="field_location"]');
      if (root) return root;

      // Try Drupal field wrapper
      root = form.querySelector('.field--name-field-location');
      if (root) return root;

      // Try to find fieldset containing address inputs
      const fieldsets = form.querySelectorAll('fieldset');
      for (const fs of fieldsets) {
        if (fs.querySelector('input[name*="[address][address_line1]"]') ||
            fs.querySelector('input[name*="[address][locality]"]') ||
            fs.querySelector('input[name*="[address][postal_code]"]')) {
          return fs;
        }
      }

      // Fallback: look for any element containing field_location address inputs
      const locationInput = form.querySelector('input[name*="field_location"][name*="[address][address_line1]"]');
      if (locationInput) {
        return locationInput.closest('fieldset') || locationInput.closest('.field--name-field-location') || form;
      }

      return null;
    },

    /**
     * Find address component field by name pattern.
     */
    findAddressComponent: function (widgetRoot, componentName, allowSelect) {
      if (!widgetRoot) return null;

      const baseSelector = `[name*="[address][${componentName}]"]`;
      let field = widgetRoot.querySelector(`input${baseSelector}`);

      if (!field && allowSelect) {
        field = widgetRoot.querySelector(`select${baseSelector}`);
      }
      if (field) return field;

      // Fallback patterns
      field = widgetRoot.querySelector(`input[name*="${componentName}"]`) ||
              (allowSelect ? widgetRoot.querySelector(`select[name*="${componentName}"]`) : null);

      return field || null;
    },

    /**
     * Set field value and notify Drupal.
     */
    setFieldValue: function (field, value) {
      if (!field) return;
      field.value = value;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
      field.dispatchEvent(new Event('blur', { bubbles: true }));
    },

    /**
     * Normalize AU state values to short codes.
     */
    normalizeAUState: function (value) {
      if (!value) return '';
      const v = value.trim();

      const short = ['NSW','VIC','QLD','SA','WA','TAS','ACT','NT'];
      if (short.includes(v.toUpperCase())) return v.toUpperCase();

      const map = {
        'new south wales': 'NSW',
        'victoria': 'VIC',
        'queensland': 'QLD',
        'south australia': 'SA',
        'western australia': 'WA',
        'tasmania': 'TAS',
        'australian capital territory': 'ACT',
        'northern territory': 'NT',
      };
      const key = v.toLowerCase();
      return map[key] || v;
    },

    /**
     * Populate Drupal Address widget fields.
     */
    populateAddressWidget: function (form, widgetRoot, components) {
      if (!form || !widgetRoot || !components) return;

      const country = this.findAddressComponent(widgetRoot, 'country_code', true);
      const state = this.findAddressComponent(widgetRoot, 'administrative_area', true);
      const suburb = this.findAddressComponent(widgetRoot, 'locality', false);
      const postcode = this.findAddressComponent(widgetRoot, 'postal_code', false);
      const line1 = this.findAddressComponent(widgetRoot, 'address_line1', false);
      const line2 = this.findAddressComponent(widgetRoot, 'address_line2', false);

      // Country first (drives dynamic state list)
      if (country) {
        const countryValue = components.country_code || 'AU';
        this.setFieldValue(country, countryValue);
      }

      // Address line 1
      if (line1) this.setFieldValue(line1, components.address_line1 || '');

      // Address line 2 (optional)
      if (line2 && components.address_line2) this.setFieldValue(line2, components.address_line2);

      // Suburb + postcode
      if (suburb) this.setFieldValue(suburb, components.locality || '');
      if (postcode) this.setFieldValue(postcode, components.postal_code || '');

      // State: if select, prefer matching option
      if (state) {
        const desired = this.normalizeAUState(components.administrative_area || '');
        if (state.tagName === 'SELECT') {
          let matched = false;
          for (const opt of state.options) {
            if (opt.value === desired || opt.text === desired) {
              state.value = opt.value;
              matched = true;
              break;
            }
          }
          if (!matched && desired) {
            for (const opt of state.options) {
              if ((opt.text || '').toUpperCase().includes(desired.toUpperCase())) {
                state.value = opt.value;
                matched = true;
                break;
              }
            }
          }
          state.dispatchEvent(new Event('change', { bubbles: true }));
          state.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          this.setFieldValue(state, desired);
        }
      }

      // Trigger Drupal formUpdated if jQuery exists
      if (window.jQuery) {
        window.jQuery(form).trigger('formUpdated');
      }
    },

    /**
     * Populate latitude and longitude fields.
     */
    populateLatLng: function (form, widgetRoot, lat, lng) {
      if (!form || !widgetRoot) return;

      const scope = widgetRoot || form || document;

      // Look for latitude field - prefer explicit classes added by myeventlane_location module
      let latField = scope.querySelector('input.myeventlane-location-latitude-field[type="hidden"], input.myeventlane-location-latitude[type="hidden"]');
      if (!latField) {
        latField = scope.querySelector('input[type="hidden"][name*="field_location"][name*="latitude"], input[type="hidden"][name*="field_location_latitude"], input[type="hidden"][name*="latitude"]');
      }
      if (!latField && form) {
        latField = form.querySelector('input[type="hidden"][name*="latitude"]');
      }

      // Look for longitude field - prefer explicit classes added by myeventlane_location module
      let lngField = scope.querySelector('input.myeventlane-location-longitude-field[type="hidden"], input.myeventlane-location-longitude[type="hidden"]');
      if (!lngField) {
        lngField = scope.querySelector('input[type="hidden"][name*="field_location"][name*="longitude"], input[type="hidden"][name*="field_location_longitude"], input[type="hidden"][name*="longitude"]');
      }
      if (!lngField && form) {
        lngField = form.querySelector('input[type="hidden"][name*="longitude"]');
      }

      if (latField) this.setFieldValue(latField, String(Number(lat).toFixed(7)));
      if (lngField) this.setFieldValue(lngField, String(Number(lng).toFixed(7)));
    },

    /**
     * Show map preview.
     */
    showMapPreview: function (mapPreview, lat, lng, title) {
      if (!mapPreview) {
        return;
      }

      mapPreview.style.display = 'block';
      mapPreview.dataset.latitude = lat;
      mapPreview.dataset.longitude = lng;
      mapPreview.dataset.title = title || '';

      // Initialize map if not already done.
      if (!mapPreview.dataset.initialized) {
        this.initMapPreview(mapPreview, lat, lng, title);
        mapPreview.dataset.initialized = 'true';
      }
    },

    /**
     * Initialize map preview.
     */
    initMapPreview: function (mapPreview, lat, lng, title) {
      const locationSettings = drupalSettings.myeventlaneLocation || {};
      const provider = locationSettings.provider || 'google_maps';

      if (provider === 'google_maps' && window.google && window.google.maps) {
        const map = new google.maps.Map(mapPreview, {
          center: { lat: lat, lng: lng },
          zoom: 15,
        });

        new google.maps.Marker({
          position: { lat: lat, lng: lng },
          map: map,
          title: title || 'Venue location',
        });
      }
      else if (provider === 'apple_maps' && window.MapKit) {
        // Apple Maps implementation.
        const map = new mapkit.Map(mapPreview);
        const coordinate = new mapkit.Coordinate(lat, lng);
        map.region = new mapkit.CoordinateRegion(coordinate, new mapkit.CoordinateSpan(0.01, 0.01));

        const annotation = new mapkit.MarkerAnnotation(coordinate, {
          title: title || 'Venue location',
        });
        map.addAnnotation(annotation);
      }
    },

    /**
     * Hide map preview.
     */
    hideMapPreview: function (mapPreview) {
      if (mapPreview) {
        mapPreview.style.display = 'none';
      }
    },
  };

})(Drupal, drupalSettings);
