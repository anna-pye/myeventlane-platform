(function (Drupal, once) {
  'use strict';

  const fieldValue = (form, selector) => {
    const field = form.querySelector(selector);
    return field && typeof field.value === 'string' ? field.value.trim() : '';
  };

  const selectedLabel = (form, selector) => {
    const field = form.querySelector(selector);
    if (!field || field.selectedIndex < 0) {
      return '';
    }
    return field.options[field.selectedIndex].text.trim();
  };

  const summaries = {
    profile(form) {
      return [
        fieldValue(form, '[name="field_display_name[0][value]"]'),
        fieldValue(form, '[name="field_city[0][value]"]'),
      ].filter(Boolean).join(' · ') || Drupal.t('Add your personal details');
    },
    security(form) {
      const email = fieldValue(form, '[name="mail"]');
      return email ? Drupal.t('@email · Password protected', {'@email': email}) : Drupal.t('Password protected');
    },
    notifications(form) {
      const contact = form.querySelector('[name="contact"]');
      return contact && contact.checked
        ? Drupal.t('Personal contact form is on')
        : Drupal.t('Personal contact form is off');
    },
    preferences(form) {
      return [
        selectedLabel(form, '[name="preferred_langcode"]'),
        selectedLabel(form, '[name="timezone"]'),
      ].filter(Boolean).join(' · ') || Drupal.t('Choose language and region');
    },
  };

  const updateOverview = (root, form) => {
    const summary = root.querySelector('[data-mel-settings-overview]');
    if (summary) {
      summary.textContent = summaries.profile(form);
    }

    const avatar = root.querySelector('[data-mel-settings-avatar]');
    const source = document.querySelector('.mel-my-account__avatar');
    if (avatar && source && !avatar.hasChildNodes()) {
      avatar.append(source.cloneNode(true));
    }
  };

  Drupal.behaviors.melAccountSettingsHub = {
    attach(context) {
      once('mel-account-settings-hub', '.mel-account-settings', context).forEach((root) => {
        const form = root.querySelector('.mel-account-settings-form');
        if (!form) {
          return;
        }

        const cards = Array.from(form.querySelectorAll('[data-mel-settings-card]'));

        const closeCard = (card) => {
          card.classList.remove('is-editing');
          const button = card.querySelector('[data-mel-settings-toggle]');
          const editor = card.querySelector('[data-mel-settings-editor]');
          if (button) {
            button.setAttribute('aria-expanded', 'false');
            button.textContent = Drupal.t('Edit');
          }
          if (editor) {
            editor.hidden = true;
          }
        };

        const refreshCard = (card) => {
          const key = card.dataset.melSettingsCard;
          const summary = card.querySelector('[data-mel-settings-summary]');
          if (summary && summaries[key]) {
            summary.textContent = summaries[key](form);
          }
        };

        cards.forEach((card) => {
          const heading = card.querySelector('.mel-account-settings__heading');
          if (!heading) {
            return;
          }

          const summary = document.createElement('p');
          summary.className = 'mel-account-settings__summary';
          summary.dataset.melSettingsSummary = '';

          const toggle = document.createElement('button');
          toggle.type = 'button';
          toggle.className = 'mel-account-settings__toggle';
          toggle.dataset.melSettingsToggle = '';
          toggle.setAttribute('aria-expanded', 'false');
          toggle.textContent = Drupal.t('Edit');

          const editor = document.createElement('div');
          editor.className = 'mel-account-settings__editor';
          editor.dataset.melSettingsEditor = '';
          Array.from(card.children).forEach((child) => {
            if (child !== heading) {
              editor.append(child);
            }
          });

          heading.after(toggle);
          heading.after(summary);
          card.append(editor);
          refreshCard(card);
          closeCard(card);

          toggle.addEventListener('click', () => {
            const opening = !card.classList.contains('is-editing');
            cards.forEach(closeCard);
            if (opening) {
              card.classList.add('is-editing');
              toggle.setAttribute('aria-expanded', 'true');
              toggle.textContent = Drupal.t('Done');
              editor.hidden = false;
              const firstControl = editor.querySelector('input:not([type="hidden"]), select, textarea, button, a');
              if (firstControl) {
                firstControl.focus({preventScroll: true});
              }
            }
          });
        });

        // Core's account container and contrib's social-auth details can remain
        // as root-level siblings even when their children use #group. Rehome the
        // intact DOM containers after render; their input names and Drupal form
        // parents are unchanged.
        const securityEditor = form.querySelector('[data-mel-settings-card="security"] [data-mel-settings-editor]');
        if (securityEditor) {
          ['#edit-account', '#edit-social-auth'].forEach((selector) => {
            const orphan = form.querySelector(selector);
            if (orphan && !securityEditor.contains(orphan)) {
              securityEditor.append(orphan);
            }
          });
        }

        if (form.dataset.melPasswordSetup === 'true') {
          const securityToggle = form.querySelector('[data-mel-settings-card="security"] [data-mel-settings-toggle]');
          if (securityToggle) {
            securityToggle.click();
          }
        }

        const invalidCard = cards.find((card) => card.querySelector('.error, [aria-invalid="true"]'));
        if (invalidCard) {
          const toggle = invalidCard.querySelector('[data-mel-settings-toggle]');
          if (toggle && !invalidCard.classList.contains('is-editing')) {
            toggle.click();
          }
        }

        const markDirty = () => {
          form.classList.add('is-dirty');
          const actions = form.querySelector('.mel-account-settings__actions');
          if (actions) {
            actions.classList.add('is-visible');
          }
          cards.forEach(refreshCard);
          updateOverview(root, form);
        };

        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('submit', () => form.classList.remove('is-dirty'));
        updateOverview(root, form);
        root.classList.add('is-enhanced');
      });
    },
  };
})(Drupal, once);
