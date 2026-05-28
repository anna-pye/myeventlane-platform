/**
 * @file
 * Vanilla sidebar carousel for event full pages.
 */

(function (Drupal, once) {
  /**
   * @param {HTMLElement} carousel
   */
  function initSidebarCarousel(carousel) {
    const slides = Array.from(carousel.querySelectorAll("[data-mel-sidebar-slide]"));
    if (slides.length < 1) {
      return;
    }

    const prev = carousel.querySelector("[data-mel-sidebar-prev]");
    const next = carousel.querySelector("[data-mel-sidebar-next]");
    const dots = Array.from(carousel.querySelectorAll("[data-mel-sidebar-dot]"));
    const viewport = carousel.querySelector("[data-mel-sidebar-viewport]");
    const isRail =
      carousel.hasAttribute("data-mel-gallery-rail") ||
      carousel.classList.contains("mel-event-gallery--rail");
    let active = slides.findIndex((slide) => slide.classList.contains("is-active"));
    if (active < 0) {
      active = 0;
    }

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /**
     * @param {number} index
     */
    function goTo(index) {
      const target = Math.max(0, Math.min(index, slides.length - 1));

      if (target !== active) {
        if (isRail) {
          slides[active].classList.remove("is-active");
          slides[active].setAttribute("aria-hidden", "true");
          active = target;
          slides[active].classList.add("is-active");
          slides[active].removeAttribute("aria-hidden");
          slides[active].scrollIntoView({
            behavior: reducedMotion ? "auto" : "smooth",
            block: "nearest",
            inline: "center",
          });
        } else {
          slides[active].classList.remove("is-active");
          slides[active].hidden = true;
          slides[active].setAttribute("aria-hidden", "true");

          active = target;
          slides[active].classList.add("is-active");
          slides[active].hidden = false;
          slides[active].removeAttribute("aria-hidden");
        }
      }

      dots.forEach((dot, i) => {
        const selected = i === active;
        dot.classList.toggle("is-active", selected);
        dot.setAttribute("aria-selected", selected ? "true" : "false");
      });

      if (prev instanceof HTMLButtonElement) {
        prev.disabled = active <= 0;
      }
      if (next instanceof HTMLButtonElement) {
        next.disabled = active >= slides.length - 1;
      }
    }

    if (prev) {
      prev.addEventListener("click", () => goTo(active - 1));
    }
    if (next) {
      next.addEventListener("click", () => goTo(active + 1));
    }

    dots.forEach((dot) => {
      dot.addEventListener("click", () => {
        const raw = dot.getAttribute("data-slide-index");
        const index = raw !== null ? parseInt(raw, 10) : 0;
        goTo(Number.isFinite(index) ? index : 0);
      });
    });

    /**
     * @param {KeyboardEvent} event
     */
    function handleCarouselKey(event) {
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        goTo(active - 1);
        return;
      }
      if (event.key === "ArrowRight") {
        event.preventDefault();
        goTo(active + 1);
        return;
      }
      if (event.key === "Home") {
        event.preventDefault();
        goTo(0);
        return;
      }
      if (event.key === "End") {
        event.preventDefault();
        goTo(slides.length - 1);
      }
    }

    carousel.addEventListener("keydown", handleCarouselKey);
    if (viewport) {
      let touchStartX = 0;
      let touchDeltaX = 0;

      viewport.addEventListener(
        "touchstart",
        (event) => {
          if (event.touches.length !== 1) {
            return;
          }
          touchStartX = event.touches[0].clientX;
          touchDeltaX = 0;
        },
        { passive: true },
      );

      viewport.addEventListener(
        "touchmove",
        (event) => {
          if (event.touches.length !== 1) {
            return;
          }
          touchDeltaX = event.touches[0].clientX - touchStartX;
        },
        { passive: true },
      );

      viewport.addEventListener(
        "touchend",
        () => {
          if (Math.abs(touchDeltaX) < 48) {
            return;
          }
          if (touchDeltaX < 0) {
            goTo(active + 1);
          } else {
            goTo(active - 1);
          }
        },
        { passive: true },
      );
    }

    if (!reducedMotion && slides.length > 1) {
      carousel.setAttribute("data-mel-sidebar-enhanced", "true");
    }

    if (isRail) {
      slides.forEach((slide, i) => {
        slide.hidden = false;
        slide.classList.toggle("is-active", i === active);
        slide.setAttribute("aria-hidden", i === active ? "false" : "true");
      });
    }

    goTo(active);
  }

  Drupal.behaviors.melEventSidebarCarousel = {
    attach(context) {
      once("mel-event-sidebar-carousel", "[data-mel-sidebar-carousel]", context).forEach((carousel) => {
        if (carousel instanceof HTMLElement) {
          initSidebarCarousel(carousel);
        }
      });
    },
  };
})(Drupal, once);
