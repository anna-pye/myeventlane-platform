/**
 * @file
 * Drag-and-drop reorder for ticket cards; triggers Form API AJAX reorder submit.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * @param {HTMLElement} list
   * @return {number[]}
   */
  function collectTicketIds(list) {
    const cards = list.querySelectorAll('.js-mel-ticket-card[data-ticket-id]');
    const ids = [];
    cards.forEach((card) => {
      const id = parseInt(card.getAttribute('data-ticket-id'), 10);
      if (!Number.isNaN(id) && id > 0) {
        ids.push(id);
      }
    });
    return ids;
  }

  /**
   * @param {HTMLElement} list
   */
  function clearDropTargets(list) {
    list.querySelectorAll('.js-mel-ticket-card.is-drop-target').forEach((el) => {
      el.classList.remove('is-drop-target');
    });
  }

  /**
   * @param {HTMLElement} list
   * @param {HTMLElement} dragged
   * @param {number} clientY
   */
  function updateDropTargetHighlight(list, dragged, clientY) {
    clearDropTargets(list);
    if (!dragged || !list.contains(dragged)) {
      return;
    }
    const after = getDragAfterElement(list, clientY);
    if (after) {
      after.classList.add('is-drop-target');
    }
    else {
      const cards = [...list.querySelectorAll('.js-mel-ticket-card[data-ticket-id]')].filter(
        (c) => c !== dragged,
      );
      const last = cards[cards.length - 1];
      if (last) {
        last.classList.add('is-drop-target');
      }
    }
  }

  /**
   * @param {HTMLElement} list
   */
  function persistOrder(list) {
    const wrapper = list.closest('#mel-ticket-builder-ajax-wrapper');
    if (!wrapper || !wrapper.classList.contains('mel-ticket-builder')) {
      return;
    }
    const input = wrapper.querySelector('.js-mel-ticket-order');
    if (!input) {
      return;
    }
    input.value = JSON.stringify(collectTicketIds(list));
    const submitBtn = wrapper.querySelector('.js-mel-ticket-reorder-submit');
    if (!submitBtn) {
      return;
    }
    if (typeof submitBtn.requestSubmit === 'function') {
      submitBtn.requestSubmit();
    }
    else {
      submitBtn.click();
    }
  }

  /**
   * @param {HTMLElement} container
   * @param {HTMLElement} dragged
   * @param {number} y
   * @return {Element|null}
   */
  function getDragAfterElement(container, y) {
    const cards = [
      ...container.querySelectorAll('.js-mel-ticket-card[data-ticket-id]:not(.is-dragging)'),
    ];
    return cards.reduce(
      (closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset, element: child };
        }
        return closest;
      },
      { offset: Number.NEGATIVE_INFINITY, element: null },
    ).element;
  }

  /**
   * @param {HTMLElement} list
   * @param {HTMLElement} wrapper
   */
  function initSortable(list, wrapper) {
    if (list.classList.contains('mel-ticket-list--no-reorder')) {
      return;
    }

    let dragged = null;

    list.addEventListener('dragstart', (e) => {
      const origin =
        e.target && e.target.nodeType === Node.ELEMENT_NODE
          ? e.target
          : e.target && e.target.parentElement;
      const handle = origin && origin.closest ? origin.closest('.js-mel-ticket-drag-handle') : null;
      if (!handle || !list.contains(handle)) {
        e.preventDefault();
        return;
      }
      const card = handle.closest('.js-mel-ticket-card');
      if (!card || !list.contains(card)) {
        e.preventDefault();
        return;
      }
      dragged = card;
      card.classList.add('is-dragging');
      list.classList.add('is-reordering');
      e.dataTransfer.effectAllowed = 'move';
      try {
        e.dataTransfer.setData('text/plain', card.getAttribute('data-ticket-id') || '');
      }
      catch (err) {
        // Some browsers throw on setData; reorder still works.
      }
    });

    list.addEventListener('dragend', () => {
      if (dragged) {
        dragged.classList.remove('is-dragging');
      }
      dragged = null;
      clearDropTargets(list);
      list.classList.remove('is-reordering');
    });

    list.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if (dragged && list.contains(dragged)) {
        updateDropTargetHighlight(list, dragged, e.clientY);
      }
    });

    list.addEventListener('dragleave', (e) => {
      // relatedTarget is often null while moving between children; only clear
      // when we know the pointer left the list for another node outside it.
      if (e.relatedTarget && !list.contains(e.relatedTarget)) {
        clearDropTargets(list);
      }
    });

    list.addEventListener('drop', (e) => {
      e.preventDefault();
      clearDropTargets(list);
      const active =
        dragged && list.contains(dragged)
          ? dragged
          : list.querySelector('.js-mel-ticket-card.is-dragging');
      if (!active || !list.contains(active)) {
        return;
      }
      const after = getDragAfterElement(list, e.clientY);
      if (after == null) {
        list.appendChild(active);
      }
      else {
        list.insertBefore(active, after);
      }
      persistOrder(list);
    });

    wrapper.querySelectorAll('.js-mel-ticket-drag-handle').forEach((handle) => {
      if (!list.contains(handle)) {
        return;
      }
      handle.addEventListener('keydown', (e) => {
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();
        }
      });
    });
  }

  Drupal.behaviors.melTicketCards = {
    attach(context) {
      once('mel-ticket-dnd', '#mel-ticket-builder-ajax-wrapper.mel-ticket-builder', context).forEach(
        (wrapper) => {
          const list = wrapper.querySelector('.js-mel-ticket-sortable');
          if (list) {
            initSortable(list, wrapper);
          }
        },
      );
    },
  };
})(Drupal, once);
