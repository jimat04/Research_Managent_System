(() => {
  'use strict';
  const form = document.getElementById('publication-form');
  const configNode = document.getElementById('publication-rules');
  if (!form || !configNode) return;
  const config = JSON.parse(configNode.textContent);
  const type = document.getElementById('publication-type-id');
  const subtype = document.getElementById('publication-subtype-id');
  const scope = document.getElementById('publication-scope-id');
  const allSubtypes = [...subtype.querySelectorAll('option[data-type-id]')].map(option => option.cloneNode(true));
  const abstract = document.getElementById('pub-abstract_text');
  const keywordItems = [...form.querySelectorAll('.keyword-item')];
  const metadataFields = [...form.querySelectorAll('[data-metadata-field]')];
  const authors = document.getElementById('publication-authors');
  const courseFilter = document.getElementById('publication-account-course');
  const authorSelect = document.getElementById('publication-account-author');
  let abstractLimitReached = false;
  let previousType = type.value;
  let previousSubtype = subtype.value;
  const words = value => (value.match(/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu) || []).length;
  const currentRules = () => config.rules[subtype.value] || config.typeRules[type.value] || config.legacyRules;

  let confirmationResolve = null;
  let confirmationOrigin = null;
  const confirmationDialog = document.createElement('dialog');
  confirmationDialog.className = 'publication-confirm-dialog';
  confirmationDialog.setAttribute('aria-labelledby', 'publication-confirm-title');
  confirmationDialog.setAttribute('aria-describedby', 'publication-confirm-message');
  const dialogContent = document.createElement('div');
  dialogContent.className = 'publication-confirm-dialog__content';
  const dialogEyebrow = document.createElement('p');
  dialogEyebrow.className = 'publication-confirm-dialog__eyebrow';
  dialogEyebrow.textContent = 'Publication record';
  const dialogTitle = document.createElement('h2');
  dialogTitle.id = 'publication-confirm-title';
  const dialogMessage = document.createElement('p');
  dialogMessage.id = 'publication-confirm-message';
  dialogMessage.className = 'publication-confirm-dialog__message';
  const dialogActions = document.createElement('div');
  dialogActions.className = 'publication-confirm-dialog__actions';
  const dialogCancel = document.createElement('button');
  dialogCancel.type = 'button';
  dialogCancel.className = 'publication-confirm-dialog__cancel';
  const dialogConfirm = document.createElement('button');
  dialogConfirm.type = 'button';
  dialogConfirm.className = 'publication-confirm-dialog__confirm';
  dialogActions.append(dialogCancel, dialogConfirm);
  dialogContent.append(dialogEyebrow, dialogTitle, dialogMessage, dialogActions);
  confirmationDialog.append(dialogContent);
  document.body.append(confirmationDialog);

  confirmationDialog.addEventListener('close', () => {
    const resolver = confirmationResolve;
    confirmationResolve = null;
    if (confirmationOrigin?.isConnected) confirmationOrigin.focus({ preventScroll: true });
    confirmationOrigin = null;
    resolver?.(confirmationDialog.returnValue === 'confirm');
  });
  confirmationDialog.addEventListener('click', event => {
    if (event.target === confirmationDialog) confirmationDialog.close('cancel');
  });
  dialogCancel.addEventListener('click', () => confirmationDialog.close('cancel'));
  dialogConfirm.addEventListener('click', () => confirmationDialog.close('confirm'));

  function confirmPublicationChange({ title, message, confirmLabel = 'Continue', cancelLabel = 'Keep current selection', tone = 'neutral' }) {
    return new Promise(resolve => {
      if (typeof confirmationDialog.showModal !== 'function') {
        resolve(window.confirm(title + '\n\n' + message));
        return;
      }
      confirmationOrigin = document.activeElement;
      confirmationResolve = resolve;
      confirmationDialog.returnValue = 'cancel';
      confirmationDialog.dataset.tone = tone;
      dialogTitle.textContent = title;
      dialogMessage.textContent = message;
      dialogCancel.textContent = cancelLabel;
      dialogConfirm.textContent = confirmLabel;
      confirmationDialog.showModal();
      dialogCancel.focus({ preventScroll: true });
    });
  }

  function rebuildSubtypes() {
    const saved = subtype.value;
    subtype.replaceChildren(new Option('Select a subtype', ''));
    allSubtypes.filter(option => option.dataset.typeId === type.value).forEach(option => subtype.append(option.cloneNode(true)));
    subtype.value = [...subtype.options].some(option => option.value === saved) ? saved : '';
    subtype.required = Boolean(type.value) && subtype.options.length > 1;
    scope.required = Boolean(type.value);
    type.required = !config.legacy;
  }
  function updateRules() {
    const rule = currentRules();
    let retained = false;
    metadataFields.forEach(wrapper => {
      const key = wrapper.dataset.metadataField;
      const input = wrapper.querySelector('input');
      const applicable = rule.fields.includes(key);
      wrapper.hidden = !applicable;
      input.disabled = !applicable;
      input.required = applicable && rule.required.includes(key);
      wrapper.querySelector('[data-required-mark]').textContent = input.required ? ' *' : '';
      retained ||= !applicable && input.value.trim() !== '';
      const label = wrapper.querySelector('[data-field-label]');
      if (label) label.textContent = (rule.field_labels || {})[key] || label.dataset.defaultLabel || label.textContent;
    });
    const retainedNote = document.getElementById('publication-retained-note');
    retainedNote.hidden = !retained;
    if (retained) retainedNote.textContent = config.hasRecord
      ? 'Metadata from another classification remains stored, but is hidden here and excluded from current metadata.'
      : 'These values remain in the form while you choose a type. They will not be saved unless they apply to the selected classification.';
    const titleLabel = document.getElementById('publication-title-label');
    if (titleLabel) titleLabel.textContent = (rule.title_label || 'Publication title') + ' *';
    const contributorLabel = form.querySelector('.publication-author-field legend');
    if (contributorLabel) contributorLabel.textContent = (rule.contributor_label || 'Authors / contributors') + ' in publication order *';
    const contributorSingular = (rule.contributor_label || 'Contributor').replace(/s$/i, '');
    form.querySelectorAll('.publication-author-row label').forEach(label => {
      if (label.firstChild) label.firstChild.nodeValue = contributorSingular + ' ';
    });
    const documentSection = form.querySelector('[data-upload-slot="supporting_file"]')?.closest('.archive-card');
    if (documentSection) {
      const heading = documentSection.querySelector('h2');
      if (heading) heading.textContent = (rule.document_label || 'Published document') + ' and publication evidence';
    }
    const limit = Number(rule.abstract_word_limit || 0);
    const count = words(abstract.value);
    const abstractVisible = Boolean(rule.abstract_visible);
    abstract.closest('.archive-field').hidden = !abstractVisible;
    abstract.disabled = !abstractVisible;
    abstract.required = Boolean(rule.abstract_required);
    const keywordSection = keywordItems[0]?.closest('fieldset');
    if (keywordSection) keywordSection.hidden = !rule.keywords_visible;
    keywordItems.forEach(item => { item.querySelector('input').disabled = !rule.keywords_visible; });
    abstract.setCustomValidity(limit && count > limit ? 'Use at most ' + limit + ' words.' : '');
    document.getElementById('abstract-word-count').textContent = count + ' words' + (limit ? ' / ' + limit + ' maximum' : '')
      + (abstractLimitReached && limit && count >= limit ? ' — maximum reached' : '');
    const requiredKeywords = Number(rule.keyword_count || 0);
    keywordItems.forEach((item, index) => {
      const input = item.querySelector('input');
      item.hidden = index >= (requiredKeywords || 10) && input.value.trim() === '';
      input.required = index < requiredKeywords;
      input.setCustomValidity('');
    });
    const entered = keywordItems.map(item => item.querySelector('input').value.trim()).filter(Boolean);
    const normalized = entered.map(value => value.toLocaleLowerCase());
    if (new Set(normalized).size !== normalized.length) keywordItems[0].querySelector('input').setCustomValidity('Remove duplicate keywords.');
    else if (requiredKeywords && entered.length > requiredKeywords) keywordItems[0].querySelector('input').setCustomValidity('Enter exactly ' + requiredKeywords + ' keywords for this configuration.');
    const pageCount = document.getElementById('pub-declared_page_count');
    const pageLimit = Number(rule.manuscript_page_limit || 0);
    document.getElementById('publication-page-count').hidden = !pageLimit && !pageCount.value;
    pageCount.required = pageLimit > 0;
    pageCount.min = '1'; pageCount.max = String(pageLimit || 10000);
    const kb = Number(rule.max_attachment_kb || config.systemMaxKb);
    const requirements = { supporting_file: rule.manuscript_required, references_file: rule.references_required, evidence_file: false };
    form.querySelectorAll('[data-upload-slot]').forEach(wrapper => {
      const slot = wrapper.dataset.uploadSlot;
      const input = wrapper.querySelector('input[type=file]');
      // The shared uploader owns file selection; the archive form adds its stricter configured limit.
      if (input) {
        input.required = Boolean(requirements[slot] && !config.hasFiles[slot]);
        input.setCustomValidity(input.files[0] && input.files[0].size > kb * 1024 ? 'The configured maximum attachment size is ' + kb + ' KB.' : '');
      }
      const uploaderLabel = wrapper.querySelector('.rms-uploader-label');
      if (uploaderLabel) {
        const labels = { supporting_file: rule.document_label || 'Published document', evidence_file: 'Supporting publication evidence', references_file: 'Separate references document' };
        uploaderLabel.textContent = labels[slot] + (input?.required ? ' *' : '');
      }
    });
    const notes = [
      limit ? 'Abstract: at most ' + limit + ' words.' : 'No abstract word limit configured.',
      requiredKeywords ? 'Exactly ' + requiredKeywords + ' distinct keywords.' : 'Keywords are optional.',
      rule.manuscript_required ? 'Published paper required.' : 'Published paper optional.',
      rule.evidence_required ? 'Publication evidence required.' : 'Publication evidence optional.',
      rule.references_required ? 'Separate references required.' : 'Separate references optional.',
      pageLimit ? 'Declared manuscript limit: ' + pageLimit + ' pages.' : 'No manuscript page limit configured.',
      'PDF attachments: maximum ' + kb + ' KB each.'
    ];
    document.getElementById('publication-rule-note').textContent = notes.join(' ');
  }
  function renumber() {
    const rows = [...authors.children];
    rows.forEach((row, i) => {
      row.querySelector('[data-author-number]').textContent = String(i + 1);
      row.querySelector('[data-author-up]').disabled = i === 0;
      row.querySelector('[data-author-down]').disabled = i === rows.length - 1;
      row.querySelector('[data-author-remove]').disabled = rows.length === 1;
    });
    document.getElementById('publication-add-author').disabled = rows.length >= 50;
  }
  function refreshLinkedAuthorFilter() {
    const filterValue = courseFilter ? courseFilter.value : '';
    if (authorSelect) {
      let hasVisibleOption = false;
      [...authorSelect.options].forEach(option => {
        if (!option.value) {
          option.hidden = false;
          option.disabled = false;
          return;
        }
        const programIds = (option.dataset.programIds || '').split(',').filter(Boolean);
        const visible = !filterValue || programIds.includes(filterValue);
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible) hasVisibleOption = true;
      });
      if (filterValue && !hasVisibleOption) {
        authorSelect.value = '';
      }
    }

    form.querySelectorAll('input[name="contributor_ids[]"]').forEach(input => {
      const programIds = (input.dataset.programIds || '').split(',').filter(Boolean);
      const row = input.closest('label');
      if (row) row.hidden = !!filterValue && !programIds.includes(filterValue);
    });
  }
  document.querySelectorAll('.archive-error a[href^="#error-"]').forEach(link => {
    link.addEventListener('click', event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const target = document.getElementById(link.hash.slice(1));
      if (!target) return;
      event.preventDefault();
      const shellHeader = document.querySelector('.admin-topbar, .faculty-topbar');
      const headerOffset = shellHeader ? Math.max(0, shellHeader.getBoundingClientRect().bottom) : 0;
      const top = window.scrollY + target.getBoundingClientRect().top - headerOffset - 16;
      if (window.location.hash !== link.hash) window.history.pushState(null, '', link.hash);
      window.scrollTo({
        top: Math.max(0, top),
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
      });
      target.focus({ preventScroll: true });
    });
  });
  function addAuthor(name = '') {
    if (authors.children.length >= 50) return;
    const row = authors.firstElementChild.cloneNode(true);
    row.querySelector('input').value = name;
    authors.append(row); renumber(); row.querySelector('input').focus();
  }
  authors.addEventListener('click', event => {
    const row = event.target.closest('.publication-author-row');
    if (!row) return;
    if (event.target.closest('[data-author-remove]') && authors.children.length > 1) row.remove();
    if (event.target.closest('[data-author-up]') && row.previousElementSibling) authors.insertBefore(row, row.previousElementSibling);
    if (event.target.closest('[data-author-down]') && row.nextElementSibling) authors.insertBefore(row.nextElementSibling, row);
    renumber();
  });
  document.getElementById('publication-add-author').addEventListener('click', () => addAuthor());
  courseFilter?.addEventListener('change', refreshLinkedAuthorFilter);
  refreshLinkedAuthorFilter();
  document.getElementById('publication-link-author').addEventListener('click', () => {
    const select = authorSelect;
    const option = select.selectedOptions[0];
    if (!option?.value) return;
    const input = form.querySelector('input[name="contributor_ids[]"][value="' + option.value + '"]');
    if (input) input.checked = true;
    if (![...authors.querySelectorAll('input')].some(input => input.value.trim() === option.dataset.name)) {
      const empty = [...authors.querySelectorAll('input')].find(input => !input.value.trim());
      if (empty) empty.value = option.dataset.name; else addAuthor(option.dataset.name);
    }
  });
  type.addEventListener('change', async () => {
    if (previousType && previousType !== type.value) {
      const confirmed = await confirmPublicationChange({
        title: 'Change publication type?',
        message: 'This clears the current subtype selection. Your entered values stay in the form. Fields that do not apply to the new type will not be submitted.',
        confirmLabel: 'Change type'
      });
      if (!confirmed) { type.value = previousType; return; }
    }
    previousType = type.value; subtype.value = ''; previousSubtype = '';
    rebuildSubtypes(); updateRules();
  });
  subtype.addEventListener('change', async () => {
    if (previousSubtype && previousSubtype !== subtype.value) {
      const confirmed = await confirmPublicationChange({
        title: 'Change publication subtype?',
        message: 'The form will update its visible fields and validation. Previously saved metadata stays preserved; incompatible unsaved values will not be submitted.',
        confirmLabel: 'Change subtype'
      });
      if (!confirmed) { subtype.value = previousSubtype; return; }
    }
    previousSubtype = subtype.value; updateRules();
  });
  form.addEventListener('input', updateRules);
  form.addEventListener('change', updateRules);
  abstract.addEventListener('beforeinput', event => {
    const limit = Number(currentRules().abstract_word_limit || 0);
    if (!limit || !event.cancelable || !event.inputType.startsWith('insert')) return;
    let inserted = event.data;
    if (inserted === null && event.dataTransfer) inserted = event.dataTransfer.getData('text/plain');
    if (inserted === null && event.inputType === 'insertLineBreak') inserted = '\n';
    if (typeof inserted !== 'string') return; // IME composition is checked on the resulting input/server save.
    const start = abstract.selectionStart ?? abstract.value.length;
    const end = abstract.selectionEnd ?? start;
    const proposed = abstract.value.slice(0, start) + inserted + abstract.value.slice(end);
    if (words(proposed) > limit) {
      event.preventDefault();
      abstractLimitReached = true;
      const count = words(abstract.value);
      document.getElementById('abstract-word-count').textContent = count + ' words / ' + limit + ' maximum — maximum reached';
    } else {
      abstractLimitReached = false;
    }
  });
  abstract.addEventListener('input', () => { abstractLimitReached = false; updateRules(); });
  let approvedResubmission = false;
  form.addEventListener('submit', async event => {
    if (approvedResubmission) { approvedResubmission = false; return; }
    updateRules();
    if (!form.reportValidity()) { event.preventDefault(); return; }
    const rules = currentRules();
    const changedHidden = metadataFields.some(wrapper => {
      const input = wrapper.querySelector('input');
      return !rules.fields.includes(wrapper.dataset.metadataField)
        && input.value.trim() !== (input.dataset.originalValue || '').trim();
    });
    if (!changedHidden) return;
    event.preventDefault();
    const confirmed = await confirmPublicationChange({
      title: 'Save without these fields?',
      message: 'Some values belong to another publication classification and will not be included in this save. Previously saved historical metadata will remain preserved.',
      confirmLabel: form.querySelector('button[type="submit"]')?.textContent.trim() || 'Save publication',
      cancelLabel: 'Review fields',
      tone: 'warning'
    });
    if (!confirmed) return;
    approvedResubmission = true;
    if (event.submitter) form.requestSubmit(event.submitter); else form.requestSubmit();
  });
  rebuildSubtypes(); updateRules(); renumber();
})();
