/**
 * @file
 * Event Extras booking cards: gallery thumbs, quantity stepper, size chips.
 */
(function (Drupal, once) {
  'use strict';

  const SELECTION_EVENT = 'mel-booking-selection-change';

  /**
   * @param {HTMLElement} card
   */
  function notifyBookingSelectionChange(card) {
    const flow = card.closest('[data-mel-booking-flow]');
    if (flow) {
      flow.dispatchEvent(new CustomEvent(SELECTION_EVENT, { bubbles: true }));
    }
  }

  /**
   * @param {HTMLElement} card
   */
  function syncQtyDisplay(card, value) {
    const display = card.querySelector('.mel-event-extra-card__qty-value');
    const input = card.querySelector('.mel-event-extra-card__qty-input');
    const v = String(value);
    if (display) {
      display.textContent = v;
    }
    if (input) {
      const prev = input.value;
      input.value = v;
      if (prev !== v) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        notifyBookingSelectionChange(card);
      }
    }
  }

  /**
   * @param {HTMLElement} card
   * @returns {string}
   */
  function getSelectedSizeLabel(card) {
    const checked = card.querySelector(
      '.mel-event-extra-card__sizes input[type="radio"][name*="selected_size"]:checked',
    );
    if (!(checked instanceof HTMLInputElement)) {
      return '';
    }
    const wrap = checked.closest('.form-type-radio');
    const label = wrap?.querySelector('label');
    return label?.textContent?.trim() || checked.value.toUpperCase();
  }

  /**
   * @param {HTMLElement} card
   */
  function syncSizeChips(card) {
    const requiresSize = card.getAttribute('data-requires-size') === '1';
    if (!requiresSize) {
      return;
    }

    card.querySelectorAll('.mel-event-extra-card__sizes .form-type-radio').forEach((wrap) => {
      const radio = wrap.querySelector('input[type="radio"]');
      const label = wrap.querySelector('label');
      const selected = radio instanceof HTMLInputElement && radio.checked;
      wrap.classList.toggle('is-selected', selected);
      if (label) {
        label.classList.toggle('is-selected', selected);
        label.setAttribute('aria-pressed', selected ? 'true' : 'false');
      }
    });

    const hint = card.querySelector('[data-mel-extra-size-selected]');
    const sizeLabel = getSelectedSizeLabel(card);
    if (hint instanceof HTMLElement) {
      if (sizeLabel !== '') {
        hint.hidden = false;
        hint.textContent = Drupal.t('Size: @size', { '@size': sizeLabel });
      }
      else {
        hint.hidden = true;
        hint.textContent = '';
      }
    }
  }

  /**
   * @param {HTMLElement} card
   */
  function bindSizeChips(card) {
    if (card.getAttribute('data-requires-size') !== '1') {
      return;
    }

    const sizeGroup = card.querySelector('.mel-event-extra-card__size-radios');
    if (sizeGroup) {
      sizeGroup.setAttribute('role', 'radiogroup');
    }

    card.querySelectorAll('.mel-event-extra-card__sizes .form-type-radio').forEach((wrap) => {
      const radio = wrap.querySelector('input[type="radio"]');
      const label = wrap.querySelector('label');
      if (!(radio instanceof HTMLInputElement) || !label) {
        return;
      }

      wrap.classList.add('mel-event-extra-card__size-chip');
      label.classList.add('mel-event-extra-card__size-chip-label');
      label.setAttribute('role', 'button');
      label.setAttribute('tabindex', '0');

      const selectChip = () => {
        radio.checked = true;
        card.querySelectorAll('.mel-event-extra-card__sizes input[type="radio"]').forEach((other) => {
          if (other !== radio) {
            other.checked = false;
          }
        });
        syncSizeChips(card);
        const qtyInput = card.querySelector('.mel-event-extra-card__qty-input');
        const cur = parseInt(qtyInput?.value || '0', 10) || 0;
        if (cur < 1) {
          syncQtyDisplay(card, 1);
        }
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        notifyBookingSelectionChange(card);
      };

      label.addEventListener('click', (e) => {
        e.preventDefault();
        selectChip();
      });

      label.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          selectChip();
        }
      });

      radio.addEventListener('change', () => {
        syncSizeChips(card);
        notifyBookingSelectionChange(card);
      });
    });

    syncSizeChips(card);
  }

  /** @type {HTMLElement|null} */
  let sharedLightbox = null;

  /** @type {(e: KeyboardEvent) => void} */
  let documentKeydownHandler = null;

  /**
   * @param {HTMLElement} card
   * @returns {{url: string, full_url: string, alt: string}[]}
   */
  function collectGallery(card) {
    const media = card.querySelector('.mel-event-extra-card__media');
    const raw = media?.getAttribute('data-extra-gallery');
    if (raw) {
      try {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) {
          return parsed.filter((item) => item && (item.url || item.full_url));
        }
      } catch (e) {
        // Fall through to thumb-derived gallery.
      }
    }
    /** @type {{url: string, full_url: string, alt: string}[]} */
    const fromThumbs = [];
    card.querySelectorAll('.mel-event-extra-card__thumb').forEach((btn) => {
      const url = btn.getAttribute('data-image-url') || '';
      if (url === '') {
        return;
      }
      fromThumbs.push({
        url,
        full_url: btn.getAttribute('data-image-full-url') || url,
        alt: btn.getAttribute('data-image-alt') || '',
      });
    });
    if (fromThumbs.length > 0) {
      return fromThumbs;
    }
    const img = card.querySelector('.mel-event-extra-card__image');
    if (img instanceof HTMLImageElement && img.src) {
      return [{ url: img.src, full_url: img.src, alt: img.alt || '' }];
    }
    return [];
  }

  /**
   * @param {HTMLElement} card
   * @param {{url: string, full_url: string, alt: string}[]} gallery
   * @returns {number}
   */
  function getActiveGalleryIndex(card, gallery) {
    const activeThumb = card.querySelector('.mel-event-extra-card__thumb.is-active');
    if (activeThumb) {
      const idx = parseInt(activeThumb.getAttribute('data-gallery-index') || '0', 10);
      if (Number.isFinite(idx) && idx >= 0 && idx < gallery.length) {
        return idx;
      }
    }
    const img = card.querySelector('.mel-event-extra-card__image');
    const src = img instanceof HTMLImageElement ? img.getAttribute('src') || '' : '';
    const found = gallery.findIndex((item) => item.url === src || item.full_url === src);
    return found >= 0 ? found : 0;
  }

  /**
   * @returns {HTMLElement}
   */
  function ensureLightbox() {
    if (sharedLightbox) {
      return sharedLightbox;
    }

    const root = document.createElement('div');
    root.className = 'mel-event-extra-lightbox';
    root.id = 'mel-event-extra-lightbox';
    root.setAttribute('hidden', 'hidden');
    root.setAttribute('aria-hidden', 'true');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', Drupal.t('Product image viewer'));
    root.innerHTML = [
      '<button type="button" class="mel-event-extra-lightbox__overlay" data-mel-lightbox-close aria-label="',
      Drupal.t('Close'),
      '"></button>',
      '<div class="mel-event-extra-lightbox__dialog" data-mel-lightbox-dialog>',
      '<header class="mel-event-extra-lightbox__header">',
      '<p class="mel-event-extra-lightbox__title" data-mel-lightbox-title></p>',
      '<button type="button" class="mel-event-extra-lightbox__close" data-mel-lightbox-close aria-label="',
      Drupal.t('Close'),
      '">&times;</button>',
      '</header>',
      '<div class="mel-event-extra-lightbox__stage">',
      '<button type="button" class="mel-event-extra-lightbox__nav mel-event-extra-lightbox__nav--prev" data-mel-lightbox-prev aria-label="',
      Drupal.t('Previous image'),
      '">&lsaquo;</button>',
      '<img class="mel-event-extra-lightbox__image" data-mel-lightbox-image alt="" />',
      '<button type="button" class="mel-event-extra-lightbox__nav mel-event-extra-lightbox__nav--next" data-mel-lightbox-next aria-label="',
      Drupal.t('Next image'),
      '">&rsaquo;</button>',
      '</div>',
      '<p class="mel-event-extra-lightbox__counter" data-mel-lightbox-counter hidden></p>',
      '<div class="mel-event-extra-lightbox__thumbs" data-mel-lightbox-thumbs hidden></div>',
      '</div>',
    ].join('');

    document.body.appendChild(root);
    sharedLightbox = root;

    const onCloseClick = (e) => {
      e.preventDefault();
      e.stopPropagation();
      closeLightbox();
    };
    root.querySelectorAll('[data-mel-lightbox-close]').forEach((btn) => {
      btn.addEventListener('click', onCloseClick);
    });

    documentKeydownHandler = (e) => {
      if (!sharedLightbox || !sharedLightbox.classList.contains('is-open')) {
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        closeLightbox();
      }
      else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        sharedLightbox.dispatchEvent(new CustomEvent('mel-lightbox:prev'));
      }
      else if (e.key === 'ArrowRight') {
        e.preventDefault();
        sharedLightbox.dispatchEvent(new CustomEvent('mel-lightbox:next'));
      }
    };

    const prev = root.querySelector('[data-mel-lightbox-prev]');
    const next = root.querySelector('[data-mel-lightbox-next]');
    if (prev) {
      prev.addEventListener('click', (e) => {
        e.preventDefault();
        root.dispatchEvent(new CustomEvent('mel-lightbox:prev'));
      });
    }
    if (next) {
      next.addEventListener('click', (e) => {
        e.preventDefault();
        root.dispatchEvent(new CustomEvent('mel-lightbox:next'));
      });
    }

    return root;
  }

  function closeLightbox() {
    if (!sharedLightbox) {
      return;
    }
    sharedLightbox.classList.remove('is-open');
    sharedLightbox.setAttribute('hidden', 'hidden');
    sharedLightbox.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('mel-modal-open');
    if (documentKeydownHandler) {
      document.removeEventListener('keydown', documentKeydownHandler);
    }
    const trigger = sharedLightbox._melTrigger;
    if (trigger && typeof trigger.focus === 'function') {
      trigger.focus();
    }
    sharedLightbox._melTrigger = null;
  }

  /**
   * @param {HTMLElement} card
   * @param {number} startIndex
   */
  function openLightbox(card, startIndex) {
    const gallery = collectGallery(card);
    if (gallery.length === 0) {
      return;
    }

    const lb = ensureLightbox();
    let index = Math.max(0, Math.min(startIndex, gallery.length - 1));
    const title = card.getAttribute('data-extra-title') || '';
    const imgEl = lb.querySelector('[data-mel-lightbox-image]');
    const titleEl = lb.querySelector('[data-mel-lightbox-title]');
    const counterEl = lb.querySelector('[data-mel-lightbox-counter]');
    const thumbsEl = lb.querySelector('[data-mel-lightbox-thumbs]');
    const prevBtn = lb.querySelector('[data-mel-lightbox-prev]');
    const nextBtn = lb.querySelector('[data-mel-lightbox-next]');
    const opener = card.querySelector('.mel-event-extra-card__image-open');

    const render = () => {
      const item = gallery[index];
      const displayUrl = item.full_url || item.url;
      if (imgEl instanceof HTMLImageElement) {
        imgEl.src = displayUrl;
        imgEl.alt = item.alt || title;
      }
      if (titleEl) {
        titleEl.textContent = title;
      }
      if (counterEl instanceof HTMLElement) {
        if (gallery.length > 1) {
          counterEl.hidden = false;
          counterEl.textContent = Drupal.t('Image @current of @total', {
            '@current': String(index + 1),
            '@total': String(gallery.length),
          });
        }
        else {
          counterEl.hidden = true;
          counterEl.textContent = '';
        }
      }
      if (prevBtn instanceof HTMLButtonElement) {
        prevBtn.hidden = gallery.length <= 1;
      }
      if (nextBtn instanceof HTMLButtonElement) {
        nextBtn.hidden = gallery.length <= 1;
      }
      if (thumbsEl instanceof HTMLElement) {
        thumbsEl.innerHTML = '';
        if (gallery.length > 1) {
          thumbsEl.hidden = false;
          gallery.forEach((thumb, thumbIndex) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mel-event-extra-lightbox__thumb';
            if (thumbIndex === index) {
              btn.classList.add('is-active');
            }
            btn.setAttribute('aria-label', Drupal.t('Show image @num', {
              '@num': String(thumbIndex + 1),
            }));
            const thumbImg = document.createElement('img');
            thumbImg.src = thumb.url || thumb.full_url;
            thumbImg.alt = '';
            thumbImg.loading = 'lazy';
            btn.appendChild(thumbImg);
            btn.addEventListener('click', (e) => {
              e.preventDefault();
              index = thumbIndex;
              render();
            });
            thumbsEl.appendChild(btn);
          });
        }
        else {
          thumbsEl.hidden = true;
        }
      }
    };

    const onPrev = () => {
      index = (index - 1 + gallery.length) % gallery.length;
      render();
    };
    const onNext = () => {
      index = (index + 1) % gallery.length;
      render();
    };

    lb.removeEventListener('mel-lightbox:prev', onPrev);
    lb.removeEventListener('mel-lightbox:next', onNext);
    lb.addEventListener('mel-lightbox:prev', onPrev);
    lb.addEventListener('mel-lightbox:next', onNext);

    render();
    lb.classList.add('is-open');
    lb.removeAttribute('hidden');
    lb.setAttribute('aria-hidden', 'false');
    document.body.classList.add('mel-modal-open');
    if (documentKeydownHandler) {
      document.addEventListener('keydown', documentKeydownHandler);
    }
    lb._melTrigger = opener instanceof HTMLElement ? opener : null;
    const closeBtn = lb.querySelector('.mel-event-extra-lightbox__close');
    if (closeBtn instanceof HTMLElement) {
      closeBtn.focus();
    }
  }

  /**
   * @param {HTMLElement} card
   */
  function bindGalleryLightbox(card) {
    const opener = card.querySelector('.mel-event-extra-card__image-open');
    if (opener) {
      opener.addEventListener('click', (e) => {
        e.preventDefault();
        const gallery = collectGallery(card);
        openLightbox(card, getActiveGalleryIndex(card, gallery));
      });
    }
  }

  Drupal.behaviors.melEventExtrasBooking = {
    attach(context) {
      once('mel-event-extra-card', '.mel-event-extra-card', context).forEach((card) => {
        const input = card.querySelector('.mel-event-extra-card__qty-input');
        const dec = card.querySelector('.mel-event-extra-card__qty-btn--dec');
        const inc = card.querySelector('.mel-event-extra-card__qty-btn--inc');
        const max = input ? parseInt(input.getAttribute('max') || '10', 10) : 10;

        bindSizeChips(card);
        bindGalleryLightbox(card);

        if (input) {
          syncQtyDisplay(card, input.value || '0');
          input.addEventListener('change', () => {
            let v = parseInt(input.value || '0', 10);
            if (Number.isNaN(v) || v < 0) {
              v = 0;
            }
            if (v > max) {
              v = max;
            }
            syncQtyDisplay(card, v);
          });
          input.addEventListener('input', () => {
            notifyBookingSelectionChange(card);
          });
        }

        if (dec) {
          dec.addEventListener('click', (e) => {
            e.preventDefault();
            const cur = parseInt(input?.value || '0', 10) || 0;
            syncQtyDisplay(card, Math.max(0, cur - 1));
          });
        }

        if (inc) {
          inc.addEventListener('click', (e) => {
            e.preventDefault();
            const cur = parseInt(input?.value || '0', 10) || 0;
            syncQtyDisplay(card, Math.min(max, cur + 1));
          });
        }

        once('mel-event-extra-thumb', '.mel-event-extra-card__thumb', card).forEach((btn) => {
          btn.addEventListener('click', (e) => {
            e.preventDefault();
            const url = btn.getAttribute('data-image-url');
            const alt = btn.getAttribute('data-image-alt') || '';
            const img = card.querySelector('.mel-event-extra-card__image');
            if (img && url) {
              img.setAttribute('src', url);
              img.setAttribute('alt', alt);
            }
            card.querySelectorAll('.mel-event-extra-card__thumb').forEach((t) => {
              t.classList.remove('is-active');
            });
            btn.classList.add('is-active');
          });
          btn.addEventListener('dblclick', (e) => {
            e.preventDefault();
            const gallery = collectGallery(card);
            const idx = parseInt(btn.getAttribute('data-gallery-index') || '0', 10);
            openLightbox(card, Number.isFinite(idx) ? idx : 0);
          });
        });
      });
    },
  };
})(Drupal, once);
