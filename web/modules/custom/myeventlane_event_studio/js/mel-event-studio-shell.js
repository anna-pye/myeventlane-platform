(function (Drupal, drupalSettings, once) {
  'use strict';

  const tokenPromise = fetch('/session/token', { credentials: 'same-origin' }).then((response) => response.text());
  const stateClasses = ['is-unsaved', 'is-saving', 'is-saved', 'is-error', 'has-draft'];

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

      once('mel-event-studio-shell-autosave', 'form[data-mel-event-studio-form="1"]', context).forEach((form) => {
        if (form.matches('.mel-event-studio-operational-tickets')) {
          return;
        }
        let timer = null;
        let dirty = false;
        const status = document.getElementById('mel-studio-form-state');
        const delay = Number(drupalSettings.myeventlaneEventStudio?.autosaveDelay || 12000);
        const autosaveUrl = drupalSettings.myeventlaneEventStudio?.autosaveUrl;
        if (!autosaveUrl) {
          return;
        }
        if (drupalSettings.myeventlaneEventStudio?.draftAvailable) {
          status?.classList.add('has-draft');
        }

        const schedule = () => {
          window.clearTimeout(timer);
          dirty = true;
          setStatus(status, Drupal.t('Unsaved changes'), 'is-unsaved');
          timer = window.setTimeout(async () => {
            const data = new FormData(form);
            data.set('mel_autosave_ts', String(Date.now()));
            setStatus(status, Drupal.t('Saving...'), 'is-saving');
            try {
              const token = await tokenPromise;
              const response = await fetch(autosaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token },
                body: data,
              });
              const result = await response.json().catch(() => ({}));
              if (response.status === 409) {
                dirty = true;
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
              setStatus(status, Drupal.t('Saved just now'), 'is-saved');
            }
            catch (error) {
              setStatus(status, Drupal.t('Draft could not be saved. Retry by editing again.'), 'is-error');
            }
          }, delay);
        };

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.addEventListener('submit', () => {
          dirty = false;
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
    },
  };
})(Drupal, drupalSettings, once);
