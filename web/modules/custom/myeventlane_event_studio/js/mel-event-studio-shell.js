(function (Drupal, drupalSettings, once) {
  'use strict';

  let tokenPromise = null;
  const stateClasses = ['is-unsaved', 'is-saving', 'is-saved', 'is-error', 'has-draft'];

  function studioSettings() {
    return drupalSettings.myeventlaneEventStudio || {};
  }

  function getCsrfToken() {
    if (!tokenPromise) {
      tokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`CSRF token request failed with ${response.status}`);
          }
          return response.text();
        })
        .then((token) => {
          const trimmed = token.trim();
          if (!trimmed) {
            throw new Error('CSRF token response was empty.');
          }
          return trimmed;
        })
        .catch((error) => {
          tokenPromise = null;
          throw error;
        });
    }
    return tokenPromise;
  }

  function setStatus(status, message, state) {
    if (!status) {
      return;
    }
    stateClasses.forEach((className) => status.classList.remove(className));
    if (state) {
      status.classList.add(state);
    }
    status.textContent = message;
  }

  function setFormPublishState(form, state) {
    if (!form) {
      return;
    }
    form.dataset.melPublishState = state;
  }

  function sectionForms(shell) {
    if (!shell) {
      return [];
    }
    return Array.from(shell.querySelectorAll('form')).filter((form) => isWritableForm(form));
  }

  function isWritableForm(form) {
    const capabilities = studioSettings().currentSectionCapabilities || {};
    const section = form.closest('[data-mel-section-writable]');
    if (section && section.dataset.melSectionWritable === '0') {
      return false;
    }
    if (form.dataset.melSectionWritable === '0') {
      return false;
    }
    if (studioSettings().currentSectionWritable === false) {
      return false;
    }
    if (capabilities.writable === false || capabilities.readonly === true || capabilities.deferred === true) {
      return false;
    }
    return true;
  }

  function appendMelDonationHidden(form, key, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = `mel[${key}]`;
    input.value = value ?? '';
    input.setAttribute('data-mel-donation-sync', '1');
    form.appendChild(input);
  }

  function bookingModeSupportsDonationSync(bookingForm) {
    const modeInput = bookingForm.querySelector('[name="mel[field_event_type]"]:checked')
      || bookingForm.querySelector('[name="mel[field_event_type]"]');
    const mode = modeInput?.value ?? '';
    return mode === 'rsvp';
  }

  function supportsAutosaveForm(form) {
    const capabilities = studioSettings().currentSectionCapabilities || {};
    return isWritableForm(form) && capabilities.supports_autosave !== false;
  }

  function dirtyForms(shell) {
    return sectionForms(shell).filter((form) => {
      const state = form.dataset.melPublishState;
      return state === 'dirty' || state === 'saving' || state === 'error';
    });
  }

  function formValue(form, name) {
    if (!form) {
      return '';
    }
    const input = form.querySelector(`[name="${name}"]`);
    return input ? input.value : '';
  }

  function publishMetadata(shell, button) {
    const forms = sectionForms(shell);
    const source = forms.find((form) => formValue(form, 'mel_studio_changed') || formValue(form, 'mel_studio_revision'));
    const topbarButton = document.querySelector('[data-mel-publish-action]');
    return {
      section: studioSettings().currentSection || button.dataset.melCurrentSection || '',
      changed: button.dataset.melNodeChanged || topbarButton?.dataset.melNodeChanged || formValue(source, 'mel_studio_changed') || studioSettings().nodeChanged || 0,
      revision_id: button.dataset.melNodeRevision || topbarButton?.dataset.melNodeRevision || formValue(source, 'mel_studio_revision') || studioSettings().nodeRevisionId || 0,
      dirty: dirtyForms(shell).length > 0,
    };
  }

  function publishUrlFor(button) {
    const topbarButton = document.querySelector('[data-mel-publish-action]');
    return button.dataset.melPublishUrl || topbarButton?.dataset.melPublishUrl || studioSettings().publishUrl;
  }

  function setPublishButtonState(button, state) {
    if (!button) {
      return;
    }
    button.classList.remove('is-publishing', 'is-published', 'cannot-publish');
    button.disabled = false;
    button.removeAttribute('aria-disabled');
    if (state === 'publishing') {
      button.classList.add('is-publishing');
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.textContent = Drupal.t('Publishing...');
    }
    else if (state === 'published') {
      button.classList.add('is-published');
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.textContent = Drupal.t('Published');
    }
    else if (state === 'cannot_publish') {
      button.classList.add('cannot-publish');
      button.textContent = Drupal.t('Cannot publish');
    }
    else {
      button.textContent = Drupal.t('Publish');
    }
  }

  function updatePublishPanels(shell, published) {
    shell.querySelectorAll('[data-mel-publish-panel="draft"], .mel-publish-action-card__draft').forEach((panel) => {
      panel.hidden = !!published;
      panel.setAttribute('aria-hidden', published ? 'true' : 'false');
    });
    shell.querySelectorAll('[data-mel-publish-panel="live"], .mel-publish-action-card__live').forEach((panel) => {
      panel.hidden = !published;
      panel.setAttribute('aria-hidden', published ? 'false' : 'true');
    });
    shell.querySelectorAll('[name="mel[status]"]').forEach((input) => {
      input.value = published ? '1' : '0';
    });
  }

  function updateFormMetadata(shell, result) {
    if (!result) {
      return;
    }
    sectionForms(shell).forEach((form) => {
      if (result.changed !== undefined && result.changed !== null) {
        const changed = form.querySelector('[name="mel_studio_changed"]');
        if (changed) {
          changed.value = String(result.changed);
        }
      }
      if (result.revisionId !== undefined && result.revisionId !== null) {
        const revision = form.querySelector('[name="mel_studio_revision"]');
        if (revision) {
          revision.value = String(result.revisionId);
        }
      }
      setFormPublishState(form, 'clean');
    });
  }

  function setText(root, selector, text) {
    const el = root.querySelector(selector);
    if (el) {
      el.textContent = text;
    }
  }

  function updateReadiness(shell, readiness) {
    if (!readiness) {
      return;
    }
    const strip = shell.querySelector('[data-mel-readiness-strip]');
    if (!strip) {
      return;
    }
    strip.classList.toggle('is-ready', !!readiness.ready);
    strip.classList.toggle('needs-attention', !readiness.ready);
    setText(strip, '[data-mel-readiness-title]', readiness.ready ? Drupal.t('Ready to publish') : Drupal.t('Needs attention'));
    setText(strip, '[data-mel-readiness-state]', readiness.state || '');
    setText(strip, '[data-mel-readiness-errors-count]', Drupal.formatPlural((readiness.errors || []).length, '1 blocker', '@count blocker(s)'));
    setText(strip, '[data-mel-readiness-warnings-count]', Drupal.formatPlural((readiness.warnings || []).length, '1 warning', '@count warning(s)'));
    setText(strip, '[data-mel-readiness-recommendations-count]', Drupal.formatPlural((readiness.recommendations || []).length, '1 idea', '@count idea(s)'));
    setText(strip, '[data-mel-readiness-completed-count]', Drupal.t('@count complete', { '@count': (readiness.completed || []).length }));
  }

  function syncTopbarState(shell, stateText) {
    const meta = shell.querySelector('.mel-event-studio-topbar__meta');
    if (!meta) {
      return;
    }
    let stateEl = meta.querySelector('[data-mel-publish-state]');
    if (stateText) {
      if (!stateEl) {
        stateEl = document.createElement('span');
        stateEl.className = 'mel-event-studio-topbar__state';
        stateEl.setAttribute('data-mel-publish-state', '');
        const badge = meta.querySelector('[data-mel-publish-status]');
        if (badge) {
          badge.insertAdjacentElement('afterend', stateEl);
        }
        else {
          meta.prepend(stateEl);
        }
      }
      stateEl.textContent = stateText;
      return;
    }
    stateEl?.remove();
  }

  function updateTopbar(shell, result) {
    if (!result || !result.topbar) {
      return;
    }
    setText(shell, '[data-mel-publish-status]', result.topbar.status || '');
    syncTopbarState(shell, result.topbar.state || '');
    setText(shell, '[data-mel-publish-last-saved]', result.topbar.lastSaved || '');
    const buttons = shell.querySelectorAll('[data-mel-publish-action], [data-mel-card-publish-action], [data-mel-unpublish-action]');
    if (result.changed !== undefined && result.changed !== null) {
      const changed = String(result.changed);
      buttons.forEach((actionButton) => {
        actionButton.dataset.melNodeChanged = changed;
      });
      studioSettings().nodeChanged = changed;
    }
    if (result.revisionId !== undefined && result.revisionId !== null) {
      const revisionId = String(result.revisionId);
      buttons.forEach((actionButton) => {
        actionButton.dataset.melNodeRevision = revisionId;
      });
      studioSettings().nodeRevisionId = revisionId;
    }
    const button = shell.querySelector('[data-mel-publish-action]');
    if (button) {
      setPublishButtonState(button, result.published ? 'published' : 'idle');
    }
  }

  function applyMobilePriorities(shell) {
    if (!shell) {
      return;
    }
    shell.querySelectorAll('.mel-event-studio-sidebar__item[data-mobile-priority]').forEach((item) => {
      const priority = Number(item.dataset.mobilePriority || 100);
      if (Number.isFinite(priority)) {
        item.style.order = String(priority);
      }
    });
  }

  function renderPublishFeedback(shell, title, messages, restoreUrl) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (!feedback) {
      return;
    }
    const errorPanel = feedback.querySelector('[data-mel-publish-error]');
    const successPanel = feedback.querySelector('[data-mel-publish-success]');
    if (successPanel) {
      successPanel.hidden = true;
    }
    if (errorPanel) {
      errorPanel.hidden = false;
    }
    feedback.classList.remove('is-success');

    const list = feedback.querySelector('[data-mel-publish-feedback-list]');
    const heading = feedback.querySelector('[data-mel-publish-feedback-title]');
    const restore = feedback.querySelector('[data-mel-publish-restore]');
    if (heading) {
      heading.textContent = title;
    }
    if (list) {
      list.textContent = '';
      (messages || []).forEach((message) => {
        const item = document.createElement('li');
        item.textContent = message;
        list.appendChild(item);
      });
    }
    if (restore) {
      if (restoreUrl) {
        restore.href = restoreUrl;
        restore.hidden = false;
      }
      else {
        restore.hidden = true;
        restore.removeAttribute('href');
      }
    }
    feedback.hidden = false;
    if (typeof feedback.focus === 'function') {
      feedback.setAttribute('tabindex', '-1');
      feedback.focus({ preventScroll: true });
    }
  }

  function renderPublishSuccessFeedback(shell, handoff) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (!feedback || !handoff) {
      return;
    }

    const errorPanel = feedback.querySelector('[data-mel-publish-error]');
    const successPanel = feedback.querySelector('[data-mel-publish-success]');
    if (errorPanel) {
      errorPanel.hidden = true;
    }
    if (!successPanel) {
      renderPublishFeedback(shell, handoff.message || Drupal.t('Published successfully'), []);
      return;
    }

    const setText = (selector, value) => {
      const el = successPanel.querySelector(selector);
      if (el && value) {
        el.textContent = value;
      }
    };

    setText('[data-mel-publish-success-title]', handoff.title);
    setText('[data-mel-publish-success-message]', handoff.message);

    const boostCard = successPanel.querySelector('[data-mel-publish-success-boost-card]');
    const boostLink = successPanel.querySelector('[data-mel-publish-success-boost]');
    const viewRow = successPanel.querySelector('[data-mel-publish-success-view-row]');
    const viewLink = successPanel.querySelector('[data-mel-publish-success-view]');
    const socialRow = successPanel.querySelector('[data-mel-publish-success-social]');

    if (boostCard) {
      if (handoff.boost_url) {
        boostCard.hidden = false;
        if (boostLink) {
          boostLink.href = handoff.boost_url;
        }
      }
      else {
        boostCard.hidden = true;
        if (boostLink) {
          boostLink.removeAttribute('href');
        }
      }
    }
    if (viewLink && viewRow) {
      if (handoff.view_url) {
        viewLink.href = handoff.view_url;
        viewRow.hidden = false;
      }
      else {
        viewRow.hidden = true;
        viewLink.removeAttribute('href');
      }
    }
    if (socialRow) {
      socialRow.textContent = '';
      const share = handoff.share || {};
      const shareLinks = [
        ['facebook', Drupal.t('Facebook')],
        ['linkedin', Drupal.t('LinkedIn')],
        ['twitter', Drupal.t('X')],
      ];
      shareLinks.forEach(([key, label]) => {
        if (!share[key]) {
          return;
        }
        const link = document.createElement('a');
        link.className = 'mel-btn mel-btn--secondary';
        link.href = share[key];
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = label;
        socialRow.appendChild(link);
      });
      socialRow.hidden = socialRow.childElementCount === 0;
    }

    successPanel.hidden = false;
    feedback.classList.add('is-success');
    feedback.hidden = false;
    if (typeof feedback.focus === 'function') {
      feedback.setAttribute('tabindex', '-1');
      feedback.focus({ preventScroll: true });
    }
  }

  function hidePublishFeedback(shell) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (feedback) {
      feedback.hidden = true;
      feedback.classList.remove('is-success');
      const errorPanel = feedback.querySelector('[data-mel-publish-error]');
      const successPanel = feedback.querySelector('[data-mel-publish-success]');
      if (errorPanel) {
        errorPanel.hidden = true;
      }
      if (successPanel) {
        successPanel.hidden = true;
      }
    }
  }

  function bindPublishBoostDismiss(context) {
    once('mel-publish-boost-dismiss', '[data-mel-publish-boost-dismiss]', context).forEach((button) => {
      button.addEventListener('click', () => {
        const card = button.closest('[data-mel-publish-success-boost-card], [data-mel-publish-boost-cta]');
        if (card) {
          card.hidden = true;
        }
      });
    });
  }

  Drupal.behaviors.melEventStudioShellAutosave = {
    attach(context) {
      bindPublishBoostDismiss(context);

      once('mel-event-studio-mobile-priority', '[data-mel-studio-shell]', context).forEach((shell) => {
        applyMobilePriorities(shell);
        const handoff = studioSettings().publishHandoff;
        if (handoff) {
          renderPublishSuccessFeedback(shell, handoff);
        }
      });

      once('mel-event-studio-sidebar-toggle', '[data-mel-studio-sidebar-toggle]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        const overlay = shell.querySelector('[data-mel-studio-sidebar-overlay]');
        const sidebar = shell.querySelector('.mel-event-studio-sidebar');
        const closeSidebar = () => {
          shell.classList.remove('is-sidebar-open');
          button.setAttribute('aria-expanded', 'false');
          if (overlay) {
            overlay.hidden = true;
          }
        };
        const openSidebar = () => {
          shell.classList.add('is-sidebar-open');
          button.setAttribute('aria-expanded', 'true');
          if (overlay) {
            overlay.hidden = false;
          }
        };
        closeSidebar();
        button.addEventListener('click', () => {
          if (shell.classList.contains('is-sidebar-open')) {
            closeSidebar();
            return;
          }
          openSidebar();
        });
        overlay?.addEventListener('click', closeSidebar);
        document.addEventListener('click', (event) => {
          if (!shell.classList.contains('is-sidebar-open')) {
            return;
          }
          if (sidebar?.contains(event.target) || button.contains(event.target) || overlay?.contains(event.target)) {
            return;
          }
          closeSidebar();
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && shell.classList.contains('is-sidebar-open')) {
            closeSidebar();
            button.focus();
          }
        });
        shell.querySelectorAll('.mel-event-studio-sidebar__link').forEach((link) => {
          link.addEventListener('click', closeSidebar);
        });
      });

      once('mel-event-studio-publish-form-state', '[data-mel-studio-shell] form', context).forEach((form) => {
        if (!isWritableForm(form)) {
          return;
        }
        setFormPublishState(form, 'clean');
        form.addEventListener('input', () => setFormPublishState(form, 'dirty'), true);
        form.addEventListener('change', () => setFormPublishState(form, 'dirty'), true);
        form.addEventListener('submit', () => setFormPublishState(form, 'clean'));
      });

      once('mel-event-studio-shell-autosave', 'form[data-mel-event-studio-form="1"]', context).forEach((form) => {
        if (!supportsAutosaveForm(form)) {
          return;
        }
        if (form.matches('.mel-event-studio-operational-tickets')) {
          return;
        }
        let timer = null;
        let dirty = false;
        const status = document.getElementById('mel-studio-form-state');
        const delay = Number(studioSettings().autosaveDelay || 12000);
        const autosaveUrl = studioSettings().autosaveUrl;
        const currentSection = studioSettings().currentSection;
        if (!autosaveUrl) {
          return;
        }
        if (studioSettings().draftAvailable) {
          status?.classList.add('has-draft');
        }

        const schedule = () => {
          window.clearTimeout(timer);
          dirty = true;
          setFormPublishState(form, 'dirty');
          setStatus(status, Drupal.t('Unsaved changes'), 'is-unsaved');
          timer = window.setTimeout(async () => {
            const data = new FormData(form);
            data.set('mel_autosave_ts', String(Date.now()));
            if (currentSection) {
              data.set('mel_studio_section', currentSection);
            }
            setFormPublishState(form, 'saving');
            setStatus(status, Drupal.t('Saving...'), 'is-saving');
            try {
              const token = await getCsrfToken();
              const response = await fetch(autosaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token },
                body: data,
              });
              const result = await response.json().catch(() => ({}));
              if (response.status === 409) {
                dirty = true;
                setFormPublishState(form, 'error');
                setStatus(
                  status,
                  result.message || Drupal.t('This section was updated elsewhere. Refresh to continue editing safely.'),
                  'is-error',
                );
                return;
              }
              if (!response.ok || !result.ok) {
                throw new Error(`Autosave failed with ${response.status}`);
              }
              dirty = false;
              setFormPublishState(form, 'clean');
              setStatus(status, Drupal.t('Saved just now'), 'is-saved');
            }
            catch (error) {
              console.error('Event Studio autosave failed.', error);
              setFormPublishState(form, 'error');
              setStatus(status, Drupal.t('Draft could not be saved. Retry by editing again.'), 'is-error');
            }
          }, delay);
        };

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.addEventListener('submit', () => {
          dirty = false;
          setFormPublishState(form, 'clean');
          window.clearTimeout(timer);
        });
        window.addEventListener('beforeunload', (event) => {
          if (!dirty) {
            return;
          }
          event.preventDefault();
          event.returnValue = '';
        });
      });

      once('mel-operational-tickets-donation-sync', 'form.mel-event-studio-operational-tickets', context).forEach((operationalForm) => {
        operationalForm.addEventListener('submit', () => {
          const stack = operationalForm.closest('.mel-event-studio-section__form-stack');
          const bookingForm = stack?.querySelector('form[data-mel-event-studio-form="1"]');
          if (!bookingForm || !bookingModeSupportsDonationSync(bookingForm)) {
            return;
          }
          operationalForm.querySelectorAll('[data-mel-donation-sync]').forEach((element) => element.remove());
          ['donation_amount', 'donation_options', 'donation_label'].forEach((key) => {
            const input = bookingForm.querySelector(`[name="mel[${key}]"]`);
            if (!input) {
              return;
            }
            appendMelDonationHidden(operationalForm, key, input.value);
          });
          const enable = bookingForm.querySelector('[name="mel[enable_donations]"]');
          appendMelDonationHidden(operationalForm, 'enable_donations', enable && enable.checked ? '1' : '0');
        }, true);
      });

      once('mel-event-studio-shell-publish', '[data-mel-publish-action]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          if (button.disabled) {
            return;
          }
          const publishUrl = publishUrlFor(button);
          if (!publishUrl) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish action is unavailable. Refresh and try again.')]);
            setPublishButtonState(button, 'cannot_publish');
            return;
          }
          const metadata = publishMetadata(shell, button);
          if (metadata.dirty) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Save this section before publishing.')]);
            setPublishButtonState(button, 'cannot_publish');
            return;
          }
          setPublishButtonState(button, 'publishing');
          hidePublishFeedback(shell);
          try {
            const token = await getCsrfToken();
            const response = await fetch(publishUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
              },
              body: JSON.stringify(metadata),
            });
            const result = await response.json().catch(() => ({}));
            updateTopbar(shell, result);
            updateReadiness(shell, result.readiness);
            if (!response.ok || !result.ok) {
              const messages = result.messages && result.messages.length
                ? result.messages
                : (result.readiness && result.readiness.errors) || [Drupal.t('Publish could not complete.')];
              renderPublishFeedback(shell, result.message || Drupal.t('Cannot publish yet'), messages, result.restoreUrl);
              setPublishButtonState(button, 'cannot_publish');
              return;
            }
            if (result.handoff) {
              renderPublishSuccessFeedback(shell, result.handoff);
            }
            else {
              renderPublishFeedback(shell, result.message || Drupal.t('Published successfully'), []);
            }
            setPublishButtonState(button, 'published');
            updatePublishPanels(shell, true);
            updateFormMetadata(shell, result);
          }
          catch (error) {
            console.error('Event Studio publish failed.', error);
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish failed. Check your connection and try again.')]);
            setPublishButtonState(button, 'cannot_publish');
          }
        });
      });

      once('mel-event-studio-shell-card-publish', '[data-mel-card-publish-action]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          if (button.disabled) {
            return;
          }
          const publishUrl = publishUrlFor(button);
          if (!publishUrl) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish action is unavailable. Refresh and try again.')]);
            return;
          }
          const metadata = {
            ...publishMetadata(shell, button),
            action: 'publish',
          };
          if (metadata.dirty) {
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Save this section before changing publish state.')]);
            return;
          }
          const originalLabel = button.textContent;
          button.disabled = true;
          button.setAttribute('aria-disabled', 'true');
          button.textContent = Drupal.t('Publishing...');
          hidePublishFeedback(shell);
          try {
            const token = await getCsrfToken();
            const response = await fetch(publishUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
              },
              body: JSON.stringify(metadata),
            });
            const result = await response.json().catch(() => ({}));
            updateTopbar(shell, result);
            updateReadiness(shell, result.readiness);
            if (!response.ok || !result.ok) {
              const messages = result.messages && result.messages.length
                ? result.messages
                : (result.readiness && result.readiness.errors) || [Drupal.t('Publish could not complete.')];
              renderPublishFeedback(shell, result.message || Drupal.t('Cannot publish yet'), messages, result.restoreUrl);
              button.disabled = false;
              button.removeAttribute('aria-disabled');
              button.textContent = originalLabel || Drupal.t('Publish now');
              return;
            }
            if (result.handoff) {
              renderPublishSuccessFeedback(shell, result.handoff);
            }
            else {
              renderPublishFeedback(shell, result.message || Drupal.t('Published successfully'), []);
            }
            updatePublishPanels(shell, true);
            updateFormMetadata(shell, result);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Publish now');
          }
          catch (error) {
            console.error('Event Studio publish failed.', error);
            renderPublishFeedback(shell, Drupal.t('Cannot publish yet'), [Drupal.t('Publish failed. Check your connection and try again.')]);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Publish now');
          }
        });
      });

      once('mel-event-studio-shell-unpublish', '[data-mel-unpublish-action]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          if (button.disabled) {
            return;
          }
          const publishUrl = publishUrlFor(button);
          if (!publishUrl) {
            renderPublishFeedback(shell, Drupal.t('Cannot unpublish yet'), [Drupal.t('Unpublish action is unavailable. Refresh and try again.')]);
            return;
          }
          const metadata = {
            ...publishMetadata(shell, button),
            action: 'unpublish',
          };
          if (metadata.dirty) {
            renderPublishFeedback(shell, Drupal.t('Cannot unpublish yet'), [Drupal.t('Save this section before changing publish state.')]);
            return;
          }
          const originalLabel = button.textContent;
          button.disabled = true;
          button.setAttribute('aria-disabled', 'true');
          button.textContent = Drupal.t('Unpublishing...');
          hidePublishFeedback(shell);
          try {
            const token = await getCsrfToken();
            const response = await fetch(publishUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
              },
              body: JSON.stringify(metadata),
            });
            const result = await response.json().catch(() => ({}));
            updateTopbar(shell, result);
            updateReadiness(shell, result.readiness);
            if (!response.ok || !result.ok) {
              const messages = result.messages && result.messages.length
                ? result.messages
                : [Drupal.t('Unpublish could not complete.')];
              renderPublishFeedback(shell, result.message || Drupal.t('Cannot unpublish yet'), messages, result.restoreUrl);
              button.disabled = false;
              button.removeAttribute('aria-disabled');
              button.textContent = originalLabel || Drupal.t('Unpublish');
              return;
            }
            renderPublishFeedback(shell, result.message || Drupal.t('Unpublished successfully'), []);
            updatePublishPanels(shell, false);
            updateFormMetadata(shell, result);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Unpublish');
          }
          catch (error) {
            console.error('Event Studio unpublish failed.', error);
            renderPublishFeedback(shell, Drupal.t('Cannot unpublish yet'), [Drupal.t('Unpublish failed. Check your connection and try again.')]);
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.textContent = originalLabel || Drupal.t('Unpublish');
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);