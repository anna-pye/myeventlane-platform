/**
 * @file
 * Footer accordion for public and internal footers on mobile.
 */
(function (Drupal, once) {
  Drupal.behaviors.melFooterAccordion = {
    attach(context) {
      once('mel-footer-accordion', '.mel-footer .mel-footer__accordion-toggle', context).forEach((button) => {
        button.addEventListener('click', () => {
          const section = button.closest('.mel-footer__accordion');
          if (!section) {
            return;
          }
          const isOpen = section.classList.toggle('is-open');
          button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
      });
    },
  };
})(Drupal, once);
