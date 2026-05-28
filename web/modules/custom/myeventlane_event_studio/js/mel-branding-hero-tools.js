/**
 * @file
 * Branding hero: focal presets, public framing preview, remove cover.
 */

(function ($, Drupal, once) {
  const PRESETS = [
    { value: "50,50" },
    { value: "50,18" },
    { value: "50,82" },
    { value: "18,50" },
    { value: "82,50" },
  ];

  /**
   * @returns {string}
   */
  function brandingHeroSourceUrl() {
    const settings = drupalSettings.myeventlane_event_studio;
    if (settings && settings.brandingHero && settings.brandingHero.sourceUrl) {
      return String(settings.brandingHero.sourceUrl);
    }
    return "";
  }

  /**
   * @param {HTMLElement} root
   * @returns {HTMLInputElement|null}
   */
  function findFocalField(root) {
    return root.querySelector("input.focal-point");
  }

  /**
   * @param {HTMLElement} root
   * @returns {HTMLInputElement|HTMLButtonElement|null}
   */
  function findNativeRemove(root) {
    return root.querySelector(
      'input[type="submit"][name*="remove_button"], button[type="submit"][name*="remove_button"]',
    );
  }

  /**
   * @param {string} value
   * @returns {{ x: number, y: number }}
   */
  function parseFocal(value) {
    const parts = String(value || "50,50").split(",");
    const x = parseInt(parts[0], 10);
    const y = parseInt(parts[1], 10);
    return {
      x: Number.isFinite(x) ? x : 50,
      y: Number.isFinite(y) ? y : 50,
    };
  }

  /**
   * @param {string} a
   * @param {string} b
   * @returns {boolean}
   */
  function focalMatches(a, b) {
    const left = parseFocal(a);
    const right = parseFocal(b);
    return left.x === right.x && left.y === right.y;
  }

  /**
   * @param {string} value
   * @returns {string|null}
   */
  function matchingPresetValue(value) {
    for (let i = 0; i < PRESETS.length; i += 1) {
      if (focalMatches(PRESETS[i].value, value)) {
        return PRESETS[i].value;
      }
    }
    return null;
  }

  /**
   * @param {HTMLElement} root
   * @param {HTMLInputElement|null} field
   */
  function syncPresetButtons(root, field) {
    const value = field ? String(field.value || "").trim() : "";
    const match = matchingPresetValue(value);
    root.querySelectorAll(".mel-es-branding-focal-preset").forEach((btn) => {
      const preset = btn.getAttribute("data-focal-preset") || "";
      const active = match !== null && preset === match;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
    });
  }

  /**
   * @param {HTMLElement} root
   * @param {string} message
   */
  function setFocalStatus(root, message) {
    const status = root.querySelector("#mel-es-branding-focal-status");
    if (status) {
      status.textContent = message;
    }
  }

  /**
   * @param {HTMLElement} root
   * @returns {HTMLImageElement|null}
   */
  function findWidgetPreviewImage(root) {
    const thumb = root.querySelector(
      ".image-widget .preview img, .image-widget .preview .thumbnail img, .focal-point-wrapper + img, .image-widget img",
    );
    return thumb instanceof HTMLImageElement ? thumb : null;
  }

  /**
   * @param {HTMLElement} root
   * @returns {string}
   */
  function resolveFramingImageSrc(root) {
    const fromSettings = brandingHeroSourceUrl();
    if (fromSettings) {
      return fromSettings;
    }
    const source = findWidgetPreviewImage(root);
    return source && source.getAttribute("src") ? source.src : "";
  }

  /**
   * @param {HTMLElement} root
   * @param {string} focalValue
   */
  function syncFramingPreview(root, focalValue) {
    const frame = root.querySelector(".js-mel-branding-hero-framing-frame");
    if (!frame) {
      return;
    }
    const src = resolveFramingImageSrc(root);
    const { x, y } = parseFocal(focalValue);
    let img = frame.querySelector("img.mel-es-branding-hero-framing__img");
    if (!src) {
      frame.hidden = true;
      if (img) {
        img.removeAttribute("src");
      }
      return;
    }
    frame.hidden = false;
    if (!img) {
      img = document.createElement("img");
      img.className = "mel-es-branding-hero-framing__img";
      img.alt = "";
      img.decoding = "async";
      img.loading = "lazy";
      frame.appendChild(img);
    }
    if (img.getAttribute("src") !== src) {
      img.src = src;
    }
    img.style.objectPosition = `${x}% ${y}%`;
  }

  /**
   * Notifies focal_point JS to reposition the crop-widget indicator.
   *
   * @param {HTMLInputElement} field
   */
  function notifyFocalPointModule(field) {
    $(field).trigger("change");
    $(document).trigger("drupalFocalPointSet");
  }

  /**
   * @param {HTMLElement} root
   */
  function syncHeroToolStrip(root) {
    const focal = findFocalField(root);
    const presets = root.querySelector(".mel-es-branding-hero-focal-presets");
    const framing = root.querySelector(".mel-es-branding-hero-framing");
    const hasImage = !!resolveFramingImageSrc(root);

    if (presets) {
      presets.hidden = !focal;
    }
    if (framing) {
      framing.hidden = !hasImage;
    }

    if (focal) {
      syncPresetButtons(root, focal);
      syncFramingPreview(root, focal.value);
      if (!focal.dataset.melFocalInitial) {
        focal.dataset.melFocalInitial = focal.value || "50,50";
        setFocalStatus(
          root,
          Drupal.t("Focal point loaded. Save branding to publish changes on your event page."),
        );
      }
    }

    const custom = root.querySelector(".js-mel-branding-hero-remove");
    const native = findNativeRemove(root);
    if (custom) {
      const enable = !!native;
      custom.disabled = !enable;
      custom.setAttribute("aria-disabled", enable ? "false" : "true");
    }
  }

  /**
   * @param {HTMLElement} root
   * @param {HTMLInputElement} field
   * @param {boolean} fromPreset
   */
  function onFocalChanged(root, field, fromPreset) {
    syncPresetButtons(root, field);
    syncFramingPreview(root, field.value);
    const initial = field.dataset.melFocalInitial || "50,50";
    if (focalMatches(field.value, initial)) {
      setFocalStatus(
        root,
        Drupal.t("Focal point matches your saved cover. Save branding after any change."),
      );
    } else if (fromPreset) {
      setFocalStatus(
        root,
        Drupal.t("Shortcut applied — save branding to update your public hero."),
      );
    } else {
      setFocalStatus(
        root,
        Drupal.t("Focal point updated — save branding to update your public hero."),
      );
    }
  }

  /**
   * @param {HTMLElement} root
   */
  function observeWidgetPreview(root) {
    if (root.dataset.melHeroPreviewObserved === "1") {
      return;
    }
    const preview = root.querySelector(".image-widget .preview, .image-widget");
    if (!preview || typeof MutationObserver === "undefined") {
      return;
    }
    root.dataset.melHeroPreviewObserved = "1";
    const observer = new MutationObserver(() => {
      syncHeroToolStrip(root);
      const field = findFocalField(root);
      if (field) {
        onFocalChanged(root, field, false);
      }
    });
    observer.observe(preview, {
      attributes: true,
      childList: true,
      subtree: true,
    });
  }

  if (!Drupal.melBrandingHeroToolsAjaxBound) {
    Drupal.melBrandingHeroToolsAjaxBound = true;
    $(document).on("ajaxComplete.melBrandingHeroTools", () => {
      document
        .querySelectorAll(".mel-es-field-group--branding")
        .forEach((root) => {
          syncHeroToolStrip(root);
          observeWidgetPreview(root);
        });
    });
    $(document).on("drupalFocalPointSet.melBrandingHeroTools", () => {
      document
        .querySelectorAll(".mel-es-field-group--branding")
        .forEach((root) => {
          const field = findFocalField(root);
          if (field) {
            onFocalChanged(root, field, false);
          }
        });
    });
  }

  Drupal.behaviors.melBrandingHeroTools = {
    attach(context) {
      once("mel-branding-hero-tools", ".mel-es-field-group--branding", context).forEach(
        (root) => {
          syncHeroToolStrip(root);
          observeWidgetPreview(root);

          const focal = findFocalField(root);
          if (focal) {
            focal.addEventListener("change", () => {
              onFocalChanged(root, focal, false);
            });
            focal.addEventListener("input", () => {
              syncPresetButtons(root, focal);
              syncFramingPreview(root, focal.value);
            });
          }

          root.addEventListener("click", (event) => {
            const presetBtn = event.target.closest(".mel-es-branding-focal-preset");
            if (presetBtn && root.contains(presetBtn)) {
              event.preventDefault();
              const value = presetBtn.getAttribute("data-focal-preset");
              if (!value) {
                return;
              }
              const field = findFocalField(root);
              if (!field) {
                return;
              }
              field.value = value;
              notifyFocalPointModule(field);
              onFocalChanged(root, field, true);
              return;
            }

            const removeBtn = event.target.closest(".js-mel-branding-hero-remove");
            if (removeBtn && root.contains(removeBtn)) {
              event.preventDefault();
              const nativeRemove = findNativeRemove(root);
              if (nativeRemove) {
                nativeRemove.click();
              }
            }
          });
        },
      );
    },
  };
})(jQuery, Drupal, once);
