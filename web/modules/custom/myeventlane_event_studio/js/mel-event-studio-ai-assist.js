/**
 * @file
 * Event Studio inline AI assist (workspace-safe, no legacy monolith dependency).
 */
(function (Drupal, once) {
  'use strict';

  function getSettings() {
    return (typeof drupalSettings !== 'undefined' && drupalSettings.melEventStudio) || {};
  }

  function val(form, name) {
    var el = form.querySelector('[name="' + name + '"]');
    if (!el) {
      return '';
    }
    if (el.type === 'checkbox') {
      return el.checked;
    }
    return (el.value || '').trim();
  }

  function valRadio(form, name) {
    var el = form.querySelector('[name="' + name + '"]:checked');
    return el ? el.value : '';
  }

  function melGetCsrfToken() {
    return fetch(Drupal.url('session/token'), {
      credentials: 'same-origin',
    }).then(function (response) {
      if (!response.ok) {
        return Promise.reject(new Error('session_token_http_' + response.status));
      }
      return response.text().then(function (text) {
        return (text || '').trim();
      });
    });
  }

  function categoryForAiPayload(form) {
    var multi = form.querySelector('select[name="mel[field_category][]"]');
    if (multi && multi.selectedOptions && multi.selectedOptions.length > 0) {
      var parts = [];
      for (var i = 0; i < multi.selectedOptions.length; i++) {
        parts.push(multi.selectedOptions[i].text);
      }
      return parts.join(', ');
    }
    return val(form, 'mel[field_category]');
  }

  function tagsForAiPayload(form) {
    var v = val(form, 'mel[field_tags]');
    if (v) {
      return v;
    }
    var parts = [];
    var inputs = form.querySelectorAll('input[name*="[field_tags]"]');
    for (var i = 0; i < inputs.length; i++) {
      var x = (inputs[i].value || '').trim();
      if (x) {
        parts.push(x);
      }
    }
    return parts.join(', ');
  }

  function ticketTypeLabel(tt, str) {
    if (tt === 'paid') {
      return str.typePaid || 'Paid tickets';
    }
    if (tt === 'external') {
      return str.typeExternal || 'External link';
    }
    return str.typeRsvp || 'Free RSVP';
  }

  function getBuilderList(form) {
    return form.querySelector('[data-mel-ticket-card-list="draft"]');
  }

  function paidTicketTierSignalCount(form) {
    var counts = [];
    var list = getBuilderList(form);
    if (list) {
      counts.push(list.querySelectorAll('.mel-tier-row').length);
    }

    var ajaxShell = document.getElementById('mel-ticket-builder-ajax-wrapper');
    if (ajaxShell) {
      counts.push(ajaxShell.querySelectorAll('.js-mel-ticket-card[data-ticket-id]').length);
    }

    var mgrWrap = form.querySelector('[data-mel-ticket-manager-form="1"]');
    if (mgrWrap) {
      var mgrCount = 0;
      mgrWrap.querySelectorAll('.mel-ticket-manager-row').forEach(function (row) {
        var del = row.querySelector('input[type="checkbox"][name*="[more][delete]"]');
        if (del && del.checked) {
          return;
        }
        var activeEl = row.querySelector('input[type="checkbox"][name*="[active]"]');
        if (activeEl && !activeEl.checked) {
          return;
        }
        var titleEl = row.querySelector('input[name*="[title]"]');
        var priceEl = row.querySelector('input[name*="[price]"]');
        var title = titleEl ? String(titleEl.value || '').trim() : '';
        var price = priceEl ? String(priceEl.value || '').trim() : '';
        var priceOk = price !== '' && !isNaN(parseFloat(price)) && parseFloat(price) > 0;
        if (title !== '' && priceOk) {
          mgrCount++;
        }
      });
      counts.push(mgrCount);
    }

    if (counts.length === 0) {
      return 0;
    }
    return Math.max.apply(null, counts);
  }

  function melAiAssistInput(form, assist) {
    var fieldName = assist.getAttribute('data-mel-ai-field-name') || '';
    if (!fieldName) {
      return null;
    }
    return form.querySelector('[name="' + fieldName + '"]');
  }

  function melAiSelectedStyles(assist) {
    var styles = [];
    assist.querySelectorAll('[data-mel-ai-chip][aria-pressed="true"]').forEach(function (chip) {
      var value = chip.getAttribute('data-mel-ai-chip') || '';
      if (value) {
        styles.push(value);
      }
    });
    return styles;
  }

  function melAiPromptType(assist) {
    return assist.getAttribute('data-mel-ai-prompt-type') || '';
  }

  function melAiPromptTypeFromSelectedStyles(assist) {
    var selected = melAiSelectedStyles(assist);
    var current = melAiPromptType(assist);
    if (current && selected.indexOf(current) !== -1) {
      return current;
    }
    return selected.length ? selected[0] : '';
  }

  function melAiLocationContext(form) {
    var raw = val(form, 'mel[field_location]');
    if (raw) {
      try {
        var parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') {
          return [
            parsed.address_line1 || '',
            parsed.locality || '',
            parsed.administrative_area || '',
            parsed.postal_code || '',
          ].filter(Boolean).join(', ');
        }
      }
      catch (e) {}
    }
    return val(form, 'mel[venue_create_name]') || val(form, 'mel[location_search]') || val(form, 'mel[venue_saved]');
  }

  function melAiEventTypeValue(form) {
    return valRadio(form, 'mel[field_event_type]') || val(form, 'mel[field_event_type]') || 'rsvp';
  }

  function melAiEventTypeContext(form) {
    var tt = melAiEventTypeValue(form);
    var str = getSettings().strings || {};
    return ticketTypeLabel(tt, str);
  }

  function melAiTicketModeContext(form) {
    var tt = melAiEventTypeValue(form);
    var mode = melAiEventTypeContext(form);
    var details = [];

    if (tt === 'rsvp') {
      var cap = val(form, 'mel[rsvp_capacity]');
      details.push(cap !== '' ? Drupal.t('Capacity: @capacity', { '@capacity': cap }) : Drupal.t('Unlimited capacity'));
    }
    else if (tt === 'paid') {
      details.push(
        val(form, 'mel[field_product_target]') !== ''
          ? Drupal.t('Ticket product linked')
          : Drupal.t('Ticket product not linked'),
      );
      details.push(Drupal.t('Ticket tiers: @count', { '@count': String(paidTicketTierSignalCount(form)) }));
    }
    else if (tt === 'external') {
      details.push(
        val(form, 'mel[external_url]') !== ''
          ? Drupal.t('Booking URL set')
          : Drupal.t('Booking URL not set'),
      );
    }

    return [mode].concat(details).filter(Boolean).join('; ');
  }

  function melAiAssistContext(form, assist) {
    return {
      title: val(form, 'mel[title]'),
      summary: val(form, 'mel[summary]'),
      body: val(form, 'mel[body]'),
      what_to_expect: val(form, 'mel[field_event_intro]'),
      category: categoryForAiPayload(form),
      tags: tagsForAiPayload(form),
      event_type: melAiEventTypeContext(form),
      ticket_mode: melAiTicketModeContext(form),
      location: melAiLocationContext(form),
      styles: melAiSelectedStyles(assist),
    };
  }

  function melAiEventContext(form) {
    return {
      title: val(form, 'mel[title]'),
      summary: val(form, 'mel[summary]'),
      category: categoryForAiPayload(form),
      tags: tagsForAiPayload(form),
      event_type: melAiEventTypeContext(form),
      ticket_mode: melAiTicketModeContext(form),
      location: melAiLocationContext(form),
    };
  }

  function melAiFormRevisionSignal(form) {
    return [
      val(form, 'mel_studio_revision'),
      val(form, 'mel_studio_changed'),
    ].filter(Boolean).join(':');
  }

  function melAiCharacterMeta(original, suggestion) {
    var originalLength = (original || '').trim().length;
    var suggestionLength = (suggestion || '').trim().length;
    if (!suggestionLength) {
      return '';
    }
    var delta = suggestionLength - originalLength;
    var deltaText = delta === 0
      ? Drupal.t('same length')
      : delta > 0
        ? Drupal.t('@count characters longer', { '@count': String(delta) })
        : Drupal.t('@count characters shorter', { '@count': String(Math.abs(delta)) });
    return Drupal.t('Current text: @current characters. New idea: @suggestion characters, @delta.', {
      '@current': String(originalLength),
      '@suggestion': String(suggestionLength),
      '@delta': deltaText,
    });
  }

  function melAiSentenceCount(text) {
    return (text || '').split(/[.!?]+/).map(function (part) {
      return part.trim();
    }).filter(Boolean).length;
  }

  function melAiAverageSentenceLength(text) {
    var sentences = (text || '').split(/[.!?]+/).map(function (part) {
      return part.trim();
    }).filter(Boolean);
    if (!sentences.length) {
      return 0;
    }
    var words = sentences.reduce(function (total, sentence) {
      return total + sentence.split(/\s+/).filter(Boolean).length;
    }, 0);
    return words / sentences.length;
  }

  function melAiChangeSummaryItems(original, suggestion, promptType) {
    var originalText = (original || '').trim();
    var suggestionText = (suggestion || '').trim();
    var originalLength = originalText.length;
    var suggestionLength = suggestionText.length;
    var changes = [];
    var hasChange = function (code) {
      return changes.some(function (change) {
        return change.code === code;
      });
    };
    var addChange = function (code, label) {
      if (code && label && !hasChange(code) && changes.length < 4) {
        changes.push({
          code: code,
          label: label,
        });
      }
    };

    if (!suggestionLength) {
      return changes;
    }

    if (originalLength > 0 && suggestionLength < originalLength) {
      var percent = Math.round((1 - (suggestionLength / originalLength)) * 100);
      if (percent >= 5) {
        addChange('shortened', Drupal.t('Shortened by @percent%', { '@percent': String(percent) }));
      }
    }
    else if (originalLength > 0 && suggestionLength > originalLength) {
      var longerPercent = Math.round(((suggestionLength / originalLength) - 1) * 100);
      if (longerPercent >= 8) {
        addChange('added_detail', Drupal.t('Added @percent% more detail', { '@percent': String(longerPercent) }));
      }
    }

    if (originalText && melAiAverageSentenceLength(suggestionText) < melAiAverageSentenceLength(originalText)) {
      addChange('simplified_sentence_structure', Drupal.t('Simplified sentence structure'));
    }

    if (promptType === 'more_welcoming' || promptType === 'community_friendly' || promptType === 'friendly' || promptType === 'lgbtqia' || promptType === 'family') {
      addChange('welcoming_tone', Drupal.t('Made tone more welcoming'));
    }
    if (promptType === 'attendee_clarity' || promptType === 'minimal' || promptType === 'shorter' || promptType === 'social_short') {
      addChange('mobile_readability', Drupal.t('Improved readability for mobile'));
    }
    if (promptType === 'workshop' || promptType === 'activist' || /\b(join|book|rsvp|reserve|discover|learn|experience|bring|come)\b/i.test(suggestionText)) {
      addChange('attendee_action', Drupal.t('Clarified the attendee action'));
    }
    if (/\b(accessible|accessibility|step-free|quiet|caption|interpreter|sensory|wheelchair|inclusive|welcoming)\b/i.test(suggestionText)
      && !/\b(accessible|accessibility|step-free|quiet|caption|interpreter|sensory|wheelchair|inclusive|welcoming)\b/i.test(originalText)) {
      addChange('accessibility_wording', Drupal.t('Added accessibility-minded wording'));
    }
    if (!changes.length && originalText && melAiSentenceCount(suggestionText) <= melAiSentenceCount(originalText)) {
      addChange('easier_to_scan', Drupal.t('Made the copy easier to scan'));
    }
    if (!changes.length) {
      addChange('guest_copy_shaping', Drupal.t('Shaped the copy for guests'));
    }

    return changes;
  }

  function melAiChangeSummary(original, suggestion, promptType) {
    return melAiChangeSummaryItems(original, suggestion, promptType).map(function (change) {
      return change.label;
    });
  }

  function melAiRenderChanges(assist, text) {
    var wrapper = assist.querySelector('[data-mel-ai-changes]');
    var list = assist.querySelector('[data-mel-ai-changes-list]');
    if (!wrapper || !list) {
      return;
    }
    list.innerHTML = '';
    var changes = text ? melAiChangeSummary(assist.melAiOriginalValue || '', text, melAiPromptType(assist)) : [];
    changes.forEach(function (change) {
      var item = document.createElement('li');
      item.textContent = change;
      list.appendChild(item);
    });
    wrapper.hidden = changes.length === 0;
  }

  function melAiExcerpt(text) {
    var clean = (text || '').replace(/\s+/g, ' ').trim();
    if (clean.length <= 160) {
      return clean;
    }
    return clean.slice(0, 157).trim() + '...';
  }

  function melAiGuestCueSummary(original, suggestion, promptType) {
    var changes = melAiChangeSummaryItems(original, suggestion, promptType);
    var cues = [];
    var addCue = function (label) {
      if (label && cues.indexOf(label) === -1 && cues.length < 3) {
        cues.push(label);
      }
    };
    changes.forEach(function (change) {
      if (change.code === 'shortened' || change.code === 'mobile_readability') {
        addCue(Drupal.t('Shorter for mobile'));
      }
      else if (change.code === 'welcoming_tone') {
        addCue(Drupal.t('More welcoming'));
      }
      else if (change.code === 'simplified_sentence_structure' || change.code === 'easier_to_scan') {
        addCue(Drupal.t('Easy to scan'));
      }
      else if (change.code === 'attendee_action' || change.code === 'accessibility_wording') {
        addCue(change.label);
      }
    });
    if (!cues.length) {
      addCue(Drupal.t('Easy to scan'));
    }
    return cues;
  }

  function melAiRenderGuestPreview(assist, text) {
    var wrapper = assist.querySelector('[data-mel-ai-guest-preview]');
    var copy = assist.querySelector('[data-mel-ai-guest-copy]');
    var cuesList = assist.querySelector('[data-mel-ai-guest-cues]');
    var original = assist.querySelector('[data-mel-ai-original]');
    var originalExcerpt = assist.querySelector('[data-mel-ai-original-excerpt]');
    if (!wrapper || !copy || !cuesList) {
      return;
    }

    var originalText = assist.melAiOriginalValue || '';
    var hasSuggestion = !!text;
    var displayText = hasSuggestion ? text : originalText;
    var hasPreviewText = !!displayText;
    copy.textContent = hasPreviewText
      ? displayText
      : Drupal.t('A guest-facing preview will appear here once there is text to shape.');
    cuesList.innerHTML = '';
    var cues = hasSuggestion
      ? melAiGuestCueSummary(originalText, text || '', melAiPromptType(assist))
      : [originalText ? Drupal.t('Current guest-facing preview') : Drupal.t('Ready for your first draft')];
    cues.forEach(function (cue) {
      var item = document.createElement('li');
      item.textContent = cue;
      cuesList.appendChild(item);
    });

    if (original && originalExcerpt) {
      var excerpt = melAiExcerpt(originalText);
      originalExcerpt.textContent = excerpt;
      original.hidden = !hasSuggestion || excerpt === '';
      original.open = false;
    }

    wrapper.classList.toggle('is-empty', !hasPreviewText);
    wrapper.hidden = false;
  }

  function melAiSetStatus(assist, message, state) {
    var status = assist.querySelector('[data-mel-ai-status]');
    if (!status) {
      return;
    }
    status.classList.remove('is-loading', 'is-error', 'is-ready', 'is-inserted');
    if (state) {
      status.classList.add(state);
    }
    status.textContent = message || '';
  }

  function melAiSetBusy(assist, busy) {
    assist.dataset.melAiBusy = busy ? '1' : '0';
    assist.classList.toggle('is-busy', !!busy);
    assist.setAttribute('aria-busy', busy ? 'true' : 'false');
    assist.querySelectorAll('[data-mel-ai-generate], [data-mel-ai-regenerate], [data-mel-ai-insert], [data-mel-ai-chip]').forEach(function (button) {
      button.disabled = !!busy;
      if (busy) {
        button.setAttribute('aria-disabled', 'true');
      }
      else {
        button.removeAttribute('aria-disabled');
      }
    });
  }

  function melAiRenderSuggestion(assist, text) {
    var preview = assist.querySelector('[data-mel-ai-preview]');
    var suggestion = assist.querySelector('[data-mel-ai-suggestion]');
    var meta = assist.querySelector('[data-mel-ai-meta]');
    var regenerate = assist.querySelector('[data-mel-ai-regenerate]');
    var insert = assist.querySelector('[data-mel-ai-insert]');
    assist.melAiSuggestion = text || '';
    if (preview) {
      preview.classList.remove('is-refreshing');
      preview.textContent = text || '';
      preview.hidden = !text;
    }
    if (meta) {
      meta.textContent = text ? melAiCharacterMeta(assist.melAiOriginalValue || '', text) : '';
    }
    melAiRenderChanges(assist, text);
    melAiRenderGuestPreview(assist, text);
    if (suggestion) {
      suggestion.hidden = !text;
    }
    if (regenerate) {
      regenerate.hidden = !text;
    }
    if (insert) {
      insert.hidden = !text;
    }
  }

  function melAiRequestSuggestion(form, assist) {
    if (assist.dataset.melAiBusy === '1') {
      return;
    }
    var endpoint = assist.getAttribute('data-mel-ai-endpoint') || '';
    var target = assist.getAttribute('data-mel-ai-target') || '';
    var section = assist.getAttribute('data-mel-ai-section') || '';
    var input = melAiAssistInput(form, assist);
    var currentValue = input ? input.value || '' : '';
    if (!endpoint || !target) {
      melAiSetStatus(assist, Drupal.t('Writing help is unavailable for this field.'), 'is-error');
      return;
    }
    assist.melAiOriginalValue = currentValue;
    assist.melAiRevisionSignal = melAiFormRevisionSignal(form);
    melAiRenderGuestPreview(assist, assist.melAiSuggestion || '');
    var preview = assist.querySelector('[data-mel-ai-preview]');
    if (preview && assist.melAiSuggestion) {
      preview.classList.add('is-refreshing');
    }
    melAiSetBusy(assist, true);
    melAiSetStatus(assist, Drupal.t('Shaping a writing idea...'), 'is-loading');
    melGetCsrfToken()
      .then(function (token) {
        if (!token) {
          return Promise.reject(new Error('empty_csrf_token'));
        }
        return fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
            Accept: 'application/json',
          },
          body: JSON.stringify({
            field: target,
            target: target,
            prompt_type: melAiPromptType(assist),
            current_value: currentValue,
            event_context: melAiEventContext(form),
            section: section,
            context: melAiAssistContext(form, assist),
          }),
        });
      })
      .then(function (response) {
        return response.json().catch(function () {
          return {};
        }).then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !(result.data.success || result.data.ok) || !result.data.text) {
          melAiRenderSuggestion(assist, '');
          melAiSetStatus(assist, Drupal.t('Unable to shape text right now. Please try again.'), 'is-error');
          return;
        }
        melAiRenderSuggestion(assist, result.data.text);
        melAiSetStatus(assist, Drupal.t('Writing idea ready. Review it, then use it if it feels right.'), 'is-ready');
      })
      .catch(function () {
        melAiRenderSuggestion(assist, '');
        melAiSetStatus(assist, Drupal.t('Writing help is temporarily unavailable. Please try again.'), 'is-error');
      })
      .finally(function () {
        melAiSetBusy(assist, false);
      });
  }

  Drupal.behaviors.melEventStudioAiAssist = {
    attach: function (context) {
      once('mel-event-studio-ai-assist', '[data-mel-ai-assist]', context).forEach(function (assist) {
        var form = assist.closest('[data-mel-event-studio-form]');
        if (!form) {
          return;
        }
        var toggle = assist.querySelector('[data-mel-ai-toggle]');
        var panel = assist.querySelector('[data-mel-ai-panel]');
        if (toggle && panel) {
          toggle.addEventListener('click', function () {
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            panel.hidden = expanded;
            if (!expanded) {
              var input = melAiAssistInput(form, assist);
              assist.melAiOriginalValue = input ? input.value || '' : '';
              melAiRenderGuestPreview(assist, assist.melAiSuggestion || '');
              var generate = assist.querySelector('[data-mel-ai-generate]');
              if (generate && typeof generate.focus === 'function') {
                generate.focus({ preventScroll: true });
              }
            }
          });
        }
        assist.querySelectorAll('[data-mel-ai-chip]').forEach(function (chip) {
          chip.addEventListener('click', function () {
            var pressed = chip.getAttribute('aria-pressed') === 'true';
            var value = chip.getAttribute('data-mel-ai-chip') || '';
            chip.setAttribute('aria-pressed', pressed ? 'false' : 'true');
            assist.setAttribute('data-mel-ai-prompt-type', pressed ? melAiPromptTypeFromSelectedStyles(assist) : value);
            if (toggle && panel && panel.hidden) {
              toggle.setAttribute('aria-expanded', 'true');
              panel.hidden = false;
            }
            melAiRequestSuggestion(form, assist);
          });
          chip.addEventListener('keydown', function (e) {
            if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
              return;
            }
            var chips = Array.prototype.slice.call(assist.querySelectorAll('[data-mel-ai-chip]'));
            var currentIndex = chips.indexOf(chip);
            if (currentIndex === -1) {
              return;
            }
            e.preventDefault();
            var nextIndex = currentIndex;
            if (e.key === 'Home') {
              nextIndex = 0;
            }
            else if (e.key === 'End') {
              nextIndex = chips.length - 1;
            }
            else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
              nextIndex = (currentIndex + 1) % chips.length;
            }
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
              nextIndex = (currentIndex - 1 + chips.length) % chips.length;
            }
            if (chips[nextIndex] && typeof chips[nextIndex].focus === 'function') {
              chips[nextIndex].focus({ preventScroll: true });
            }
          });
        });
        assist.querySelectorAll('[data-mel-ai-generate], [data-mel-ai-regenerate]').forEach(function (button) {
          button.addEventListener('click', function () {
            melAiRequestSuggestion(form, assist);
          });
        });
        var insert = assist.querySelector('[data-mel-ai-insert]');
        if (insert) {
          insert.addEventListener('click', function () {
            var input = melAiAssistInput(form, assist);
            var suggestion = assist.melAiSuggestion || '';
            if (!input || !suggestion) {
              melAiSetStatus(assist, Drupal.t('There is no writing idea to use yet.'), 'is-error');
              return;
            }
            if (assist.melAiRevisionSignal && melAiFormRevisionSignal(form) !== assist.melAiRevisionSignal) {
              melAiSetStatus(assist, Drupal.t('This event changed after the idea was shaped. Review the field before using it.'), 'is-error');
              return;
            }
            input.value = suggestion;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            if (typeof input.focus === 'function') {
              input.focus({ preventScroll: true });
            }
            form.dispatchEvent(new CustomEvent('melEventStudio:aiInsert', {
              bubbles: true,
              detail: { form: form },
            }));
            melAiSetStatus(assist, Drupal.t('Used. You can keep editing before saving.'), 'is-inserted');
          });
        }
      });
    },
  };
})(Drupal, once);
