(() => {
  const app = document.querySelector('.pdf-review-app');
  if (!app) return;

  const pageHost = app.querySelector('[data-pdf-pages]');
  const status = app.querySelector('[data-viewer-status]');
  const dialog = app.querySelector('[data-annotation-dialog]');
  const commentField = app.querySelector('[data-annotation-comment]');
  const colorPicker = app.querySelector('[data-annotation-color-picker]');
  const quoteView = app.querySelector('[data-annotation-quote]');
  const errorView = app.querySelector('[data-annotation-error]');
  const addButton = app.querySelector('[data-add-annotation]');
  const areaButton = app.querySelector('[data-area-annotation]');
  const deleteButton = app.querySelector('[data-annotation-delete]');
  const approvalButton = document.querySelector('[data-annotation-approval]');
  const decisionNote = document.querySelector('[data-annotation-decision-note]');
  const pageNumberField = app.querySelector('[data-page-number]');
  const pageCountView = app.querySelector('[data-page-count]');
  const zoomView = app.querySelector('[data-zoom-level]');
  const searchInput = app.querySelector('[data-search-input]');
  const searchCount = app.querySelector('[data-search-count]');
  const pages = new Map();
  const annotations = new Map();
  const dismissedComments = new Set();
  const searchMatches = [];
  const annotationColors = ['yellow', 'blue', 'green', 'pink', 'purple'];
  const pending = { anchors: [], selectedText: '' };
  let pdfjsLib = null;
  let pdfDocument = null;
  let canEdit = app.dataset.canEdit === 'true';
  let zoom = 0;
  let activeAnnotation = null;
  let areaMode = false;
  let areaDrag = null;
  let selectionObserver = null;
  let rotation = 0;
  let fitMode = 'width';
  let activeSearchMatch = -1;
  let searchInputTimer = null;

  const assetUrl = (path) => new URL(path, app.dataset.pdfjsBase).href;
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  function announce(message, isError = false) {
    status.textContent = message;
    status.classList.toggle('is-error', isError);
  }

  function syncAnnotationDecisionControls() {
    const hasDraftAnnotations = canEdit && annotations.size > 0;
    if (approvalButton) approvalButton.hidden = hasDraftAnnotations;
    if (decisionNote) decisionNote.hidden = !hasDraftAnnotations;
  }

  function normalizedRect(rect, pageElement) {
    const pageBounds = pageElement.getBoundingClientRect();
    if (pageBounds.width <= 0 || pageBounds.height <= 0) return null;
    const left = clamp((rect.left - pageBounds.left) / pageBounds.width, 0, 1);
    const top = clamp((rect.top - pageBounds.top) / pageBounds.height, 0, 1);
    const right = clamp((rect.right - pageBounds.left) / pageBounds.width, 0, 1);
    const bottom = clamp((rect.bottom - pageBounds.top) / pageBounds.height, 0, 1);
    if (right <= left || bottom <= top) return null;
    return rotateRect([left, top, right - left, bottom - top], (360 - rotation) % 360);
  }

  function rotateRect(rect, degrees) {
    const [x, y, width, height] = rect;
    if (degrees === 90) return [1 - y - height, x, height, width];
    if (degrees === 180) return [1 - x - width, 1 - y - height, width, height];
    if (degrees === 270) return [y, 1 - x - width, height, width];
    return rect;
  }

  function currentSelection() {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed || !selection.rangeCount) return null;
    const range = selection.getRangeAt(0);
    const groups = new Map();

    for (const rect of range.getClientRects()) {
      if (rect.width < 1 || rect.height < 1) continue;
      const pointX = rect.left + rect.width / 2;
      const pointY = rect.top + rect.height / 2;
      const hit = document.elementFromPoint(pointX, pointY);
      const pageElement = hit && hit.closest('.pdf-review-page');
      if (!pageElement) continue;
      const pageNumber = Number(pageElement.dataset.pageNumber);
      const position = normalizedRect(rect, pageElement);
      if (!Number.isInteger(pageNumber) || !position) continue;
      if (!groups.has(pageNumber)) groups.set(pageNumber, []);
      groups.get(pageNumber).push(position);
    }

    if (!groups.size) return null;
    return {
      selectedText: selection.toString().trim().slice(0, 5000),
      anchors: [...groups.entries()].map(([page, rects]) => ({ page, rects })),
    };
  }

  function refreshSelection() {
    if (!addButton || areaMode) return;
    const selection = currentSelection();
    pending.anchors = selection?.anchors || [];
    pending.selectedText = selection?.selectedText || '';
    addButton.disabled = !pending.anchors.length;
    if (selection?.anchors.length) {
      announce('Text selected. Add a comment to attach reviewer feedback.');
    }
  }

  function dialogOpen() {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  }

  function dialogClose() {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function openAnnotationDialog(annotation = null) {
    activeAnnotation = annotation;
    const isExisting = Boolean(annotation);
    const editable = canEdit;
    app.querySelector('#pdf-annotation-title').textContent = isExisting ? 'Highlight comment' : 'Add a comment to this highlight';
    quoteView.textContent = annotation?.selected_text || pending.selectedText || 'Page area highlight';
    quoteView.hidden = !quoteView.textContent;
    commentField.value = annotation?.comment || '';
    commentField.readOnly = !editable;
    commentField.setAttribute('aria-readonly', editable ? 'false' : 'true');
    if (colorPicker) {
      colorPicker.hidden = !editable;
      const selectedColor = annotationColors.includes(annotation?.color) ? annotation.color : 'yellow';
      colorPicker.querySelectorAll('input[name="highlight_color"]').forEach((input) => {
        input.checked = input.value === selectedColor;
        input.disabled = !editable;
      });
    }
    app.querySelector('[data-annotation-save]').hidden = !editable;
    app.querySelector('[data-annotation-cancel]').textContent = editable ? 'Cancel' : 'Close';
    deleteButton.hidden = !editable || !isExisting;
    errorView.hidden = true;
    errorView.textContent = '';
    dialogOpen();
    if (editable) commentField.focus();
    else app.querySelector('[data-annotation-cancel]').focus();
  }

  function commentGutterWidth() {
    const hostWidth = pageHost.clientWidth;
    const hasVisibleBubbles = !canEdit && [...annotations.values()].some((annotation) =>
      (annotation.anchors || []).some((pageAnchor) =>
        Array.isArray(pageAnchor.rects)
        && pageAnchor.rects.length > 0
        && !dismissedComments.has(`${annotation.id}:${Number(pageAnchor.page)}`)
      )
    );
    if (!hasVisibleBubbles || hostWidth < 740) return 0;
    return Math.min(320, Math.max(240, hostWidth * 0.32));
  }

  function pageScale(pageInfo) {
    if (zoom > 0) return zoom;
    const pageWidth = rotation % 180 ? pageInfo.baseHeight : pageInfo.baseWidth;
    const pageHeight = rotation % 180 ? pageInfo.baseWidth : pageInfo.baseHeight;
    const availableWidth = Math.max(240, pageHost.clientWidth - 44 - commentGutterWidth());
    if (fitMode === 'page') {
      const availableHeight = Math.max(240, pageHost.clientHeight - 44);
      return Math.min(availableWidth / pageWidth, availableHeight / pageHeight, 4);
    }
    return Math.min(4, availableWidth / pageWidth);
  }

  function updateZoomLabel() {
    const firstPage = pages.values().next().value;
    if (!firstPage) return;
    zoomView.textContent = `${Math.round(pageScale(firstPage.pageInfo) * 100)}%`;
  }

  function createPageElement(pageNumber, pageInfo) {
    const pageElement = document.createElement('article');
    pageElement.className = 'pdf-review-page';
    pageElement.dataset.pageNumber = String(pageNumber);
    pageElement.setAttribute('aria-label', `Page ${pageNumber}`);
    const canvas = document.createElement('canvas');
    canvas.className = 'pdf-review-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    const textLayer = document.createElement('div');
    textLayer.className = 'textLayer';
    const annotationLayer = document.createElement('div');
    annotationLayer.className = 'pdf-annotation-layer';
    annotationLayer.setAttribute('aria-label', `Highlights on page ${pageNumber}`);
    pageElement.append(canvas, textLayer, annotationLayer);
    pageHost.append(pageElement);

    const pageState = {
      pageNumber,
      pageInfo,
      pageElement,
      canvas,
      textLayer,
      annotationLayer,
      textItems: pageInfo.textItems || [],
      renderedScale: 0,
      renderedRotation: -1,
      rendering: false,
    };
    pages.set(pageNumber, pageState);
    resizePageBox(pageState);
    return pageState;
  }

  function resizePageBox(pageState) {
    const scale = pageScale(pageState.pageInfo);
    const width = (rotation % 180 ? pageState.pageInfo.baseHeight : pageState.pageInfo.baseWidth) * scale;
    const height = (rotation % 180 ? pageState.pageInfo.baseWidth : pageState.pageInfo.baseHeight) * scale;
    pageState.pageElement.style.width = `${width}px`;
    pageState.pageElement.style.height = `${height}px`;
    pageState.pageInfo.width = width;
    pageState.pageInfo.height = height;
  }

  async function renderPage(pageState) {
    const scale = pageScale(pageState.pageInfo);
    if (pageState.rendering || (Math.abs(pageState.renderedScale - scale) < 0.01 && pageState.renderedRotation === rotation)) return;
    pageState.rendering = true;
    pageState.renderedScale = 0;
    try {
      const page = await pdfDocument.getPage(pageState.pageNumber);
      const viewport = page.getViewport({ scale, rotation: (page.rotate + rotation) % 360 });
      const outputScale = window.devicePixelRatio || 1;
      // Keep transparent PDF areas transparent so the page's white background shows through.
      const context = pageState.canvas.getContext('2d', { alpha: true });
      pageState.canvas.width = Math.ceil(viewport.width * outputScale);
      pageState.canvas.height = Math.ceil(viewport.height * outputScale);
      pageState.canvas.style.width = `${viewport.width}px`;
      pageState.canvas.style.height = `${viewport.height}px`;
      context.setTransform(outputScale, 0, 0, outputScale, 0, 0);
      pageState.textLayer.replaceChildren();
      pageState.textLayer.style.setProperty('--scale-factor', String(scale));
      pageState.textLayer.style.setProperty('--total-scale-factor', String(scale));
      await page.render({ canvasContext: context, viewport }).promise;
      const textContent = await page.getTextContent();
      pageState.textItems = textContent.items.map((item) => typeof item.str === 'string' ? item.str : '');
      const textLayer = new pdfjsLib.TextLayer({ textContentSource: textContent, container: pageState.textLayer, viewport });
      await textLayer.render();
      pageState.renderedScale = scale;
      pageState.renderedRotation = rotation;
      paintSearchMatches(pageState);
      paintAnnotations(pageState);
    } catch (error) {
      announce('This PDF page could not be rendered. Try downloading the original document.', true);
    } finally {
      pageState.rendering = false;
      if (pageState.renderedScale && (Math.abs(pageState.renderedScale - pageScale(pageState.pageInfo)) >= 0.01
        || pageState.renderedRotation !== rotation)) {
        pageState.renderedScale = 0;
        requestAnimationFrame(() => renderPage(pageState));
      }
    }
  }

  function paintAnnotations(pageState) {
    pageState.annotationLayer.replaceChildren();
    for (const annotation of annotations.values()) {
      for (const pageAnchor of annotation.anchors || []) {
        if (Number(pageAnchor.page) !== pageState.pageNumber) continue;
        const rects = [];
        for (const rect of pageAnchor.rects || []) {
          if (!Array.isArray(rect) || rect.length !== 4) continue;
          const canonical = rect.map((value) => Number(value));
          if (canonical.some((value) => !Number.isFinite(value))) continue;
          const normalized = rotateRect(canonical, rotation);
          rects.push(normalized);
          const mark = document.createElement('button');
          mark.type = 'button';
          const color = annotationColors.includes(annotation.color) ? annotation.color : 'yellow';
          mark.className = `pdf-annotation-mark is-color-${color}`;
          mark.dataset.annotationId = String(annotation.id);
          mark.style.left = `${clamp(normalized[0], 0, 1) * 100}%`;
          mark.style.top = `${clamp(normalized[1], 0, 1) * 100}%`;
          mark.style.width = `${clamp(normalized[2], 0, 1 - clamp(normalized[0], 0, 1)) * 100}%`;
          mark.style.height = `${clamp(normalized[3], 0, 1 - clamp(normalized[1], 0, 1)) * 100}%`;
          mark.setAttribute('aria-label', `Highlight comment: ${annotation.comment}`);
          mark.addEventListener('click', () => openAnnotationDialog(annotation));
          pageState.annotationLayer.append(mark);
        }

        if (!rects.length || canEdit) continue;
        const pageWidth = pageState.pageElement.clientWidth;
        const pageHeight = pageState.pageElement.clientHeight;
        const anchorLeft = Math.min(...rects.map((rect) => rect[0])) * pageWidth;
        const anchorTop = Math.min(...rects.map((rect) => rect[1])) * pageHeight;
        const anchorBottom = Math.max(...rects.map((rect) => rect[1] + rect[3])) * pageHeight;
        const anchorCenter = (anchorTop + anchorBottom) / 2;
        const gutterWidth = commentGutterWidth();
        const sidePlacement = gutterWidth > 0;
        const bubble = document.createElement('button');
        bubble.type = 'button';
        const color = annotationColors.includes(annotation.color) ? annotation.color : 'yellow';
        bubble.className = `pdf-annotation-comment is-color-${color}${sidePlacement ? ' is-side-right' : ''}`;
        bubble.setAttribute('aria-label', `Center highlighted passage and hide comment. ${annotation.comment}`);
        bubble.title = 'Click to center the highlighted passage and hide this comment';
        const bubbleText = document.createElement('span');
        bubbleText.textContent = annotation.comment;
        const closeMark = document.createElement('span');
        closeMark.className = 'pdf-annotation-comment-close';
        closeMark.setAttribute('aria-hidden', 'true');
        closeMark.textContent = '×';
        bubble.append(bubbleText, closeMark);
        const bubbleKey = `${annotation.id}:${pageState.pageNumber}`;
        bubble.hidden = dismissedComments.has(bubbleKey);
        bubble.addEventListener('click', () => {
          dismissedComments.add(bubbleKey);
          bubble.hidden = true;
          syncCommentLayout();
          redrawAnnotations();

          const targetMark = pageState.annotationLayer.querySelector(
            `.pdf-annotation-mark[data-annotation-id="${annotation.id}"]`
          );
          if (!targetMark) return;
          const hostBounds = pageHost.getBoundingClientRect();
          const markBounds = targetMark.getBoundingClientRect();
          const targetTop = pageHost.scrollTop + markBounds.top - hostBounds.top
            - (pageHost.clientHeight - markBounds.height) / 2;
          const targetLeft = pageHost.scrollLeft + markBounds.left - hostBounds.left
            - (pageHost.clientWidth - markBounds.width) / 2;
          const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
          pageHost.scrollTo({ top: targetTop, left: targetLeft, behavior });
          targetMark.focus({ preventScroll: true });
        });
        if (sidePlacement) bubble.style.width = `${gutterWidth - 24}px`;
        bubble.style.left = sidePlacement ? `${pageWidth + 20}px` : '8px';
        bubble.style.top = `${anchorBottom + 12}px`;
        pageState.annotationLayer.append(bubble);

        const bubbleWidth = bubble.offsetWidth;
        const bubbleHeight = bubble.offsetHeight;
        const bubbleLeft = sidePlacement
          ? pageWidth + 20
          : clamp(anchorLeft, 8, Math.max(8, pageWidth - bubbleWidth - 8));
        const belowTop = anchorBottom + 12;
        const aboveTop = anchorTop - bubbleHeight - 12;
        const occupied = [...pageState.annotationLayer.querySelectorAll('.pdf-annotation-comment')]
          .filter((otherBubble) => otherBubble !== bubble)
          .map((otherBubble) => ({
            left: otherBubble.offsetLeft,
            top: otherBubble.offsetTop,
            right: otherBubble.offsetLeft + otherBubble.offsetWidth,
            bottom: otherBubble.offsetTop + otherBubble.offsetHeight,
          }));
        const preferredTop = sidePlacement ? anchorCenter - bubbleHeight / 2 : belowTop;
        const candidates = [preferredTop, belowTop, aboveTop, ...occupied.map((box) => box.bottom + 12)];
        const noOverlap = (candidateTop) => occupied.every((box) =>
          bubbleLeft + bubbleWidth + 8 <= box.left
          || bubbleLeft >= box.right + 8
          || candidateTop + bubbleHeight + 8 <= box.top
          || candidateTop >= box.bottom + 8
        );
        let bubbleTop = candidates.find((candidateTop) =>
          candidateTop >= 8
          && candidateTop + bubbleHeight <= pageHeight - 8
          && noOverlap(candidateTop)
        );
        if (bubbleTop === undefined) {
          bubbleTop = candidates.find((candidateTop) => candidateTop >= 8 && noOverlap(candidateTop));
        }
        if (bubbleTop === undefined) {
          bubbleTop = Math.max(pageHeight + 12, ...occupied.map((box) => box.bottom + 12), belowTop);
        }
        const showAbove = !sidePlacement && bubbleTop + bubbleHeight <= anchorTop;
        bubble.style.left = `${bubbleLeft}px`;
        bubble.style.top = `${bubbleTop}px`;
        if (sidePlacement) {
          bubble.style.setProperty('--annotation-pointer-y', `${clamp(anchorCenter - bubbleTop, 16, Math.max(16, bubbleHeight - 16))}px`);
        } else {
          bubble.style.setProperty('--annotation-pointer-x', `${clamp(anchorLeft - bubbleLeft, 16, Math.max(16, bubbleWidth - 32))}px`);
        }
        bubble.classList.toggle('is-above', showAbove);
      }
    }
  }

  function syncCommentLayout() {
    pageHost.classList.toggle('has-comment-gutter', commentGutterWidth() > 0);
    for (const pageState of pages.values()) {
      const oldScale = pageState.renderedScale;
      resizePageBox(pageState);
      const newScale = pageScale(pageState.pageInfo);
      if (oldScale && Math.abs(oldScale - newScale) >= 0.01) {
        pageState.renderedScale = 0;
        renderPage(pageState);
      }
    }
    updateZoomLabel();
  }

  function redrawAnnotations() {
    for (const pageState of pages.values()) paintAnnotations(pageState);
  }

  function collectAreaAnchor(pageState, x, y, width, height) {
    const pageBounds = pageState.pageElement.getBoundingClientRect();
    const rect = normalizedRect({ left: x, top: y, right: x + width, bottom: y + height }, pageState.pageElement);
    if (!rect) return null;
    return [{ page: pageState.pageNumber, rects: [rect] }];
  }

  function setAreaMode(enabled) {
    areaMode = enabled;
    areaButton?.classList.toggle('is-active', enabled);
    pageHost.classList.toggle('is-marking-area', enabled);
    if (enabled) {
      pending.anchors = [];
      pending.selectedText = '';
      if (addButton) addButton.disabled = true;
      announce('Drag over a document area to mark it, then add a comment.');
    } else {
      announce('Area marking stopped.');
    }
  }

  async function requestAnnotations() {
    const response = await fetch(app.dataset.annotationsUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !Array.isArray(payload.annotations)) throw new Error(payload.error || 'Document annotations could not be loaded.');
    canEdit = payload.can_edit === true;
    app.dataset.canEdit = canEdit ? 'true' : 'false';
    annotations.clear();
    for (const annotation of payload.annotations || []) annotations.set(Number(annotation.id), annotation);
    syncAnnotationDecisionControls();
    syncCommentLayout();
    redrawAnnotations();
  }

  async function submitAnnotation(action) {
    const formData = new FormData();
    formData.set('csrf_token', app.dataset.csrfToken);
    formData.set('annotation_action', action);
    if (activeAnnotation) formData.set('annotation_id', String(activeAnnotation.id));
    if (action !== 'delete') {
      formData.set('selected_text', activeAnnotation?.selected_text || pending.selectedText);
      formData.set('anchors', JSON.stringify(activeAnnotation?.anchors || pending.anchors));
      formData.set('comment', commentField.value.trim());
      const selectedColor = colorPicker?.querySelector('input[name="highlight_color"]:checked')?.value || activeAnnotation?.color || 'yellow';
      formData.set('highlight_color', annotationColors.includes(selectedColor) ? selectedColor : 'yellow');
    }

    const response = await fetch(app.dataset.annotationPostUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: formData,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !Array.isArray(payload.annotations)) throw new Error(payload.error || 'The annotation could not be saved.');
    if (action === 'update' && activeAnnotation) {
      const dismissedPrefix = `${activeAnnotation.id}:`;
      for (const key of dismissedComments) {
        if (key.startsWith(dismissedPrefix)) dismissedComments.delete(key);
      }
    }
    annotations.clear();
    for (const annotation of payload.annotations || []) annotations.set(Number(annotation.id), annotation);
    syncAnnotationDecisionControls();
    syncCommentLayout();
    redrawAnnotations();
    activeAnnotation = null;
    pending.anchors = [];
    pending.selectedText = '';
    if (addButton) addButton.disabled = true;
    window.getSelection()?.removeAllRanges();
    dialogClose();
    announce(action === 'delete' ? 'Draft highlight removed.' : 'Draft comment saved. It will be visible to the researchers after the review decision.');
  }

  async function initialize() {
    try {
      const moduleUrl = assetUrl('build/pdf.min.mjs');
      pdfjsLib = await import(moduleUrl);
      pdfjsLib.GlobalWorkerOptions.workerSrc = assetUrl('build/pdf.worker.min.mjs');
      const loadingTask = pdfjsLib.getDocument({
        url: app.dataset.pdfUrl,
        cMapUrl: assetUrl('cmaps/'),
        cMapPacked: true,
        iccUrl: assetUrl('iccs/'),
        standardFontDataUrl: assetUrl('standard_fonts/'),
        wasmUrl: assetUrl('wasm/'),
        isEvalSupported: false,
      });
      pdfDocument = await loadingTask.promise;
      pageCountView.textContent = `of ${pdfDocument.numPages}`;
      pageNumberField.max = String(pdfDocument.numPages);

      for (let number = 1; number <= pdfDocument.numPages; number++) {
        const page = await pdfDocument.getPage(number);
        const viewport = page.getViewport({ scale: 1 });
        const textContent = await page.getTextContent();
        createPageElement(number, {
          baseWidth: viewport.width,
          baseHeight: viewport.height,
          width: 0,
          height: 0,
          textItems: textContent.items.map((item) => typeof item.str === 'string' ? item.str : ''),
        });
        page.cleanup();
      }
      app.querySelector('[data-fit-width]').setAttribute('aria-pressed', 'true');
      app.querySelector('[data-fit-page]').setAttribute('aria-pressed', 'false');
      updateZoomLabel();

      let annotationsAvailable = true;
      try {
        await requestAnnotations();
      } catch (error) {
        annotationsAvailable = false;
        canEdit = false;
        app.dataset.canEdit = 'false';
        if (addButton) addButton.disabled = true;
        if (areaButton) areaButton.disabled = true;
      }
      selectionObserver = new IntersectionObserver((entries) => {
        let visiblePage = null;
        const hostBounds = pageHost.getBoundingClientRect();
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          const pageState = pages.get(Number(entry.target.dataset.pageNumber));
          if (pageState) renderPage(pageState);
          const pageBounds = entry.target.getBoundingClientRect();
          const visibleHeight = Math.max(0, Math.min(pageBounds.bottom, hostBounds.bottom) - Math.max(pageBounds.top, hostBounds.top));
          const visibleRatio = pageBounds.height > 0 ? visibleHeight / pageBounds.height : 0;
          if (visibleRatio > 0 && (!visiblePage || visibleRatio > visiblePage.ratio)) {
            visiblePage = { ratio: visibleRatio, page: Number(entry.target.dataset.pageNumber) };
          }
        }
        if (visiblePage) pageNumberField.value = String(visiblePage.page);
      }, { root: pageHost, rootMargin: '700px 0px' });
      for (const pageState of pages.values()) selectionObserver.observe(pageState.pageElement);
      announce(annotationsAvailable
        ? `${pdfDocument.numPages} page${pdfDocument.numPages === 1 ? '' : 's'} loaded. Select text to add a comment.`
        : 'PDF loaded, but annotations could not be loaded. Refresh the page or contact the research office.', !annotationsAvailable);
    } catch (error) {
      announce(error.message || 'The PDF could not be loaded.', true);
    }
  }

  pageHost.addEventListener('mouseup', refreshSelection);
  pageHost.addEventListener('keyup', refreshSelection);
  pageHost.addEventListener('pointerdown', (event) => {
    if (!areaMode || event.target.closest('.pdf-annotation-mark')) return;
    const pageElement = event.target.closest('.pdf-review-page');
    if (!pageElement) return;
    const pageState = pages.get(Number(pageElement.dataset.pageNumber));
    if (!pageState) return;
    event.preventDefault();
    areaDrag = { pageState, startX: event.clientX, startY: event.clientY, preview: document.createElement('div') };
    areaDrag.preview.className = 'pdf-area-selection-preview';
    pageState.annotationLayer.append(areaDrag.preview);
    pageHost.setPointerCapture?.(event.pointerId);
  });
  pageHost.addEventListener('pointermove', (event) => {
    if (!areaDrag) return;
    const bounds = areaDrag.pageState.pageElement.getBoundingClientRect();
    const left = clamp(Math.min(event.clientX, areaDrag.startX) - bounds.left, 0, bounds.width);
    const top = clamp(Math.min(event.clientY, areaDrag.startY) - bounds.top, 0, bounds.height);
    const right = clamp(Math.max(event.clientX, areaDrag.startX) - bounds.left, 0, bounds.width);
    const bottom = clamp(Math.max(event.clientY, areaDrag.startY) - bounds.top, 0, bounds.height);
    Object.assign(areaDrag.preview.style, {
      left: `${left}px`, top: `${top}px`, width: `${right - left}px`, height: `${bottom - top}px`,
    });
  });
  pageHost.addEventListener('pointerup', (event) => {
    if (!areaDrag) return;
    const bounds = areaDrag.pageState.pageElement.getBoundingClientRect();
    const left = clamp(Math.min(event.clientX, areaDrag.startX), bounds.left, bounds.right);
    const top = clamp(Math.min(event.clientY, areaDrag.startY), bounds.top, bounds.bottom);
    const right = clamp(Math.max(event.clientX, areaDrag.startX), bounds.left, bounds.right);
    const bottom = clamp(Math.max(event.clientY, areaDrag.startY), bounds.top, bounds.bottom);
    areaDrag.preview.remove();
    if (right - left >= 6 && bottom - top >= 6) {
      pending.anchors = collectAreaAnchor(areaDrag.pageState, left, top, right - left, bottom - top);
      pending.selectedText = '';
      openAnnotationDialog();
    }
    areaDrag = null;
    setAreaMode(false);
  });

  addButton?.addEventListener('click', () => {
    if (pending.anchors.length) openAnnotationDialog();
  });
  areaButton?.addEventListener('click', () => setAreaMode(!areaMode));
  app.querySelector('[data-annotation-cancel]').addEventListener('click', dialogClose);
  app.querySelector('[data-annotation-save]').addEventListener('click', async () => {
    if (!commentField.value.trim()) {
      errorView.textContent = 'Enter a comment before saving.';
      errorView.hidden = false;
      commentField.focus();
      return;
    }
    const action = activeAnnotation ? 'update' : 'create';
    try {
      await submitAnnotation(action);
    } catch (error) {
      errorView.textContent = error.message;
      errorView.hidden = false;
    }
  });
  deleteButton.addEventListener('click', async () => {
    if (!activeAnnotation || !window.confirm('Remove this draft highlight and comment?')) return;
    try {
      await submitAnnotation('delete');
    } catch (error) {
      errorView.textContent = error.message;
      errorView.hidden = false;
    }
  });

  app.querySelector('[data-page-previous]').addEventListener('click', () => {
    const target = Math.max(1, Number(pageNumberField.value || 1) - 1);
    pages.get(target)?.pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    pageNumberField.value = String(target);
  });
  app.querySelector('[data-page-next]').addEventListener('click', () => {
    const target = Math.min(pdfDocument?.numPages || 1, Number(pageNumberField.value || 1) + 1);
    pages.get(target)?.pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    pageNumberField.value = String(target);
  });
  pageNumberField.addEventListener('change', () => {
    const target = clamp(Number(pageNumberField.value) || 1, 1, pdfDocument?.numPages || 1);
    pageNumberField.value = String(target);
    pages.get(target)?.pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  function rerenderPages() {
    for (const pageState of pages.values()) {
      const wasRendered = pageState.renderedScale > 0;
      resizePageBox(pageState);
      if (wasRendered) {
        pageState.renderedScale = 0;
        renderPage(pageState);
      }
    }
    updateZoomLabel();
  }

  function setFitMode(mode) {
    fitMode = mode;
    zoom = 0;
    app.querySelector('[data-fit-width]').setAttribute('aria-pressed', String(mode === 'width'));
    app.querySelector('[data-fit-page]').setAttribute('aria-pressed', String(mode === 'page'));
    rerenderPages();
  }

  function changeZoom(delta) {
    const fitScale = pages.size ? pageScale(pages.values().next().value.pageInfo) : 1;
    zoom = clamp((zoom || fitScale) + delta, 0.5, 4);
    app.querySelector('[data-fit-width]').setAttribute('aria-pressed', 'false');
    app.querySelector('[data-fit-page]').setAttribute('aria-pressed', 'false');
    rerenderPages();
  }
  app.querySelector('[data-zoom-in]').addEventListener('click', () => changeZoom(0.15));
  app.querySelector('[data-zoom-out]').addEventListener('click', () => changeZoom(-0.15));
  app.querySelector('[data-fit-width]').addEventListener('click', () => setFitMode('width'));
  app.querySelector('[data-fit-page]').addEventListener('click', () => setFitMode('page'));
  app.querySelector('[data-zoom-reset]').addEventListener('click', () => setFitMode('width'));
  app.querySelector('[data-rotate]').addEventListener('click', () => {
    rotation = (rotation + 90) % 360;
    rerenderPages();
    announce(`Pages rotated ${rotation} degrees clockwise.`);
  });
  const fullscreenButton = app.querySelector('[data-fullscreen]');
  fullscreenButton.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement === app) {
        await document.exitFullscreen();
      } else {
        await app.requestFullscreen();
      }
    } catch (error) {
      announce('Fullscreen is unavailable in this browser.', true);
    }
  });
  document.addEventListener('fullscreenchange', () => {
    const isFullscreen = document.fullscreenElement === app;
    fullscreenButton.textContent = isFullscreen ? 'Exit fullscreen' : 'Fullscreen';
    fullscreenButton.setAttribute('aria-label', isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen');
    requestAnimationFrame(() => rerenderPages());
  });
  app.querySelector('[data-print]').addEventListener('click', () => window.print());

  function paintSearchMatches(pageState) {
    const spans = pageState.textLayer.querySelectorAll('span');
    const matchedIndices = new Set();
    searchMatches.forEach((match, matchIndex) => {
      if (match.page !== pageState.pageNumber) return;
      match.itemIndices.forEach((index) => matchedIndices.add(index));
      if (matchIndex === activeSearchMatch) match.itemIndices.forEach((index) => matchedIndices.add(index));
    });
    spans.forEach((span, index) => {
      const matching = matchedIndices.has(index);
      span.classList.toggle('pdf-search-match', matching);
      span.classList.toggle('is-current-match', matching && searchMatches[activeSearchMatch]?.page === pageState.pageNumber
        && searchMatches[activeSearchMatch]?.itemIndices.includes(index));
    });
  }

  function runSearch(direction = 1, navigateToMatch = true) {
    const query = searchInput.value.trim().toLocaleLowerCase();
    searchMatches.length = 0;
    activeSearchMatch = -1;
    for (const pageState of pages.values()) {
      const items = pageState.textItems || [];
      const text = items.join(' ').toLocaleLowerCase();
      let offset = 0;
      while (query && (offset = text.indexOf(query, offset)) !== -1) {
        let start = offset;
        let end = offset + query.length;
        const indices = [];
        let cursor = 0;
        items.forEach((item, index) => {
          const itemStart = cursor;
          const itemEnd = itemStart + item.length;
          if (start < itemEnd && end > itemStart) indices.push(index);
          cursor = itemEnd + 1;
        });
        if (indices.length) searchMatches.push({ page: pageState.pageNumber, itemIndices: [...new Set(indices)] });
        offset += Math.max(1, query.length);
      }
    }
    if (!searchMatches.length) {
      searchCount.textContent = query ? 'No matches' : 'Enter text to search';
      for (const pageState of pages.values()) paintSearchMatches(pageState);
      if (navigateToMatch) announce(query ? 'No matching text found.' : 'Search cleared.');
      return;
    }
    if (navigateToMatch) {
      activeSearchMatch = direction > 0 ? 0 : searchMatches.length - 1;
      showSearchMatch();
    } else {
      activeSearchMatch = -1;
      searchCount.textContent = `${searchMatches.length} match${searchMatches.length === 1 ? '' : 'es'} found`;
      for (const pageState of pages.values()) paintSearchMatches(pageState);
    }
  }

  function showSearchMatch() {
    const match = searchMatches[activeSearchMatch];
    if (!match) return;
    for (const pageState of pages.values()) paintSearchMatches(pageState);
    const pageState = pages.get(match.page);
    pageState?.pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    searchCount.textContent = `${activeSearchMatch + 1} of ${searchMatches.length}`;
    announce(`Match ${activeSearchMatch + 1} of ${searchMatches.length}, page ${match.page}.`);
  }

  function flushPendingSearch(direction) {
    if (!searchInputTimer) return false;
    clearTimeout(searchInputTimer);
    searchInputTimer = null;
    runSearch(direction);
    return true;
  }

  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      if (flushPendingSearch(event.shiftKey ? -1 : 1)) return;
      if (searchInput.value.trim().toLocaleLowerCase() !== searchMatches.query) {
        searchMatches.query = searchInput.value.trim().toLocaleLowerCase();
        runSearch(event.shiftKey ? -1 : 1);
      } else if (searchMatches.length) {
        activeSearchMatch = activeSearchMatch < 0
          ? (event.shiftKey ? searchMatches.length - 1 : 0)
          : (activeSearchMatch + (event.shiftKey ? searchMatches.length - 1 : 1)) % searchMatches.length;
        showSearchMatch();
      }
    }
  });
  app.querySelector('[data-search-next]').addEventListener('click', () => {
    if (flushPendingSearch(1)) return;
    if (searchMatches.query !== searchInput.value.trim().toLocaleLowerCase()) {
      searchMatches.query = searchInput.value.trim().toLocaleLowerCase();
      runSearch(1);
      return;
    }
    if (searchMatches.length) {
      activeSearchMatch = activeSearchMatch < 0 ? 0 : (activeSearchMatch + 1) % searchMatches.length;
      showSearchMatch();
    }
  });
  app.querySelector('[data-search-previous]').addEventListener('click', () => {
    if (flushPendingSearch(-1)) return;
    if (searchMatches.query !== searchInput.value.trim().toLocaleLowerCase()) {
      searchMatches.query = searchInput.value.trim().toLocaleLowerCase();
      runSearch(-1);
      return;
    }
    if (searchMatches.length) {
      activeSearchMatch = activeSearchMatch < 0
        ? searchMatches.length - 1
        : (activeSearchMatch - 1 + searchMatches.length) % searchMatches.length;
      showSearchMatch();
    }
  });
  searchInput.addEventListener('input', () => {
    clearTimeout(searchInputTimer);
    searchMatches.query = searchInput.value.trim().toLocaleLowerCase();
    if (!searchInput.value.trim()) {
      searchMatches.length = 0;
      activeSearchMatch = -1;
      searchCount.textContent = 'Enter text to search';
      for (const pageState of pages.values()) paintSearchMatches(pageState);
      return;
    }
    searchCount.textContent = 'Searching…';
    searchInputTimer = window.setTimeout(() => {
      searchInputTimer = null;
      runSearch(1, false);
    }, 120);
  });

  const resizeObserver = new ResizeObserver(() => {
    if (zoom > 0) return;
    if (fitMode === 'page') rerenderPages();
    else syncCommentLayout();
  });
  resizeObserver.observe(pageHost);
  initialize();
})();
