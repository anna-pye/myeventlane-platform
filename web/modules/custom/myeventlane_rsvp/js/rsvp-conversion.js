(function (Drupal, once) {
  const getForms = (context, key) => {
    const forms = once(key, '.mel-rsvp-public-form', context);
    // Fallback for attach() calls where context is the form element itself.
    if (
      context instanceof Element
      && context.matches('.mel-rsvp-public-form')
      && context.dataset[key] !== '1'
    ) {
      context.dataset[key] = '1';
      forms.push(context);
    }
    return forms;
  };

  Drupal.behaviors.melRsvpConversion = {
    attach(context) {
      getForms(context, 'melRsvpConversionAttached').forEach((form) => {
        const selector = form.querySelectorAll('input[name="people"]');
        const guestsInput = form.querySelector('input[name="quantity"]');
        const chipGroups = form.querySelectorAll('.mel-chip-group');
        const attendeeCards = form.querySelectorAll('.mel-attendee-card');
        const copyAllCheckbox = form.querySelector('.mel-copy-all');

        const upgradeChipMarkup = (group) => {
          const inputs = group.querySelectorAll('input[type="radio"], input[type="checkbox"]');
          inputs.forEach((input) => {
            const wrapper = input.closest('.form-item');
            if (!wrapper || wrapper.dataset.melChipReady === '1') {
              return;
            }
            wrapper.dataset.melChipReady = '1';
            wrapper.classList.add('mel-chip');
            const label = wrapper.querySelector('label');
            if (!label) {
              return;
            }

            const text = label.textContent ? label.textContent.trim() : '';
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
          const inputs = group.querySelectorAll('input[type="radio"], input[type="checkbox"]');
          inputs.forEach((input) => {
            const wrapper = input.closest('.form-item');
            if (!wrapper) {
              return;
            }
            wrapper.classList.add('mel-chip');
            if (input.checked) {
              wrapper.classList.add('mel-chip--active');
            }
            else {
              wrapper.classList.remove('mel-chip--active');
            }
          });
        };

        chipGroups.forEach((group) => {
          upgradeChipMarkup(group);
          syncChipGroup(group);
          const inputs = group.querySelectorAll('input[type="radio"], input[type="checkbox"]');
          inputs.forEach((input) => {
            input.addEventListener('change', () => syncChipGroup(group));
          });
        });

        const copyFirstAttendeeToAll = () => {
          if (!copyAllCheckbox || !copyAllCheckbox.checked) {
            return;
          }
          const firstName = form.querySelector('[name="attendees[0][name]"]');
          const firstEmail = form.querySelector('[name="attendees[0][email]"]');
          attendeeCards.forEach((card, i) => {
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

        const getRequestedAttendeeCount = () => {
          let value = parseInt(guestsInput?.value || '1', 10);
          if (!Number.isFinite(value)) {
            value = 1;
          }
          selector.forEach((el) => {
            if (el.checked) {
              value = parseInt(el.value || '1', 10) || value;
            }
          });
          return Math.max(1, Math.min(10, value));
        };

        const updateAttendeeCards = (shouldScroll) => {
          const value = getRequestedAttendeeCount();
          attendeeCards.forEach((card, index) => {
            if (index < value) {
              card.style.display = 'block';
              requestAnimationFrame(() => {
                card.classList.add('is-visible');
              });
            }
            else {
              card.style.display = 'none';
              card.classList.remove('is-visible');
            }
          });

          if (value > 1 && shouldScroll) {
            form.querySelector('.mel-attendees')?.scrollIntoView({
              behavior: 'smooth',
              block: 'start'
            });
          }
        };

        const syncGuestsFromIntent = () => {
          if (!guestsInput) {
            return;
          }
          let selected = 1;
          selector.forEach((el) => {
            if (el.checked) {
              selected = parseInt(el.value, 10) || 1;
            }
          });
          guestsInput.value = String(Math.max(1, selected));
          updateAttendeeCards(true);
          copyFirstAttendeeToAll();
        };

        selector.forEach((el) => {
          el.addEventListener('change', syncGuestsFromIntent);
        });

        if (guestsInput) {
          guestsInput.addEventListener('input', () => {
            updateAttendeeCards(true);
            copyFirstAttendeeToAll();
          });
        }

        if (copyAllCheckbox) {
          copyAllCheckbox.addEventListener('change', () => {
            copyFirstAttendeeToAll();
          });
          form.querySelector('[name="attendees[0][name]"]')?.addEventListener('input', copyFirstAttendeeToAll);
          form.querySelector('[name="attendees[0][email]"]')?.addEventListener('input', copyFirstAttendeeToAll);
        }

        updateAttendeeCards(false);
      });
    }
  };

  Drupal.behaviors.melRsvpCta = {
    attach(context) {
      getForms(context, 'melRsvpCtaAttached').forEach((form) => {
        const button = form.querySelector('.mel-sticky-cta .mel-btn')
          || form.querySelector('.form-actions .mel-btn, .form-actions input[type="submit"], .form-actions button[type="submit"]');
        if (!button) {
          return;
        }

        const setButtonLabel = (label) => {
          if (button.matches('input[type="submit"], input[type="button"]')) {
            button.value = label;
            return;
          }
          button.textContent = label;
        };

        const ensureLabelWrapper = () => {
          if (button.matches('input[type="submit"], input[type="button"]')) {
            return null;
          }
          let label = button.querySelector('.mel-btn__label');
          if (label) {
            return label;
          }
          label = document.createElement('span');
          label.className = 'mel-btn__label';
          label.textContent = (button.textContent || '').trim();
          button.textContent = '';
          button.appendChild(label);
          button.classList.add('is-visible');
          return label;
        };

        const updateCtaText = (newText) => {
          const label = ensureLabelWrapper();
          if (!label) {
            // Fallback path for <input type="submit">: animate container only.
            button.classList.add('is-updating');
            setButtonLabel(newText);
            window.setTimeout(() => {
              button.classList.remove('is-updating');
            }, 300);
            return;
          }

          button.classList.add('is-fading');
          window.setTimeout(() => {
            label.textContent = newText;
            button.classList.remove('is-fading');
            button.classList.add('is-visible');
            button.classList.add('is-updating');
            window.setTimeout(() => {
              button.classList.remove('is-updating');
            }, 300);
          }, 120);
        };

        const getCount = () => {
          const peopleChecked = form.querySelector('input[name="people"]:checked');
          const peopleValue = peopleChecked ? parseInt(peopleChecked.value || '1', 10) : NaN;
          const quantityInput = form.querySelector('input[name="quantity"]');
          const quantityValue = quantityInput ? parseInt(quantityInput.value || '1', 10) : NaN;
          const count = Number.isFinite(quantityValue) ? quantityValue : peopleValue;
          return Math.max(1, Math.min(10, Number.isFinite(count) ? count : 1));
        };

        const getDonationState = () => {
          const donationEnabled = form.querySelector('input[name="donation_enabled"]');
          if (!(donationEnabled instanceof HTMLInputElement) || !donationEnabled.checked) {
            return { enabled: false, suffix: '' };
          }

          const selectedDonation = form.querySelector('input[name="donation_choice"]:checked');
          if (!(selectedDonation instanceof HTMLInputElement)) {
            return { enabled: true, suffix: ' + support' };
          }

          if (selectedDonation.value === 'other') {
            const otherInput = form.querySelector('input[name="donation_other"]');
            const raw = otherInput instanceof HTMLInputElement ? otherInput.value.trim() : '';
            if (raw === '') {
              return { enabled: true, suffix: ' + support' };
            }
            const parsed = Number(raw);
            if (!Number.isFinite(parsed) || parsed <= 0) {
              return { enabled: true, suffix: ' + support' };
            }
            const amount = Number.isInteger(parsed)
              ? String(parsed)
              : parsed.toFixed(2).replace(/\.00$/, '');
            return { enabled: true, suffix: ` + $${amount} support` };
          }

          const preset = Number(selectedDonation.value);
          if (!Number.isFinite(preset) || preset <= 0) {
            return { enabled: true, suffix: ' + support' };
          }
          const amount = Number.isInteger(preset)
            ? String(preset)
            : preset.toFixed(2).replace(/\.00$/, '');
          return { enabled: true, suffix: ` + $${amount} support` };
        };

        const updateCta = () => {
          const count = getCount();
          const donationState = getDonationState();
          let baseText = count > 1 ? `🎉 Reserve ${count} spots` : '✨ Reserve my spot';
          if (donationState.enabled) {
            baseText = count > 1 ? `💛 Reserve ${count} spots` : '💛 Reserve my spot';
          }
          const ctaText = `${baseText}${donationState.suffix}`;
          updateCtaText(ctaText);
        };

        form.querySelectorAll('[name="people"], [name="quantity"], [name="donation_enabled"], [name="donation_choice"], [name="donation_other"]').forEach((el) => {
          el.addEventListener('change', updateCta);
          el.addEventListener('input', updateCta);
        });

        // Set initial state once without transition flash.
        ensureLabelWrapper();
        const initialCount = getCount();
        const initialText = initialCount > 1 ? `🎉 Reserve ${initialCount} spots` : '✨ Reserve my spot';
        setButtonLabel(initialText);
        button.classList.add('is-visible');
      });
    }
  };
})(Drupal, once);
