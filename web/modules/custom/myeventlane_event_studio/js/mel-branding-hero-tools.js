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

  const CROP_ATTENTION_CLASS = "mel-es-branding-crop-wrapper--attention";

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
   * @returns {string}
   */
  function currentHeroFid(root) {
    const fids = root.querySelector(
      'input[name*="field_event_image"][name*="[fids]"]',
    );
    if (!fids) {
      return "";
    }
    const raw = String(fids.value || "").trim();
    if (!raw) {
      return "";
    }
    return raw.split(/\s+/)[0];
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
      ".image-widget .preview img, .image-widget .preview .thumbnail img, .focal-point-wrapper + img, .image-widget img[data-drupal-selector*='-preview'], .image-widget img",
    );
    return thumb instanceof HTMLImageElement ? thumb : null;
  }

  /**
   * Narrow preview target for MutationObserver — never the full image widget.
   *
   * @param {HTMLElement} root
   * @returns {HTMLElement|null}
   */
  function findHeroPreviewObserveTarget(root) {
    const widget = root.querySelector(".image-widget");
    if (!widget) {
      return null;
    }
    const previewContainer = widget.querySelector(".preview");
    if (previewContainer instanceof HTMLElement) {
      return previewContainer;
    }
    const previewImage = widget.querySelector(
      "img[data-drupal-selector*='-preview']",
    );
    if (previewImage instanceof HTMLImageElement) {
      return previewImage;
    }
    return null;
  }

  /**
   * @param {HTMLElement} root
   * @returns {string}
   */
  function resolveFramingImageSrc(root) {
    const fromSettings = brandingHeroSourceUrl();
    const settings = drupalSettings.myeventlane_event_studio;
    const settingsFid =
      settings &&
      settings.brandingHero &&
      settings.brandingHero.fileId !== undefined
        ? String(settings.brandingHero.fileId)
        : "";
    const activeFid = currentHeroFid(root);
    if (
      fromSettings &&
      activeFid &&
      settingsFid &&
      activeFid === settingsFid
    ) {
      return fromSettings;
    }
    const source = findWidgetPreviewImage(root);
    if (source && source.getAttribute("src")) {
      return source.src;
    }
    // Do not fall back to drupalSettings when FIDs differ — that URL is stale
    // until branding is saved after a cover image change.
    return "";
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
    // Framing preview uses mel_event_hero_featured (event_hero crop baked in).
    img.style.objectPosition = "50% 50%";
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
   * @returns {HTMLElement|null}
   */
  function findCropWrapper(root) {
    return root.querySelector(
      ".image-data__crop-wrapper, details[data-drupal-selector*='crop-wrapper']",
    );
  }

  /**
   * @param {HTMLElement} root
   * @returns {HTMLInputElement|null}
   */
  function findCropAppliedInput(root) {
    const input = root.querySelector(
      'input[name*="field_event_image"][name*="crop_applied"]',
    );
    return input instanceof HTMLInputElement ? input : null;
  }

  /**
   * @param {HTMLElement} root
   * @returns {boolean}
   */
  function hasUploadedHeroFile(root) {
    const fids = root.querySelector(
      'input[name*="field_event_image"][name*="[fids]"]',
    );
    return !!(fids && String(fids.value || "").trim() !== "");
  }

  /**
   * @param {HTMLElement} root
   * @returns {boolean}
   */
  function isCropApplied(root) {
    const applied = findCropAppliedInput(root);
    if (applied) {
      return String(applied.value) === "1";
    }
    const wrapper = findCropWrapper(root);
    return !(wrapper && wrapper.classList.contains("error"));
  }

  /**
   * Whether image_widget_crop has marked the hero crop wrapper invalid.
   *
   * @param {HTMLElement} root
   * @returns {boolean}
   */
  function hasCropValidationError(root) {
    const wrapper = findCropWrapper(root);
    return !!(wrapper && wrapper.classList.contains("error"));
  }

  /**
   * @param {HTMLElement} root
   * @returns {boolean}
   */
  function cropNeedsAttention(root) {
    if (!hasUploadedHeroFile(root)) {
      return false;
    }
    const wrapper = findCropWrapper(root);
    if (wrapper && wrapper.classList.contains("error")) {
      return true;
    }
    return !isCropApplied(root);
  }

  /**
   * @param {HTMLElement} root
   */
  function syncCropRequiredNotice(root) {
    const notice = root.querySelector(".mel-es-branding-crop-notice");
    const wrapper = findCropWrapper(root);
    const needsAttention = cropNeedsAttention(root);
    const hasError = !!(wrapper && wrapper.classList.contains("error"));

    if (notice) {
      const shouldHide = !needsAttention;
      if (notice.hidden !== shouldHide) {
        notice.hidden = shouldHide;
      }
      if (notice.classList.contains("is-error") !== hasError) {
        notice.classList.toggle("is-error", hasError);
      }
    }

    if (wrapper instanceof HTMLDetailsElement) {
      if (hasError || needsAttention) {
        if (!wrapper.open) {
          wrapper.open = true;
        }
        if (!wrapper.classList.contains(CROP_ATTENTION_CLASS)) {
          wrapper.classList.add(CROP_ATTENTION_CLASS);
        }
      }
      else if (wrapper.classList.contains(CROP_ATTENTION_CLASS)) {
        wrapper.classList.remove(CROP_ATTENTION_CLASS);
      }
    }
    else if (wrapper && wrapper.classList.contains(CROP_ATTENTION_CLASS)) {
      wrapper.classList.remove(CROP_ATTENTION_CLASS);
    }

    if (needsAttention) {
      const message = hasError
        ? Drupal.t(
            "Cover crop is required — apply the 16:9 crop, then save branding.",
          )
        : Drupal.t(
            "Apply the 16:9 crop under your cover image before saving branding.",
          );
      const status = root.querySelector("#mel-es-branding-focal-status");
      if (status && status.textContent !== message) {
        setFocalStatus(root, message);
      }
    }
  }

  /**
   * True when the user clicked the branding Save submit (not upload/remove/AJAX).
   *
   * @param {SubmitEvent} event
   * @returns {boolean}
   */
  function isSaveBrandingSubmit(event) {
    const submitter = event.submitter;
    if (!(submitter instanceof HTMLElement)) {
      return false;
    }
    if (submitter.id === "edit-continue") {
      return true;
    }
    if (
      submitter.matches('input[type="submit"], button[type="submit"]')
      && /save branding/i.test(String(submitter.value || submitter.textContent || ""))
    ) {
      return true;
    }
    return false;
  }

  /**
   * @param {HTMLElement} root
   */
  function scrollToBrandingFormIssue(root) {
    const cropIssue = root.querySelector(
      ".image-data__crop-wrapper.error, .mel-es-branding-crop-wrapper--attention, .mel-es-branding-crop-notice:not([hidden])",
    );
    if (cropIssue) {
      cropIssue.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    const workspace = root.closest(".mel-event-studio--workspace");
    const errBox =
      (workspace && workspace.querySelector(".messages--error")) ||
      document.querySelector(".mel-event-studio .messages--error");
    if (errBox) {
      errBox.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  /**
   * @param {HTMLElement} root
   */
  function observeCropWidget(root) {
    if (root.dataset.melCropObserved === "1") {
      return;
    }
    const wrapper = findCropWrapper(root);
    if (!wrapper || typeof MutationObserver === "undefined") {
      return;
    }
    root.dataset.melCropObserved = "1";
    const observer = new MutationObserver((records) => {
      let shouldSync = false;
      for (let i = 0; i < records.length; i += 1) {
        const record = records[i];
        if (record.type === "childList") {
          shouldSync = true;
          break;
        }
        if (
          record.type === "attributes"
          && record.attributeName === "class"
          && record.target === wrapper
        ) {
          shouldSync = true;
          break;
        }
      }
      if (shouldSync) {
        syncCropRequiredNotice(root);
      }
    });
    observer.observe(wrapper, {
      attributes: true,
      attributeFilter: ["class"],
      childList: true,
      subtree: true,
    });
    const applied = findCropAppliedInput(root);
    if (applied) {
      applied.addEventListener("change", () => {
        syncCropRequiredNotice(root);
      });
    }
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

    syncCropRequiredNotice(root);
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
    const target = findHeroPreviewObserveTarget(root);
    if (!target || typeof MutationObserver === "undefined") {
      return;
    }
    root.dataset.melHeroPreviewObserved = "1";
    const observer = new MutationObserver(() => {
      syncHeroToolStrip(root);
    });
    if (target instanceof HTMLImageElement) {
      observer.observe(target, {
        attributes: true,
        attributeFilter: ["src"],
      });
      return;
    }
    observer.observe(target, {
      attributes: true,
      attributeFilter: ["src", "hidden"],
      childList: true,
      subtree: true,
    });
  }

  /**
   * Rebind observers after AJAX replaces managed-file markup.
   *
   * @param {HTMLElement} root
   */
  function refreshBrandingHeroObservers(root) {
    delete root.dataset.melCropObserved;
    delete root.dataset.melHeroPreviewObserved;
    syncHeroToolStrip(root);
    observeWidgetPreview(root);
    observeCropWidget(root);
  }

  if (!Drupal.melBrandingHeroToolsAjaxBound) {
    Drupal.melBrandingHeroToolsAjaxBound = true;
    $(document).on("ajaxComplete.melBrandingHeroTools", () => {
      document
        .querySelectorAll(".mel-es-field-group--branding")
        .forEach((root) => {
          refreshBrandingHeroObservers(root);
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
          observeCropWidget(root);

          if (
            hasCropValidationError(root) &&
            root.dataset.melCropErrorScrolled !== "1"
          ) {
            root.dataset.melCropErrorScrolled = "1";
            scrollToBrandingFormIssue(root);
          }

          const form = root.closest("form");
          if (form && !form.dataset.melBrandingSaveCropBound) {
            form.dataset.melBrandingSaveCropBound = "1";
            form.addEventListener(
              "submit",
              (event) => {
                if (!isSaveBrandingSubmit(event)) {
                  return;
                }
                if (cropNeedsAttention(root) || hasCropValidationError(root)) {
                  syncCropRequiredNotice(root);
                  scrollToBrandingFormIssue(root);
                }
              },
              true,
            );
          }

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
