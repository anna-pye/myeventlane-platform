(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.melHelpFeedback = {
    attach(context) {
      once('mel-help-feedback', '.mel-help-feedback', context).forEach((wrapper) => {
        const endpoint = wrapper.getAttribute('data-endpoint');
        const token = wrapper.getAttribute('data-token');
        if (!endpoint || !token) {
          return;
        }

        const buttons = wrapper.querySelectorAll('[data-helpful]');
        const status = wrapper.querySelector('.mel-help-feedback__status');
        const noHint = wrapper.querySelector('.mel-help-feedback__no-hint');

        buttons.forEach((button) => {
          button.addEventListener('click', async () => {
            const helpful = button.getAttribute('data-helpful');
            if (helpful !== '0' && helpful !== '1') {
              return;
            }

            buttons.forEach((btn) => {
              btn.disabled = true;
            });

            try {
              const body = new URLSearchParams();
              body.set('helpful', helpful);
              body.set('token', token);

              const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                  'X-CSRF-Token': token,
                },
                credentials: 'same-origin',
                body: body.toString(),
              });
              if (!response.ok) {
                throw new Error('Feedback request failed');
              }

              if (status) {
                status.textContent = Drupal.t('Thanks for your feedback.');
              }
              buttons.forEach((btn) => {
                btn.hidden = true;
              });
              if (helpful === '0' && noHint) {
                noHint.hidden = false;
              }
            }
            catch (error) {
              if (status) {
                status.textContent = Drupal.t('Something went wrong. Please try again.');
              }
              buttons.forEach((btn) => {
                btn.disabled = false;
              });
            }
          });
        });
      });
    },
  };
})(Drupal, once);
