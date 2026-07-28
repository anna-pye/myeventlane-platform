/**
 * @file
 * Narrow compatibility bridge for image_widget_crop's jQuery Cropper 4.
 *
 * Drupal's current jQuery no longer exposes $.isFunction, while Cropper 4
 * still calls it during initialisation. Keep the bridge on the Branding
 * workspace library instead of altering jQuery globally for unrelated pages.
 */
(function ($) {
  'use strict';

  if (typeof $.isFunction !== 'function') {
    $.isFunction = function (value) {
      return typeof value === 'function';
    };
  }
})(jQuery);
