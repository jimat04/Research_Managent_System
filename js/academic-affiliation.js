(function () {
  'use strict';

  function text(tag, className, value) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (value !== undefined) element.textContent = value;
    return element;
  }

  function initEditor(editor, initial) {
    if (!editor || !window.RmsAcademicAffiliationCatalog) return;
    const catalog = window.RmsAcademicAffiliationCatalog;
    const rowHost = editor.querySelector('[data-affiliation-rows]');
    const addButton = editor.querySelector('[data-add-affiliation]');
    if (!rowHost || !addButton) return;

    let rows = Array.isArray(initial) ? initial : [];
    if (!rows.length) {
      try { rows = JSON.parse(editor.dataset.initial || '[]'); } catch (error) { rows = []; }
    }

    function renderProgramOptions(container, group, selectedIds, fieldName) {
      container.replaceChildren();
      const programs = group ? group.programs : [];
      if (!programs.length) {
        container.append(text('p', 'affiliation-empty', group
          ? 'No programs or specializations are mapped to this group. You may leave this unassigned.'
          : 'Select an academic unit to see its mapped programs.'));
        return;
      }
      const select = document.createElement('select');
      select.className = 'affiliation-program-select';
      select.name = fieldName;
      select.multiple = true;
      select.size = Math.min(Math.max(programs.length, 3), 7);
      programs.forEach((program) => {
        const displayName = program.program_code
          ? program.program_code + ' — ' + program.program_name
          : program.program_name;
        const option = new Option(displayName + (program.is_specialization ? ' · Specialization' : ''), String(program.affiliation_program_id));
        option.selected = selectedIds.includes(Number(program.affiliation_program_id));
        select.append(option);
      });
      container.append(select);
    }

    function addRow(data) {
      data = data || { group_id: 0, program_ids: [] };
      const index = Number(editor.dataset.nextRowIndex || '0');
      editor.dataset.nextRowIndex = String(index + 1);
      const row = text('div', 'affiliation-row');
      const controls = text('div', 'affiliation-row-controls');
      const fieldName = 'academic_affiliations[' + index + '][program_ids][]';
      const groupLabel = text('label', 'affiliation-group-field');
      groupLabel.append(text('span', 'affiliation-field-label', 'Academic Unit / Program Group'));
      const select = document.createElement('select');
      select.name = 'academic_affiliations[' + index + '][group_id]';
      select.required = true;
      select.append(new Option('Select a group', ''));
      catalog.forEach((group) => select.append(new Option(group.group_name, String(group.group_id))));
      groupLabel.append(select);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'affiliation-remove';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove this academic unit');
      remove.addEventListener('click', () => row.remove());
      controls.append(groupLabel, remove);

      const programField = text('fieldset', 'affiliation-program-field');
      programField.append(text('legend', 'affiliation-field-label', 'Academic Program'));
      const programOptions = text('div', 'affiliation-program-options');
      programField.append(programOptions);
      const hint = text('p', 'affiliation-empty', 'Leave empty for Not Applicable or when no program assignment is recorded.');
      programField.append(hint);
      row.append(controls, programField);
      rowHost.append(row);

      select.addEventListener('change', () => {
        const selectedGroup = catalog.find((group) => String(group.group_id) === select.value);
        renderProgramOptions(programOptions, selectedGroup, [], fieldName);
      });
      select.value = String(data.group_id || '');
      const selectedGroup = catalog.find((group) => String(group.group_id) === select.value);
      renderProgramOptions(programOptions, selectedGroup, (data.program_ids || []).map(Number), fieldName);
    }

    rowHost.replaceChildren();
    editor.dataset.nextRowIndex = '0';
    rows.forEach(addRow);
    if (editor.dataset.editorInitialized !== 'true') {
      addButton.addEventListener('click', () => addRow());
      editor.dataset.editorInitialized = 'true';
    }
  }

  window.initRmsAcademicAffiliationEditor = initEditor;

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-academic-affiliation-editor]').forEach((editor) => initEditor(editor));
  });
}());
