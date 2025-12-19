(function (Drupal, once, drupalSettings) {
  'use strict';

  function loadGoogleMaps(apiKey) {
    if (window.google && window.google.maps) {
      return Promise.resolve();
    }

    if (!apiKey) {
      return Promise.reject(new Error('Missing Google Maps API key in drupalSettings.'));
    }

    // Prevent double loads.
    if (window.__melGoogleMapsLoading) {
      return window.__melGoogleMapsLoading;
    }

    window.__melGoogleMapsLoading = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey);
      script.async = true;
      script.defer = true;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error('Failed to load Google Maps JS.'));
      document.head.appendChild(script);
    });

    return window.__melGoogleMapsLoading;
  }

  function getApiKey() {
    // Preferred: provided by your MyEventLane Location custom module.
    if (drupalSettings && drupalSettings.myeventlaneLocation && drupalSettings.myeventlaneLocation.googleMapsApiKey) {
      return drupalSettings.myeventlaneLocation.googleMapsApiKey;
    }
    // Fallback: allow key to be set by this module if needed later.
    if (drupalSettings && drupalSettings.myeventlane_event && drupalSettings.myeventlane_event.googleMapsApiKey) {
      return drupalSettings.myeventlane_event.googleMapsApiKey;
    }
    return '';
  }

  function initMap(el) {
    const lat = parseFloat(el.dataset.lat || '');
    const lng = parseFloat(el.dataset.lng || '');
    const title = el.dataset.title || '';

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      el.innerHTML = '<div class="mel-event__map-fallback"><p>Map unavailable.</p></div>';
      return;
    }

    const map = new google.maps.Map(el, {
      center: { lat, lng },
      zoom: 15,
      disableDefaultUI: true,
      zoomControl: true,
      gestureHandling: 'cooperative'
    });

    new google.maps.Marker({
      position: { lat, lng },
      map,
      title
    });
  }

  Drupal.behaviors.melEventMap = {
    attach: function (context) {
      once('mel-event-map', '.myeventlane-event-map-container', context).forEach((el) => {
        const apiKey = getApiKey();

        loadGoogleMaps(apiKey)
          .then(() => initMap(el))
          .catch((e) => {
            // Show a clean fallback in the UI.
            el.innerHTML = '<div class="mel-event__map-fallback"><p>Map unavailable.</p></div>';
            // Keep an error for debugging.
            // eslint-disable-next-line no-console
            console.warn('[MyEventLane] Map init failed:', e.message || e);
          });
      });
    }
  };

})(Drupal, once, drupalSettings);