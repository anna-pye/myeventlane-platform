document.querySelectorAll('.mel-event-card').forEach(card => {
  card.addEventListener('click', () => {
    document.querySelectorAll('.mel-event-card')
      .forEach(c => c.classList.remove('selected'));

    card.classList.add('selected');
  });
});
