/**
 * @file
 * Door Mode check-in: debounced search (GET) + POST check-in with CSRF.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const DEBOUNCE_MS = 300;

  function debounce(fn, ms) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  function postJson(url, body, csrfToken) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
    }).then((r) => r.json());
  }

  function getJson(url) {
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then((r) => r.json());
  }

  Drupal.behaviors.melDoorCheckin = {
    attach(context) {
      const cfg = drupalSettings.melDoorCheckin;
      if (!cfg || !cfg.validateUrl || !cfg.searchUrl || !cfg.csrfToken) {
        return;
      }

      once('mel-door-checkin', '[data-mel-door-checkin]', context).forEach((root) => {
        const input = root.querySelector('#checkin-input');
        const resultEl = root.querySelector('#checkin-result');
        const listEl = root.querySelector('#checkin-candidates');
        if (!input || !resultEl) {
          return;
        }

        const clearCandidates = () => {
          if (listEl) {
            listEl.hidden = true;
            while (listEl.firstChild) {
              listEl.removeChild(listEl.firstChild);
            }
          }
        };

        const melRes = Drupal.melDoorResult || null;
        const render = (resOrText, isErrorLegacy) => {
          if (melRes && typeof melRes.show === 'function' && typeof melRes.fromValidate === 'function') {
            if (resOrText && typeof resOrText === 'object') {
              melRes.fromValidate(resultEl, resOrText);
              return;
            }
            const text = String(resOrText || '').trim();
            if (!text) {
              melRes.hide(resultEl);
              return;
            }
            if (isErrorLegacy) {
              melRes.show(resultEl, 'error', { title: text, meta: '', detail: '' });
            }
            else {
              melRes.show(resultEl, 'success', { title: text, meta: '', detail: '' });
            }
            return;
          }
          const text = typeof resOrText === 'string' ? resOrText : JSON.stringify(resOrText || '');
          resultEl.textContent = text;
          resultEl.classList.toggle('mel-checkin__result--error', Boolean(isErrorLegacy));
        };

        function renderCandidates(candidates) {
          if (!listEl) {
            return;
          }
          clearCandidates();

          candidates.forEach((c) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'mel-door-candidate-card';
            row.setAttribute('role', 'option');
            const name = document.createElement('span');
            name.className = 'mel-door-candidate-card__name';
            name.textContent = String(c.name || '');
            row.appendChild(name);
            const emailLine = document.createElement('span');
            emailLine.className = 'mel-door-candidate-card__email';
            emailLine.textContent = String(c.email || '');
            row.appendChild(emailLine);
            row.addEventListener('click', () => {
              if (!melRes) {
                render(Drupal.t('Checking in…'));
              }
              else {
                melRes.show(resultEl, 'loading', {
                  title: Drupal.t('Checking in…'),
                  meta: Drupal.t('Please wait.'),
                  detail: '',
                });
              }

              postJson(cfg.validateUrl, { paragraph_id: c.paragraph_id }, cfg.csrfToken)
                .then((res) => {
                  if (melRes && typeof melRes.fromValidate === 'function') {
                    melRes.fromValidate(resultEl, res);
                  }
                  else if (res.status === 'success') {
                    render(res.message || Drupal.t('Checked in'), false);
                  }
                  else if (res.status === 'duplicate') {
                    render(res.message || Drupal.t('Already checked in'), false);
                  }
                  else {
                    render(res.message || Drupal.t('Could not check in'), true);
                  }
                  clearCandidates();
                  input.value = '';
                })
                .catch(() => render(Drupal.t('Check-in failed.'), true));
            });

            listEl.appendChild(row);
          });
          listEl.hidden = false;
        }

        const runSearch = debounce((q) => {
          if (!q || q.length < 2) {
            clearCandidates();
            return;
          }
          const url = `${cfg.searchUrl}?q=${encodeURIComponent(q)}`;
          getJson(url)
            .then((data) => {
              if (data.status !== 'ok' || !Array.isArray(data.candidates)) {
                return;
              }
              if (data.candidates.length === 0) {
                clearCandidates();
                render(Drupal.t('No matches.'), true);
                return;
              }
              if (melRes && typeof melRes.hide === 'function') {
                melRes.hide(resultEl);
              }
              render('', false);
              renderCandidates(data.candidates);
            })
            .catch(() => {
              render(Drupal.t('Search failed.'), true);
            });
        }, DEBOUNCE_MS);

        input.addEventListener('input', (e) => {
          const v = e.target.value.trim();
          if (v.length < 2) {
            clearCandidates();
          }
          if (v.length >= 2 && v.length < 32) {
            runSearch(v);
          }
        });

        input.addEventListener('change', (e) => {
          const v = e.target.value.trim();
          if (!v) {
            return;
          }
          if (v.length >= 32) {
            if (melRes) {
              melRes.show(resultEl, 'loading', {
                title: Drupal.t('Checking in…'),
                meta: Drupal.t('Validating ticket code.'),
                detail: '',
              });
            }
            else {
              render(Drupal.t('Checking in…'));
            }

            postJson(cfg.validateUrl, { code: v }, cfg.csrfToken)
              .then((res) => {
                if (res.status === 'multiple' && Array.isArray(res.candidates)) {
                  if (melRes) {
                    melRes.show(resultEl, 'warning', {
                      title: Drupal.t('Multiple matches'),
                      meta: Drupal.t('Tap the attendee below to finish check-in.'),
                      detail: '',
                    });
                  }
                  else {
                    render(Drupal.t('Multiple matches — pick one.'));
                  }
                  renderCandidates(res.candidates);
                  return;
                }
                if (melRes && typeof melRes.fromValidate === 'function') {
                  melRes.fromValidate(resultEl, res);
                  if (res.status === 'success') {
                    clearCandidates();
                    input.value = '';
                  }
                  return;
                }
                if (res.status === 'success') {
                  render(res.message || Drupal.t('Checked in'), false);
                  clearCandidates();
                  input.value = '';
                }
                else if (res.status === 'duplicate') {
                  render(res.message || Drupal.t('Already checked in'), false);
                }
                else {
                  render(res.message || Drupal.t('Could not check in'), true);
                }
              })
              .catch(() => render(Drupal.t('Check-in failed.'), true));
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
