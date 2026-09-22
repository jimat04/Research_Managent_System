(function () {
  'use strict';

  var originalDisabled = new WeakMap();
  var dirtyForms = new Set();

  function submitControls(form) {
    return Array.prototype.filter.call(
      document.querySelectorAll('button[type="submit"], input[type="submit"]'),
      function (control) { return control.form === form; }
    );
  }

  function syncSubmitState(form) {
    if (!form) return;
    var hasInvalidFile = Boolean(form.querySelector('input[type="file"][data-file-invalid="true"]'));
    submitControls(form).forEach(function (control) {
      if (!originalDisabled.has(control)) originalDisabled.set(control, control.disabled);
      control.disabled = hasInvalidFile || originalDisabled.get(control);
    });
  }

  function fileConfig(input) {
    var uploader = input.closest('.rms-file-uploader');
    var accept = input.dataset.accept || (uploader && uploader.dataset.accept) || input.getAttribute('accept') || '';
    var maxBytes = Number(input.dataset.maxBytes || 0);

    if (!maxBytes && uploader) {
      var maxKilobytes = Number(uploader.dataset.maxSize || 0);
      if (Number.isFinite(maxKilobytes) && maxKilobytes > 0) maxBytes = maxKilobytes * 1024;
    }

    if (accept) input.dataset.accept = accept;
    if (Number.isFinite(maxBytes) && maxBytes > 0) input.dataset.maxBytes = String(maxBytes);

    return { accept: accept, maxBytes: maxBytes };
  }

  function errorElement(input) {
    var existingId = input.dataset.appFileErrorId;
    if (existingId) return document.getElementById(existingId);

    var error = document.createElement('small');
    error.id = 'app-file-error-' + Math.random().toString(36).slice(2);
    error.setAttribute('role', 'alert');
    error.style.display = 'none';
    error.style.color = '#dc2626';
    error.style.fontSize = '12px';
    error.style.marginTop = '6px';

    var uploader = input.closest('.rms-file-uploader');
    var anchor = uploader || input;
    anchor.parentNode.insertBefore(error, anchor.nextSibling);
    input.dataset.appFileErrorId = error.id;
    input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
    return error;
  }

  function resetUploaderDisplay(input) {
    var uploader = input.closest('.rms-file-uploader');
    if (!uploader) return;
    var list = uploader.querySelector('.rms-uploader-file-list');
    var dropzone = uploader.querySelector('.rms-uploader-dropzone');
    if (list) {
      list.classList.remove('active');
      list.innerHTML = '';
    }
    if (dropzone) dropzone.style.display = '';
  }

  function showFileError(input, message) {
    var error = errorElement(input);
    error.textContent = message;
    error.style.display = 'block';
    input.dataset.fileInvalid = 'true';
    input.setAttribute('aria-invalid', 'true');
    input.value = '';
    resetUploaderDisplay(input);
    syncSubmitState(input.form);
  }

  function clearFileError(input) {
    var error = errorElement(input);
    error.textContent = '';
    error.style.display = 'none';
    delete input.dataset.fileInvalid;
    input.removeAttribute('aria-invalid');
    syncSubmitState(input.form);
  }

  function allowedExtension(fileName, accept) {
    var extensions = accept.split(',').map(function (value) { return value.trim().toLowerCase(); })
      .filter(function (value) { return value.charAt(0) === '.'; });
    if (!extensions.length) return true;
    var lowerName = fileName.toLowerCase();
    return extensions.some(function (extension) { return lowerName.endsWith(extension); });
  }

  function validateFileInput(input) {
    var config = fileConfig(input);
    var file = input.files && input.files[0];
    if (!file) {
      clearFileError(input);
      return;
    }

    if (config.maxBytes > 0 && file.size > config.maxBytes) {
      showFileError(input, 'File is too large. Maximum size is ' + (config.maxBytes / (1024 * 1024)).toFixed(1) + ' MB.');
      return;
    }

    if (config.accept && !allowedExtension(file.name, config.accept)) {
      showFileError(input, 'File type not allowed. Accepted: ' + config.accept.split(',').join(', ').toUpperCase() + '.');
      return;
    }

    clearFileError(input);
  }

  document.querySelectorAll('input[type="file"]').forEach(function (input) {
    fileConfig(input);
    input.addEventListener('change', function () { validateFileInput(input); });
  });

  document.querySelectorAll('form.form-guard').forEach(function (form) {
    submitControls(form).forEach(function (control) { originalDisabled.set(control, control.disabled); });

    function markDirty(event) {
      if (event.target.form === form) dirtyForms.add(form);
    }

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function () { dirtyForms.delete(form); });
  });

  window.addEventListener('beforeunload', function (event) {
    if (!dirtyForms.size) return;
    event.preventDefault();
    event.returnValue = '';
  });
}());
