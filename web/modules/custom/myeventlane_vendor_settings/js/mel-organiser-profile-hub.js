(function (Drupal, once) {
  'use strict';

  const copy = {
    public: { empty: 'Private by default', ready: 'Choose what visitors can see' },
    profile: { empty: 'Add your story and public details', ready: 'Profile details added' },
    visual: { empty: 'Add a logo and banner', ready: 'Brand appearance set' },
    contact: { empty: 'Add private contact details', ready: 'Contact details added' },
    venues: { empty: 'Manage your reusable venues', ready: 'Manage your reusable venues' },
    business: { empty: 'Add invoicing and payment details', ready: 'Business details added' },
    team: { empty: 'Only you have access', ready: 'Team access configured' },
    notifications: { empty: 'Choose when we contact you', ready: 'Notification choices set' },
  };

  function usefulValue(element) {
    if (element.type === 'checkbox' || element.type === 'radio') {
      return element.checked;
    }
    if (element.type === 'file') {
      return element.files && element.files.length > 0;
    }
    if (element.type === 'hidden' || element.type === 'submit') {
      return false;
    }
    return String(element.value || '').trim() !== '';
  }

  function updateCard(card) {
    const key = card.dataset.melOrganiserCard;
    const summary = card.querySelector(':scope > summary');
    if (!summary || !copy[key]) return;

    const published = card.querySelector('input[name="public_page[published]"]');
    const inputs = Array.from(card.querySelectorAll('input, textarea, select'));
    const hasValue = inputs.some(usefulValue);
    let text = hasValue ? copy[key].ready : copy[key].empty;

    if (key === 'public') {
      const visibleCount = inputs.filter((input) =>
        input.type === 'checkbox' && input !== published && input.checked
      ).length;
      text = published && published.checked
        ? `Published · ${visibleCount} optional detail${visibleCount === 1 ? '' : 's'} visible`
        : 'Private · only you and authorised staff can view it';
    }
    else if (key === 'profile') {
      const storyFields = card.querySelectorAll('textarea, input[name*="summary"], input[name*="tagline"], input[name*="website"], input[name*="social_links"]');
      text = Array.from(storyFields).some(usefulValue)
        ? 'Story and public details added'
        : 'Name saved · add your story and links';
    }
    else if (key === 'visual') {
      text = card.querySelector('.file, .managed-file .file-link')
        ? 'Brand images added'
        : 'Choose a logo, banner and colour';
    }
    else if (key === 'team') {
      text = card.querySelector('ul li') ? 'Team access configured' : 'Only you have access';
    }

    summary.querySelector('.mel-organiser-profile-card__summary').textContent = text;
    summary.querySelector('.mel-organiser-profile-card__action').textContent = card.open ? 'Done' : 'Edit';
  }

  Drupal.behaviors.melOrganiserProfileHub = {
    attach(context) {
      once('mel-organiser-profile-hub', '#vendor-settings-form', context).forEach((form) => {
        form.classList.add('mel-organiser-profile-hub');
        const cards = form.querySelectorAll('[data-mel-organiser-card]');
        const actions = form.querySelector('.mel-organiser-profile-hub__save');

        cards.forEach((card) => {
          const summary = card.querySelector(':scope > summary');
          if (!summary) return;
          summary.insertAdjacentHTML('beforeend', '<span class="mel-organiser-profile-card__meta"><span class="mel-organiser-profile-card__summary"></span><span class="mel-organiser-profile-card__action" aria-hidden="true"></span></span>');
          card.addEventListener('toggle', () => updateCard(card));
          card.querySelectorAll('input, textarea, select').forEach((input) => {
            input.addEventListener('change', () => updateCard(card));
            input.addEventListener('input', () => updateCard(card));
          });
          if (card.querySelector('.form-item--error-message, .messages--error, [aria-invalid="true"]')) {
            card.open = true;
          }
          updateCard(card);
        });

        if (actions) {
          actions.hidden = true;
          const revealSave = () => {
            actions.hidden = false;
            actions.classList.add('is-visible');
          };
          form.addEventListener('input', revealSave, { once: true });
          form.addEventListener('change', revealSave, { once: true });
        }
      });
    },
  };
})(Drupal, once);
