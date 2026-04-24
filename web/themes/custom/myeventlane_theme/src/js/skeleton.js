/**
 * @file
 * Hides event grid skeletons once real card markup is in the DOM.
 */
document.addEventListener('DOMContentLoaded', () => {
  const grids = document.querySelectorAll('[data-loaded="false"]');

  grids.forEach((grid) => {
    if (grid.children.length > 0) {
      grid.setAttribute('data-loaded', 'true');

      const skeleton = grid.previousElementSibling;
      if (skeleton && skeleton.hasAttribute('data-skeleton')) {
        skeleton.style.display = 'none';
      }
    }
  });
});
