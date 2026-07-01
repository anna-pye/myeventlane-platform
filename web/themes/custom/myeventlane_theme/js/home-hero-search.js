/**
 * @file
 * Homepage hero search: events browse vs site search when location is used.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melHomeHeroSearch = {
    attach: function (context) {
      once('melHomeHeroSearch', '.mel-home-hero__search', context).forEach(function (form) {
        if (form.getAttribute('data-mel-search-mode') === 'site') {
          return;
        }
        form.addEventListener('submit', function (event) {
          var searchInput = form.querySelector('[name="search"]');
          var locationInput = form.querySelector('[name="location"]');
          var keyword = searchInput && searchInput.value ? searchInput.value.trim() : '';
          var location = locationInput && locationInput.value ? locationInput.value.trim() : '';
          var eventsUrl = form.getAttribute('data-events-url') || '/events';
          var siteSearchUrl = form.getAttribute('data-search-url') || '/search';

          if (!keyword && !location) {
            event.preventDefault();
            return;
          }

          if (location) {
            event.preventDefault();
            var combined = [keyword, location].filter(Boolean).join(' ');
            window.location.href = siteSearchUrl + '?q=' + encodeURIComponent(combined);
          }
          else if (keyword) {
            form.setAttribute('action', eventsUrl);
          }
        });
      });
    },
  };
})(Drupal, once);
