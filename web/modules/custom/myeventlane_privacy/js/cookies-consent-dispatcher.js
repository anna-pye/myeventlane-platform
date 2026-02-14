/**
 * @file
 * Klaro consent dispatcher.
 *
 * Integrates with Klaro! Consent Manager (drupal/klaro).
 * Uses klaro.getManager(config).watch() for consent changes and
 * getConsent(name) for current state.
 *
 * Service IDs must match Klaro service entity IDs:
 * gtm, gtag, meta_pixel, hotjar, recaptcha.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  // ---- Helpers ----
  const once = (fn) => {
    let ran = false;
    return (...args) => {
      if (ran) return;
      ran = true;
      return fn(...args);
    };
  };

  const loadScriptOnce = (() => {
    const loaded = new Set();
    return (src, attrs = {}) => {
      if (!src || loaded.has(src)) return;
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      Object.entries(attrs).forEach(([k, v]) => s.setAttribute(k, v));
      document.head.appendChild(s);
      loaded.add(src);
    };
  })();

  const injectInlineOnce = (() => {
    const injected = new Set();
    return (key, jsText) => {
      if (!key || injected.has(key)) return;
      const s = document.createElement('script');
      s.type = 'text/javascript';
      s.text = jsText;
      document.head.appendChild(s);
      injected.add(key);
    };
  })();

  // ---- Consent-driven feature toggles ----
  const features = {
    gtm: {
      enable: once(() => {
        const gtmId = drupalSettings?.myeventlane?.gtmId;
        if (!gtmId) return;
        injectInlineOnce('gtm-init', `
          (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
          new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
          j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
          'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
          })(window,document,'script','dataLayer','${gtmId}');
        `);
      }),
      disable: () => {},
    },

    gtag: {
      enable: once(() => {
        const gaId = drupalSettings?.myeventlane?.ga4Id;
        if (!gaId) return;

        loadScriptOnce(`https://www.googletagmanager.com/gtag/js?id=${gaId}`);
        injectInlineOnce('gtag-init', `
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
          gtag('config', '${gaId}', { anonymize_ip: true });
        `);
      }),
      disable: () => {},
    },

    meta_pixel: {
      enable: once(() => {
        const pixelId = drupalSettings?.myeventlane?.metaPixelId;
        if (!pixelId) return;

        injectInlineOnce('meta-pixel-init', `
          !function(f,b,e,v,n,t,s)
          {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
          n.callMethod.apply(n,arguments):n.queue.push(arguments)};
          if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
          n.queue=[];t=b.createElement(e);t.async=!0;
          t.src=v;s=b.getElementsByTagName(e)[0];
          s.parentNode.insertBefore(t,s)}(window, document,'script',
          'https://connect.facebook.net/en_US/fbevents.js');
          fbq('init','${pixelId}');
          fbq('track','PageView');
        `);
      }),
      disable: () => {},
    },

    hotjar: {
      enable: once(() => {
        const hjid = drupalSettings?.myeventlane?.hotjarId;
        if (!hjid) return;

        injectInlineOnce('hotjar-init', `
          (function(h,o,t,j,a,r){
              h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};
              h._hjSettings={hjid:${hjid},hjsv:6};
              a=o.getElementsByTagName('head')[0];
              r=o.createElement('script');r.async=1;
              r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;
              a.appendChild(r);
          })(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');
        `);
      }),
      disable: () => {},
    },

    recaptcha: {
      enable: once(() => {
        const siteKey = drupalSettings?.myeventlane?.recaptchaSiteKey;
        if (!siteKey) return;
        loadScriptOnce(`https://www.google.com/recaptcha/api.js?render=${siteKey}`);
      }),
      disable: () => {},
    },
  };

  const applyConsent = (consentMap) => {
    const map = consentMap && typeof consentMap === 'object' ? consentMap : {};
    Object.keys(features).forEach((id) => {
      const allowed = map[id] === true;
      if (allowed) {
        features[id].enable();
      } else {
        features[id].disable();
      }
    });
  };

  // ---- Drupal behavior: integrate with Klaro ----
  Drupal.behaviors.myeventlaneConsentDispatcher = {
    attach: function attach() {
      if (window.__melConsentDispatcherBound) return;
      window.__melConsentDispatcherBound = true;

      // Klaro may not be loaded on all pages (disabled URIs, etc.).
      if (typeof window.klaro === 'undefined') {
        return;
      }

      // Klaro config: Drupal module may expose as klaro.config or root klaro.
      const config = drupalSettings?.klaro?.config ?? drupalSettings?.klaro ?? window.klaroConfig;
      if (!config) {
        return;
      }

      const manager = window.klaro.getManager(config);
      if (!manager) {
        return;
      }

      // Apply current consent state immediately.
      const initialConsents = {};
      Object.keys(features).forEach((id) => {
        initialConsents[id] = manager.getConsent(id) === true;
      });
      applyConsent(initialConsents);

      if (drupalSettings?.myeventlane?.environment === 'dev') {
        console.log('[MyEventLane Privacy] Initial consent:', initialConsents);
      }

      // React to consent changes.
      manager.watch({
        update: function (obj, name, data) {
          if (name === 'consents' && data) {
            if (drupalSettings?.myeventlane?.environment === 'dev') {
              console.log('[MyEventLane Privacy] Consent update:', data);
            }
            applyConsent(data);
          }
        },
      });
    },
  };

})(Drupal, drupalSettings);
