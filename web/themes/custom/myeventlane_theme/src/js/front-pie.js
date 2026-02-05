(function (Drupal, once) {
  Drupal.behaviors.melFrontPie = {
    attach(context) {
      once('melFrontPie', '[data-mel-pie]', context).forEach((root) => {
        const slices = root.querySelectorAll('[data-mel-slice]');
        const legends = root.querySelectorAll('[data-mel-legend]');
        const tooltip = root.querySelector('[data-mel-tooltip]');
        const tipTitle = root.querySelector('[data-mel-tooltip-title]');
        const tipMeta = root.querySelector('[data-mel-tooltip-meta]');

        const clear = () => {
          slices.forEach((p) => p.classList.remove('is-active'));
          legends.forEach((l) => l.classList.remove('is-active'));
          if (tooltip) {
            tooltip.classList.remove('is-active');
            tooltip.setAttribute('aria-hidden', 'true');
          }
        };

        const activate = (tid, el) => {
          clear();
          const slice = root.querySelector(`[data-mel-slice="${tid}"]`);
          const legend = root.querySelector(`[data-mel-legend="${tid}"]`);
          if (slice) slice.classList.add('is-active');
          if (legend) legend.classList.add('is-active');

          if (tooltip && slice) {
            const label = slice.getAttribute('data-mel-label') || '';
            const count = slice.getAttribute('data-mel-count') || '0';
            const percent = slice.getAttribute('data-mel-percent') || '0';
            if (tipTitle) tipTitle.textContent = label;
            if (tipMeta) tipMeta.textContent = `${count} events (${percent}%)`;

            // Position tooltip near pointer.
            if (el && el.clientX && el.clientY) {
              const rect = root.getBoundingClientRect();
              const x = el.clientX - rect.left;
              const y = el.clientY - rect.top;
              tooltip.style.setProperty('--mel-tip-x', `${x}px`);
              tooltip.style.setProperty('--mel-tip-y', `${y}px`);
            }

            tooltip.classList.add('is-active');
            tooltip.setAttribute('aria-hidden', 'false');
          }
        };

        slices.forEach((slice) => {
          const tid = slice.getAttribute('data-mel-slice');
          if (!tid) return;

          slice.addEventListener('mousemove', (e) => activate(tid, e));
          slice.addEventListener('mouseenter', (e) => activate(tid, e));
          slice.addEventListener('mouseleave', () => clear());
        });

        legends.forEach((legend) => {
          const tid = legend.getAttribute('data-mel-legend');
          if (!tid) return;

          legend.addEventListener('mouseenter', () => activate(tid, null));
          legend.addEventListener('mouseleave', () => clear());
        });

        // Also highlight from pills if present.
        root.ownerDocument.querySelectorAll('[data-mel-cat]').forEach((pill) => {
          const tid = pill.getAttribute('data-mel-cat');
          if (!tid) return;
          pill.addEventListener('mouseenter', () => activate(tid, null));
          pill.addEventListener('mouseleave', () => clear());
        });
      });
    },
  };
})(Drupal, once);
