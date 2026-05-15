/**
 * @file
 * Branding hero: focal presets and remove cover (delegates to core Remove).
 */

(function ($, Drupal, once) {
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
   * @param {HTMLElement} root
   */
  function syncHeroToolStrip(root) {
    const focal = findFocalField(root);
    const presets = root.querySelector(".mel-es-branding-hero-focal-presets");
    if (presets) {
      presets.hidden = !focal;
    }
    const custom = root.querySelector(".js-mel-branding-hero-remove");
    const native = findNativeRemove(root);
    if (custom) {
      const enable = !!native;
      custom.disabled = !enable;
      custom.setAttribute("aria-disabled", enable ? "false" : "true");
    }
  }

  if (!Drupal.melBrandingHeroToolsAjaxBound) {
    Drupal.melBrandingHeroToolsAjaxBound = true;
    $(document).on("ajaxComplete.melBrandingHeroTools", () => {
      document
        .querySelectorAll(".mel-es-field-group--branding")
        .forEach((root) => {
          syncHeroToolStrip(root);
        });
    });
  }

  Drupal.behaviors.melBrandingHeroTools = {
    attach(context) {
      once("mel-branding-hero-tools", ".mel-es-field-group--branding", context).forEach(
        (root) => {
          syncHeroToolStrip(root);

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
              $(field).trigger("change");
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
