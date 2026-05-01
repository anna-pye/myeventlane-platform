/**
 * @file
 * Footer accordion for internal (vendor) footer on small viewports.
 */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mel-footer__accordion-toggle').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        if (e.target.closest('form.mel-protected-form')) {
          return;
        }
        btn.parentElement.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', btn.parentElement.classList.contains('is-open'));
      });
    });
  });
})();
