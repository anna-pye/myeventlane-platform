/**
 * @file
 * Vendor venues list functionality.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Copy share link to clipboard.
   */
  Drupal.behaviors.venueShareLink = {
    attach: function (context) {
      $(context).find('.mel-copy-share-link').once('venue-share-link').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var shareUrl = $btn.data('share-url');

        if (navigator.clipboard && shareUrl) {
          navigator.clipboard.writeText(shareUrl).then(function () {
            $btn.addClass('copied');
            setTimeout(function () {
              $btn.removeClass('copied');
            }, 2000);
          }).catch(function (err) {
            console.error('Failed to copy share link:', err);
          });
        }
      });
    }
  };

})(jQuery, Drupal);
