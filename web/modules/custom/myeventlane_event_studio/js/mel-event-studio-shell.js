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
    return Array.from(shell.querySelectorAll('form'));
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
    return {
      section: studioSettings().currentSection || button.dataset.melCurrentSection || '',
      changed: formValue(source, 'mel_studio_changed') || button.dataset.melNodeChanged || studioSettings().nodeChanged || 0,
      revision_id: formValue(source, 'mel_studio_revision') || button.dataset.melNodeRevision || studioSettings().nodeRevisionId || 0,
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
    setText(strip, '[data-mel-readiness-completed-count]', Drupal.t('@count complete', { '@count': (readiness.completed || []).length }));
  }

  function updateTopbar(shell, result) {
    if (!result || !result.topbar) {
      return;
    }
    setText(shell, '[data-mel-publish-status]', result.topbar.status || '');
    setText(shell, '[data-mel-publish-state]', result.topbar.state || '');
    setText(shell, '[data-mel-publish-last-saved]', result.topbar.lastSaved || '');
    const button = shell.querySelector('[data-mel-publish-action]');
    if (button && result.changed !== undefined && result.changed !== null) {
      button.dataset.melNodeChanged = String(result.changed);
    }
    if (button && result.revisionId !== undefined && result.revisionId !== null) {
      button.dataset.melNodeRevision = String(result.revisionId);
    }
    if (button) {
      setPublishButtonState(button, result.published ? 'published' : 'idle');
    }
  }

  function renderPublishFeedback(shell, title, messages, restoreUrl) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (!feedback) {
      return;
    }
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

  function hidePublishFeedback(shell) {
    const feedback = shell.querySelector('[data-mel-publish-feedback]');
    if (feedback) {
      feedback.hidden = true;
    }
  }

  Drupal.behaviors.melEventStudioShellAutosave = {
    attach(context) {
      once('mel-event-studio-sidebar-toggle', '[data-mel-studio-sidebar-toggle]', context).forEach((button) => {
        const shell = button.closest('[data-mel-studio-shell]');
        if (!shell) {
          return;
        }
        button.addEventListener('click', () => {
          const isOpen = shell.classList.toggle('is-sidebar-open');
          button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        shell.addEventListener('click', (event) => {
          if (event.target !== shell || !shell.classList.contains('is-sidebar-open')) {
            return;
          }
          shell.classList.remove('is-sidebar-open');
          button.setAttribute('aria-expanded', 'false');
        });
        shell.querySelectorAll('.mel-event-studio-sidebar__link').forEach((link) => {
          link.addEventListener('click', () => {
            shell.classList.remove('is-sidebar-open');
            button.setAttribute('aria-expanded', 'false');
          });
        });
      });

      once('mel-event-studio-publish-form-state', '[data-mel-studio-shell] form', context).forEach((form) => {
        setFormPublishState(form, 'clean');
        form.addEventListener('input', () => setFormPublishState(form, 'dirty'), true);
        form.addEventListener('change', () => setFormPublishState(form, 'dirty'), true);
        form.addEventListener('submit', () => setFormPublishState(form, 'clean'));
      });

      once('mel-event-studio-shell-autosave', 'form[data-mel-event-studio-form="1"]', context).forEach((form) => {
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
            renderPublishFeedback(shell, result.message || Drupal.t('Published successfully'), []);
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
            renderPublishFeedback(shell, result.message || Drupal.t('Published successfully'), []);
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