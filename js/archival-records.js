(() => {
  const search = document.getElementById('owner-search');
  const select = document.getElementById('owner-id');
  if (search && select) search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    for (const option of select.options) option.hidden = Boolean(option.value && !option.selected && !option.textContent.toLocaleLowerCase().includes(query));
  });
  document.querySelectorAll('[data-archive-print]').forEach(button => button.addEventListener('click', () => window.print()));

  const publicationTypeFilter = document.getElementById('filter-classification-type');
  const publicationSubtypeFilter = document.getElementById('filter-classification-subtype');
  if (publicationTypeFilter && publicationSubtypeFilter) {
    const subtypeOptions = [...publicationSubtypeFilter.options]
      .filter(option => option.dataset.typeId)
      .map(option => option.cloneNode(true));
    const subtypePlaceholder = publicationSubtypeFilter.options[0].cloneNode(true);
    const filterSubtypesByType = () => {
      const selectedSubtype = publicationSubtypeFilter.value;
      const matchingOptions = subtypeOptions.filter(option =>
        !publicationTypeFilter.value || option.dataset.typeId === publicationTypeFilter.value
      );
      publicationSubtypeFilter.replaceChildren(
        subtypePlaceholder.cloneNode(true),
        ...matchingOptions.map(option => option.cloneNode(true))
      );
      publicationSubtypeFilter.value = matchingOptions.some(option => option.value === selectedSubtype)
        ? selectedSubtype
        : '';
    };
    publicationTypeFilter.addEventListener('change', filterSubtypesByType);
    filterSubtypesByType();
  }

  // Conditional display for "Other publication type" field
  const publicationTypeSelect = document.getElementById('field-publication_type');
  const otherTypeField = document.getElementById('field-publication_type_name');
  if (publicationTypeSelect && otherTypeField) {
    const otherTypeWrapper = otherTypeField.closest('.archive-field');
    function toggleOtherType() {
      if (publicationTypeSelect.value === 'other') {
        if (otherTypeWrapper) otherTypeWrapper.style.display = 'block';
        otherTypeField.required = true;
      } else {
        if (otherTypeWrapper) otherTypeWrapper.style.display = 'none';
        otherTypeField.required = false;
        otherTypeField.value = '';
      }
    }
    publicationTypeSelect.addEventListener('change', toggleOtherType);
    toggleOtherType();
  }
})();
