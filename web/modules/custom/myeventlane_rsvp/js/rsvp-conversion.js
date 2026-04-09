(function (Drupal, once) {

  const getForms = (context, key) => {
    const elements = context.querySelectorAll('.mel-rsvp-public-form');
    const forms = Array.from(once(key, elements));

    if (context.nodeType === 1 && context.matches('.mel-rsvp-public-form')) {
      if (!once.filter(key, context).length) {
        once(key, context);
        forms.push(context);
      }
    }

    return forms;
  };

  Drupal.behaviors.melRsvpConversion = {
    attach(context) {

      getForms(context, 'melRsvpConversionAttached').forEach((form) => {

        const getSelectorInputs = () =>
          form.querySelectorAll('input[name="people"], select[name="people"]');

        const guestsInput = form.querySelector('input[name="quantity"]');
        const chipGroups = form.querySelectorAll('.mel-chip-group');
        const getAttendeeCards = () => form.querySelectorAll('.mel-attendee-card');
        const copyAllCheckbox = form.querySelector('.mel-copy-all');

        const MAX = drupalSettings?.mel?.maxAttendees || 10;

        const upgradeChipMarkup = (group) => {
          group.querySelectorAll('input').forEach((input) => {
            const wrapper = input.closest('.form-item');
            if (!wrapper || wrapper.dataset.melChipReady === '1') return;

            wrapper.dataset.melChipReady = '1';
            wrapper.classList.add('mel-chip');

            const label = wrapper.querySelector('label');
            if (!label) return;

            const text = label.textContent?.trim() || '';
            label.textContent = '';
            label.classList.add('mel-chip__label');
            label.appendChild(input);

            const span = document.createElement('span');
            span.className = 'mel-chip__text';
            span.textContent = text;
            label.appendChild(span);
          });
        };

        const syncChipGroup = (group) => {
          group.querySelectorAll('input').forEach((input) => {
            const wrapper = input.closest('.form-item');
            if (!wrapper) return;

            wrapper.classList.toggle('mel-chip--active', input.checked);
          });
        };

        chipGroups.forEach((group) => {
          upgradeChipMarkup(group);
          syncChipGroup(group);

          group.querySelectorAll('input').forEach((input) => {
            once('mel-chip-input', input).forEach(() => {
              input.addEventListener('change', () => syncChipGroup(group));
            });
          });
        });

        const getRequestedAttendeeCount = () => {
          const select = form.querySelector('select[name="people"]');

          if (select instanceof HTMLSelectElement) {
            const v = parseInt(select.value || '1', 10);
            return Math.max(1, Math.min(MAX, v || 1));
          }

          let value = parseInt(guestsInput?.value || '1', 10) || 1;

          getSelectorInputs().forEach((el) => {
            if (el.checked) {
              value = parseInt(el.value, 10) || value;
            }
          });

          return Math.max(1, Math.min(MAX, value));
        };

        const updateAttendeeCards = (scroll) => {
          const count = getRequestedAttendeeCount();

          getAttendeeCards().forEach((card, i) => {
            card.classList.toggle('is-hidden', i >= count);
            card.classList.toggle('is-visible', i < count);
          });

          if (count > 1 && scroll) {
            form.querySelector('.mel-attendees')?.scrollIntoView({ behavior: 'smooth' });
          }
        };

        const syncGuests = () => {
          if (!guestsInput) return;

          const count = getRequestedAttendeeCount();
          guestsInput.value = String(count);

          updateAttendeeCards(true);
        };

        getSelectorInputs().forEach((el) => {
          once('mel-selector', el).forEach(() => {
            el.addEventListener('change', syncGuests);
          });
        });

        if (guestsInput) {
          once('mel-guests', guestsInput).forEach(() => {
            guestsInput.addEventListener('input', () => updateAttendeeCards(true));
          });
        }

        updateAttendeeCards(false);
      });
    }
  };

})(Drupal, once);