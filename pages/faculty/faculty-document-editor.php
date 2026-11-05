<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/faculty-shell.php';
require_once __DIR__ . '/../../includes/earist-document-editor.php';

$user = getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'faculty') { http_response_code(403); exit('Access denied.'); }
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
$error = '';
$session = null;
$canRecover = false;
$requestedId = null;
$requestedSelector = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) { http_response_code(400); $error = 'Your request expired. Return to Generated Documents and try again.'; }
    else {
        try {
            $requestedId = earistDocumentId($_POST['generated_document_id'] ?? null);
            $requestedSelector = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';
            $session = earistDocumentEditorCreateSession($conn, $requestedId, $requestedSelector);
        } catch (Throwable $exception) {
            http_response_code($exception instanceof DomainException ? ($exception->getCode() ?: 400) : 503);
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'The editor could not be started. Preview and download remain available.';
            $canRecover = $exception instanceof DomainException && (int) $exception->getCode() === 409
                && $error === 'Another editor is already active for this saved version.';
            error_log('EARIST editor start failed: ' . get_class($exception));
        }
    }
} else { http_response_code(405); $error = 'Start editing from a saved document preview.'; }
renderFacultyShell($user, 'faculty-generated-documents', 'Edit Generated DOCX', 'Edit a saved document version. Saving creates a new history version.', ['earist-document-editor.css']);
?>
<main class="frr-wrap<?= $session ? ' earist-docx-workspace' : '' ?>">
  <?php if ($error !== ''): ?>
    <?php if ($canRecover): ?>
      <section class="frr-panel earist-docx-recovery-panel" aria-labelledby="earist-recovery-title">
        <div class="earist-docx-recovery-heading">
          <span class="earist-docx-recovery-icon" aria-hidden="true">🔒</span>
          <div>
            <p class="earist-docx-recovery-eyebrow">EDITING SESSION</p>
            <h2 id="earist-recovery-title">This saved version is already open</h2>
          </div>
        </div>
        <p class="earist-docx-recovery-copy">RMS can check whether ONLYOFFICE still has this document open. If it does, the lock stays in place to protect the other session’s changes.</p>
        <div class="earist-docx-recovery-actions">
        <form id="editor-recovery-form" class="earist-docx-recovery-form" method="post" action="<?= htmlspecialchars(SITE_URL . 'pages/faculty/earist-document-edit-recover.php', ENT_QUOTES, 'UTF-8') ?>">
          <?= csrfField() ?>
          <input type="hidden" name="generated_document_id" value="<?= (int) $requestedId ?>">
          <input type="hidden" name="file" value="<?= htmlspecialchars($requestedSelector, ENT_QUOTES, 'UTF-8') ?>">
          <button class="earist-docx-save-close earist-docx-recovery-submit" type="submit"><span class="earist-docx-recovery-button-icon" aria-hidden="true">↻</span><span>Check &amp; Reopen Editor</span></button>
        </form>
        <a class="earist-docx-return" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-generated-documents.php', ENT_QUOTES, 'UTF-8') ?>">Return to Generated Documents</a>
        </div>
        <p id="editor-recovery-status" class="earist-docx-recovery-status" role="status" aria-live="polite" hidden></p>
        <form id="editor-reopen-form" method="post" action="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-document-editor.php', ENT_QUOTES, 'UTF-8') ?>" hidden>
          <?= csrfField() ?>
          <input type="hidden" name="generated_document_id" value="<?= (int) $requestedId ?>">
          <input type="hidden" name="file" value="<?= htmlspecialchars($requestedSelector, ENT_QUOTES, 'UTF-8') ?>">
        </form>
        <script>
          (() => {
            const form = document.getElementById('editor-recovery-form');
            const status = document.getElementById('editor-recovery-status');
            const reopenForm = document.getElementById('editor-reopen-form');
            const button = form.querySelector('button[type="submit"]');
            const recoverUrl = form.action;
            const statusBase = <?= json_encode(SITE_URL . 'pages/shared/earist-document-edit-status.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const reopen = () => {
              status.dataset.state = 'success';
              status.textContent = 'No active editor found. Reopening this saved version…';
              reopenForm.requestSubmit();
            };
            form.addEventListener('submit', async event => {
              event.preventDefault();
              button.disabled = true;
              button.querySelector('span:last-child').textContent = 'Checking editor…';
              status.hidden = false;
              status.dataset.state = 'checking';
              status.textContent = 'Checking whether ONLYOFFICE still has this document open…';
              try {
                const response = await fetch(recoverUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store' });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'RMS could not confirm the editor status. The lock was kept.');
                if (result.reopen) { reopen(); return; }
                const statusUrl = new URL(statusBase, window.location.href);
                statusUrl.searchParams.set('session', result.session_id);
                statusUrl.searchParams.set('recovery_since', String(result.marker));
                const deadline = Date.now() + 30000;
                const poll = async () => {
                  try {
                    const check = await fetch(statusUrl.href, { credentials: 'same-origin', cache: 'no-store' });
                    const state = await check.json();
                    if (state.state === 'closed' || state.state === 'saved') { reopen(); return; }
                    if (state.recovery_checked && (state.state === 'active' || state.state === 'expired')) {
                      status.dataset.state = 'active';
                      status.textContent = 'ONLYOFFICE still reports an active editor. Close that editor first; RMS kept the lock.';
                      button.disabled = false;
                      button.querySelector('span:last-child').textContent = 'Check & Reopen Editor';
                      return;
                    }
                  } catch (_) {}
                  if (Date.now() >= deadline) {
                    status.dataset.state = 'warning';
                    status.textContent = 'RMS could not confirm whether the editor is closed. The lock was kept; try again shortly.';
                    button.disabled = false;
                    button.querySelector('span:last-child').textContent = 'Check & Reopen Editor';
                    return;
                  }
                  window.setTimeout(poll, 1500);
                };
                window.setTimeout(poll, 1500);
              } catch (failure) {
                status.dataset.state = 'warning';
                status.textContent = failure instanceof Error ? failure.message : 'RMS could not confirm the editor status. The lock was kept.';
                button.disabled = false;
                button.querySelector('span:last-child').textContent = 'Check & Reopen Editor';
              }
            });
          })();
        </script>
      </section>
    <?php else: ?>
      <section class="frr-panel"><div class="frr-alert frr-alert--error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <a class="earist-docx-return" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-generated-documents.php', ENT_QUOTES, 'UTF-8') ?>">Return to Generated Documents</a></section>
    <?php endif; ?>
  <?php else: ?>
    <?php $editorTitle = trim((string) ($session['config']['document']['title'] ?? 'Generated document')); ?>
    <section class="frr-panel earist-docx-editor-surface">
      <header class="earist-docx-workspace-bar">
        <div class="earist-docx-workspace-heading">
          <a class="earist-docx-workspace-brand" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-generated-documents.php', ENT_QUOTES, 'UTF-8') ?>" aria-label="EARIST RMS Generated Documents">
            <img src="<?= htmlspecialchars(SITE_URL . 'photos/rms-logo.png', ENT_QUOTES, 'UTF-8') ?>" alt="">
          </a>
          <div class="earist-docx-workspace-titles">
            <h1><?= htmlspecialchars($editorTitle !== '' ? $editorTitle : 'Generated document', ENT_QUOTES, 'UTF-8') ?></h1>
            <p role="status" id="editor-save-status">Editing a saved version · Save &amp; Close stores your changes as a new version.</p>
          </div>
        </div>
        <div class="earist-docx-workspace-actions">
          <a id="editor-history-link" class="earist-docx-return" href="<?= htmlspecialchars(SITE_URL . 'pages/faculty/faculty-generated-documents.php', ENT_QUOTES, 'UTF-8') ?>">Generated Documents</a>
          <button class="earist-docx-save-close" id="editor-save-close" type="button" disabled>Save &amp; Close</button>
        </div>
      </header>
      <div class="earist-docx-editor-frame" aria-label="DOCX editor">
        <div id="onlyoffice-editor"></div>
      </div>
      <dialog class="earist-docx-return-dialog" id="editor-return-dialog" aria-labelledby="editor-return-title" aria-describedby="editor-return-copy">
        <div class="earist-docx-return-dialog-content">
          <span class="earist-docx-dialog-icon" aria-hidden="true">📝</span>
          <h2 id="editor-return-title">Save before returning?</h2>
          <p id="editor-return-copy">If you made changes, RMS saves them as a new immutable version and then opens Generated Documents. If the document is unchanged, no new version is created. The source version remains unchanged.</p>
          <div class="earist-docx-return-dialog-actions">
            <button class="earist-docx-return" id="editor-return-cancel" type="button">Stay in editor</button>
            <button class="earist-docx-save-close" id="editor-save-return" type="button" disabled>Save &amp; Return</button>
          </div>
        </div>
      </dialog>
      <dialog class="earist-docx-save-progress" id="editor-save-progress" aria-labelledby="editor-save-progress-title" aria-describedby="editor-save-progress-message editor-save-progress-hint">
        <div class="earist-docx-save-progress-content">
          <div class="earist-docx-save-progress-mark" aria-hidden="true">
            <span class="earist-docx-save-progress-spinner"></span>
            <span class="earist-docx-save-progress-file"><span></span><span></span><span></span></span>
          </div>
          <div class="earist-docx-save-progress-copy">
            <p class="earist-docx-save-progress-eyebrow">DOCUMENT SAVE</p>
            <h2 id="editor-save-progress-title">Saving your document</h2>
            <p class="earist-docx-save-progress-message" id="editor-save-progress-message" role="status" aria-live="polite" aria-atomic="true">Sending your edits to ONLYOFFICE…</p>
            <div class="earist-docx-save-progress-track" id="editor-save-progress-track" role="progressbar" aria-label="Document save progress" aria-valuemin="0" aria-valuemax="100" aria-valuetext="Waiting for save confirmation">
              <span></span>
            </div>
            <p class="earist-docx-save-progress-hint" id="editor-save-progress-hint">Keep this window open. RMS creates a new history version only after it confirms the save.</p>
            <button class="earist-docx-save-close earist-docx-save-progress-retry" id="editor-save-progress-retry" type="button" hidden>Retry Save &amp; Close</button>
          </div>
        </div>
      </dialog>
      <form id="editor-save-close-form" hidden><?= csrfField() ?></form>
      <p id="onlyoffice-error" role="alert" hidden>The editor service could not be loaded or saved. Your saved source remains available in Generated Documents.</p>
    </section>
    <script src="<?= htmlspecialchars($session['api_url'], ENT_QUOTES, 'UTF-8') ?>"></script>
    <script>
      (() => {
        const config = <?= json_encode($session['config'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const error = document.getElementById('onlyoffice-error');
        const saveStatus = document.getElementById('editor-save-status');
        const saveCloseButton = document.getElementById('editor-save-close');
        const saveProgressDialog = document.getElementById('editor-save-progress');
        const saveProgressTitle = document.getElementById('editor-save-progress-title');
        const saveProgressMessage = document.getElementById('editor-save-progress-message');
        const saveProgressHint = document.getElementById('editor-save-progress-hint');
        const saveProgressTrack = document.getElementById('editor-save-progress-track');
        const saveProgressRetryButton = document.getElementById('editor-save-progress-retry');
        const historyLink = document.getElementById('editor-history-link');
        const returnDialog = document.getElementById('editor-return-dialog');
        const returnTitle = document.getElementById('editor-return-title');
        const returnCopy = document.getElementById('editor-return-copy');
        const returnCancelButton = document.getElementById('editor-return-cancel');
        const saveReturnButton = document.getElementById('editor-save-return');
        const editorFrame = document.querySelector('.earist-docx-editor-frame');
        const saveCloseUrl = new URL(<?= json_encode(SITE_URL . 'pages/faculty/earist-document-edit-save-close.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, window.location.href);
        const saveCloseForm = document.getElementById('editor-save-close-form');
        const saveCloseData = new FormData(saveCloseForm);
        saveCloseData.set('session_id', <?= json_encode($session['session_id']) ?>);
        let docEditor = null;
        let waitingForSaveClose = false;
        let saveCloseRequested = false;
        let closeRequested = false;
        let editorReady = false;
        let editorFinished = false;
        let saveFailed = false;
        let returnAfterClose = false;
        let returnMayNavigateOnClose = false;
        let saveWaitTimer = null;
        const clearSaveWaitTimer = () => {
          if (saveWaitTimer !== null) window.clearTimeout(saveWaitTimer);
          saveWaitTimer = null;
        };
        const updateSaveProgress = (title, message, hint) => {
          saveProgressTitle.textContent = title;
          saveProgressMessage.textContent = message;
          saveProgressHint.textContent = hint;
          saveProgressTrack.setAttribute('aria-valuetext', message);
          if (!saveProgressDialog.open) saveProgressDialog.showModal();
        };
        const closeSaveProgress = () => {
          saveProgressRetryButton.hidden = true;
          if (saveProgressDialog.open) saveProgressDialog.close();
        };
        saveProgressDialog.addEventListener('cancel', event => event.preventDefault());
        saveProgressRetryButton.addEventListener('click', () => {
          saveProgressRetryButton.hidden = true;
          saveCloseButton.click();
        });
        const showSaveWaitRecovery = () => {
          if (!waitingForSaveClose || editorFinished) return;
          waitingForSaveClose = false;
          saveCloseButton.disabled = false;
          saveCloseButton.textContent = 'Retry Save & Close';
          saveStatus.textContent = 'RMS is still waiting for ONLYOFFICE’s save confirmation. The editor is paused and the source version is unchanged. Select Retry Save & Close to ask the editor again; keep this page open until RMS confirms the result.';
          updateSaveProgress('Taking longer than expected', 'RMS is still waiting for ONLYOFFICE to confirm the saved version.', 'Your original version is unchanged. The editor is paused; retrying is safe.');
          saveProgressRetryButton.hidden = false;
          saveProgressRetryButton.focus();
        };
        historyLink.addEventListener('click', event => {
          if (editorFinished) return;
          event.preventDefault();
          if (!editorReady) {
            saveStatus.textContent = 'The editor is still loading. Wait for the document before leaving.';
            return;
          }
          returnDialog.showModal();
        });
        returnCancelButton.addEventListener('click', () => returnDialog.close());
        saveReturnButton.addEventListener('click', () => {
          if (saveFailed) {
            returnDialog.close();
            window.location.assign(historyLink.href);
            return;
          }
          if (!editorReady || saveCloseButton.disabled) return;
          returnAfterClose = true;
          returnMayNavigateOnClose = false;
          returnDialog.close();
          saveCloseButton.click();
        });
        const requestEditorClose = () => {
          if (!docEditor || typeof docEditor.requestClose !== 'function' || closeRequested) return;
          closeRequested = true;
          saveStatus.textContent = 'The saved copy is in RMS. Closing the editor…';
          try { docEditor.requestClose(); }
          catch (_) { closeRequested = false; saveStatus.textContent = 'Your saved copy is in RMS. Close the editor when ready.'; }
        };
        saveCloseButton.addEventListener('click', () => {
          if (!docEditor || typeof docEditor.requestClose !== 'function' || waitingForSaveClose) return;
          const retryingSave = saveCloseRequested;
          clearSaveWaitTimer();
          saveCloseRequested = true;
          returnAfterClose = true;
          saveProgressRetryButton.hidden = true;
          saveCloseButton.disabled = true;
          saveCloseButton.textContent = 'Save & Close';
          saveReturnButton.disabled = true;
          saveStatus.textContent = retryingSave
            ? 'Retrying the save request. RMS will keep checking for ONLYOFFICE’s confirmation…'
            : 'Saving your changes to a new immutable version…';
          updateSaveProgress(retryingSave ? 'Retrying your save' : 'Saving your document',
            retryingSave ? 'RMS is asking ONLYOFFICE to confirm the edited file again.' : 'Sending your edits to ONLYOFFICE…',
            'Keep this window open. RMS creates a new history version only after it confirms the save.');
          fetch(saveCloseUrl.href, { method: 'POST', body: saveCloseData, credentials: 'same-origin', cache: 'no-store' })
            .then(async response => {
              const result = await response.json();
              if (!response.ok) {
                const rejection = new Error(result.error || 'The save request was rejected. Your document remains open.');
                rejection.requestRejected = true;
                throw rejection;
              }
              if (result.no_changes) {
                if (returnAfterClose) returnMayNavigateOnClose = true;
                waitingForSaveClose = false;
                clearSaveWaitTimer();
                saveStatus.textContent = 'There are no new changes. Closing the editor…';
                updateSaveProgress('No changes found', 'Closing the editor and returning to Generated Documents…', 'No new history version will be created.');
                requestEditorClose();
                return;
              }
              waitingForSaveClose = true;
              editorFrame.style.pointerEvents = 'none';
              saveStatus.textContent = 'Save request accepted. Waiting for ONLYOFFICE to confirm the saved version…';
              updateSaveProgress('Waiting for save confirmation', 'ONLYOFFICE accepted the request. RMS is verifying the edited document before adding it to history.', 'Your source version and mapped answers remain unchanged.');
              saveWaitTimer = window.setTimeout(showSaveWaitRecovery, 30000);
            })
            .catch(failure => {
              if (failure && failure.requestRejected) {
                clearSaveWaitTimer();
                waitingForSaveClose = false;
                saveCloseRequested = false;
                returnAfterClose = false;
                editorFrame.style.pointerEvents = '';
                closeSaveProgress();
                saveStatus.textContent = failure.message;
                saveCloseButton.disabled = false;
                saveCloseButton.textContent = 'Save & Close';
                saveReturnButton.disabled = false;
                return;
              }
              // A lost response is ambiguous: the force-save may have reached ONLYOFFICE.
              // Keep editing/return disabled and continue polling before offering a retry.
              if (saveCloseRequested) {
                waitingForSaveClose = true;
                editorFrame.style.pointerEvents = 'none';
                saveStatus.textContent = failure instanceof Error
                  ? `${failure.message} RMS will continue checking; the source version remains unchanged.`
                  : 'RMS could not confirm the save request. Keep this page open while it checks; the source version remains unchanged.';
                updateSaveProgress('Checking save status', 'The request response was interrupted. RMS will keep checking before offering a retry.', 'Keep this window open. The original version remains unchanged.');
                saveWaitTimer = window.setTimeout(showSaveWaitRecovery, 30000);
              } else {
                closeSaveProgress();
                saveStatus.textContent = failure instanceof Error ? failure.message : 'The document could not be saved. Your document remains open.';
                saveCloseButton.disabled = false;
                saveCloseButton.textContent = 'Save & Close';
                saveReturnButton.disabled = false;
              }
            });
        });
        const statusUrl = new URL(<?= json_encode(SITE_URL . 'pages/shared/earist-document-edit-status.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, window.location.href);
        statusUrl.searchParams.set('session', <?= json_encode($session['session_id']) ?>);
        const poll = window.setInterval(async () => {
          try {
            const response = await fetch(statusUrl.href, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            const state = await response.json();
            if (state.state === 'save_ready') {
              clearSaveWaitTimer();
              saveStatus.textContent = 'The immutable version is saved in RMS. The source version remains unchanged.';
              if (saveCloseRequested) {
                updateSaveProgress('Version confirmed', 'RMS has verified your edited document and is closing the editor…', 'The new immutable version is saved. Your original remains unchanged.');
                waitingForSaveClose = false;
                saveCloseRequested = false;
                saveCloseButton.disabled = true;
                saveReturnButton.disabled = true;
                if (typeof docEditor.denyEditingRights === 'function') docEditor.denyEditingRights('Your saved version is ready. Close this editor to return to RMS.');
                requestEditorClose();
              }
            } else if (state.state === 'saved') {
              window.clearInterval(poll);
              clearSaveWaitTimer();
              editorFinished = true;
              waitingForSaveClose = false;
              saveCloseRequested = false;
              saveStatus.textContent = 'Edits saved as a new immutable history version. The source version and mapped answers are unchanged.';
              updateSaveProgress('Saved successfully', 'Your new history version is ready. Returning to Generated Documents…', 'The original document and mapped answers are unchanged.');
              const target = new URL(historyLink.href);
              if (state.generated_document_id) target.searchParams.set('created', String(state.generated_document_id));
              historyLink.href = target.href;
              saveCloseButton.disabled = true;
              saveReturnButton.disabled = true;
              if (returnAfterClose) { window.location.assign(historyLink.href); return; }
            } else if (state.state === 'closed' || state.state === 'expired') {
              window.clearInterval(poll);
              clearSaveWaitTimer();
              editorFinished = true;
              waitingForSaveClose = false;
              saveCloseRequested = false;
              const safeClose = state.state === 'closed' && returnMayNavigateOnClose;
              returnAfterClose = false;
              saveStatus.textContent = state.state === 'closed' ? 'The editor closed without saving changes. No new history version was created.'
                : 'The edit session expired. No new history version was created.';
              closeSaveProgress();
              saveCloseButton.disabled = true;
              saveReturnButton.disabled = true;
              if (safeClose) window.location.assign(historyLink.href);
            } else if (state.state === 'failed') {
              window.clearInterval(poll);
              clearSaveWaitTimer();
              waitingForSaveClose = false;
              saveCloseRequested = false;
              saveFailed = true;
              returnAfterClose = false;
              editorFrame.style.pointerEvents = '';
              closeSaveProgress();
              saveStatus.textContent = 'ONLYOFFICE could not save the document. No new version was published; your saved source remains unchanged.';
              saveCloseButton.disabled = true;
              saveReturnButton.disabled = false;
              returnTitle.textContent = 'No new version was saved';
              returnCopy.textContent = 'Returning now may discard this editing session. Your previously saved version remains unchanged and available in Generated Documents.';
              saveReturnButton.textContent = 'Return without saving';
            }
          } catch (_) {
            if (saveCloseRequested) {
              saveStatus.textContent = 'RMS cannot check the save status right now. Keep this page open; no new version is confirmed yet.';
              updateSaveProgress('Checking save status', 'RMS cannot reach the status service right now.', 'Keep this window open. RMS has not confirmed a new version.');
            }
          }
        }, 2000);
        if (!window.DocsAPI || typeof window.DocsAPI.DocEditor !== 'function') {
          error.hidden = false;
          editorFinished = true;
          saveStatus.textContent = 'The editor could not load. No edits were saved; the original version remains available.';
          return;
        }
        try {
          docEditor = new window.DocsAPI.DocEditor('onlyoffice-editor', { ...config, width: '100%', height: '100%', events: {
            onDocumentReady: () => {
              editorReady = true;
              saveCloseButton.disabled = false;
              saveReturnButton.disabled = false;
            },
            onRequestClose: () => {
              if (docEditor && typeof docEditor.destroyEditor === 'function') docEditor.destroyEditor();
              saveStatus.textContent = 'Editor closed. Waiting for RMS to confirm the saved version…';
            },
            onError: () => {
              error.hidden = false;
              editorFinished = true;
              closeSaveProgress();
              saveStatus.textContent = 'The editor encountered a problem. No new version was published; the saved source remains available.';
            }
          } });
        }
        catch (_) {
          error.hidden = false;
          editorFinished = true;
          saveStatus.textContent = 'The editor could not start. No edits were saved; the original version remains available.';
        }
      })();
    </script>
  <?php endif; ?>
</main>
<?php renderFacultyShellClose(); ?>
