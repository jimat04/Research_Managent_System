(() => {
  'use strict';

  const form = document.querySelector('[data-record-form]');
  if (form) {
    const list = form.querySelector('[data-author-list]');
    const live = form.querySelector('[data-author-live]');
    const addExternal = form.querySelector('[data-add-external]');
    const save = form.querySelector('[data-save-record]');
    const facultySearch = form.querySelector('#frr-faculty-query');
    const facultySearchButton = form.querySelector('button[value="search_faculty"]');
    const saveLabel = save.textContent;
    let saving = false;

    const rows = () => Array.from(list.querySelectorAll('[data-author-row]'));
    const renumber = () => {
      rows().forEach((row, index, all) => {
        row.querySelector('[data-author-legend]').textContent = `Author ${index + 1}`;
        row.querySelectorAll('[data-author-key]').forEach(input => {
          const key = input.dataset.authorKey;
          const oldId = input.id;
          const id = `frr-author-${index}-${key}`;
          const label = oldId ? Array.from(row.querySelectorAll('label')).find(item => item.htmlFor === oldId) : null;
          input.name = `authors[${index}][${key}]`;
          if (input.type !== 'hidden') {
            input.id = id;
            if (label) label.htmlFor = id;
          }
        });
        row.querySelectorAll('[data-author-action]').forEach(button => {
          const action = button.dataset.authorAction;
          button.value = `${action}:${index}`;
          button.disabled = (action === 'move_up' && index === 0) || (action === 'move_down' && index === all.length - 1);
          button.setAttribute('aria-label', action === 'remove' ? `Remove author ${index + 1}` : `Move author ${index + 1} ${action === 'move_up' ? 'up' : 'down'}`);
        });
      });
    };

    const assignLeadByOrder = () => {
      rows().forEach((row, index) => {
        row.querySelector('[data-author-key="author_role"]').value = index === 0 ? 'lead' : 'coauthor';
      });
    };

    const makeExternal = () => {
      const row = document.createElement('fieldset');
      row.className = 'frr-author';
      row.dataset.authorRow = '';
      const legend = document.createElement('legend');
      legend.dataset.authorLegend = '';
      row.append(legend);
      ['record_author_id', 'faculty_user_id'].forEach(key => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.dataset.authorKey = key;
        row.append(input);
      });
      const grid = document.createElement('div');
      grid.className = 'frr-grid';
      const field = (key, text, options = null, limit = 255) => {
        const wrapper = document.createElement('div');
        wrapper.className = `frr-field${key === 'affiliation' ? ' frr-field--wide' : ''}`;
        const label = document.createElement('label');
        label.textContent = `${text} *`;
        const input = document.createElement(options ? 'select' : 'input');
        input.id = `frr-new-${key}`;
        label.htmlFor = input.id;
        input.dataset.authorKey = key;
        input.required = true;
        if (options) {
          options.forEach(([value, name]) => input.add(new Option(name, value)));
          if (key === 'author_type') input.value = 'external';
        } else {
          input.type = 'text';
          input.maxLength = limit;
        }
        wrapper.append(label, input);
        grid.append(wrapper);
      };
      field('full_name', 'Full name');
      field('position', 'Academic rank / position');
      field('affiliation', 'Institutional affiliation', null, 500);
      field('author_type', 'Internal / external author', [['internal', 'Internal faculty'], ['external', 'External author']]);
      field('author_role', 'Authorship role', [['', 'Choose a role'], ['lead', 'Lead Author'], ['coauthor', 'Co-Author']]);
      row.append(grid);
      const hint = document.createElement('p');
      hint.className = 'frr-hint';
      hint.dataset.authorAccount = '';
      hint.textContent = 'External author; no RMS account is required.';
      row.append(hint);
      const actions = document.createElement('div');
      actions.className = 'frr-author-actions';
      [['move_up', '↑ Move up'], ['move_down', '↓ Move down'], ['remove', 'Remove']].forEach(([action, label]) => {
        const button = document.createElement('button');
        button.type = 'submit';
        button.name = 'form_action';
        button.formNoValidate = true;
        button.className = `frr-btn frr-btn--small${action === 'remove' ? ' frr-btn--danger-text' : ''}`;
        button.dataset.authorAction = action;
        button.textContent = label;
        actions.append(button);
      });
      row.append(actions);
      return row;
    };

    rows().forEach(row => {
      const type = row.querySelector('[data-author-key="author_type"]');
      const account = row.querySelector('[data-author-key="faculty_user_id"]');
      const saved = row.querySelector('[data-author-key="record_author_id"]');
      row.dataset.historicalInternal = type.value === 'internal' && !account.value && !!saved.value ? '1' : '0';
    });

    const validateAuthors = () => {
      const all = rows();
      const roles = all.map(row => row.querySelector('[data-author-key="author_role"]'));
      roles.forEach(input => input.setCustomValidity(''));
      if (roles.length && roles.filter(input => input.value === 'lead').length !== 1) {
        roles[0].setCustomValidity('Choose exactly one Lead Author and mark the remaining authors as Co-Authors.');
      }
      all.forEach(row => {
        const type = row.querySelector('[data-author-key="author_type"]');
        const account = row.querySelector('[data-author-key="faculty_user_id"]');
        const internal = type.value === 'internal';
        type.setCustomValidity(internal && !account.value && row.dataset.historicalInternal !== '1' ? 'Use the faculty search to add a registered internal author.' : '');
        const hint = row.querySelector('[data-author-account]');
        hint.textContent = internal ? (account.value ? 'Linked to a registered faculty account. Saved author details remain editable.' : 'Use the faculty search for a new internal author. Historical account links may be unavailable.') : 'External author; no RMS account is required.';
      });
    };

    // Preserve the saved lead author's identity, and normalize legacy order on the editable form.
    const savedLead = rows().find(row => row.querySelector('[data-author-key="author_role"]').value === 'lead');
    if (savedLead) {
      list.insertBefore(savedLead, list.firstElementChild);
      renumber();
      assignLeadByOrder();
    }

    form.addEventListener('click', event => {
      const button = event.target.closest('[data-author-action], [data-add-external]');
      if (!button) return;
      event.preventDefault();
      if (saving) return;
      if (button.hasAttribute('data-add-external')) {
        const row = makeExternal();
        list.append(row);
        renumber();
        row.querySelector('[data-author-key="full_name"]').focus();
        live.textContent = `External author added. ${rows().length} authors in this record.`;
      } else {
        const row = button.closest('[data-author-row]');
        const action = button.dataset.authorAction;
        if (action === 'remove') {
          const next = row.nextElementSibling || row.previousElementSibling;
          row.remove();
          renumber();
          (next?.querySelector('[data-author-key="full_name"]') || addExternal).focus();
          live.textContent = `Author removed. ${rows().length} authors remain. Save to keep this change.`;
        } else {
          const sibling = action === 'move_up' ? row.previousElementSibling : row.nextElementSibling;
          if (!sibling) return;
          if (action === 'move_up') list.insertBefore(row, sibling);
          else list.insertBefore(sibling, row);
          renumber();
          assignLeadByOrder();
          row.querySelector('[data-author-key="full_name"]').focus();
          live.textContent = rows().indexOf(row) === 0
            ? 'Author moved to position 1 and set as Lead Author. Remaining authors are Co-Authors. Save to keep this order.'
            : `Author moved to position ${rows().indexOf(row) + 1}. The first author is the Lead Author. Save to keep this order.`;
        }
      }
      validateAuthors();
    });

    const workType = form.querySelector('#frr-work_type');
    const otherType = form.querySelector('#frr-work_type_other');
    const updateOther = () => {
      const needed = workType.value === 'other';
      otherType.required = needed;
      otherType.disabled = !needed;
      otherType.closest('.frr-field').hidden = !needed;
    };
    const validateDates = () => {
      [['date_started', 'date_completed'], ['conference_start_date', 'conference_end_date']].forEach(([startKey, endKey]) => {
        const start = form.elements.namedItem(startKey);
        const end = form.elements.namedItem(endKey);
        end.setCustomValidity(start.value && end.value && end.value < start.value ? 'The end date cannot be earlier than the start date.' : '');
      });
    };
    form.addEventListener('change', event => {
      const role = event.target.closest('[data-author-key="author_role"]');
      if (role && role.value === 'lead') {
        const row = role.closest('[data-author-row]');
        list.insertBefore(row, list.firstElementChild);
        renumber();
        assignLeadByOrder();
        live.textContent = 'Lead Author moved to position 1. Remaining authors are Co-Authors. Save to keep this order.';
        role.focus();
      }
      validateAuthors();
      validateDates();
      updateOther();
    });
    form.addEventListener('input', validateDates);
    facultySearch.addEventListener('keydown', event => {
      if (event.key !== 'Enter' || event.isComposing) return;
      event.preventDefault();
      form.requestSubmit(facultySearchButton);
    });
    form.addEventListener('submit', event => {
      if (saving) { event.preventDefault(); return; }
      const intent = event.submitter?.value || 'save';
      if (intent !== 'save') return;
      validateAuthors();
      validateDates();
      if (!rows().length) {
        event.preventDefault();
        live.textContent = 'Add at least one author before saving.';
        addExternal.focus();
        return;
      }
      if (!form.reportValidity()) { event.preventDefault(); return; }
      saving = true;
      form.setAttribute('aria-busy', 'true');
      save.setAttribute('aria-disabled', 'true');
      save.textContent = 'Saving…';
    });
    window.addEventListener('pageshow', () => {
      saving = false;
      form.removeAttribute('aria-busy');
      save.removeAttribute('aria-disabled');
      save.textContent = saveLabel;
    });
    renumber();
    updateOther();
    validateAuthors();
    validateDates();
    document.querySelector('[data-error-summary]')?.focus();
  }

  let activeMonthPicker = null;
  document.querySelectorAll('input[type="month"][data-month-picker]').forEach(input => {
    if (input.dataset.monthPickerEnhanced === 'true') return;
    input.dataset.monthPickerEnhanced = 'true';

    const parseMonth = value => {
      const match = /^(\d{4})-(\d{2})$/.exec(value || '');
      return match && Number(match[2]) >= 1 && Number(match[2]) <= 12
        ? { year: Number(match[1]), month: Number(match[2]) - 1 }
        : null;
    };
    const monthValue = (year, month) => `${year}-${String(month + 1).padStart(2, '0')}`;
    const minimum = parseMonth(input.min);
    const maximum = parseMonth(input.max);
    const lowerYear = minimum?.year || 1000;
    const upperYear = maximum?.year || 9999;
    const fieldLabel = Array.from(input.labels || []).map(label => label.textContent.trim().replace(/\s+/g, ' ')).join(' ')
      || 'Choose a month';
    const monthLong = Array.from({ length: 12 }, (_, month) => new Intl.DateTimeFormat(undefined, { month: 'long' }).format(new Date(2020, month, 1)));
    const monthShort = Array.from({ length: 12 }, (_, month) => new Intl.DateTimeFormat(undefined, { month: 'short' }).format(new Date(2020, month, 1)));
    const today = new Date();

    const wrapper = document.createElement('div');
    wrapper.className = 'frr-month-picker';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.append(input);

    const trigger = document.createElement('button');
    trigger.className = 'frr-month-picker-trigger';
    trigger.type = 'button';
    trigger.textContent = '🗓️';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML = '<span data-month-value></span><span class="frr-month-picker-icon" aria-hidden="true">&#128197;</span>';
    input.setAttribute('aria-hidden', 'true');
    input.tabIndex = -1;
    wrapper.append(trigger);

    const panel = document.createElement('section');
    panel.className = 'frr-month-picker-panel';
    panel.id = `${input.id || 'research-month'}-picker`;
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', `${fieldLabel} month picker`);
    panel.innerHTML = '<div class="frr-month-picker-head"><input type="number" data-month-year aria-label="Year"><div class="frr-month-picker-nav"><button type="button" data-month-previous aria-label="Previous year">‹</button><button type="button" data-month-next aria-label="Next year">›</button></div></div><div class="frr-month-picker-grid" role="group"></div><div class="frr-month-picker-footer"><button type="button" data-month-clear>Clear</button><button type="button" data-month-current>This month</button><button type="button" data-month-close>Close</button></div>';
    document.body.append(panel);

    const yearInput = panel.querySelector('[data-month-year]');
    const monthGrid = panel.querySelector('.frr-month-picker-grid');
    const previousButton = panel.querySelector('[data-month-previous]');
    const nextButton = panel.querySelector('[data-month-next]');
    previousButton.textContent = '\u2039';
    nextButton.textContent = '\u203A';
    const selectedFormatter = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });
    let viewYear = parseMonth(input.value)?.year || today.getFullYear();

    trigger.setAttribute('aria-controls', panel.id);
    yearInput.min = String(lowerYear);
    yearInput.max = String(upperYear);

    function syncTriggerName() {
      const selected = parseMonth(input.value);
      trigger.querySelector('[data-month-value]').textContent = selected
        ? selectedFormatter.format(new Date(selected.year, selected.month, 1))
        : 'Choose month and year';
      trigger.setAttribute('aria-label', selected
        ? `${fieldLabel}. Selected ${selectedFormatter.format(new Date(selected.year, selected.month, 1))}. Choose another month.`
        : `${fieldLabel}. Choose a month and year.`);
    }

    function positionPanel() {
      const bounds = trigger.getBoundingClientRect();
      const panelWidth = Math.min(326, window.innerWidth - 24);
      const panelHeight = panel.offsetHeight;
      const preferredLeft = bounds.right - panelWidth;
      const left = Math.max(12, Math.min(preferredLeft, window.innerWidth - panelWidth - 12));
      const below = bounds.bottom + 8;
      const top = below + panelHeight <= window.innerHeight - 12
        ? below
        : Math.max(12, bounds.top - panelHeight - 8);
      panel.style.left = `${left}px`;
      panel.style.top = `${top}px`;
    }

    function closePicker(restoreFocus = false) {
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (activeMonthPicker === closePicker) activeMonthPicker = null;
      if (restoreFocus) trigger.focus();
    }

    function isAllowed(year, month) {
      const value = monthValue(year, month);
      return (!minimum || value >= monthValue(minimum.year, minimum.month))
        && (!maximum || value <= monthValue(maximum.year, maximum.month));
    }

    function selectMonth(month) {
      if (!isAllowed(viewYear, month)) return;
      input.value = monthValue(viewYear, month);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      syncTriggerName();
      closePicker(true);
    }

    function renderMonths(focusMonth = null) {
      const selected = parseMonth(input.value);
      yearInput.value = String(viewYear);
      previousButton.disabled = viewYear <= lowerYear;
      nextButton.disabled = viewYear >= upperYear;
      monthGrid.setAttribute('aria-label', String(viewYear));
      monthGrid.replaceChildren();
      let rovingMonth = focusMonth;
      if (rovingMonth === null && selected?.year === viewYear) rovingMonth = selected.month;
      if (rovingMonth === null && today.getFullYear() === viewYear) rovingMonth = today.getMonth();
      if (rovingMonth === null || !isAllowed(viewYear, rovingMonth)) {
        rovingMonth = Array.from({ length: 12 }, (_, month) => month).find(month => isAllowed(viewYear, month)) ?? 0;
      }

      monthLong.forEach((name, month) => {
        const button = document.createElement('button');
        button.className = 'frr-month-option';
        button.type = 'button';
        button.textContent = monthShort[month];
        button.setAttribute('aria-label', `${name} ${viewYear}`);
        button.setAttribute('aria-pressed', String(selected?.year === viewYear && selected.month === month));
        button.tabIndex = month === rovingMonth ? 0 : -1;
        button.disabled = !isAllowed(viewYear, month);
        if (selected?.year === viewYear && selected.month === month) button.classList.add('is-selected');
        if (today.getFullYear() === viewYear && today.getMonth() === month) button.classList.add('is-current');
        button.addEventListener('click', () => selectMonth(month));
        button.addEventListener('keydown', event => {
          const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -3, ArrowDown: 3 };
          if (!(event.key in offsets)) return;
          event.preventDefault();
          let nextMonth = (month + offsets[event.key] + 12) % 12;
          for (let attempts = 0; attempts < 12 && !isAllowed(viewYear, nextMonth); attempts++) {
            nextMonth = (nextMonth + Math.sign(offsets[event.key]) + 12) % 12;
          }
          monthGrid.querySelectorAll('.frr-month-option')[nextMonth]?.focus();
        });
        monthGrid.append(button);
      });
      positionPanel();
    }

    function openPicker() {
      if (activeMonthPicker && activeMonthPicker !== closePicker) activeMonthPicker();
      const selected = parseMonth(input.value);
      viewYear = Math.max(lowerYear, Math.min(upperYear, selected?.year || today.getFullYear()));
      renderMonths(selected?.year === viewYear ? selected.month : null);
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      activeMonthPicker = closePicker;
      positionPanel();
      monthGrid.querySelector('[tabindex="0"]:not(:disabled)')?.focus();
    }

    function changeYear(amount) {
      viewYear = Math.max(lowerYear, Math.min(upperYear, viewYear + amount));
      renderMonths();
    }

    trigger.addEventListener('click', openPicker);
    wrapper.addEventListener('click', event => { if (event.target !== trigger) openPicker(); });
    input.addEventListener('invalid', () => window.setTimeout(() => { openPicker(); trigger.focus(); }, 0));
    input.addEventListener('change', syncTriggerName);
    Array.from(input.labels || []).forEach(label => label.addEventListener('click', event => {
      event.preventDefault();
      openPicker();
      trigger.focus();
    }));
    previousButton.addEventListener('click', () => changeYear(-1));
    nextButton.addEventListener('click', () => changeYear(1));
    yearInput.addEventListener('change', () => {
      const requestedYear = Number(yearInput.value);
      if (!Number.isInteger(requestedYear) || requestedYear < lowerYear || requestedYear > upperYear) {
        yearInput.value = String(viewYear);
        return;
      }
      viewYear = requestedYear;
      renderMonths();
    });
    panel.querySelector('[data-month-clear]').addEventListener('click', () => {
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      syncTriggerName();
      closePicker(true);
    });
    panel.querySelector('[data-month-current]').addEventListener('click', () => {
      viewYear = Math.max(lowerYear, Math.min(upperYear, today.getFullYear()));
      selectMonth(today.getMonth());
    });
    panel.querySelector('[data-month-close]').addEventListener('click', () => closePicker(true));
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePicker(true);
      }
    });
    document.addEventListener('pointerdown', event => {
      if (!panel.hidden && !panel.contains(event.target) && !wrapper.contains(event.target)) closePicker();
    });
    window.addEventListener('resize', () => { if (!panel.hidden) positionPanel(); });
    window.addEventListener('scroll', () => { if (!panel.hidden) positionPanel(); }, true);
    syncTriggerName();
  });

  const docxSavedDialog = document.querySelector('[data-docx-saved-dialog]');
  if (docxSavedDialog && typeof docxSavedDialog.showModal === 'function') {
    const closeButton = docxSavedDialog.querySelector('[data-docx-saved-close]');
    closeButton.addEventListener('click', () => docxSavedDialog.close());
    docxSavedDialog.addEventListener('click', event => {
      if (event.target === docxSavedDialog) docxSavedDialog.close();
    });
    docxSavedDialog.showModal();
    closeButton.focus({ preventScroll: true });
  }

  document.querySelectorAll('[data-delete-disclosure]').forEach(disclosure => {
    const toggle = disclosure.querySelector('summary');
    const updateLabel = () => { toggle.textContent = disclosure.open ? 'Cancel' : 'Delete'; };
    disclosure.addEventListener('toggle', updateLabel);
    updateLabel();
  });

  document.querySelectorAll('[data-state-form]').forEach(stateForm => {
    let submitting = false;
    stateForm.addEventListener('submit', event => {
      if (submitting) { event.preventDefault(); return; }
      if (stateForm.hasAttribute('data-delete-confirm') && !window.confirm('Move this research record to Deleted / Recoverable? Its information and authors will be kept, and you can restore it later.')) {
        event.preventDefault();
        return;
      }
      submitting = true;
      stateForm.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => { submitting = false; stateForm.removeAttribute('aria-busy'); });
  });
})();
