/**
 * @file
 * Event map rendering for Google Maps and Apple Maps.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Initializes map rendering on Event view pages.
   */
  function initEventMap() {
    console.log('MyEventLane Location: Initializing event map...');
    const settings = drupalSettings.myeventlaneLocation || {};
    const eventData = drupalSettings.myeventlaneLocationEvent || {};
    const provider = settings.provider || 'google_maps';

    console.log('MyEventLane Location: Settings:', settings);
    console.log('MyEventLane Location: Event data:', eventData);

    if (!eventData.latitude || !eventData.longitude) {
      console.warn('MyEventLane Location: Event coordinates not found. Event data:', eventData);
      return;
    }

    // Try multiple selectors to find the map container.
    let mapContainer = document.querySelector('.myeventlane-event-map-container');
    if (!mapContainer) {
      // Fallback: try to find by data attributes.
      mapContainer = document.querySelector('[data-latitude][data-longitude]');
    }
    if (!mapContainer) {
      console.warn('MyEventLane Location: Map container not found.');
      return;
    }

    const lat = parseFloat(eventData.latitude);
    const lng = parseFloat(eventData.longitude);
    const title = eventData.title || 'Event Location';

    if (provider === 'google_maps') {
      initGoogleMapsMap(mapContainer, lat, lng, title, settings);
    } else if (provider === 'apple_maps') {
      initAppleMapsMap(mapContainer, lat, lng, title, settings);
    }
  }

  /**
   * Initializes Google Maps embedded map.
   */
  function initGoogleMapsMap(container, lat, lng, title, settings) {
    // Check if Google Maps API is loaded.
    if (typeof google === 'undefined' || !google.maps) {
      // Load Google Maps API.
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${settings.google_maps_api_key}&callback=myeventlaneLocationGoogleMapsMapReady`;
      script.async = true;
      script.defer = true;
      window.myeventlaneLocationGoogleMapsMapReady = function () {
        setupGoogleMapsMap(container, lat, lng, title);
      };
      document.head.appendChild(script);
    } else {
      setupGoogleMapsMap(container, lat, lng, title);
    }
  }

  /**
   * Sets up Google Maps after API is loaded.
   */
  function setupGoogleMapsMap(container, lat, lng, title) {
    const map = new google.maps.Map(container, {
      center: { lat: lat, lng: lng },
      zoom: 15,
      mapTypeControl: true,
      streetViewControl: true,
      fullscreenControl: true,
    });

    const marker = new google.maps.Marker({
      position: { lat: lat, lng: lng },
      map: map,
      title: title,
    });

    // Add info window.
    const infoWindow = new google.maps.InfoWindow({
      content: `<strong>${title}</strong><br>${lat}, ${lng}`,
    });
    marker.addListener('click', function () {
      infoWindow.open(map, marker);
    });
  }

  /**
   * Initializes Apple Maps embedded map.
   */
  function initAppleMapsMap(container, lat, lng, title, settings) {
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
          setupAppleMapsMap(container, lat, lng, title);
        }
      };
      document.head.appendChild(script);
    } else {
      setupAppleMapsMap(container, lat, lng, title);
    }
  }

  /**
   * Sets up Apple Maps after API is loaded.
   */
  function setupAppleMapsMap(container, lat, lng, title) {
    const map = new mapkit.Map(container);
    const coordinate = new mapkit.Coordinate(lat, lng);
    map.region = new mapkit.CoordinateRegion(coordinate, new mapkit.CoordinateSpan(0.01, 0.01));

    const marker = new mapkit.MarkerAnnotation(coordinate, {
      title: title,
    });
    map.addAnnotation(marker);
  }

  // Initialize on DOM ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEventMap);
  } else {
    initEventMap();
  }

})(Drupal, drupalSettings);

