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

  // #region agent log
  Drupal.behaviors.melFrontDebugCards = {
    attach(context) {
      once('melFrontDebugCards', 'body', context).forEach(() => {
        const doc = context && context.ownerDocument ? context.ownerDocument : document;
        const isFront = !!doc.querySelector('.mel-page--front, .mel-front, .mel-front-hero');
        const grid = doc.querySelector('.mel-events-grid');
        const eventGrid = doc.querySelector('.mel-event-grid');
        const viewContent = grid ? grid.querySelector('.view-content') : null;
        const cardCount = doc.querySelectorAll('.mel-event-card').length;
        const gridItemCount = grid ? grid.querySelectorAll('.mel-event-card, .views-row, article').length : 0;
        const gridTextLen = grid ? (grid.textContent || '').trim().length : 0;
        const gridDisplay = grid ? (doc.defaultView ? doc.defaultView.getComputedStyle(grid).display : null) : null;
        const viewDisplay = viewContent ? (doc.defaultView ? doc.defaultView.getComputedStyle(viewContent).display : null) : null;
        const eventGridDisplay = eventGrid ? (doc.defaultView ? doc.defaultView.getComputedStyle(eventGrid).display : null) : null;
        const hasNoResultsText = grid ? /no results|no events|no content/i.test(grid.textContent || '') : false;

        fetch('http://127.0.0.1:7242/ingest/a1ba5d3f-5aeb-448c-b801-95693370f59d', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sessionId: 'debug-session', runId: 'run1', hypothesisId: 'A', location: 'src/js/front-pie.js:melFrontDebugCards', message: 'Front cards snapshot', data: { isFront, hasGrid: !!grid, cardCount, gridItemCount, gridTextLen, hasNoResultsText }, timestamp: Date.now() }) }).catch(() => {});
        fetch('http://127.0.0.1:7242/ingest/a1ba5d3f-5aeb-448c-b801-95693370f59d', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sessionId: 'debug-session', runId: 'run1', hypothesisId: 'B', location: 'src/js/front-pie.js:melFrontDebugCards', message: 'Front grids computed display', data: { gridDisplay, viewDisplay, eventGridDisplay }, timestamp: Date.now() }) }).catch(() => {});

        const firstCard = doc.querySelector('.mel-event-card');
        if (firstCard && doc.defaultView) {
          const rect = firstCard.getBoundingClientRect();
          const cs = doc.defaultView.getComputedStyle(firstCard);
          const image = firstCard.querySelector('.mel-event-card-image');
          const body = firstCard.querySelector('.mel-event-card-body');
          const title = firstCard.querySelector('.mel-event-card-title');
          fetch('http://127.0.0.1:7242/ingest/a1ba5d3f-5aeb-448c-b801-95693370f59d', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sessionId: 'debug-session', runId: 'run1', hypothesisId: 'C', location: 'src/js/front-pie.js:melFrontDebugCards', message: 'First card rect + computed', data: { rect: { x: rect.x, y: rect.y, w: rect.width, h: rect.height }, display: cs.display, visibility: cs.visibility, opacity: cs.opacity, bg: cs.backgroundColor, shadow: cs.boxShadow }, timestamp: Date.now() }) }).catch(() => {});
          fetch('http://127.0.0.1:7242/ingest/a1ba5d3f-5aeb-448c-b801-95693370f59d', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sessionId: 'debug-session', runId: 'run1', hypothesisId: 'D', location: 'src/js/front-pie.js:melFrontDebugCards', message: 'First card structure', data: { hasImage: !!image, hasBody: !!body, hasTitle: !!title, childCount: firstCard.children ? firstCard.children.length : null }, timestamp: Date.now() }) }).catch(() => {});
        }
      });
    },
  };
  // #endregion agent log
})(Drupal, once);
