/**
 * @file
 * Homepage Discover (mel_home_events embed_discover) — chip UI drives Views exposed filters (AJAX).
 */
(function (Drupal, once) {
  'use strict';

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  /**
   * Return YYYY-MM-DDTHH:mm:00 in local time (for Views datetime string fields).
   */
  function localDatetime(d) {
    return (
      d.getFullYear() +
      '-' +
      pad2(d.getMonth() + 1) +
      '-' +
      pad2(d.getDate()) +
      'T' +
      pad2(d.getHours()) +
      ':' +
      pad2(d.getMinutes()) +
      ':00'
    );
  }

  /**
   * Start of local calendar day.
   */
  function startOfLocalDay(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0, 0);
  }

  /**
   * Start of the next local calendar day (exclusive upper bound for “today” windows).
   */
  function startOfNextLocalDay(d) {
    const t = startOfLocalDay(d);
    t.setDate(t.getDate() + 1);
    return t;
  }

  /**
   * This weekend: next Saturday 00:00 through Sunday 23:59 (local) — if today is after Sunday, use upcoming weekend.
   */
  function thisWeekendRange() {
    const now = new Date();
    const day = now.getDay();
    const sat = new Date(now);
    let add = (6 - day + 7) % 7;
    if (day === 0) {
      add = 6;
    } else if (add === 0 && day !== 6) {
      add = 7;
    }
    sat.setDate(now.getDate() + add);
    sat.setHours(0, 0, 0, 0);
    const sun = new Date(sat);
    sun.setDate(sat.getDate() + 1);
    sun.setHours(23, 59, 0, 0);
    return { min: localDatetime(sat), max: localDatetime(sun) };
  }

  function clearListField(form, namePrefix) {
    form.querySelectorAll('input, select, textarea').forEach((el) => {
      const n = el.getAttribute('name') || '';
      if (!n.indexOf) {
        return;
      }
      if (n === namePrefix || n.startsWith(namePrefix + '[') || n.startsWith(namePrefix + '[]') || n.indexOf(namePrefix) === 0) {
        if (el.type === 'checkbox' || el.type === 'radio') {
          el.checked = false;
        } else if (el.tagName === 'SELECT') {
          el.selectedIndex = 0;
        } else {
          el.value = '';
        }
      }
    });
  }

  function setStartRange(form, identifier, min, max) {
    const minEl =
      form.querySelector(`[name="${identifier}[min]"]`) ||
      form.querySelector(`[name="${identifier}.min]"]`) ||
      form.querySelector(`input[name*="${identifier}"][name$="[min]"]`) ||
      form.querySelector('input[name^="' + identifier + '"][id*="min"]');
    const maxEl =
      form.querySelector(`[name="${identifier}[max]"]`) ||
      form.querySelector(`[name="${identifier}.max]"]`) ||
      form.querySelector('input[name*="' + identifier + '"][name$="[max]"]') ||
      form.querySelector('input[name^="' + identifier + '"][id*="max"]');
    if (minEl) {
      minEl.value = min;
    }
    if (maxEl) {
      maxEl.value = max;
    }
    if (!minEl && !maxEl) {
      const one = form.querySelector(`[name="${identifier}"]`) || form.querySelector(`[name*="${identifier}"]`);
      if (one) {
        one.value = min || max || '';
      }
    }
  }

  function clearStartRange(form, identifier) {
    setStartRange(form, identifier, '', '');
    form.querySelectorAll(`[name*="${identifier}"]`).forEach((el) => {
      if (el.type === 'date' || el.type === 'datetime-local' || el.type === 'text' || el.tagName === 'INPUT' || el.tagName === 'SELECT') {
        if ((el.getAttribute('name') || '').indexOf(identifier) !== -1) {
          el.value = '';
        }
      }
    });
  }

  /**
   * "Free" chip: numeric filter on product target with operator=empty
   * (exposed as name=type_filter — any value triggers the empty filter; blank = off).
   */
  function setRsvpType(form) {
    const tf = form.querySelector('input[name="type_filter"]');
    if (tf) {
      tf.value = '1';
      return;
    }
  }

  function clearRsvpType(form) {
    const tf = form.querySelector('input[name="type_filter"]');
    if (tf) {
      tf.value = '';
    }
  }

  function setTrendingOn(form) {
    const t =
      form.querySelector('select[name="trending_filter"]') ||
      form.querySelector('input[name="trending_filter"]') ||
      form.querySelector('input[type="checkbox"][name="trending_filter"]') ||
      form.querySelector('input[name="trending_filter[1]"]');
    if (t) {
      if (t.type === 'checkbox' || t.type === 'radio') {
        t.checked = true;
      } else if (t.tagName === 'SELECT') {
        for (let i = 0; i < t.options.length; i++) {
          if (t.options[i].value === '1' || t.options[i].value === 1) {
            t.selectedIndex = i;
            return;
          }
        }
        t.value = '1';
      } else {
        t.value = '1';
      }
    }
  }

  function clearTrending(form) {
    const t =
      form.querySelector('select[name="trending_filter"]') ||
      form.querySelector('input[name="trending_filter"]') ||
      form.querySelector('input[type="checkbox"][name="trending_filter"]');
    if (t) {
      if (t.type === 'checkbox' || t.type === 'radio') {
        t.checked = false;
      } else if (t.tagName === 'SELECT' && t.options.length) {
        t.selectedIndex = 0;
      } else {
        t.value = '';
      }
    }
  }

  function applyDiscoverFilter(discover, filter) {
    const form = discover.querySelector('.views-exposed-form') || document.querySelector('#views-exposed-form-mel-home-events-embed-discover');
    if (!form) {
      return;
    }
    const startId = 'start_filter';
    clearStartRange(form, startId);
    clearRsvpType(form);
    clearTrending(form);

    const todayStart = startOfLocalDay(new Date());
    const nextDay = startOfNextLocalDay(todayStart);

    switch (filter) {
      case 'now': {
        setStartRange(form, startId, localDatetime(todayStart), localDatetime(nextDay));
        break;
      }
      case 'weekend': {
        const r = thisWeekendRange();
        setStartRange(form, startId, r.min, r.max);
        break;
      }
      case 'free': {
        setRsvpType(form);
        break;
      }
      case 'trending': {
        setTrendingOn(form);
        break;
      }
      case 'all':
      default: {
        break;
      }
    }
    const submit = form.querySelector('input.js-form-submit, button.js-form-submit, [type="submit"]');
    if (submit) {
      // Views AJAX binds via jQuery; native .click() bypasses those handlers.
      if (typeof jQuery !== 'undefined') {
        jQuery(submit).trigger('click');
      }
      else {
        submit.click();
      }
    }
  }

  Drupal.behaviors.melFilters = {
    attach(context) {
      once('mel-filters', '.js-mel-home-discover', context).forEach((discover) => {
        const chips = discover.querySelectorAll('.mel-filter-chip[data-mel-filter], .mel-filter-chip[data-filter]');
        if (!chips.length) {
          return;
        }
        function setActive(activeEl) {
          discover.querySelectorAll('.mel-filter-chip').forEach((c) => {
            c.classList.remove('mel-filter-chip--active');
            c.setAttribute('aria-pressed', 'false');
          });
          activeEl.classList.add('mel-filter-chip--active');
          activeEl.setAttribute('aria-pressed', 'true');
        }
        chips.forEach((chip) => {
          chip.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            setActive(chip);
            applyDiscoverFilter(
              discover,
              chip.getAttribute('data-mel-filter') || chip.getAttribute('data-filter') || ''
            );
          });
        });
        const pre = discover.querySelector('.mel-filter-chip.mel-filter-chip--active, .mel-filter-chip[aria-pressed="true"]');
        if (pre) {
          pre.setAttribute('aria-pressed', 'true');
        } else if (chips[0]) {
          setActive(chips[0]);
        }
      });
    }
  };
})(Drupal, once);
