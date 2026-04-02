/**
 * @file
 * Single writer for MEL field_location: fills real Drupal Address widget POST names.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  const MEL_DEBUG =
    !!(drupalSettings &&
      drupalSettings.melEventBuilderPreview &&
      drupalSettings.melEventBuilderPreview.builderDebug);

  /**
   * Address inputs use names like field_location[widget][0][address][address_line1].
   * Query by prefix + suffix on the form (not only under [data-mel-address]).
   *
   * @param {HTMLFormElement} form
   * @param {string|string[]} nameSuffix
   *   e.g. '[address][address_line1]' or ['[address][country_code]', '[country_code]']
   */
  function melQueryFieldLocationControl(form, nameSuffix) {
    if (!form) {
      return null;
    }
    const notSearch = ':not([name*="address_search"]):not([name*="field_location_address_search"])';
    const suffixes = Array.isArray(nameSuffix) ? nameSuffix : [nameSuffix];
    for (let s = 0; s < suffixes.length; s++) {
      const suf = suffixes[s];
      const base = `[name^="field_location"]${notSearch}[name$="${suf}"]`;
      const el =
        form.querySelector(`input${base}`) ||
        form.querySelector(`textarea${base}`) ||
        form.querySelector(`select${base}`);
      if (el) {
        return el;
      }
    }
    return null;
  }

  function melSetFieldValue(field, value) {
    if (!field) {
      return;
    }
    field.value = value == null ? '' : String(value);
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    field.dispatchEvent(new Event('blur', { bubbles: true }));
  }

  function melNormalizeAUState(value) {
    if (!value) {
      return '';
    }
    const v = String(value).trim();
    const short = ['NSW', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'ACT', 'NT'];
    if (short.includes(v.toUpperCase())) {
      return v.toUpperCase();
    }
    const map = {
      'new south wales': 'NSW',
      nsw: 'NSW',
      victoria: 'VIC',
      vic: 'VIC',
      queensland: 'QLD',
      qld: 'QLD',
      'south australia': 'SA',
      sa: 'SA',
      'western australia': 'WA',
      wa: 'WA',
      tasmania: 'TAS',
      tas: 'TAS',
      'australian capital territory': 'ACT',
      act: 'ACT',
      'northern territory': 'NT',
      nt: 'NT',
    };
    const key = v.toLowerCase();
    return map[key] || v;
  }

  function melRevealWhenWhere(form) {
    const section = form.querySelector('.mel-event-section--when-where');
    if (section) {
      section.classList.add('is-manual-address');
    }
  }

  /**
   * @param {google.maps.places.PlaceResult} place
   */
  function melExtractGoogleComponents(place) {
    const out = {
      name: place && place.name ? place.name : '',
      address_line1: '',
      address_line2: '',
      locality: '',
      administrative_area: '',
      postal_code: '',
      country_code: 'AU',
    };
    if (!place) {
      return out;
    }
    const comps = place.address_components || [];
    let streetNumber = '';
    let route = '';
    for (let i = 0; i < comps.length; i++) {
      const c = comps[i];
      const types = c.types || [];
      if (types.includes('street_number')) {
        streetNumber = c.long_name || '';
      }
      if (types.includes('route')) {
        route = c.long_name || '';
      }
      if (types.includes('subpremise')) {
        out.address_line2 = c.long_name || '';
      }
      if (types.includes('locality')) {
        out.locality = c.long_name || '';
      }
      if (types.includes('administrative_area_level_1')) {
        out.administrative_area = c.short_name || c.long_name || '';
      }
      if (types.includes('postal_code')) {
        out.postal_code = c.long_name || '';
      }
      if (types.includes('country')) {
        out.country_code = c.short_name || 'AU';
      }
    }
    if (streetNumber && route) {
      out.address_line1 = streetNumber + ' ' + route;
    }
    else if (route) {
      out.address_line1 = route;
    }
    else if (streetNumber) {
      out.address_line1 = streetNumber;
    }
    out.address_line1 = String(out.address_line1 || '').trim();
    if (!out.address_line1 && place.formatted_address) {
      out.address_line1 = String(place.formatted_address).split(',')[0].trim();
    }
    if (!out.address_line1 && out.name) {
      out.address_line1 = String(out.name).trim();
    }
    return out;
  }

  function melPopulateLatLng(form, lat, lng) {
    if (lat == null || lng == null || !form) {
      return;
    }
    const latN = Number(lat);
    const lngN = Number(lng);
    if (Number.isNaN(latN) || Number.isNaN(lngN)) {
      return;
    }
    const latS = String(latN.toFixed(7));
    const lngS = String(lngN.toFixed(7));

    const wLat = form.querySelector('input[name^="field_location"][name$="[latitude]"]');
    const wLng = form.querySelector('input[name^="field_location"][name$="[longitude]"]');
    if (wLat) {
      melSetFieldValue(wLat, latS);
    }
    if (wLng) {
      melSetFieldValue(wLng, lngS);
    }

    let latField = form.querySelector('input[name^="field_location_latitude"]');
    let lngField = form.querySelector('input[name^="field_location_longitude"]');
    if (!latField) {
      latField = form.querySelector('input[type="hidden"][name="myeventlane_location_latitude"]');
    }
    if (!lngField) {
      lngField = form.querySelector('input[type="hidden"][name="myeventlane_location_longitude"]');
    }
    if (latField) {
      melSetFieldValue(latField, latS);
    }
    if (lngField) {
      melSetFieldValue(lngField, lngS);
    }
  }

  function melPopulatePlaceId(form, placeId) {
    if (!placeId || !form) {
      return;
    }
    const f =
      form.querySelector('input[name^="field_location"][name*="place_id"]') ||
      form.querySelector('input.mel-place-id[type="hidden"]');
    if (f) {
      melSetFieldValue(f, placeId);
    }
  }

  /**
   * @param {HTMLFormElement} form
   * @param {{address_line1?: string, address_line2?: string, locality?: string, administrative_area?: string, postal_code?: string, country_code?: string}} fields
   * @param {number|string|null} lat
   * @param {number|string|null} lng
   * @param {string} [placeId]
   */
  function writeFieldLocationFromPayload(form, fields, lat, lng, placeId) {
    if (!form || !fields) {
      return;
    }
    if (MEL_DEBUG && window.console) {
      console.log('[MEL Location] writeFieldLocationFromPayload', fields, lat, lng);
    }

    melRevealWhenWhere(form);

    const countryValue = fields.country_code || 'AU';
    const countryEl = melQueryFieldLocationControl(form, ['[address][country_code]', '[country_code]']);

    function applyValues() {
      const setLine = (suffix, value, allowEmpty) => {
        const el = melQueryFieldLocationControl(form, suffix);
        if (!el) {
          return;
        }
        let str = value == null ? '' : String(value);
        if (!allowEmpty && str === '') {
          return;
        }
        melSetFieldValue(el, str);
      };

      setLine('[address][address_line1]', fields.address_line1, true);
      if (fields.address_line2) {
        setLine('[address][address_line2]', fields.address_line2, false);
      }
      setLine('[address][locality]', fields.locality, false);
      setLine('[address][postal_code]', fields.postal_code, false);

      const country = melQueryFieldLocationControl(form, ['[address][country_code]', '[country_code]']);
      if (country) {
        melSetFieldValue(country, countryValue === '' ? 'AU' : countryValue);
      }

      const rState = melQueryFieldLocationControl(form, '[address][administrative_area]');
      if (rState) {
        const desired = melNormalizeAUState(fields.administrative_area || '');
        if (rState.tagName === 'SELECT') {
          let matched = false;
          for (let i = 0; i < rState.options.length; i++) {
            const opt = rState.options[i];
            if (opt.value === desired || opt.text === desired) {
              rState.value = opt.value;
              matched = true;
              break;
            }
          }
          if (!matched && desired) {
            for (let j = 0; j < rState.options.length; j++) {
              const opt = rState.options[j];
              if ((opt.text || '').toUpperCase().includes(desired.toUpperCase())) {
                rState.value = opt.value;
                matched = true;
                break;
              }
            }
          }
          if (matched) {
            rState.dispatchEvent(new Event('change', { bubbles: true }));
            rState.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
        else if (desired !== '') {
          melSetFieldValue(rState, desired);
        }
      }

      melPopulateLatLng(form, lat, lng);
      melPopulatePlaceId(form, placeId || '');

      if (window.jQuery) {
        window.jQuery(form).trigger('formUpdated');
      }
      form.dispatchEvent(new Event('input', { bubbles: true }));
      form.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (countryEl) {
      const current = countryEl.value || '';
      if (current !== countryValue) {
        melSetFieldValue(countryEl, countryValue);
        const countryItem = countryEl.closest('.js-form-item, .form-item');
        const ajaxWrapper = countryItem
          ? countryItem.querySelector('.ajax-wrapper, [data-drupal-selector*="ajax"]')
          : null;
        if (ajaxWrapper && window.jQuery) {
          const ajaxComplete = () => {
            applyValues();
          };
          window.jQuery(document).one('ajaxComplete', ajaxComplete);
          window.setTimeout(() => {
            window.jQuery(document).off('ajaxComplete', ajaxComplete);
            applyValues();
          }, 2000);
          return;
        }
      }
    }

    applyValues();
  }

  /**
   * @param {Object} place Google PlaceResult
   * @param {HTMLFormElement} form
   */
  function populate(place, form) {
    if (!place || !form) {
      return;
    }
    const fields = melExtractGoogleComponents(place);
    let lat = null;
    let lng = null;
    if (place.geometry && place.geometry.location) {
      const loc = place.geometry.location;
      lat = typeof loc.lat === 'function' ? loc.lat() : loc.lat;
      lng = typeof loc.lng === 'function' ? loc.lng() : loc.lng;
    }
    writeFieldLocationFromPayload(form, fields, lat, lng, place.place_id || '');
  }

  /**
   * @param {HTMLFormElement} form
   * @param {Object} components
   * @param {number|null} lat
   * @param {number|null} lng
   * @param {string} [placeId]
   */
  function populateFromComponents(form, components, lat, lng, placeId) {
    if (!form || !components) {
      return;
    }
    const fields = {
      address_line1: components.address_line1 || '',
      address_line2: components.address_line2 || '',
      locality: components.locality || '',
      administrative_area: components.administrative_area || '',
      postal_code: components.postal_code || '',
      country_code: components.country_code || 'AU',
    };
    writeFieldLocationFromPayload(form, fields, lat, lng, placeId || '');
  }

  Drupal.melLocation = Drupal.melLocation || {};
  Drupal.melLocation.populate = populate;
  Drupal.melLocation.populateFromComponents = populateFromComponents;
  Drupal.melLocation.populateFromGooglePlace = populate;
})(Drupal, drupalSettings);
