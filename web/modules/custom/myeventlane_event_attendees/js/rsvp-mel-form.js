(function (Drupal, once) {
  Drupal.behaviors.melRsvpDonationOptions = {
    attach(context) {
      once('mel-rsvp-donation', '.mel-rsvp-card', context).forEach((formEl) => {
        const radios = formEl.querySelectorAll('input[type="radio"][name$="[donation_choice]"]');
        if (!radios.length) {
          return;
        }
        const donationEnabled = formEl.querySelector('input[name$="[donation_enabled]"]');
        const hiddenInput = formEl.querySelector('input[name$="[donation_amount]"]');
        const otherInput = formEl.querySelector('input[name$="[donation_other]"]');

        const sync = () => {
          const enabled = donationEnabled ? donationEnabled.checked : false;
          radios.forEach((radio) => {
            const item = radio.closest('.form-item');
            const label = formEl.querySelector(`label[for="${radio.id}"]`) || radio.closest('label');
            if (item) {
              item.classList.add('mel-donation-option');
              item.classList.toggle('active', enabled && radio.checked);
            }
            if (label) {
              label.classList.add('mel-donation-option');
              label.classList.toggle('active', enabled && radio.checked);
            }
          });

          if (!hiddenInput) {
            return;
          }
          if (!enabled) {
            radios.forEach((radio) => {
              radio.checked = false;
            });
            if (otherInput) {
              otherInput.value = '';
            }
            hiddenInput.value = '0';
            return;
          }

          const checked = Array.from(radios).find((radio) => radio.checked);
          if (!checked) {
            hiddenInput.value = '0';
            return;
          }

          if (checked.value === 'other') {
            const raw = otherInput ? otherInput.value : '';
            hiddenInput.value = raw !== '' ? String(raw) : '0';
            return;
          }

          hiddenInput.value = checked.value;
        };

        radios.forEach((radio) => {
          radio.addEventListener('change', sync);
        });
        if (donationEnabled) {
          donationEnabled.addEventListener('change', sync);
        }
        if (otherInput) {
          otherInput.addEventListener('input', sync);
        }
        sync();
      });
    },
  };
})(Drupal, once);
