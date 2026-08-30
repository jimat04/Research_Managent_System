# RMS File Uploader Component — Testing Guide

## Overview

Premium file uploader component for RMS matching the purple/lavender design language.

**Component Files:**
- `css/file-uploader.css` — Standalone stylesheet
- `js/file-uploader.js` — Vanilla JavaScript uploader logic
- `includes/file-uploader.php` — Server-side handler & HTML renderer
- `public/api/upload.php` — AJAX upload endpoint

**Demo Integration:** Student submit-research page (`pages/student/submit-research.php`)

---

## 7-Step Smoke Test

### 1. **Idle State**
- Open `http://localhost/rms/pages/student/submit-research.php`
- Log in as student: `jdelacruz@rms.edu.ph` / `Student@123`
- Scroll to "Proposal Document" section
- **Expected:** Soft lavender dropzone (#F8F4FF), dashed purple border (#D4C4F0), cloud icon 📁, "Click to browse or drag files here" prompt

### 2. **Hover State**
- Hover over the dropzone
- **Expected:** Border turns solid purple (#5B1EBC), background saturates to #F0EBFF, subtle scale up (1.01)

### 3. **Drag-Over State**
- Drag a PDF file over the dropzone (don't drop yet)
- **Expected:** 
  - Border becomes 3px solid purple
  - Background: rgba(91,30,188,0.08)
  - "Drop to upload" label appears
  - Box shadow: 0 8px 24px rgba(91,30,188,0.18)

### 4. **File Selected (Valid)**
- Drop the PDF or click to browse and select a valid PDF (< 10 MB)
- **Expected:**
  - Dropzone collapses/hides
  - File row appears showing:
    - 📕 icon for PDF (📄 for DOC/DOCX)
    - Filename (truncated with ellipsis if long)
    - File size (e.g., "2.4 MB")
    - "✓ Ready" status in green
    - "Replace" and "✕" buttons

### 5. **Wrong Type Error**
- Click "Replace" button
- Select a `.txt` or `.jpg` file
- **Expected:**
  - Dropzone shakes briefly (CSS animation)
  - Red error alert appears below: "File type not allowed. Accepted: PDF, DOC, DOCX."
  - Error auto-hides after 5 seconds

### 6. **File Too Large Error**
- Click "Replace" button
- Select a PDF > 10 MB
- **Expected:**
  - Shake animation
  - Red error: "File is too large. Maximum size is 10.0 MB."
  - Auto-hide after 5 seconds

### 7. **Successful Upload**
- Fill in the form:
  - **Title:** "Test Research Project"
  - **Category:** Select any
  - **Academic Year:** Select any
  - **Abstract:** "This is a test abstract for the file uploader component."
- Ensure a valid PDF is selected in the uploader
- Click "Submit for Review"
- **Expected:**
  - Form submits successfully
  - Redirects to research detail page or shows success message
  - In database:
    - New row in `research_projects` table
    - New row in `uploads` table with:
      - `project_id` = new project ID
      - `type` = 'proposal'
      - `file_path` = 'uploads/proposals/rms_XXXXXXXXXX.pdf'
      - `uploaded_by` = student's user_id
  - Physical file saved in `uploads/proposals/` directory

---

## Database Verification

After test #7, run these queries:

```sql
-- Check the uploaded file record
SELECT upload_id, project_id, type, original_name, file_name, file_size, uploaded_by, uploaded_at
FROM uploads
ORDER BY uploaded_at DESC
LIMIT 1;

-- Check the project record
SELECT project_id, title, status, created_by
FROM research_projects
ORDER BY created_at DESC
LIMIT 1;
```

---

## Accessibility Testing

### Keyboard Navigation
1. Tab to the dropzone → should show purple focus ring
2. Press Enter or Space → should open file picker
3. Tab to "Replace" button → focus ring visible
4. Tab to "✕ Remove" button → focus ring visible

### Screen Reader
- Use NVDA/JAWS and navigate to the dropzone
- **Expected announcements:**
  - "Upload Proposal" (label)
  - "Drag & drop or click to browse" (description)
  - "Click or drag files to upload" (ARIA label)
  - After selecting file: "1 file(s) selected and ready to upload" (live region)
  - On error: "Error: File type not allowed..." (live region)

### Reduced Motion
- Enable `prefers-reduced-motion` in OS/browser
- **Expected:** No shake animation, no scale transforms, instant transitions

---

## Component Options

### Usage in Other Pages

```php
<?php
require_once __DIR__ . '/../../includes/file-uploader.php';

echo renderFileUploader([
  'inputName' => 'chapter_file',           // Form input name
  'accept' => '.pdf',                      // Comma-separated extensions
  'maxSize' => 10000,                      // KB (10 MB)
  'folderTarget' => 'chapters',            // Subfolder under uploads/
  'label' => 'Upload Chapter',             // Label text
  'description' => 'Chapter 1: Introduction',  // Helper text
  'allowedFormatsText' => 'PDF only • Max 10 MB',  // Format display
  'required' => true,                      // Is field required?
  'acceptMultiple' => false,               // Allow multiple files?
  'projectId' => 123,                      // Associate with project (optional)
  'chapterId' => 45                        // Associate with chapter (optional)
]);
?>
```

### Server-Side Handling

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['chapter_file'])) {
  $result = handleRmsUpload([
    'inputName' => 'chapter_file',
    'folderTarget' => 'chapters',
    'maxSize' => 10000,
    'accept' => ['.pdf'],
    'projectId' => $project_id,
    'chapterId' => $chapter_id,
    'type' => 'chapter'
  ], $_FILES, $conn);

  if ($result['success']) {
    $upload_id = $result['upload_id'];
    // ... continue
  } else {
    $error = $result['error'];
  }
}
```

---

## Folder Targets & Defaults

| Folder Target | Accept | Max Size | Type |
|---------------|--------|----------|------|
| `proposals` | .pdf, .doc, .docx | 10 MB | proposal |
| `chapters` | .pdf | 10 MB | chapter |
| `defense` | .pdf, .ppt, .pptx | 20 MB | defense |
| `manuscripts` | .pdf, .doc, .docx | 20 MB | manuscript |

---

## Security Features

✅ **Whitelist validation** — Both MIME type AND extension checked  
✅ **Unique filenames** — `uniqid('rms_', true)` prevents overwrites  
✅ **CSRF protection** — Token required for AJAX uploads  
✅ **Session gating** — `requireLogin()` on upload endpoint  
✅ **File permissions** — `chmod 0644` on stored files  
✅ **Directory protection** — `uploads/.htaccess` blocks PHP execution  
✅ **Prepared statements** — SQL injection prevention  

---

## Known Limitations

- **AJAX progress bar** is wired up in JS but requires the endpoint to fully support streaming progress events. For v1, it works via regular form POST (no animated progress bar, but upload still succeeds).
- **Multiple file upload** is supported by the component but the demo (submit-research) uses single file mode.
- **Cancel upload** button is rendered but XHR abort is not fully wired (optional for v1).

---

## Troubleshooting

### Blank dropzone / Component not loading
- Check CSS is loaded: View Source → `<link rel="stylesheet" href="../../css/file-uploader.css">`
- Check JS is loaded: View Source → `<script src="../../js/file-uploader.js"></script>`
- Console errors? Open DevTools → Console tab

### File upload fails silently
- Check `uploads/proposals/` directory exists and is writable by web server
- Check PHP `upload_max_filesize` and `post_max_size` in `php.ini` (should be >= 10M)
- Check database `uploads` table exists (run `database/schema/rms_db.sql` if missing)

### "CSRF token invalid" error
- Ensure `<?php echo csrfField(); ?>` is inside the `<form>` tag
- Check session is active (login as a valid user)

### Files upload but don't appear in database
- Check `handleRmsUpload()` is being called with correct parameters
- Check database connection is active (`$conn` is valid)
- Enable error reporting: `ini_set('display_errors', 1);` at top of upload.php

---

## Browser Compatibility

- **Chrome/Edge 90+** ✅
- **Firefox 88+** ✅
- **Safari 14+** ✅ (drag-and-drop on macOS/iOS)
- **Mobile Safari** ✅ (tap to browse)
- **IE11** ❌ (no support; use modern browser)

---

## Design Tokens

```css
--uploader-primary: #5B1EBC       /* Primary purple */
--uploader-primary-light: #7B3FE4 /* Light purple */
--uploader-secondary: #0F6CBD     /* Blue accent */
--uploader-success: #22c55e       /* Green success */
--uploader-danger: #dc2626        /* Red error */
--uploader-bg-idle: #F8F4FF       /* Soft lavender */
--uploader-bg-hover: #F0EBFF      /* Saturated lavender */
--uploader-border-idle: #D4C4F0   /* Dashed border */
```

Icon emoji:
- 📁 Idle (cloud/folder)
- 📕 PDF
- 📄 DOC/DOCX
- 📊 PPT/PPTX
- 🖼 PNG/JPG/GIF
- ✓ Ready/Success
- ❌ Error

---

## Next Steps (v2 Enhancements)

- [ ] Wire up XHR upload with real-time progress bar animation
- [ ] Add "Cancel upload" abort functionality
- [ ] Support multiple file uploads in one dropzone (already wired in JS)
- [ ] Add image preview thumbnails for avatar/cover uploads
- [ ] Integrate with chapter upload flow (Chapter 1-5 submissions)
- [ ] Add existing file display with "Replace" / "Remove" options when editing

---

**Component Version:** 1.0  
**Last Updated:** 2026-08-30  
**Author:** RMS Development Team
