(function (Drupal, once) {
  'use strict';

  const IDENTITY_STORAGE_KEY = 'myeventlane.checkoutIdentity.v1';

  Drupal.behaviors.melCheckout = {
    attach(context) {
      attachIdentityMemory(context);
      attachContactCollapse(context);
      attachSummaryDisclosure(context);
      attachGuidedCheckout(context);
      attachAttendeeCards(context);
      attachAttendeeUtilities(context);
      attachPromoDisclosure(context);
      attachSavedCardDisclosure(context);

      const forms = once(
        'mel-checkout-error-scroll',
        '.mel-checkout-shell form, form.mel-checkout-single-page',
        context
      );

      forms.forEach((form) => {
        window.requestAnimationFrame(() => {
          const errorTarget = findFirstError(form);

          if (!errorTarget) {
            return;
          }

          showErrorSummary(form);
          highlightErrorSection(errorTarget);

          errorTarget.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'center',
          });

          const focusTarget = findFocusableError(errorTarget);
          if (focusTarget) {
            focusTarget.focus({ preventScroll: true });
          }
        });
      });
    },
  };

  function attachSummaryDisclosure(context) {
    const summaries = once(
      'mel-checkout-summary-disclosure',
      '[data-mel-checkout-summary]',
      context
    );

    summaries.forEach((summary) => {
      const mobile = window.matchMedia('(max-width: 768px)');
      const sync = () => {
        if (!mobile.matches) {
          summary.open = true;
          return;
        }

        if (!hasErrors(summary)) {
          summary.open = false;
        }
      };

      if (typeof mobile.addEventListener === 'function') {
        mobile.addEventListener('change', sync);
      }
      sync();
    });
  }

  function attachGuidedCheckout(context) {
    const checkouts = once(
      'mel-checkout-guided-sections',
      '.mel-checkout--structured',
      context
    );

    checkouts.forEach((checkout) => {
      const form = checkout.closest('form') || document.querySelector('.mel-checkout-shell form');
      const sections = getGuidedSections(checkout);

      if (!form || !sections.details || !sections.payment) {
        return;
      }

      let initialised = false;
      let frame = null;
      const update = () => {
        if (frame) {
          window.cancelAnimationFrame(frame);
        }
        frame = window.requestAnimationFrame(() => {
          updateGuidedSections(checkout, sections, initialised);
          syncStickyCta(checkout);
          initialised = true;
        });
      };

      update();
      form.addEventListener('input', update);
      form.addEventListener('change', update);
    });
  }

  function attachAttendeeCards(context) {
    const panes = once(
      'mel-checkout-attendee-cards',
      '.mel-attendee-details-groups',
      context
    );

    panes.forEach((pane) => {
      const cards = Array.from(pane.querySelectorAll('details.mel-attendee-card'));
      if (!cards.length) {
        return;
      }

      let firstUpdate = true;
      const updateCards = (sourceCard = null, advance = false) => {
        const firstIncomplete = cards.find((card) => !isSectionComplete(card));

        cards.forEach((card) => {
          const complete = isSectionComplete(card);
          const activeInCard = card.contains(document.activeElement);
          card.classList.toggle('is-complete', complete);
          card.classList.toggle('has-errors', hasErrors(card));

          if (hasErrors(card)) {
            card.open = true;
            return;
          }

          if (firstUpdate && card !== firstIncomplete) {
            card.open = false;
            return;
          }

          if (complete && card !== firstIncomplete && !activeInCard) {
            card.open = false;
          }
        });

        if (firstIncomplete) {
          firstIncomplete.open = true;
        }

        if (advance && sourceCard && isSectionComplete(sourceCard) && !sourceCard.contains(document.activeElement)) {
          const nextCard = cards.find((card) => !isSectionComplete(card));
          if (nextCard && nextCard !== sourceCard) {
            nextCard.open = true;
            scrollToSection(nextCard);
          }
        }

        firstUpdate = false;
      };

      updateCards();
      cards.forEach((card) => {
        card.addEventListener('input', () => updateCards(card, false));
        card.addEventListener('change', () => updateCards(card, true));
      });
    });
  }

  function attachAttendeeUtilities(context) {
    const cards = once(
      'mel-checkout-attendee-utilities',
      'details.mel-attendee-card',
      context
    );

    cards.forEach((card) => {
      const copyButton = card.querySelector('.mel-attendee-copy__button');
      const copyStatus = card.querySelector('.mel-attendee-copy__status');

      if (copyButton) {
        copyButton.addEventListener('click', () => {
          const checkout = card.closest('.mel-checkout--structured');
          const buyerFields = checkout ? getMappedFields(checkout, 'data-mel-buyer-field') : {};
          const attendeeFields = getMappedFields(card, 'data-mel-attendee-field');
          const requiredKeys = ['first-name', 'last-name', 'email'];
          const firstMissing = requiredKeys.find((key) => !buyerFields[key] || !buyerFields[key].value.trim());

          if (firstMissing) {
            if (copyStatus) {
              copyStatus.textContent = Drupal.t('Enter the buyer details first.');
            }
            buyerFields[firstMissing]?.focus();
            return;
          }

          Object.keys(attendeeFields).forEach((key) => {
            const source = buyerFields[key];
            const destination = attendeeFields[key];
            if (!source || !destination || !source.value.trim()) {
              return;
            }
            destination.value = source.value;
            destination.dispatchEvent(new Event('input', { bubbles: true }));
          });

          revealOptionalPhone(card);
          if (copyStatus) {
            copyStatus.textContent = Drupal.t('Buyer details copied to this ticket.');
          }
        });
      }

      const phoneWrapper = card.querySelector('[data-mel-optional-phone]');
      const phoneInput = phoneWrapper?.querySelector('input[type="tel"]');
      if (!phoneWrapper || !phoneInput || phoneInput.value.trim() || hasErrors(phoneWrapper)) {
        return;
      }

      phoneWrapper.hidden = true;
      const revealButton = document.createElement('button');
      revealButton.type = 'button';
      revealButton.className = 'mel-attendee-phone-toggle';
      revealButton.textContent = Drupal.t('Add phone number');
      revealButton.addEventListener('click', () => {
        phoneWrapper.hidden = false;
        revealButton.hidden = true;
        phoneInput.focus();
      });
      phoneWrapper.parentNode.insertBefore(revealButton, phoneWrapper);
    });
  }

  function getMappedFields(scope, attribute) {
    return Array.from(scope.querySelectorAll(`[${attribute}]`)).reduce((fields, input) => {
      fields[input.getAttribute(attribute)] = input;
      return fields;
    }, {});
  }

  function revealOptionalPhone(card) {
    const phoneWrapper = card.querySelector('[data-mel-optional-phone]');
    const phoneInput = phoneWrapper?.querySelector('input[type="tel"]');
    if (!phoneWrapper || !phoneInput || !phoneInput.value.trim()) {
      return;
    }
    phoneWrapper.hidden = false;
    const toggle = card.querySelector('.mel-attendee-phone-toggle');
    if (toggle) {
      toggle.hidden = true;
    }
  }

  function attachPromoDisclosure(context) {
    const promotions = once(
      'mel-checkout-promo-disclosure',
      '.mel-checkout__summary .checkout-pane-coupon-redemption, .mel-checkout__summary .commerce-checkout-pane-coupon-redemption, .mel-checkout__summary .coupon-redemption-form, .mel-checkout__summary .commerce-coupon-redemption-form',
      context
    );

    promotions.forEach((promotion) => {
      if (promotion.closest('.mel-checkout-promo')) {
        return;
      }

      const disclosure = document.createElement('details');
      disclosure.className = 'mel-checkout-promo';
      disclosure.open = hasErrors(promotion) || Boolean(promotion.querySelector('input:not([type="hidden"])')?.value.trim());

      const summary = document.createElement('summary');
      summary.textContent = Drupal.t('Have a promo code?');
      disclosure.appendChild(summary);

      promotion.parentNode.insertBefore(disclosure, promotion);
      disclosure.appendChild(promotion);
    });
  }

  function attachSavedCardDisclosure(context) {
    const toggles = once(
      'mel-checkout-saved-card-disclosure',
      '[data-mel-saved-card-toggle]',
      context
    );

    toggles.forEach((toggle) => {
      const paymentSection = toggle.closest('.mel-checkout-section--payment');
      const list = paymentSection?.querySelector('[data-mel-saved-card-list]');
      if (!list) {
        toggle.hidden = true;
        return;
      }

      const olderChoices = Array.from(
        list.querySelectorAll('[data-mel-saved-card-older]')
      );
      const rows = olderChoices
        .map((choice) => choice.closest('.form-type-radio, .js-form-item, .form-item'))
        .filter(Boolean);
      if (!rows.length) {
        toggle.hidden = true;
        return;
      }

      const setExpanded = (expanded) => {
        rows.forEach((row) => {
          row.hidden = !expanded;
        });
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.textContent = expanded
          ? toggle.dataset.hideLabel
          : toggle.dataset.showLabel;
      };

      toggle.addEventListener('click', () => {
        setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
      });
      setExpanded(false);
    });
  }

  function getGuidedSections(checkout) {
    return {
      details: checkout.querySelector('.mel-checkout-section[data-step="details"]'),
      attendees: checkout.querySelector('.mel-checkout-section[data-step="attendees"]'),
      payment: checkout.querySelector('.mel-checkout-section[data-step="payment"]'),
      extras: checkout.querySelector('.mel-checkout-section[data-step="extras"]'),
      legal: checkout.querySelector('.mel-checkout-section[data-step="legal"]'),
    };
  }

  function updateGuidedSections(checkout, sections, allowScroll) {
    const detailsComplete = isSectionComplete(sections.details);
    const attendeesComplete = sections.attendees ? isSectionComplete(sections.attendees) : true;

    revealSection(sections.details, true);
    revealSection(sections.attendees, detailsComplete, allowScroll);
    revealSection(sections.payment, detailsComplete && attendeesComplete, allowScroll);
    revealSection(sections.extras, detailsComplete && attendeesComplete, false);
    revealSection(sections.legal, detailsComplete && attendeesComplete, false);

    checkout.classList.toggle('is-ready-for-payment', detailsComplete && attendeesComplete);
  }

  function revealSection(section, reveal, allowScroll = false) {
    if (!section) {
      return;
    }

    const wasRevealed = section.classList.contains('is-revealed');
    section.classList.toggle('is-revealed', reveal);
    section.classList.toggle('is-locked', !reveal);
    section.setAttribute('aria-hidden', reveal ? 'false' : 'true');

    if ('inert' in section) {
      section.inert = !reveal;
    }

    if (reveal && allowScroll && !wasRevealed) {
      scrollToSection(section);
    }
  }

  function isSectionComplete(section) {
    if (!section || hasErrors(section)) {
      return false;
    }

    const controls = getRelevantControls(section);
    return controls.every((control) => {
      if (!control.required && !isRequiredGroupControl(control)) {
        return true;
      }

      if (typeof control.checkValidity === 'function') {
        return control.checkValidity();
      }

      return Boolean(control.value && control.value.trim());
    });
  }

  function getRelevantControls(section) {
    return Array.from(section.querySelectorAll('input, select, textarea')).filter((control) => {
      if (control.disabled || control.type === 'hidden' || control.type === 'button' || control.type === 'submit') {
        return false;
      }

      return !control.closest('[hidden], .visually-hidden, .mel-fast-checkout');
    });
  }

  function isRequiredGroupControl(control) {
    if (!control.required || (control.type !== 'checkbox' && control.type !== 'radio')) {
      return false;
    }

    const namedControls = control.name && control.form
      ? control.form.elements[control.name]
      : null;
    const group = namedControls && typeof namedControls.length === 'number'
      ? Array.from(namedControls)
      : [control];

    return group.some((item) => item.checked);
  }

  function showErrorSummary(form) {
    const checkout = form.querySelector('.mel-checkout--structured') || form;
    const summary = checkout.querySelector('.mel-checkout-error-summary');
    if (!summary) {
      return;
    }

    summary.hidden = false;
    summary.classList.add('is-visible');
  }

  function highlightErrorSection(target) {
    const section = target.closest('.mel-checkout-section, .mel-attendee-card');
    if (!section) {
      return;
    }

    section.classList.add('has-errors');
  }

  function scrollToSection(section) {
    section.scrollIntoView({
      behavior: prefersReducedMotion() ? 'auto' : 'smooth',
      block: 'start',
    });
  }

  function syncStickyCta(checkout) {
    const sticky = checkout.querySelector('.mel-checkout-sticky');
    if (!sticky) {
      return;
    }

    const total = sticky.querySelector('.mel-checkout-cta__total-value, .mel-checkout-total-inline__value');
    if (total) {
      sticky.setAttribute('data-total', total.textContent.trim());
    }
  }

  function attachIdentityMemory(context) {
    const panes = once(
      'mel-checkout-identity-memory',
      '.mel-contact-details-pane',
      context
    );

    panes.forEach((pane) => {
      const form = pane.closest('form');
      const fields = getIdentityFields(pane);

      if (!form || !fields.email || !fields.firstName || !fields.lastName) {
        return;
      }

      const stored = readStoredIdentity();
      if (stored) {
        prefillEmptyIdentityFields(fields, stored);
        insertContinueAs(pane, fields, stored);
      }

      const persist = () => saveIdentityFromFields(fields);
      form.addEventListener('submit', persist);
      Object.values(fields).forEach((field) => {
        if (field) {
          field.addEventListener('change', persist);
          field.addEventListener('blur', persist);
        }
      });
    });
  }

  function attachContactCollapse(context) {
    const panes = once(
      'mel-checkout-contact-collapse',
      '.mel-contact-details-pane',
      context
    );

    panes.forEach((pane) => {
      const fields = getIdentityFields(pane);

      if (!hasRequiredIdentity(fields) || hasErrors(pane)) {
        return;
      }

      pane.classList.add('mel-contact-details-pane--collapsed');
      setIdentityFieldVisibility(pane, false);

      insertContinueAs(pane, fields, { firstName: '', lastName: '', email: '' });
      const summary = pane.querySelector('.mel-identity-memory');
      if (summary) {
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'mel-identity-memory__edit';
        edit.textContent = Drupal.t('Edit');
        edit.addEventListener('click', () => {
          pane.classList.remove('mel-contact-details-pane--collapsed');
          setIdentityFieldVisibility(pane, true);
          edit.remove();
          const firstField = fields.email || fields.firstName;
          if (firstField) {
            firstField.focus();
          }
        });
        summary.appendChild(edit);
      }
    });
  }

  function getIdentityFields(scope) {
    return {
      email: scope.querySelector('.mel-buyer-email'),
      firstName: scope.querySelector('.mel-buyer-first-name'),
      lastName: scope.querySelector('.mel-buyer-last-name'),
      mobile: scope.querySelector('.mel-buyer-mobile'),
    };
  }

  function prefillEmptyIdentityFields(fields, stored) {
    if (fields.email && !fields.email.value && stored.email) {
      fields.email.value = stored.email;
    }
    if (fields.firstName && !fields.firstName.value && stored.firstName) {
      fields.firstName.value = stored.firstName;
    }
    if (fields.lastName && !fields.lastName.value && stored.lastName) {
      fields.lastName.value = stored.lastName;
    }
  }

  function insertContinueAs(pane, fields, stored) {
    if (pane.querySelector('.mel-identity-memory')) {
      return;
    }

    const name = formatName(fields, stored);
    if (!name) {
      return;
    }

    const summary = document.createElement('div');
    summary.className = 'mel-identity-memory';
    summary.setAttribute('role', 'status');

    const label = document.createElement('span');
    label.className = 'mel-identity-memory__label';
    label.textContent = Drupal.t('Continue as @name', { '@name': name });
    summary.appendChild(label);

    pane.insertBefore(summary, pane.firstElementChild);
  }

  function setIdentityFieldVisibility(pane, visible) {
    const selectors = [
      '.mel-buyer-email',
      '.mel-buyer-first-name',
      '.mel-buyer-last-name',
      '.mel-buyer-mobile',
    ];

    selectors.forEach((selector) => {
      const field = pane.querySelector(selector);
      const wrapper = field ? field.closest('.form-item') : null;
      if (!wrapper) {
        return;
      }

      if (!visible) {
        wrapper.hidden = true;
        return;
      }

      wrapper.hidden = !field.required && field.value.trim() === '';
    });
  }

  function hasRequiredIdentity(fields) {
    return Boolean(
      fields.email && fields.email.value.trim() &&
        fields.firstName && fields.firstName.value.trim() &&
        fields.lastName && fields.lastName.value.trim()
    );
  }

  function hasErrors(scope) {
    return Boolean(
      scope.querySelector('.form-item--error, [aria-invalid="true"], .messages--error')
    );
  }

  function saveIdentityFromFields(fields) {
    const storage = getLocalStorage();
    if (!storage) {
      return;
    }

    const email = fields.email ? fields.email.value.trim() : '';
    const firstName = fields.firstName ? fields.firstName.value.trim() : '';
    const lastName = fields.lastName ? fields.lastName.value.trim() : '';

    if (!email || !firstName) {
      return;
    }

    storage.setItem(
      IDENTITY_STORAGE_KEY,
      JSON.stringify({
        email,
        firstName,
        lastName,
        updatedAt: new Date().toISOString(),
      })
    );
  }

  function readStoredIdentity() {
    const storage = getLocalStorage();
    if (!storage) {
      return null;
    }

    try {
      const raw = storage.getItem(IDENTITY_STORAGE_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }
      return {
        email: typeof parsed.email === 'string' ? parsed.email : '',
        firstName: typeof parsed.firstName === 'string' ? parsed.firstName : '',
        lastName: typeof parsed.lastName === 'string' ? parsed.lastName : '',
      };
    }
    catch (e) {
      return null;
    }
  }

  function getLocalStorage() {
    try {
      return window.localStorage || null;
    }
    catch (e) {
      return null;
    }
  }

  function formatName(fields, stored) {
    const firstName = fields.firstName && fields.firstName.value
      ? fields.firstName.value.trim()
      : stored.firstName;
    const lastName = fields.lastName && fields.lastName.value
      ? fields.lastName.value.trim()
      : stored.lastName;

    return [firstName, lastName].filter(Boolean).join(' ').trim();
  }

  function findFirstError(form) {
    return form.querySelector(
      '.form-item--error, [aria-invalid="true"], .messages--error'
    );
  }

  function findFocusableError(errorTarget) {
    if (isFocusable(errorTarget)) {
      return errorTarget;
    }

    return errorTarget.querySelector(
      'input:not([type="hidden"]), select, textarea, button, [tabindex]:not([tabindex="-1"])'
    );
  }

  function isFocusable(element) {
    return element.matches(
      'input:not([type="hidden"]), select, textarea, button, [tabindex]:not([tabindex="-1"])'
    );
  }

  function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }
})(Drupal, once);
