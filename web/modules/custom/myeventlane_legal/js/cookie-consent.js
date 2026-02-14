/**
 * @file
 * Cookie consent banner and preferences.
 *
 * Stores consent in mel_consent cookie. Gates analytics/marketing scripts
 * until user opts in. No analytics scripts are currently detected; this
 * framework is ready for when they are added.
 */
(function (Drupal, drupalSettings, once) {
  const COOKIE_NAME = 'mel_consent';
  const BANNER_ID = 'mel-cookie-banner';
  const BANNER_DISMISSED = 'mel-cookie-banner-dismissed';

  function getConsent() {
    const match = document.cookie.match(new RegExp('(^| )' + COOKIE_NAME + '=([^;]+)'));
    if (match) {
      try {
        return JSON.parse(decodeURIComponent(match[2]));
      } catch (e) {
        return null;
      }
    }
    return null;
  }

  function setConsent(preferences) {
    const payload = {
      necessary: true,
      analytics: preferences.analytics || false,
      marketing: preferences.marketing || false,
      timestamp: preferences.timestamp || Math.floor(Date.now() / 1000)
    };
    const url = (drupalSettings.myeventlane_legal && drupalSettings.myeventlane_legal.preferences_url) || '/cookies/preferences';
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      if (data.success && data.preferences) {
        window.dispatchEvent(new CustomEvent('melConsentUpdated', { detail: data.preferences }));
      }
    });
  }

  function showBanner() {
    if (document.getElementById(BANNER_ID)) return;
    const banner = document.createElement('div');
    banner.id = BANNER_ID;
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', Drupal.t('Cookie consent'));
    banner.className = 'mel-cookie-banner';
    banner.innerHTML = 
      '<div class="mel-cookie-banner__content">' +
      '  <p class="mel-cookie-banner__text">' + Drupal.t('We use cookies. You can choose which categories to allow.') + '</p>' +
      '  <div class="mel-cookie-banner__actions">' +
      '    <button type="button" class="mel-btn mel-btn--secondary mel-consent-necessary-only" data-consent="necessary">' + Drupal.t('Necessary only') + '</button>' +
      '    <button type="button" class="mel-btn mel-btn--primary mel-consent-allow-all" data-consent="all">' + Drupal.t('Allow all') + '</button>' +
      '    <a href="/cookies" class="mel-btn mel-btn--link mel-consent-preferences">' + Drupal.t('Preferences') + '</a>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(banner);

    banner.querySelector('.mel-consent-necessary-only').addEventListener('click', function () {
      setConsent({ necessary: true, analytics: false, marketing: false });
      banner.remove();
      sessionStorage.setItem(BANNER_DISMISSED, '1');
    });
    banner.querySelector('.mel-consent-allow-all').addEventListener('click', function () {
      setConsent({ necessary: true, analytics: true, marketing: true });
      banner.remove();
      sessionStorage.setItem(BANNER_DISMISSED, '1');
    });
  }

  function initBanner() {
    // When Klaro (drupal/klaro) is the consent manager, do not show our banner.
    if (drupalSettings.klaro) return;
    const consent = getConsent();
    if (consent) return;
    if (sessionStorage.getItem(BANNER_DISMISSED)) return;
    showBanner();
  }

  function initPreferencesPage() {
    const form = document.getElementById('mel-cookie-preferences-form');
    if (!form) return;
    // When Klaro is the consent manager, hide the legacy form (preferences managed via Klaro).
    if (drupalSettings.klaro) {
      form.closest('.mel-cookie-preferences')?.classList.add('mel-cookie-preferences--klaro-only');
      return;
    }

    const consent = getConsent();
    const analyticsCheck = form.querySelector('#mel-consent-analytics');
    const marketingCheck = form.querySelector('#mel-consent-marketing');
    if (consent) {
      analyticsCheck.checked = consent.analytics || false;
      marketingCheck.checked = consent.marketing || false;
    }

    form.querySelector('#mel-save-cookie-preferences').addEventListener('click', function () {
      setConsent({
        necessary: true,
        analytics: analyticsCheck.checked,
        marketing: marketingCheck.checked
      });
      if (typeof Drupal !== 'undefined' && Drupal.Message) {
        const msgs = document.querySelector('.messages');
        if (msgs) msgs.innerHTML = '';
        Drupal.Message().add(Drupal.t('Preferences saved.'), { type: 'status' });
      }
    });
  }

  Drupal.behaviors.myeventlaneLegalCookieConsent = {
    attach: function (context, settings) {
      once('mel-cookie-consent', 'body', context).forEach(function () {
        initBanner();
        initPreferencesPage();
      });
    }
  };
})(Drupal, drupalSettings, once);
