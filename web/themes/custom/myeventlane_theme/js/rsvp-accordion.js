(function (Drupal, once) {
  Drupal.behaviors.melRsvpAccordion = {
    attach: function (context) {
      once('mel-rsvp-accordion', '.mel-rsvp-toggle', context).forEach((toggle) => {
        toggle.addEventListener('click', function () {
          const target = this.dataset.target;
          const content = document.getElementById('mel-rsvp-' + target);
          const chevron = this.querySelector('.mel-chevron');

          if (!content || !chevron) return;

          const isOpen = content.classList.contains('is-open');
          if (isOpen) {
            content.classList.remove('is-open');
            chevron.textContent = '+';
            this.setAttribute('aria-expanded', 'false');
          } else {
            content.classList.add('is-open');
            chevron.textContent = '\u2013';
            this.setAttribute('aria-expanded', 'true');
          }
        });
      });
    },
  };
})(Drupal, once);
