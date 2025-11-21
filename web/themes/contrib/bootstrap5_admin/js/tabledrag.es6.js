/**
 * @file
 * tabledrag.js overrides and functionality extensions.
 */

(($, Drupal) => {
  $.extend(
    Drupal.theme,
    /** @lends Drupal.theme */ {
      /**
       * Constructs contents of the toggle weight button.
       *
       * @param {boolean} show
       *   If the table weights are currently displayed.
       *
       * @return {string}
       *  HTML markup for the weight toggle button content.
       */
      toggleButtonContent: (show) => {
        const classes = [
          'action-link',
          'action-link--extrasmall',
          'tabledrag-toggle-weight',
        ];
        let icon = '', title = '';
        if (show) {
          classes.push('action-link--icon-hide');
          title = Drupal.t('Hide row weights');
          icon = '<i class="bi bi-eye"></i>';
        } else {
          classes.push('action-link--icon-show');
          title = Drupal.t('Show row weights');
          icon = '<i class="bi bi-eye-slash"></i>';
        }
        return `<span class="${classes.join(' ')}" title="${title}">${icon}</a>`;
      },
    },
  );
})(jQuery, Drupal);
