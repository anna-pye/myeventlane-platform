(function (Drupal, once) {

  const getForms = (context, key) => {
    let roots;
    if (
      context &&
      context.nodeType === 1 &&
      typeof context.matches === 'function' &&
      context.matches('.mel-rsvp-public-form')
    ) {
      // Behaviors often receive the form element as context; querySelectorAll does not include self.
      roots = [context];
    }
    else if (context && typeof context.querySelectorAll === 'function') {
      roots = context.querySelectorAll('.mel-rsvp-public-form');
    }
    else {
      roots = [];
    }
    return [...once(key, roots)];
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

            // Defensive: AJAX or partial DOM can skip upgradeChipMarkup; chips still need base class.
            wrapper.classList.add('mel-chip');
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
          const select = form.querySelector('select[name="people"]');
          const count = getRequestedAttendeeCount();

          if (select instanceof HTMLSelectElement) {
            // Form API + AJAX render exactly N cards; never hide extras (avoids guest 2 invisible).
            getAttendeeCards().forEach((card) => {
              card.classList.remove('is-hidden');
              card.classList.add('is-visible');
            });
          }
          else {
            getAttendeeCards().forEach((card, i) => {
              card.classList.toggle('is-hidden', i >= count);
              card.classList.toggle('is-visible', i < count);
            });
          }

          if (count > 1 && scroll) {
            form.querySelector('.mel-attendees')?.scrollIntoView({ behavior: 'smooth' });
          }
        };

        const copyFirstAttendeeToAll = () => {
          if (!copyAllCheckbox || !copyAllCheckbox.checked) {
            return;
          }
          const firstName = form.querySelector('[name="attendees[0][name]"]');
          const firstEmail = form.querySelector('[name="attendees[0][email]"]');
          getAttendeeCards().forEach((card, i) => {
            if (i === 0) {
              return;
            }
            const name = card.querySelector('[name^="attendees"][name$="[name]"]');
            const email = card.querySelector('[name^="attendees"][name$="[email]"]');
            if (name) {
              name.value = firstName?.value || '';
            }
            if (email) {
              email.value = firstEmail?.value || '';
            }
          });
        };

        const syncGuests = () => {
          if (!guestsInput) return;

          const count = getRequestedAttendeeCount();
          guestsInput.value = String(count);

          updateAttendeeCards(true);
          copyFirstAttendeeToAll();
        };

        getSelectorInputs().forEach((el) => {
          once('mel-selector', el).forEach(() => {
            el.addEventListener('change', syncGuests);
          });
        });

        if (guestsInput) {
          once('mel-guests', guestsInput).forEach(() => {
            guestsInput.addEventListener('input', () => {
              updateAttendeeCards(true);
              copyFirstAttendeeToAll();
            });
          });
        }

        if (copyAllCheckbox) {
          once('mel-rsvp-copy-all', copyAllCheckbox).forEach(() => {
            copyAllCheckbox.addEventListener('change', copyFirstAttendeeToAll);
          });
        }

        const firstNameField = form.querySelector('[name="attendees[0][name]"]');
        const firstEmailField = form.querySelector('[name="attendees[0][email]"]');
        if (firstNameField) {
          once('mel-rsvp-copy-sync-name', firstNameField).forEach(() => {
            firstNameField.addEventListener('input', copyFirstAttendeeToAll);
          });
        }
        if (firstEmailField) {
          once('mel-rsvp-copy-sync-email', firstEmailField).forEach(() => {
            firstEmailField.addEventListener('input', copyFirstAttendeeToAll);
          });
        }

        updateAttendeeCards(false);
      });
    }
  };

})(Drupal, once);