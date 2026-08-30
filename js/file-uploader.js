/**
 * RMS File Uploader Component
 * Premium file upload interface with drag-and-drop, validation, and progress tracking
 *
 * Vanilla JavaScript implementation matching RMS design language
 * No external dependencies required
 */

(function() {
  'use strict';

  /**
   * Initialize all file uploaders on the page
   */
  function initFileUploaders() {
    const uploaders = document.querySelectorAll('.rms-file-uploader');
    uploaders.forEach(uploader => new RmsFileUploader(uploader));
  }

  /**
   * RmsFileUploader class
   * Handles a single file uploader instance
   */
  class RmsFileUploader {
    constructor(element) {
      this.element = element;
      this.dropzone = element.querySelector('.rms-uploader-dropzone');
      this.input = element.querySelector('.rms-uploader-input');
      this.fileList = element.querySelector('.rms-uploader-file-list');
      this.errorContainer = element.querySelector('.rms-uploader-error');
      this.errorMessage = element.querySelector('.rms-uploader-error-message');
      this.liveRegion = element.querySelector('[aria-live]');

      // Read configuration from data attributes
      this.config = {
        accept: this.element.dataset.accept || '.pdf,.doc,.docx',
        acceptMultiple: this.element.dataset.multiple === 'true',
        maxSize: parseInt(this.element.dataset.maxSize || '10000', 10) * 1024, // Convert KB to bytes
        required: this.element.dataset.required === 'true',
        disabled: this.element.dataset.disabled === 'true',
        folderTarget: this.element.dataset.folderTarget || 'proposals',
        inputName: this.element.dataset.inputName || 'uploaded_file',
        projectId: this.element.dataset.projectId || null,
        chapterId: this.element.dataset.chapterId || null,
        uploadEndpoint: this.element.dataset.uploadEndpoint || '../public/api/upload.php'
      };

      // State
      this.files = [];
      this.uploadedFiles = [];

      // Initialize
      this.init();
    }

    init() {
      if (this.config.disabled) {
        this.dropzone.classList.add('disabled');
        return;
      }

      // Set up event listeners
      this.setupEventListeners();

      // Set input attributes
      if (this.config.acceptMultiple) {
        this.input.setAttribute('multiple', 'multiple');
      }
      this.input.setAttribute('accept', this.config.accept);
    }

    setupEventListeners() {
      // Click to open file picker
      this.dropzone.addEventListener('click', () => this.input.click());

      // Keyboard accessibility
      this.dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.input.click();
        }
      });

      // File input change
      this.input.addEventListener('change', (e) => this.handleFileSelect(e));

      // Drag and drop
      this.dropzone.addEventListener('dragover', (e) => this.handleDragOver(e));
      this.dropzone.addEventListener('dragleave', (e) => this.handleDragLeave(e));
      this.dropzone.addEventListener('drop', (e) => this.handleDrop(e));
    }

    handleDragOver(e) {
      e.preventDefault();
      e.stopPropagation();
      this.dropzone.classList.add('drag-over');
    }

    handleDragLeave(e) {
      e.preventDefault();
      e.stopPropagation();
      this.dropzone.classList.remove('drag-over');
    }

    handleDrop(e) {
      e.preventDefault();
      e.stopPropagation();
      this.dropzone.classList.remove('drag-over');

      const files = e.dataTransfer.files;
      if (files.length > 0) {
        this.processFiles(files);
      }
    }

    handleFileSelect(e) {
      const files = e.target.files;
      if (files.length > 0) {
        this.processFiles(files);
      }
    }

    processFiles(fileList) {
      this.clearError();

      // Convert FileList to array
      const filesArray = Array.from(fileList);

      // If not multiple, only take first file
      const filesToProcess = this.config.acceptMultiple ? filesArray : [filesArray[0]];

      // Validate each file
      const validFiles = [];
      for (const file of filesToProcess) {
        const validation = this.validateFile(file);
        if (validation.valid) {
          validFiles.push(file);
        } else {
          this.showError(validation.error);
          return; // Stop on first error
        }
      }

      if (validFiles.length > 0) {
        this.files = validFiles;
        this.renderFileList();
        this.announceToScreenReader(`${validFiles.length} file(s) selected and ready to upload`);
      }
    }

    validateFile(file) {
      // Check file size
      if (file.size > this.config.maxSize) {
        const maxSizeMB = (this.config.maxSize / (1024 * 1024)).toFixed(1);
        return {
          valid: false,
          error: `File is too large. Maximum size is ${maxSizeMB} MB.`
        };
      }

      // Check file type
      const acceptedTypes = this.config.accept.split(',').map(t => t.trim().toLowerCase());
      const fileName = file.name.toLowerCase();
      const fileExtension = '.' + fileName.split('.').pop();

      const isAccepted = acceptedTypes.some(type => {
        if (type.startsWith('.')) {
          return fileName.endsWith(type);
        }
        // MIME type check
        return file.type === type;
      });

      if (!isAccepted) {
        const formatList = acceptedTypes.join(', ').toUpperCase();
        return {
          valid: false,
          error: `File type not allowed. Accepted formats: ${formatList}`
        };
      }

      return { valid: true };
    }

    renderFileList() {
      // Hide dropzone, show file list
      this.dropzone.style.display = 'none';
      this.fileList.classList.add('active');
      this.fileList.innerHTML = '';

      this.files.forEach((file, index) => {
        const fileItem = this.createFileItem(file, index);
        this.fileList.appendChild(fileItem);
      });
    }

    createFileItem(file, index) {
      const fileItem = document.createElement('div');
      fileItem.className = 'rms-uploader-file-item';
      fileItem.dataset.index = index;

      const icon = this.getFileIcon(file.name);
      const size = this.formatFileSize(file.size);

      fileItem.innerHTML = `
        <div class="rms-uploader-file-icon">${icon}</div>
        <div class="rms-uploader-file-details">
          <div class="rms-uploader-file-name" title="${this.escapeHtml(file.name)}">${this.escapeHtml(file.name)}</div>
          <div class="rms-uploader-file-meta">
            <span class="rms-uploader-file-size">${size}</span>
            <span class="rms-uploader-file-status ready">✓ Ready</span>
          </div>
          <div class="rms-uploader-progress">
            <div class="rms-uploader-progress-fill" style="width: 0%;"></div>
          </div>
        </div>
        <div class="rms-uploader-file-actions">
          <button type="button" class="rms-uploader-btn-replace" data-action="replace" aria-label="Replace file">
            Replace
          </button>
          <button type="button" class="rms-uploader-btn-remove" data-action="remove" aria-label="Remove file">
            ✕
          </button>
        </div>
      `;

      // Event listeners for actions
      fileItem.querySelector('[data-action="remove"]').addEventListener('click', () => this.removeFile(index));
      fileItem.querySelector('[data-action="replace"]').addEventListener('click', () => this.replaceFile(index));

      return fileItem;
    }

    removeFile(index) {
      this.files.splice(index, 1);

      if (this.files.length === 0) {
        // No files left, show dropzone again
        this.fileList.classList.remove('active');
        this.dropzone.style.display = 'block';
        this.input.value = ''; // Clear input
        this.announceToScreenReader('File removed');
      } else {
        // Re-render file list
        this.renderFileList();
        this.announceToScreenReader('File removed');
      }
    }

    replaceFile(index) {
      // Trigger file picker for replacement
      const tempInput = document.createElement('input');
      tempInput.type = 'file';
      tempInput.accept = this.config.accept;
      tempInput.style.display = 'none';

      tempInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
          const newFile = e.target.files[0];
          const validation = this.validateFile(newFile);

          if (validation.valid) {
            this.files[index] = newFile;
            this.renderFileList();
            this.announceToScreenReader('File replaced');
          } else {
            this.showError(validation.error);
          }
        }
        document.body.removeChild(tempInput);
      });

      document.body.appendChild(tempInput);
      tempInput.click();
    }

    showError(message) {
      this.errorMessage.textContent = message;
      this.errorContainer.classList.add('active');
      this.announceToScreenReader(`Error: ${message}`);

      // Auto-hide after 5 seconds
      setTimeout(() => {
        this.clearError();
      }, 5000);
    }

    clearError() {
      this.errorContainer.classList.remove('active');
      this.errorMessage.textContent = '';
    }

    announceToScreenReader(message) {
      if (this.liveRegion) {
        this.liveRegion.textContent = message;
        // Clear after announcement
        setTimeout(() => {
          this.liveRegion.textContent = '';
        }, 1000);
      }
    }

    getFileIcon(filename) {
      const ext = filename.toLowerCase().split('.').pop();
      const iconMap = {
        pdf: '📕',
        doc: '📄',
        docx: '📄',
        ppt: '📊',
        pptx: '📊',
        png: '🖼',
        jpg: '🖼',
        jpeg: '🖼',
        gif: '🖼',
        default: '📁'
      };
      return iconMap[ext] || iconMap.default;
    }

    formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    /**
     * Get files for form submission
     * Returns the files array for manual form handling
     */
    getFiles() {
      return this.files;
    }

    /**
     * Check if uploader has files
     */
    hasFiles() {
      return this.files.length > 0;
    }

    /**
     * Upload files via AJAX (optional, for XHR progress tracking)
     * Returns a promise
     */
    async uploadFiles() {
      if (this.files.length === 0) {
        return { success: false, error: 'No files selected' };
      }

      const formData = new FormData();

      // Add files
      this.files.forEach((file, index) => {
        const fieldName = this.config.acceptMultiple ? `${this.config.inputName}[]` : this.config.inputName;
        formData.append(fieldName, file);
      });

      // Add metadata
      formData.append('folder_target', this.config.folderTarget);
      if (this.config.projectId) {
        formData.append('project_id', this.config.projectId);
      }
      if (this.config.chapterId) {
        formData.append('chapter_id', this.config.chapterId);
      }

      // Add CSRF token if available
      const csrfToken = document.querySelector('input[name="csrf_token"]');
      if (csrfToken) {
        formData.append('csrf_token', csrfToken.value);
      }

      try {
        const response = await this.uploadWithProgress(formData);
        return response;
      } catch (error) {
        this.showError('Upload failed. Please try again.');
        return { success: false, error: error.message };
      }
    }

    uploadWithProgress(formData) {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();

        // Progress tracking
        xhr.upload.addEventListener('progress', (e) => {
          if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            this.updateProgress(percentComplete);
          }
        });

        // Load complete
        xhr.addEventListener('load', () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              const response = JSON.parse(xhr.responseText);
              if (response.success) {
                this.updateProgress(100, true);
                this.announceToScreenReader('Upload complete');
                resolve(response);
              } else {
                reject(new Error(response.error || 'Upload failed'));
              }
            } catch (e) {
              reject(new Error('Invalid response from server'));
            }
          } else {
            reject(new Error(`Server error: ${xhr.status}`));
          }
        });

        // Error
        xhr.addEventListener('error', () => {
          reject(new Error('Network error'));
        });

        // Abort
        xhr.addEventListener('abort', () => {
          reject(new Error('Upload cancelled'));
        });

        // Send request
        xhr.open('POST', this.config.uploadEndpoint);
        xhr.send(formData);
      });
    }

    updateProgress(percent, complete = false) {
      const progressBars = this.fileList.querySelectorAll('.rms-uploader-progress');
      const progressFills = this.fileList.querySelectorAll('.rms-uploader-progress-fill');
      const statusElements = this.fileList.querySelectorAll('.rms-uploader-file-status');

      progressBars.forEach(bar => bar.classList.add('active'));

      progressFills.forEach(fill => {
        fill.style.width = `${percent}%`;
        if (complete) {
          fill.classList.add('complete');
        }
      });

      statusElements.forEach(status => {
        if (complete) {
          status.className = 'rms-uploader-file-status ready';
          status.innerHTML = '✓ Upload complete';
        } else if (percent > 0) {
          status.className = 'rms-uploader-file-status uploading';
          status.innerHTML = `⬆ Uploading... ${Math.round(percent)}%`;
        }
      });
    }
  }

  // Auto-initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFileUploaders);
  } else {
    initFileUploaders();
  }

  // Export for manual initialization if needed
  window.RmsFileUploader = RmsFileUploader;
  window.initFileUploaders = initFileUploaders;
})();
