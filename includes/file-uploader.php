<?php
/**
 * RMS File Uploader Component
 *
 * Server-side file upload handler and HTML renderer for the premium file uploader component.
 * Provides secure file validation, storage, and database logging.
 *
 * USAGE EXAMPLE:
 *
 * // In your page/form:
 * <?php require_once __DIR__ . '/file-uploader.php'; ?>
 *
 * <form method="POST" enctype="multipart/form-data">
 *   <?php echo csrfField(); ?>
 *
 *   <?php echo renderFileUploader([
 *     'inputName' => 'proposal_file',
 *     'accept' => '.pdf,.doc,.docx',
 *     'maxSize' => 10000,  // KB
 *     'folderTarget' => 'proposals',
 *     'label' => 'Upload Proposal',
 *     'description' => 'Drag & drop your proposal manuscript or click to browse',
 *     'required' => true
 *   ]); ?>
 *
 *   <button type="submit">Submit</button>
 * </form>
 *
 * // In your POST handler:
 * if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proposal_file'])) {
 *   $result = handleRmsUpload([
 *     'inputName' => 'proposal_file',
 *     'folderTarget' => 'proposals',
 *     'maxSize' => 10000,
 *     'accept' => ['.pdf', '.doc', '.docx'],
 *     'projectId' => $project_id,
 *     'type' => 'proposal'
 *   ], $_FILES, $conn);
 *
 *   if ($result['success']) {
 *     $upload_id = $result['upload_id'];
 *     // ... continue with your logic
 *   } else {
 *     $error = $result['error'];
 *   }
 * }
 */

// Ensure config is loaded for database connection and constants
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', __DIR__ . '/../uploads');
}

/**
 * Render the file uploader HTML component
 *
 * @param array $options Configuration options
 *   - inputName: string, form input name (default: 'uploaded_file')
 *   - accept: string, comma-separated file extensions (default: '.pdf,.doc,.docx')
 *   - maxSize: int, max size in KB (default: 10000)
 *   - folderTarget: string, subfolder under uploads/ (default: 'proposals')
 *   - label: string, label text (default: 'Upload File')
 *   - description: string, helper text (default: 'Drag & drop or click to browse')
 *   - allowedFormatsText: string, formats display text (default: auto-generated)
 *   - acceptMultiple: bool, allow multiple files (default: false)
 *   - required: bool, is field required (default: false)
 *   - disabled: bool, disable uploader (default: false)
 *   - projectId: int|null, associate with project (default: null)
 *   - chapterId: int|null, associate with chapter (default: null)
 *   - existingFileUrl: string|null, current file if editing (default: null)
 *
 * @return string HTML markup
 */
function renderFileUploader($options = []) {
    // Default options
    $defaults = [
        'inputName' => 'uploaded_file',
        'accept' => '.pdf,.doc,.docx',
        'maxSize' => 10000, // KB
        'folderTarget' => 'proposals',
        'label' => 'Upload File',
        'description' => 'Drag & drop or click to browse',
        'allowedFormatsText' => '',
        'acceptMultiple' => false,
        'required' => false,
        'disabled' => false,
        'projectId' => null,
        'chapterId' => null,
        'existingFileUrl' => null,
        'uploadEndpoint' => '../public/api/upload.php'
    ];

    $opts = array_merge($defaults, $options);

    // Auto-generate allowed formats text if not provided
    if (empty($opts['allowedFormatsText'])) {
        $extensions = explode(',', $opts['accept']);
        $extensions = array_map('trim', $extensions);
        $extensions = array_map('strtoupper', $extensions);
        $maxSizeMB = number_format($opts['maxSize'] / 1024, 1);
        $opts['allowedFormatsText'] = implode(', ', $extensions) . ' • Max ' . $maxSizeMB . ' MB';
    }

    // Build data attributes
    $dataAttrs = sprintf(
        'data-accept="%s" data-max-size="%d" data-folder-target="%s" data-input-name="%s" data-upload-endpoint="%s"',
        htmlspecialchars($opts['accept'], ENT_QUOTES, 'UTF-8'),
        (int) $opts['maxSize'],
        htmlspecialchars($opts['folderTarget'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($opts['inputName'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($opts['uploadEndpoint'], ENT_QUOTES, 'UTF-8')
    );

    if ($opts['acceptMultiple']) {
        $dataAttrs .= ' data-multiple="true"';
    }
    if ($opts['required']) {
        $dataAttrs .= ' data-required="true"';
    }
    if ($opts['disabled']) {
        $dataAttrs .= ' data-disabled="true"';
    }
    if ($opts['projectId']) {
        $dataAttrs .= sprintf(' data-project-id="%d"', (int) $opts['projectId']);
    }
    if ($opts['chapterId']) {
        $dataAttrs .= sprintf(' data-chapter-id="%d"', (int) $opts['chapterId']);
    }

    // Start output buffer
    ob_start();
    ?>
    <div class="rms-file-uploader" <?php echo $dataAttrs; ?>>
        <label class="rms-uploader-label">
            <?php echo htmlspecialchars($opts['label'], ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($opts['required']): ?>
                <span style="color: #dc2626;">*</span>
            <?php endif; ?>
        </label>
        <span class="rms-uploader-description">
            <?php echo htmlspecialchars($opts['description'], ENT_QUOTES, 'UTF-8'); ?>
        </span>

        <!-- Dropzone (idle state) -->
        <div class="rms-uploader-dropzone" tabindex="0" role="button" aria-label="Click or drag files to upload">
            <div class="rms-uploader-icon">📁</div>
            <div class="rms-uploader-prompt">Click to browse or drag files here</div>
            <div class="rms-uploader-hint"><?php echo htmlspecialchars($opts['description'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="rms-uploader-formats"><?php echo htmlspecialchars($opts['allowedFormatsText'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="rms-uploader-drag-prompt">Drop to upload</div>
        </div>

        <!-- File list (selected state) -->
        <div class="rms-uploader-file-list" role="list" aria-label="Selected files">
            <!-- Populated by JavaScript -->
        </div>

        <!-- Error display -->
        <div class="rms-uploader-error" role="alert">
            <div class="rms-uploader-error-icon">❌</div>
            <div class="rms-uploader-error-message"></div>
        </div>

        <!-- Hidden file input -->
        <input
            type="file"
            name="<?php echo htmlspecialchars($opts['inputName'], ENT_QUOTES, 'UTF-8'); ?><?php echo $opts['acceptMultiple'] ? '[]' : ''; ?>"
            class="rms-uploader-input"
            accept="<?php echo htmlspecialchars($opts['accept'], ENT_QUOTES, 'UTF-8'); ?>"
            <?php echo $opts['acceptMultiple'] ? 'multiple' : ''; ?>
            <?php echo $opts['required'] ? 'required' : ''; ?>
            <?php echo $opts['disabled'] ? 'disabled' : ''; ?>
            aria-label="<?php echo htmlspecialchars($opts['label'], ENT_QUOTES, 'UTF-8'); ?>"
        />

        <!-- Screen reader live region for announcements -->
        <div class="rms-uploader-sr-only" aria-live="polite" aria-atomic="true"></div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Handle file upload server-side
 *
 * @param array $options Upload configuration
 *   - inputName: string, form input name
 *   - folderTarget: string, subfolder under uploads/
 *   - maxSize: int, max size in KB
 *   - accept: array, allowed extensions (e.g., ['.pdf', '.doc'])
 *   - projectId: int|null, research project ID
 *   - chapterId: int|null, chapter ID
 *   - type: string, upload type ('proposal', 'chapter', 'defense', 'manuscript')
 * @param array $files The $_FILES superglobal
 * @param mysqli $conn Database connection
 * @return array ['success' => bool, 'error' => string|null, 'upload_id' => int|null, 'file_name' => string|null, 'file_path' => string|null]
 */
function handleRmsUpload($options, $files, $conn) {
    $globalConn = $GLOBALS['conn'] ?? null;
    if (!$conn) {
        $conn = $globalConn;
    }

    // Validate user is logged in
    if (!isset($_SESSION['user_id'])) {
        return ['success' => false, 'error' => 'User not authenticated'];
    }

    $user_id = (int) $_SESSION['user_id'];

    // Default options
    $defaults = [
        'inputName' => 'uploaded_file',
        'folderTarget' => 'proposals',
        'maxSize' => 10000, // KB
        'accept' => ['.pdf', '.doc', '.docx'],
        'projectId' => null,
        'chapterId' => null,
        'type' => 'proposal'
    ];

    $opts = array_merge($defaults, $options);

    // FIX #2: Folder whitelist - defense in depth
    $allowedFolders = ['proposals', 'chapters', 'defense', 'manuscripts'];
    if (!in_array($opts['folderTarget'], $allowedFolders, true)) {
        return ['success' => false, 'error' => 'Invalid upload destination'];
    }

    // Check if file was uploaded
    if (!isset($files[$opts['inputName']])) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }

    $file = $files[$opts['inputName']];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
        return ['success' => false, 'error' => $errorMsg];
    }

    $originalName = $file['name'];
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    $mimeType = $file['type'];

    // Validate file size
    $maxSizeBytes = $opts['maxSize'] * 1024;
    if ($fileSize > $maxSizeBytes) {
        $maxSizeMB = number_format($opts['maxSize'] / 1024, 1);
        return ['success' => false, 'error' => "File is too large. Maximum size is {$maxSizeMB} MB."];
    }

    // Validate file extension
    $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = array_map(function($ext) {
        return ltrim(strtolower($ext), '.');
    }, $opts['accept']);

    if (!in_array($fileExtension, $allowedExtensions, true)) {
        $allowedList = implode(', ', array_map('strtoupper', $allowedExtensions));
        return ['success' => false, 'error' => "File type not allowed. Accepted: {$allowedList}"];
    }

    // FIX #1: Server-side MIME detection using finfo
    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/octet-stream'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif']
    ];

    if (!function_exists('finfo_open')) {
        return ['success' => false, 'error' => 'Server MIME detection not available'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($tmpPath);

    if (isset($allowedMimes[$fileExtension])) {
        if (!in_array($detectedMime, $allowedMimes[$fileExtension], true)) {
            return ['success' => false, 'error' => "File appears to be corrupted or is not a valid " . strtoupper($fileExtension) . " file."];
        }
    }

    // Generate safe unique filename
    $safeName = 'rms_' . uniqid('', true) . '.' . $fileExtension;

    // Build target directory path
    $targetDir = UPLOADS_DIR . '/' . $opts['folderTarget'];
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }
    }

    // FIX #3: Path traversal protection - verify target stays within UPLOADS_DIR
    $realTargetDir = realpath($targetDir);
    $realUploadsDir = realpath(UPLOADS_DIR);
    if ($realTargetDir === false || strpos($realTargetDir, $realUploadsDir) !== 0) {
        return ['success' => false, 'error' => 'Invalid upload path'];
    }

    $targetPath = $targetDir . '/' . $safeName;

    // Move uploaded file
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file'];
    }

    // Set file permissions
    chmod($targetPath, 0644);

    // Store relative path for database
    $relativePath = 'uploads/' . $opts['folderTarget'] . '/' . $safeName;

    // Insert into uploads table (use detected MIME type from finfo)
    $stmt = $conn->prepare('
        INSERT INTO uploads
        (project_id, chapter_id, uploaded_by, type, original_name, file_name, file_path, file_size, mime_type, upload_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');

    $projectId = $opts['projectId'] !== null ? (int) $opts['projectId'] : null;
    $chapterId = $opts['chapterId'] ? (int) $opts['chapterId'] : null;
    $type = $opts['type'];

    $stmt->bind_param(
        'iiissssss',
        $projectId,
        $chapterId,
        $user_id,
        $type,
        $originalName,
        $safeName,
        $relativePath,
        $fileSize,
        $detectedMime
    );

    if (!$stmt->execute()) {
        // If database insert fails, clean up the file
        unlink($targetPath);
        return ['success' => false, 'error' => 'Failed to save upload record'];
    }

    $uploadId = $stmt->insert_id;

    // Log activity
    if (function_exists('logActivity')) {
        logActivity('Uploaded file: ' . $originalName, 'uploads');
    }

    return [
        'success' => true,
        'upload_id' => $uploadId,
        'file_name' => $safeName,
        'file_path' => $relativePath,
        'original_name' => $originalName,
        'size' => $fileSize
    ];
}

/**
 * Get uploaded file by ID
 *
 * @param int $uploadId
 * @param mysqli $conn
 * @return array|null File record or null
 */
function getRmsUpload($uploadId, $conn) {
    $stmt = $conn->prepare('SELECT * FROM uploads WHERE upload_id = ? LIMIT 1');
    $stmt->bind_param('i', $uploadId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Delete an uploaded file
 *
 * @param int $uploadId
 * @param mysqli $conn
 * @return bool Success
 */
function deleteRmsUpload($uploadId, $conn) {
    $upload = getRmsUpload($uploadId, $conn);
    if (!$upload) {
        return false;
    }

    // Delete physical file
    $filePath = __DIR__ . '/../' . $upload['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete database record
    $stmt = $conn->prepare('DELETE FROM uploads WHERE upload_id = ?');
    $stmt->bind_param('i', $uploadId);
    return $stmt->execute();
}
