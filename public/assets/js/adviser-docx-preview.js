(() => {
  const dialog = document.querySelector('[data-adviser-docx-dialog]');
  if (!dialog) return;

  const status = dialog.querySelector('[data-docx-preview-status]');
  const scrollRegion = dialog.querySelector('[data-docx-preview-scroll]');
  const content = dialog.querySelector('[data-docx-preview-content]');
  const downloadLink = dialog.querySelector('[data-docx-preview-download]');
  const maxPreviewBytes = 10 * 1024 * 1024;
  let previewSequence = 0;

  function closePreview() {
    previewSequence++;
    if (dialog.open) dialog.close();
  }

  document.addEventListener('click', async (event) => {
    const closeButton = event.target.closest('[data-docx-preview-close]');
    if (closeButton) {
      closePreview();
      return;
    }

    const previewButton = event.target.closest('[data-docx-preview-open]');
    if (!previewButton) return;

    const requestUrl = previewButton.dataset.docxUrl;
    const sequence = ++previewSequence;
    content.replaceChildren();
    scrollRegion.hidden = true;
    status.hidden = false;
    status.textContent = 'Loading secure document preview...';
    downloadLink.href = requestUrl;
    dialog.showModal();

    try {
      if (!window.docx || typeof window.docx.renderAsync !== 'function') {
        throw new Error('The document viewer is unavailable.');
      }

      const response = await fetch(requestUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
      });
      if (!response.ok) throw new Error('The document could not be retrieved.');

      const contentType = response.headers.get('content-type') || '';
      if (!contentType.toLowerCase().includes('application/vnd.openxmlformats-officedocument.wordprocessingml.document')) {
        throw new Error('The response was not a DOCX document.');
      }

      const declaredSize = Number(response.headers.get('content-length') || 0);
      if (declaredSize > maxPreviewBytes) throw new Error('This document is too large to preview.');

      const documentBlob = await response.blob();
      if (documentBlob.size === 0 || documentBlob.size > maxPreviewBytes) {
        throw new Error('This document is empty or too large to preview.');
      }

      await window.docx.renderAsync(documentBlob, content, null, {
        renderAltChunks: false,
        renderComments: false,
        useBase64URL: true,
      });

      if (sequence !== previewSequence) return;
      status.hidden = true;
      scrollRegion.hidden = false;
      scrollRegion.scrollTop = 0;
    } catch (error) {
      if (sequence !== previewSequence) return;
      status.textContent = error instanceof Error
        ? error.message + ' You can download the DOCX to open it in Word or LibreOffice.'
        : 'Preview unavailable. Download the DOCX to open it in Word or LibreOffice.';
    }
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closePreview();
  });
  dialog.addEventListener('close', () => {
    previewSequence++;
  });
})();