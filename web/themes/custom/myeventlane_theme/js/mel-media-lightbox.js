/**
 * @file
 * Lightweight accessible gallery lightbox (native dialog).
 */

(function (Drupal, once) {
  /** @type {Map<string, { openAt: (index: number) => void }>} */
  const lightboxRegistry = new Map();

  /**
   * @param {HTMLElement} root
   * @returns {Array<Record<string, unknown>>}
   */
  function readGalleryData(root) {
    const script = root.querySelector("[data-mel-lightbox-data]");
    if (!script || !script.textContent) {
      return [];
    }
    try {
      const parsed = JSON.parse(script.textContent);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  /**
   * @param {HTMLElement} root
   * @param {HTMLDialogElement} dialog
   * @param {Array<Record<string, unknown>>} items
   */
  function bindLightbox(root, dialog, items) {
    const img = dialog.querySelector("[data-mel-lightbox-img]");
    const caption = dialog.querySelector("[data-mel-lightbox-caption]");
    const prev = dialog.querySelector("[data-mel-lightbox-prev]");
    const next = dialog.querySelector("[data-mel-lightbox-next]");
    const closeBtn = dialog.querySelector("[data-mel-lightbox-close]");
    let activeIndex = 0;
    let lastFocus = null;

    /**
     * @param {number} index
     */
    function showIndex(index) {
      const item = items[index];
      if (!item || !img) {
        return;
      }
      activeIndex = index;
      const urls = item.urls && typeof item.urls === "object" ? item.urls : {};
      const lightboxUrl = urls.lightbox || urls.card || "";
      img.src = String(lightboxUrl);
      img.alt = String(item.alt || "");
      if (caption) {
        const altText = String(item.alt || "").trim();
        if (altText) {
          caption.textContent = altText;
          caption.hidden = false;
        } else {
          caption.textContent = "";
          caption.hidden = true;
        }
      }
      if (prev) {
        prev.hidden = items.length < 2;
        prev.disabled = index <= 0;
      }
      if (next) {
        next.hidden = items.length < 2;
        next.disabled = index >= items.length - 1;
      }
    }

    function openAt(index) {
      if (!items.length || typeof dialog.showModal !== "function") {
        return;
      }
      lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      showIndex(Math.max(0, Math.min(index, items.length - 1)));
      dialog.showModal();
      if (closeBtn instanceof HTMLElement) {
        closeBtn.focus();
      }
    }

    function closeDialog() {
      if (!dialog.open) {
        return;
      }
      dialog.close();
      if (img) {
        img.removeAttribute("src");
      }
      if (lastFocus instanceof HTMLElement) {
        lastFocus.focus();
      }
    }

    root.addEventListener("click", (event) => {
      const trigger = event.target.closest("[data-mel-lightbox-open]");
      if (!trigger || !root.contains(trigger)) {
        return;
      }
      event.preventDefault();
      const raw = trigger.getAttribute("data-mel-lightbox-index");
      const index = raw !== null ? parseInt(raw, 10) : 0;
      openAt(Number.isFinite(index) ? index : 0);
    });

    if (closeBtn) {
      closeBtn.addEventListener("click", () => closeDialog());
    }

    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) {
        closeDialog();
      }
    });

    dialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      closeDialog();
    });

    if (prev) {
      prev.addEventListener("click", () => {
        if (activeIndex > 0) {
          showIndex(activeIndex - 1);
        }
      });
    }

    if (next) {
      next.addEventListener("click", () => {
        if (activeIndex < items.length - 1) {
          showIndex(activeIndex + 1);
        }
      });
    }

    dialog.addEventListener("keydown", (event) => {
      if (event.key === "ArrowLeft" && activeIndex > 0) {
        event.preventDefault();
        showIndex(activeIndex - 1);
      }
      if (event.key === "ArrowRight" && activeIndex < items.length - 1) {
        event.preventDefault();
        showIndex(activeIndex + 1);
      }
    });

    const rootId = root.getAttribute("data-mel-lightbox-root-id") || "";
    if (rootId !== "") {
      lightboxRegistry.set(rootId, { openAt });
    }
  }

  Drupal.behaviors.melMediaLightbox = {
    attach(context) {
      once("mel-media-lightbox", "[data-mel-lightbox-root]", context).forEach((root) => {
        const dialog = root.querySelector("[data-mel-lightbox-dialog]");
        if (!(dialog instanceof HTMLDialogElement)) {
          return;
        }
        const items = readGalleryData(root);
        if (!items.length) {
          return;
        }
        bindLightbox(root, dialog, items);
      });

      once("mel-media-lightbox-external", "body", context).forEach(() => {
        document.addEventListener("click", (event) => {
          const target = event.target;
          if (!(target instanceof Element)) {
            return;
          }
          const trigger = target.closest("[data-mel-lightbox-open][data-mel-lightbox-root-ref]");
          if (!(trigger instanceof HTMLElement)) {
            return;
          }
          const ref = trigger.getAttribute("data-mel-lightbox-root-ref") || "";
          const entry = lightboxRegistry.get(ref);
          if (!entry) {
            return;
          }
          event.preventDefault();
          const raw = trigger.getAttribute("data-mel-lightbox-index");
          const index = raw !== null ? parseInt(raw, 10) : 0;
          entry.openAt(Number.isFinite(index) ? index : 0);
        });
      });
    },
  };
})(Drupal, once);
