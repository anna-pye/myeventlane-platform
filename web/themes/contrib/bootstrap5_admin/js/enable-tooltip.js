// cSpell:ignore Popover
if (typeof bootstrap !== 'undefined') {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    if (el.getAttribute('title') || el.getAttribute('data-bs-original-title')) {
      new bootstrap.Tooltip(el);
    }
  });
  document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
    if (el.getAttribute('title') || el.getAttribute('data-bs-content')) {
      new bootstrap.Popover(el);
    }
  });
}
