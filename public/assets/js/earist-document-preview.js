(() => {
  const dialog = document.querySelector('[data-earist-preview-dialog]');
  if (!(dialog instanceof HTMLDialogElement)) return;

  const title = dialog.querySelector('[data-earist-preview-title]');
  const status = dialog.querySelector('[data-earist-preview-status]');
  const scrollRegion = dialog.querySelector('[data-earist-preview-scroll]');
  const content = dialog.querySelector('[data-earist-preview-content]');
  const downloadLink = dialog.querySelector('[data-earist-preview-download]');
  const maxPreviewBytes = 10 * 1024 * 1024;
  let requestSequence = 0;

  document.querySelectorAll('[data-earist-preview-open]').forEach((button) => {
    button.hidden = false;
  });

  function closePreview() {
    requestSequence++;
    if (dialog.open) dialog.close();
  }

  document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;

    if (event.target.closest('[data-earist-preview-close]')) {
      closePreview();
      return;
    }

    const button = event.target.closest('[data-earist-preview-open]');
    if (!button) return;

    const sequence = ++requestSequence;
    const requestedTitle = button.dataset.previewTitle || 'Generated document preview';
    const requestedUrl = button.dataset.previewUrl || '';
    const downloadUrl = button.dataset.downloadUrl || '';
    content.replaceChildren();
    scrollRegion.hidden = true;
    downloadLink.hidden = !downloadUrl;
    downloadLink.href = downloadUrl || '#';
    downloadLink.setAttribute('download', '');
    title.textContent = requestedTitle;
    status.hidden = false;
    status.textContent = 'Loading saved document preview…';
    if (!dialog.open) dialog.showModal();

    try {
      if (!window.docx || typeof window.docx.renderAsync !== 'function') {
        throw new Error('The document viewer is unavailable.');
      }

      const documentUrl = new URL(requestedUrl, window.location.href);
      if (documentUrl.origin !== window.location.origin) {
        throw new Error('The document preview URL is invalid.');
      }

      const response = await fetch(documentUrl.href, {
        credentials: 'same-origin',
        headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
      });
      if (!response.ok) throw new Error('This saved document is unavailable or you do not have access.');

      const contentType = (response.headers.get('content-type') || '').toLowerCase();
      if (!contentType.includes('application/vnd.openxmlformats-officedocument.wordprocessingml.document')) {
        throw new Error('The response was not a DOCX document.');
      }

      const declaredSize = Number(response.headers.get('content-length') || 0);
      if (declaredSize > maxPreviewBytes) throw new Error('This document is larger than the 10 MB preview limit. Download it to view the full file.');

      const documentBlob = await response.blob();
      if (documentBlob.size === 0) throw new Error('The saved document is empty. Download it or contact support.');
      if (documentBlob.size > maxPreviewBytes) throw new Error('This document is larger than the 10 MB preview limit. Download it to view the full file.');

      await window.docx.renderAsync(documentBlob, content, null, {
        breakPages: true,
        renderAltChunks: false,
        renderComments: false,
        useBase64URL: true,
      });

      if (requestedTitle.startsWith('Annex E')) {
        content.querySelectorAll('.docx-wrapper > section.docx > header div').forEach((drawing) => {
          if (drawing.style.position === 'relative' && drawing.style.display === 'inline-block'
            && (drawing.style.left || drawing.style.top)) {
            drawing.style.transform = 'translateX(1.25in)';
          }
        });
      }

      content.querySelectorAll('.docx-wrapper > section.docx').forEach((page) => {
        const pageWidth = Number.parseFloat(page.style.width) || page.getBoundingClientRect().width;
        const pageHeight = Number.parseFloat(page.style.minHeight) || page.getBoundingClientRect().height;
        page.classList.toggle('ed-docx-preview__page--landscape', pageWidth > pageHeight);
      });

      if (sequence !== requestSequence) return;
      status.hidden = true;
      scrollRegion.hidden = false;
      scrollRegion.scrollTop = 0;
    } catch (error) {
      if (sequence !== requestSequence) return;
      status.textContent = error instanceof Error
        ? `${error.message} Use Download DOCX to open the saved file in Word or LibreOffice.`
        : 'Preview unavailable. Use Download DOCX to open the saved file in Word or LibreOffice.';
    }
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closePreview();
  });
  dialog.addEventListener('close', () => {
    requestSequence++;
    content.replaceChildren();
  });
})();
