/**
 * @file
 * Venue quick create modal functionality.
 *
 * Integrates with myeventlane_location autocomplete:
 * - Listens for place_selected events from location search.
 * - Populates venue name and address fields from selected place.
 */

(function ($, Drupal, once, drupalSettings) {
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
      var formWrappers = once('venue-quick-create-place', '.mel-venue-enrichment-form', context);
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

      if (!$searchInput.length) {
        return;
      }

      // Store reference for populateFields
      var self = this;

      // Listen for place_selected custom event from location autocomplete.
      $searchInput[0].addEventListener('place_selected', function (e) {
        self.populateFields(e.detail, $venueNameField, $addressField, $latField, $lngField);
        self.loadSuggestions(wrapper, e.detail || {});
      });

      // Fallback: Check if Google Places Autocomplete is attached and hook into it.
      setTimeout(function () {
        if (window.google && window.google.maps && window.google.maps.places) {
          var existingAutocomplete = $searchInput.data('google-autocomplete');
          if (!existingAutocomplete && !$searchInput.data('mel-autocomplete-attached')) {
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
        }
      }

      // Populate address field.
      if (formattedAddress && $addressField.length) {
        $addressField.val(formattedAddress);
        $addressField.trigger('change');
      }

      // Populate coordinates.
      if (lat !== null && $latField.length) {
        $latField.val(lat.toFixed(7));
      }
      if (lng !== null && $lngField.length) {
        $lngField.val(lng.toFixed(7));
      }

    },

    /**
     * Load accessible existing venues and saveable Overture suggestions.
     */
    loadSuggestions: function (wrapper, detail) {
      var settings = drupalSettings.myeventlaneVenueEnrichment || {};
      var container = wrapper.querySelector('[data-venue-suggestions]');
      if (!settings.suggestionsUrl || !container) {
        return;
      }

      var nameField = wrapper.querySelector('.myeventlane-venue-name-field');
      var addressField = wrapper.querySelector('.mel-venue-address-field');
      var latField = wrapper.querySelector('.myeventlane-location-latitude-field');
      var lngField = wrapper.querySelector('.myeventlane-location-longitude-field');
      var url = new URL(settings.suggestionsUrl, window.location.origin);
      url.searchParams.set('name', nameField ? nameField.value : '');
      url.searchParams.set('address', addressField ? addressField.value : '');
      url.searchParams.set('lat', detail.lat !== undefined ? detail.lat : (latField ? latField.value : ''));
      url.searchParams.set('lng', detail.lng !== undefined ? detail.lng : (lngField ? lngField.value : ''));
      if (settings.currentVenueId) {
        url.searchParams.set('exclude_venue_id', settings.currentVenueId);
      }

      container.replaceChildren(this.message(Drupal.t('Checking MyEventLane and public venue data…'), 'loading'));
      var self = this;
      fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Venue suggestions request failed.');
          }
          return response.json();
        })
        .then(function (payload) {
          self.renderSuggestions(wrapper, container, payload || {});
        })
        .catch(function () {
          container.replaceChildren(self.message(
            Drupal.t('We could not check public venue details. You can still enter the venue manually.'),
            'warning'
          ));
        });
    },

    /**
     * Render suggestion cards with safe DOM text nodes.
     */
    renderSuggestions: function (wrapper, container, payload) {
      container.replaceChildren();
      var existing = Array.isArray(payload.existing) ? payload.existing : [];
      var overture = Array.isArray(payload.overture) ? payload.overture : [];

      if (existing.length) {
        var existingSection = this.section(Drupal.t('We may already have this venue'));
        existing.forEach(function (candidate) {
          var card = document.createElement('article');
          card.className = 'mel-venue-suggestion mel-venue-suggestion--existing';
          card.appendChild(this.candidateTitle(candidate.name, candidate.address));
          var button = this.button(Drupal.t('Use existing venue'));
          button.addEventListener('click', function () {
            $('body').trigger('venueCreated', [{
              venue_id: candidate.venue_id,
              venue_name: candidate.name,
              address: candidate.address || '',
              latitude: candidate.latitude,
              longitude: candidate.longitude
            }]);
            var cancel = wrapper.querySelector('.dialog-cancel');
            if (cancel) cancel.click();
          });
          card.appendChild(button);
          existingSection.appendChild(card);
        }, this);
        container.appendChild(existingSection);
      }

      if (overture.length) {
        var publicSection = this.section(Drupal.t('Public details found'));
        var help = document.createElement('p');
        help.className = 'mel-venue-suggestions__help';
        help.textContent = Drupal.t('Use only the details you recognise. Nothing is added until you choose Use.');
        publicSection.appendChild(help);
        overture.forEach(function (candidate) {
          publicSection.appendChild(this.overtureCard(wrapper, candidate));
        }, this);
        if (payload.attribution) {
          var attribution = document.createElement('p');
          attribution.className = 'mel-venue-suggestions__attribution';
          attribution.textContent = payload.attribution;
          publicSection.appendChild(attribution);
        }
        container.appendChild(publicSection);
      }

      if (!existing.length && !overture.length) {
        container.appendChild(this.message(
          Drupal.t('No close venue match was found. Check the details below and create a new venue.'),
          'neutral'
        ));
      }
    },

    overtureCard: function (wrapper, candidate) {
      var card = document.createElement('article');
      card.className = 'mel-venue-suggestion mel-venue-suggestion--public';
      card.appendChild(this.candidateTitle(candidate.name, candidate.address));

      var fields = [
        ['name', Drupal.t('Venue name'), candidate.name],
        ['address', Drupal.t('Address'), candidate.address],
        ['website', Drupal.t('Website'), candidate.website],
        ['phone', Drupal.t('Public phone'), candidate.phone],
        ['email', Drupal.t('Public email'), candidate.email]
      ];
      var socials = candidate.socials || {};
      Object.keys(socials).forEach(function (field) {
        fields.push([field, this.socialLabel(field), socials[field]]);
      }, this);

      var list = document.createElement('dl');
      list.className = 'mel-venue-suggestion__fields';
      fields.forEach(function (field) {
        if (!field[2]) return;
        var row = document.createElement('div');
        row.className = 'mel-venue-suggestion__field';
        var term = document.createElement('dt');
        term.textContent = field[1];
        var description = document.createElement('dd');
        var value = document.createElement('span');
        value.textContent = field[2];
        description.appendChild(value);
        var useButton = this.button(Drupal.t('Use'));
        useButton.classList.add('mel-btn--small');
        useButton.addEventListener('click', function () {
          this.applySuggestionField(wrapper, candidate, field[0], field[2], useButton);
        }.bind(this));
        description.appendChild(useButton);
        row.appendChild(term);
        row.appendChild(description);
        list.appendChild(row);
      }, this);
      card.appendChild(list);
      return card;
    },

    applySuggestionField: function (wrapper, candidate, field, value, button) {
      var target = null;
      if (field === 'name') {
        target = wrapper.querySelector('.myeventlane-venue-name-field');
      }
      else if (field === 'address') {
        target = wrapper.querySelector('.mel-venue-address-field');
      }
      else {
        target = wrapper.querySelector('[data-enrichment-field="' + field + '"]');
      }
      if (!target) return;

      target.value = value;
      target.dispatchEvent(new Event('change', { bubbles: true }));
      if (field === 'address') {
        var lat = wrapper.querySelector('.myeventlane-location-latitude-field');
        var lng = wrapper.querySelector('.myeventlane-location-longitude-field');
        if (lat && candidate.latitude !== undefined) lat.value = candidate.latitude;
        if (lng && candidate.longitude !== undefined) lng.value = candidate.longitude;
      }
      if (field !== 'name' && field !== 'address') {
        var details = target.closest('details');
        if (details) details.open = true;
      }

      var sourceInput = wrapper.querySelector('[data-overture-source-id]');
      var acceptedInput = wrapper.querySelector('[data-overture-accepted-fields]');
      if (sourceInput && acceptedInput) {
        if (sourceInput.value && sourceInput.value !== candidate.source_id) {
          acceptedInput.value = '[]';
        }
        sourceInput.value = candidate.source_id;
        var accepted = [];
        try { accepted = JSON.parse(acceptedInput.value || '[]'); } catch (e) {}
        if (accepted.indexOf(field) === -1) accepted.push(field);
        acceptedInput.value = JSON.stringify(accepted);
      }
      button.textContent = Drupal.t('Used');
      button.disabled = true;
    },

    section: function (title) {
      var section = document.createElement('section');
      section.className = 'mel-venue-suggestions__section';
      var heading = document.createElement('h3');
      heading.textContent = title;
      section.appendChild(heading);
      return section;
    },

    candidateTitle: function (name, address) {
      var wrapper = document.createElement('div');
      var strong = document.createElement('strong');
      strong.textContent = name || Drupal.t('Unnamed venue');
      wrapper.appendChild(strong);
      if (address) {
        var text = document.createElement('span');
        text.textContent = address;
        wrapper.appendChild(text);
      }
      return wrapper;
    },

    button: function (label) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'mel-btn mel-btn--secondary';
      button.textContent = label;
      return button;
    },

    message: function (text, type) {
      var message = document.createElement('p');
      message.className = 'mel-venue-suggestions__message mel-venue-suggestions__message--' + type;
      message.textContent = text;
      return message;
    },

    socialLabel: function (field) {
      var labels = {
        facebook: Drupal.t('Facebook'),
        instagram: Drupal.t('Instagram'),
        twitter: Drupal.t('X (Twitter)'),
        linkedin: Drupal.t('LinkedIn'),
        youtube: Drupal.t('YouTube'),
        tiktok: Drupal.t('TikTok')
      };
      return labels[field] || Drupal.t('Social profile');
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

})(jQuery, Drupal, once, drupalSettings);
