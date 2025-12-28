/**
 * @file
 * Homepage Free/Paid toggle filter.
 *
 * Filters event cards by event type (free/RSVP vs paid/ticketed) without page reload.
 * Progressive enhancement - works with or without JavaScript.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.homepageEventToggle = {
    attach: function (context, settings) {
      const toggleContainer = context.querySelector('.mel-event-type-toggle');
      const eventsGrid = context.querySelector('#events-grid');
      
      if (!toggleContainer || !eventsGrid) {
        return;
      }

      const pills = toggleContainer.querySelectorAll('.mel-toggle-pill');
      
      // Get all event cards in the grid.
      const eventCards = eventsGrid.querySelectorAll('.mel-event-card, .views-row, article');
      
      pills.forEach(pill => {
        pill.addEventListener('click', function(e) {
          e.preventDefault();
          
          const filter = this.getAttribute('data-filter');
          
          // Update aria-pressed states.
          pills.forEach(p => {
            p.setAttribute('aria-pressed', p === this ? 'true' : 'false');
          });
          
          // Filter event cards.
          eventCards.forEach(card => {
            const ticketType = card.getAttribute('data-event-type') || 
                             card.querySelector('.mel-status-pill, [class*="status-"]')?.className.match(/status-(\w+)/)?.[1] || '';
            
            if (filter === 'all') {
              card.style.display = '';
            } else if (filter === 'free') {
              // Show RSVP/free events (rsvp, both)
              const isFree = ticketType === 'rsvp' || ticketType === 'both' || 
                           card.textContent.toLowerCase().includes('rsvp') ||
                           card.textContent.toLowerCase().includes('free');
              card.style.display = isFree ? '' : 'none';
            } else if (filter === 'paid') {
              // Show paid/ticketed events (paid, both)
              const isPaid = ticketType === 'paid' || ticketType === 'both' ||
                           (!card.textContent.toLowerCase().includes('rsvp') && 
                            !card.textContent.toLowerCase().includes('free'));
              card.style.display = isPaid ? '' : 'none';
            }
          });
          
          // Update URL without reload (for bookmarking, but don't break SEO).
          if (history.pushState) {
            const url = new URL(window.location);
            if (filter === 'all') {
              url.searchParams.delete('event_type');
            } else {
              url.searchParams.set('event_type', filter);
            }
            history.pushState({ filter }, '', url);
          }
        });
      });
      
      // Handle browser back/forward.
      window.addEventListener('popstate', function(e) {
        const url = new URL(window.location);
        const filter = url.searchParams.get('event_type') || 'all';
        const activePill = toggleContainer.querySelector(`[data-filter="${filter}"]`);
        if (activePill) {
          activePill.click();
        }
      });
      
      // Apply initial filter from URL.
      const url = new URL(window.location);
      const initialFilter = url.searchParams.get('event_type') || 'all';
      const initialPill = toggleContainer.querySelector(`[data-filter="${initialFilter}"]`);
      if (initialPill && initialFilter !== 'all') {
        initialPill.click();
      }
    }
  };

})(Drupal);
