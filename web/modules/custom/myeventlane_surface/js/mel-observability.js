/**
 * @file
 * Progressive enhancement for staff observability panel (no telemetry collection).
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melObservability = {
    attach(context) {
      once('mel-observability-panel', '.mel-observability-panel', context).forEach((panel) => {
        const summaries = panel.querySelectorAll('summary');
        summaries.forEach((summary) => {
          summary.setAttribute('tabindex', '0');
        });
      });
    },
  };
})(Drupal, once);
