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

      once('mel-share-native', '.mel-share-native', context).forEach((btn) => {
        if (!navigator.share) {
          btn.hidden = true;
          return;
        }

        btn.addEventListener('click', () => {
          const url = btn.dataset.url || '';
          if (!url) {
            return;
          }

          navigator.share({
            title: btn.dataset.title || document.title,
            text: btn.dataset.text || '',
            url,
          }).catch(() => {
            // User cancelled or platform refused; visible fallback links remain.
          });
        });
      });
    },
  };
})(Drupal, once);
