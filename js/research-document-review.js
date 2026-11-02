(() => {
  const form = document.querySelector('.review-form');
  const dialog = document.querySelector('.review-confirm-dialog');
  if (!form || !dialog || typeof dialog.showModal !== 'function') return;

  const approveButton = form.querySelector('button[name="decision"][value="approved"]');
  const returnButton = form.querySelector('button[name="decision"][value="returned"]');
  const commentsField = form.querySelector('textarea[name="comments"]');
  const commentsSection = dialog.querySelector('[data-review-confirm-comments]');

  if (!approveButton) return;

  approveButton.addEventListener('click', (event) => {
    const comments = commentsField ? commentsField.value.trim() : '';
    if (!comments) return;

    event.preventDefault();
    commentsSection.hidden = comments === '';
    commentsSection.querySelector('h3').textContent = 'Comments/Suggestions';
    commentsSection.querySelector('p').textContent = comments;
    dialog.showModal();
    dialog.querySelector('.review-confirm-cancel').focus();
  });

  dialog.querySelector('.review-confirm-cancel').addEventListener('click', () => dialog.close());
  dialog.querySelector('.review-confirm-approve').addEventListener('click', () => {
    form.requestSubmit(approveButton);
    dialog.close();
  });

  const returnConfirmation = dialog.querySelector('.review-confirm-return');
  if (returnConfirmation && returnButton) {
    returnConfirmation.addEventListener('click', () => {
      form.requestSubmit(returnButton);
      dialog.close();
    });
  }

  dialog.addEventListener('close', () => approveButton.focus());
})();
