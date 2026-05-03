(function (Drupal, once) {
  'use strict';

  const IDENTITY_STORAGE_KEY = 'myeventlane.checkoutIdentity.v1';

  Drupal.behaviors.melCheckout = {
    attach(context) {
      attachIdentityMemory(context);
      attachContactCollapse(context);

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
