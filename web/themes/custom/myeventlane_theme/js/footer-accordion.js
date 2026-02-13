/**
 * @file
 * Footer accordion for internal (vendor/admin) footer on mobile.
 */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mel-footer__accordion-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.parentElement.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', btn.parentElement.classList.contains('is-open'));
      });
    });
  });
})();
