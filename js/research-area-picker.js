(() => {
  const initResearchAreaPicker = () => {
    document.querySelectorAll('[data-title-classification]').forEach((group) => {
      const titleCategory = group.querySelector('.title-category');
      const titleArea = group.querySelector('.title-area');
      if (!titleCategory || !titleArea) return;
      const titleAreaOptions = Array.from(titleArea.options).slice(1);
      const refreshTitleAreas = () => {
        let selectedAreaIsValid = false;
        titleAreaOptions.forEach((option) => {
          const mapped = Number(titleCategory.value) > 0
            && (option.dataset.categoryIds || '').split(',').map(Number).includes(Number(titleCategory.value));
          option.hidden = !mapped;
          option.disabled = !mapped;
          if (mapped && option.value === titleArea.value) selectedAreaIsValid = true;
        });
        if (!selectedAreaIsValid) titleArea.value = '';
        titleArea.options[0].textContent = titleCategory.value ? '-- Select Research Area --' : '-- Select a category first --';
      };
      titleCategory.addEventListener('change', refreshTitleAreas);
      refreshTitleAreas();
    });

    const category = document.getElementById('category_id');
    const picker = document.getElementById('research-area-picker');
    const count = document.getElementById('research-area-count');
    const message = document.getElementById('research-area-message');
    if (!category || !picker || !count || !message) return;

    const options = Array.from(picker.querySelectorAll('label[data-category-ids]'));
    const form = picker.closest('form');
    const originalCategory = Number(picker.dataset.originalCategory);
    const checkboxes = options.map((option) => option.querySelector('input[type="checkbox"]'));
    if (!checkboxes.length) {
      message.hidden = false;
      message.textContent = 'No eligible areas are configured. Ask an administrator to update the catalog.';
      return;
    }
    const eligible = (option) => option.dataset.eligible === '1'
      && Number(category.value) > 0
      && option.dataset.categoryIds.split(',').map(Number).includes(Number(category.value));
    const retained = (option) => option.dataset.saved === '1'
      && Number(category.value) === originalCategory;
    const refreshAreas = () => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
      let invalid = false;
      let available = 0;
      options.forEach((option, index) => {
        const checkbox = checkboxes[index];
        const valid = eligible(option);
        // A saved association can be retained or explicitly restored under its original category.
        const saved = retained(option);
        const visible = valid || saved || checkbox.checked;
        option.hidden = !visible;
        option.style.display = visible ? 'flex' : 'none';
        checkbox.disabled = !checkbox.checked && ((!valid && !saved) || selected >= 5);
        if (valid) available += 1;
        if (checkbox.checked && !valid && !saved) invalid = true;
      });
      count.textContent = '(' + selected + ' selected)';
      const feedback = [];
      if (!category.value) feedback.push('Select a research category to see eligible areas.');
      else if (!available) feedback.push('No enabled areas are mapped to this category. Ask an administrator to update the mappings.');
      if (invalid) feedback.push('Some selected areas are no longer eligible for this category. They remain selected; uncheck them explicitly before saving.');
      if (selected < 1) feedback.push('Select at least one research area.');
      if (selected > 5) feedback.push('Select no more than five research areas.');
      message.hidden = feedback.length === 0;
      message.textContent = feedback.join(' ');
      // Anchor group validation on the category control, outside the collapsible picker.
      category.setCustomValidity(invalid ? 'Remove selected areas that are not eligible for this category.'
        : selected < 1 || selected > 5 ? 'Select between one and five research areas.' : '');
    };
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refreshAreas));
    category.addEventListener('change', refreshAreas);
    if (form) {
      form.addEventListener('submit', (event) => {
        if (event.submitter && event.submitter.formNoValidate) return;
        // Team-management buttons use the same page without saving classifications.
        if (event.submitter && ['add_member', 'remove_member', 'remove_attachment'].includes(event.submitter.value)) return;
        refreshAreas();
        if (!category.checkValidity()) {
          event.preventDefault();
          picker.open = true;
          category.reportValidity();
        }
      });
    }
    refreshAreas();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initResearchAreaPicker, { once: true });
  } else {
    initResearchAreaPicker();
  }
})();

