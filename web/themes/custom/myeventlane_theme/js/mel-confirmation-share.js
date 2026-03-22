/**
 * @file
 * Light UX: copy event link button on confirmation page.
 */
(function (Drupal, once) {
  Drupal.behaviors.melConfirmationShare = {
    attach(context) {
      once('mel-share-copy', '.mel-share-copy', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const url = btn.dataset.url || '';
          if (url && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(() => {
              const original = btn.textContent;
              btn.textContent = Drupal.t('Link copied ✓');
              btn.disabled = true;
              setTimeout(() => {
                btn.textContent = original;
                btn.disabled = false;
              }, 2000);
            });
          }
        });
      });
    },
  };
})(Drupal, once);
