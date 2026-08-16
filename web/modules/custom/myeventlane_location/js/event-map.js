/**
 * @file
 * Event map rendering for Google Maps and Apple Maps.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Selects the directions provider for the current device.
   *
   * Mobile devices use their native maps provider where possible. Desktop
   * devices follow the configured event map provider.
   */
  function configureDirectionsLinks(provider) {
    const userAgent = navigator.userAgent || '';
    const platform = navigator.platform || '';
    const isAppleMobile = /iPad|iPhone|iPod/i.test(userAgent)
      || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/i.test(userAgent) || /Android/i.test(platform);

    document.querySelectorAll('[data-mel-directions-link]').forEach((link) => {
      const appleUrl = link.dataset.appleDirectionsUrl;
      const googleUrl = link.dataset.googleDirectionsUrl;
      let directionsUrl = googleUrl;

      if (isAppleMobile) {
        directionsUrl = appleUrl;
      } else if (!isAndroid && provider === 'apple_maps') {
        directionsUrl = appleUrl;
      }

      if (directionsUrl) {
        link.href = directionsUrl;
      }
    });
  }

  /**
   * Initializes map rendering on Event view pages.
   */
  function initEventMap() {
    const settings = drupalSettings.myeventlaneLocation || {};
    const eventData = drupalSettings.myeventlaneLocationEvent || {};
    const provider = settings.provider || 'google_maps';

    configureDirectionsLinks(provider);

    if (!eventData.latitude || !eventData.longitude) {
      console.warn('MyEventLane Location: Event coordinates not found.');
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

    // Get coordinates from data attributes as fallback
    const lat = parseFloat(eventData.latitude) || parseFloat(mapContainer.dataset.latitude);
    const lng = parseFloat(eventData.longitude) || parseFloat(mapContainer.dataset.longitude);
    const title = eventData.title || mapContainer.dataset.title || 'Event Location';
    
    // Get address from nearby elements if available
    const addressElement = mapContainer.closest('.event-sidebar__section')?.querySelector('.event-sidebar__address, address');
    const address = addressElement ? addressElement.textContent.trim() : null;

    // Validate coordinates
    if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      console.error('MyEventLane Location: Invalid coordinates:', lat, lng);
      return;
    }

    if (provider === 'google_maps') {
      initGoogleMapsMap(mapContainer, lat, lng, title, address, settings);
    } else if (provider === 'apple_maps') {
      initAppleMapsMap(mapContainer, lat, lng, title, address, settings);
    }
  }

  /**
   * Initializes Google Maps embedded map.
   */
  function initGoogleMapsMap(container, lat, lng, title, address, settings) {
    // Check if Google Maps API is loaded.
    if (typeof google === 'undefined' || !google.maps) {
      // Load Google Maps API.
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${settings.google_maps_api_key}&callback=myeventlaneLocationGoogleMapsMapReady`;
      script.async = true;
      script.defer = true;
      window.myeventlaneLocationGoogleMapsMapReady = function () {
        setupGoogleMapsMap(container, lat, lng, title, address);
      };
      document.head.appendChild(script);
    } else {
      setupGoogleMapsMap(container, lat, lng, title, address);
    }
  }

  /**
   * Sets up Google Maps after API is loaded.
   * Minimal controls and styling for clean, calm appearance.
   */
  function setupGoogleMapsMap(container, lat, lng, title, address) {
    // Ensure container has proper dimensions
    if (container.offsetHeight === 0) {
      container.style.height = '200px';
      container.style.minHeight = '200px';
    }

    // Minimal map styling - reduce visual clutter
    const mapStyles = [
      {
        featureType: 'poi',
        elementType: 'labels',
        stylers: [{ visibility: 'off' }]
      },
      {
        featureType: 'transit',
        elementType: 'labels',
        stylers: [{ visibility: 'off' }]
      },
      {
        featureType: 'poi.business',
        stylers: [{ visibility: 'off' }]
      }
    ];

    const map = new google.maps.Map(container, {
      center: { lat: lat, lng: lng },
      zoom: 16,
      // Disable most controls to reduce Google branding
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      zoomControl: true,
      zoomControlOptions: {
        position: google.maps.ControlPosition.RIGHT_CENTER
      },
      // Minimal styling
      styles: mapStyles,
      // Disable default UI features
      disableDefaultUI: false,
      gestureHandling: 'cooperative',
      // Ensure map renders
      mapTypeId: google.maps.MapTypeId.ROADMAP,
    });

    // Simple marker
    const marker = new google.maps.Marker({
      position: { lat: lat, lng: lng },
      map: map,
      title: title,
      animation: google.maps.Animation.DROP,
    });

    // Info window with title and address
    const infoContent = address 
      ? `<div style="padding: 8px; line-height: 1.5;"><strong>${title}</strong><br><span style="color: #666; font-size: 0.9em;">${address}</span></div>`
      : `<div style="padding: 8px;"><strong>${title}</strong></div>`;
    
    const infoWindow = new google.maps.InfoWindow({
      content: infoContent,
    });
    
    marker.addListener('click', function () {
      infoWindow.open(map, marker);
    });
  }

  /**
   * Initializes Apple Maps embedded map.
   */
  function initAppleMapsMap(container, lat, lng, title, address, settings) {
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
  function setupAppleMapsMap(container, lat, lng, title, address) {
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
