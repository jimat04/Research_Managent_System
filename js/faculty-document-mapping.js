(function () {
  'use strict';

  var root = document.querySelector('[data-document-wizard]');
  if (!root) return;

  var panels = Array.prototype.slice.call(root.querySelectorAll('[data-wizard-panel]'));
  var progressButtons = Array.prototype.slice.call(root.querySelectorAll('[data-wizard-goto]'));
  var stepInput = root.querySelector('[data-wizard-step-input]');
  var status = root.querySelector('[data-wizard-status]');
  var feedback = root.querySelector('#document-answer-feedback');
  var progress = root.querySelector('.frr-wizard-progress');
  var form = root.querySelector('[data-wizard-form]');
  var reviewButton = root.querySelector('[data-review-button]');
  var saveButton = root.querySelector('[data-save-button]');
  var reviewConfirmation = root.querySelector('[data-review-confirmation]');
  var reviewReady = root.getAttribute('data-review-ready') === '1';
  var labels = ['Confirm source and document', 'Answer document questions', 'Review mapped fields', 'Review and save'];
  var activeStep = Math.min(4, Math.max(1, Number(root.getAttribute('data-active-step')) || 1));

  if (!panels.length) return;
  root.classList.add('is-enhanced');

  function setStep(step, moveFocus) {
    activeStep = Math.min(4, Math.max(1, step));
    panels.forEach(function (panel) {
      panel.hidden = Number(panel.getAttribute('data-wizard-panel')) !== activeStep;
    });
    progressButtons.forEach(function (button) {
      var current = Number(button.getAttribute('data-wizard-goto')) === activeStep;
      if (current) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
      var item = button.closest('li');
      if (item) item.classList.toggle('is-current', current);
    });
    if (stepInput) stepInput.value = String(activeStep);
    if (status) status.textContent = 'Step ' + activeStep + ' of 4: ' + labels[activeStep - 1];
    if (moveFocus) {
      var heading = root.querySelector('[data-wizard-panel="' + activeStep + '"] h2');
      if (heading) heading.focus();
      else root.scrollIntoView({ block: 'start', behavior: 'auto' });
    }
  }

  root.addEventListener('click', function (event) {
    var removeAiRow = event.target.closest('[data-ai-row-remove]');
    var next = event.target.closest('[data-wizard-next]');
    var back = event.target.closest('[data-wizard-back]');
    var goto = event.target.closest('[data-wizard-goto]');
    if (removeAiRow) {
      var row = removeAiRow.closest('[data-ai-row]');
      if (!row) return;
      row.remove();
      Array.prototype.forEach.call(root.querySelectorAll('[data-ai-row]'), function (remainingRow, index) {
        var label = remainingRow.querySelector('[data-ai-row-label]');
        var removeButton = remainingRow.querySelector('[data-ai-row-remove]');
        if (label) label.textContent = 'AI detail ' + (index + 1);
        if (removeButton) removeButton.setAttribute('aria-label', 'Delete AI detail row ' + (index + 1));
        Array.prototype.forEach.call(remainingRow.querySelectorAll('textarea[name^="inputs[ai_tools]"]'), function (field) {
          field.name = field.name.replace(/^(inputs\[ai_tools\]\[)\d+(\]\[(?:tool|purpose|prompts|extent)\])$/, '$1' + index + '$2');
          field.id = field.id.replace(/^ai-\d+-(tool|purpose|prompts|extent)$/, 'ai-' + index + '-$1');
        });
      });
      invalidateReview({ target: { name: 'inputs[ai_tools]' } });
    } else if (next) setStep(activeStep + 1, true);
    else if (back) setStep(activeStep - 1, true);
    else if (goto) setStep(Number(goto.getAttribute('data-wizard-goto')), true);
  });

  function invalidateReview(event) {
    var field = event.target;
    if (!field || !field.name) return;
    if (field.name.indexOf('inputs[') !== 0 && field.name !== 'discard_removed_author_answers') return;
    if (!reviewReady) return;

    reviewReady = false;
    root.setAttribute('data-review-ready', '0');
    if (reviewButton) reviewButton.hidden = false;
    if (saveButton) saveButton.hidden = true;
    if (reviewConfirmation) {
      reviewConfirmation.hidden = false;
      reviewConfirmation.classList.add('is-stale');
      reviewConfirmation.textContent = 'Answers changed since the last review. Review all answers again before saving.';
    }
  }

  if (form) {
    form.addEventListener('input', invalidateReview);
    form.addEventListener('change', invalidateReview);
  }

  if (feedback && progress) root.insertBefore(feedback, progress);
  setStep(activeStep, false);

  if (feedback) {
    feedback.focus();
    feedback.scrollIntoView({ block: 'center', behavior: 'auto' });
    var errorGroup = root.getAttribute('data-error-group');
    if (errorGroup) {
      var group = root.querySelector('[data-answer-group="' + errorGroup + '"]');
      if (group) group.scrollIntoView({ block: 'start', behavior: 'auto' });
    }
  }
})();
