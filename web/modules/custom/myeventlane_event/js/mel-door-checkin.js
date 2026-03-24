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
            listEl.innerHTML = '';
          }
        };

        const showResult = (text, isError) => {
          resultEl.textContent = text;
          resultEl.classList.toggle('mel-checkin__result--error', Boolean(isError));
        };

        function renderCandidates(candidates) {
          if (!listEl) {
            return;
          }
          listEl.innerHTML = '';
          candidates.forEach((c) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mel-checkin__candidate';
            const label = [c.name, c.email].filter(Boolean).join(' — ');
            btn.textContent = label || String(c.paragraph_id);
            btn.addEventListener('click', () => {
              showResult(Drupal.t('Checking in…'), false);
              postJson(cfg.validateUrl, { paragraph_id: c.paragraph_id }, cfg.csrfToken)
                .then((res) => {
                  if (res.status === 'success') {
                    showResult(res.message || Drupal.t('Checked in'), false);
                    clearCandidates();
                    input.value = '';
                  } else if (res.status === 'duplicate') {
                    showResult(res.message || Drupal.t('Already checked in'), false);
                  } else {
                    showResult(res.message || Drupal.t('Could not check in'), true);
                  }
                })
                .catch(() => showResult(Drupal.t('Check-in failed.'), true));
            });
            li.appendChild(btn);
            listEl.appendChild(li);
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
                showResult(Drupal.t('No matches.'), true);
                return;
              }
              showResult('', false);
              renderCandidates(data.candidates);
            })
            .catch(() => {
              showResult(Drupal.t('Search failed.'), true);
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
            showResult(Drupal.t('Checking in…'), false);
            postJson(cfg.validateUrl, { code: v }, cfg.csrfToken)
              .then((res) => {
                if (res.status === 'success') {
                  showResult(res.message || Drupal.t('Checked in'), false);
                  clearCandidates();
                  input.value = '';
                } else if (res.status === 'multiple' && Array.isArray(res.candidates)) {
                  showResult(Drupal.t('Multiple matches — pick one.'), false);
                  renderCandidates(res.candidates);
                } else if (res.status === 'duplicate') {
                  showResult(res.message || Drupal.t('Already checked in'), false);
                } else {
                  showResult(res.message || Drupal.t('Could not check in'), true);
                }
              })
              .catch(() => showResult(Drupal.t('Check-in failed.'), true));
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
