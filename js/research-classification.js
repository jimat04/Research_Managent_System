(() => {
  const areaFormLink = document.querySelector('[data-area-form-link]');
  const areaSettings = document.getElementById('area-settings');
  if (areaFormLink && areaSettings) {
    areaFormLink.addEventListener('click', (event) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      areaSettings.open = true;
      history.replaceState(null, '', '#area-settings');
      document.getElementById('area-name').focus({ preventScroll: true });
      areaSettings.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
      });
    });
  }

  const mappingForm = document.getElementById('mapping-form');
  let dirty = false;
  let saving = false;

  if (mappingForm) {
    const options = Array.from(mappingForm.querySelectorAll('[data-current]'));
    const feedback = document.getElementById('mapping-changes');
    const confirmation = mappingForm.querySelector('[name="confirm_mappings"]');
    const refreshChanges = () => {
      let additions = 0;
      let removals = 0;
      let selected = 0;
      options.forEach((option) => {
        const checked = option.querySelector('input[type="checkbox"]').checked;
        const current = option.dataset.current === '1';
        const added = checked && !current;
        const removed = !checked && current;
        if (checked) selected += 1;
        if (added) additions += 1;
        if (removed) removals += 1;
        option.classList.toggle('is-added', added);
        option.classList.toggle('is-removed', removed);
        option.querySelector('[data-change-label]').textContent = added ? 'Will add' : removed ? 'Will remove' : 'Unchanged';
      });
      dirty = additions + removals > 0;
      feedback.textContent = selected + ' selected · ' + additions + ' additions · ' + removals + ' removals. '
        + (dirty ? 'Unsaved changes. Review and confirm before saving.' : 'No unsaved mapping changes.');
    };
    mappingForm.addEventListener('change', (event) => {
      if (event.target.name !== 'area_ids[]') return;
      confirmation.checked = false;
      refreshChanges();
    });
    refreshChanges();
    window.addEventListener('pageshow', refreshChanges);
    window.addEventListener('beforeunload', (event) => {
      if (!dirty || saving) return;
      event.preventDefault();
      event.returnValue = '';
    });
  }

  const forms = Array.from(document.querySelectorAll('[data-saving-form]'));
  forms.forEach((form) => {
    form.addEventListener('submit', () => {
      saving = true;
      form.setAttribute('aria-busy', 'true');
      form.querySelector('.classification-saving').hidden = false;
      form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
    });
  });
  // Back/forward cache can restore a page while its former save controls are disabled.
  window.addEventListener('pageshow', () => {
    saving = false;
    forms.forEach((form) => {
      form.removeAttribute('aria-busy');
      form.querySelector('.classification-saving').hidden = true;
      form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = false; });
    });
  });
})();
