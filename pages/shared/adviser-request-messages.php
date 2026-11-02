<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
$user = getCurrentUser();
if (!$user || !in_array((string) ($user['role'] ?? ''), ['student', 'faculty'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$userId = (int) $user['user_id'];
$requestId = filter_var($_POST['request_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) {
    http_response_code(404);
    exit('Adviser request not found.');
}

function adviserRequestMessagesTableExists(mysqli $connection): bool
{
    $stmt = $connection->prepare("SHOW TABLES LIKE 'adviser_requests'");
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

if (!adviserRequestMessagesTableExists($conn)) {
    http_response_code(404);
    exit('Adviser request feature is unavailable.');
}

function adviserRequestMessageEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$requestStmt = $conn->prepare(
    'SELECT ar.request_id, ar.project_id, ar.requester_id, ar.faculty_id, ar.status,
            rp.title AS project_title,
            s.first_name AS student_first_name, s.last_name AS student_last_name,
            f.first_name AS faculty_first_name, f.last_name AS faculty_last_name
       FROM adviser_requests ar
       JOIN research_projects rp ON rp.project_id = ar.project_id AND rp.deleted_at IS NULL
       JOIN users s ON s.user_id = ar.requester_id AND s.deleted_at IS NULL
       JOIN users f ON f.user_id = ar.faculty_id AND f.deleted_at IS NULL
      WHERE ar.request_id = ?
      LIMIT 1'
);
$requestStmt->bind_param('i', $requestId);
$requestStmt->execute();
$request = $requestStmt->get_result()->fetch_assoc() ?: null;
$requestStmt->close();
if (!$request || !in_array($userId, [(int) $request['requester_id'], (int) $request['faculty_id']], true)) {
    http_response_code(403);
    exit('Access denied.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . 'pages/shared/messages.php?request_id=' . $requestId);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form has expired. Refresh the page and try again.';
    } else {
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 5000) {
            $error = 'Enter a message of up to 5,000 characters.';
        } elseif ((string) $request['status'] === 'declined') {
            $error = 'Messaging is closed for a declined adviser request.';
        } else {
            $messageStmt = $conn->prepare('INSERT INTO adviser_request_messages (request_id, sender_id, message) VALUES (?, ?, ?)');
            $messageStmt->bind_param('iis', $requestId, $userId, $message);
            if ($messageStmt->execute()) {
                $messageStmt->close();
                $recipientId = $userId === (int) $request['requester_id']
                    ? (int) $request['faculty_id']
                    : (int) $request['requester_id'];
                $projectTitle = (string) $request['project_title'];
                createNotification(
                    $recipientId,
                    'New adviser request message',
                    'A message was added to the adviser request for ' . $projectTitle . '.',
                    'info',
                    'pages/shared/messages.php?request_id=' . $requestId
                );
                header('Location: ' . SITE_URL . 'pages/shared/messages.php?request_id=' . $requestId);
                exit;
            }
            $messageStmt->close();
            $error = 'The message could not be sent. Please try again.';
        }
    }
}

$messages = [];
$messagesStmt = $conn->prepare(
    'SELECT m.message_id, m.sender_id, m.message, m.created_at,
            sender.first_name, sender.last_name
       FROM adviser_request_messages m
       JOIN users sender ON sender.user_id = m.sender_id
      WHERE m.request_id = ?
      ORDER BY m.created_at, m.message_id'
);
$messagesStmt->bind_param('i', $requestId);
$messagesStmt->execute();
$messagesResult = $messagesStmt->get_result();
while ($row = $messagesResult->fetch_assoc()) {
    $messages[] = $row;
}
$messagesStmt->close();

$isStudent = (string) $user['role'] === 'student';
$partnerFirstName = $isStudent ? (string) $request['faculty_first_name'] : (string) $request['student_first_name'];
$partnerLastName = $isStudent ? (string) $request['faculty_last_name'] : (string) $request['student_last_name'];
$partnerName = trim($partnerFirstName . ' ' . $partnerLastName);
if ($partnerName === '') {
    $partnerName = $isStudent ? 'Research adviser' : 'Student';
}
$partnerInitials = '';
foreach (preg_split('/\s+/u', $partnerName) ?: [] as $namePart) {
    if ($namePart !== '') {
        $partnerInitials .= mb_strtoupper(mb_substr($namePart, 0, 1, 'UTF-8'), 'UTF-8');
    }
    if (mb_strlen($partnerInitials, 'UTF-8') >= 2) {
        break;
    }
}
if ($partnerInitials === '') {
    $partnerInitials = '?';
}

if ($user['role'] === 'faculty') {
    require_once __DIR__ . '/../../includes/faculty-shell.php';
    renderFacultyShell($user, 'faculty-advising.php', 'Adviser Request Messages', 'Private messages for this adviser request.');
} else {
    require_once __DIR__ . '/../../includes/student-shell.php';
    renderStudentShell($user, 'messages', 'Adviser Request Messages', 'Private messages for this adviser request.');
}
?>
<style>
.adviser-messages {
  --chat-accent: <?= $user['role'] === 'faculty' ? '#1D4ED8' : '#5B1EBC' ?>;
  --chat-accent-soft: <?= $user['role'] === 'faculty' ? 'rgba(29, 78, 216, .08)' : 'rgba(91, 30, 188, .08)' ?>;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(960px, 100%);
  height: min(680px, calc(100dvh - 180px));
  min-height: 380px;
  margin: 0 auto;
  overflow: hidden;
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  background: #fff;
  color: #172033;
  box-shadow: 0 12px 32px rgba(31, 49, 78, .08);
}
.adviser-chat-header {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 14px;
  padding: 18px 22px;
  border-bottom: 1px solid #e8edf3;
  background: #fff;
}
.adviser-chat-avatar {
  display: grid;
  flex: 0 0 46px;
  width: 46px;
  height: 46px;
  place-items: center;
  border: 1px solid color-mix(in srgb, var(--chat-accent) 18%, white);
  border-radius: 50%;
  background: var(--chat-accent-soft);
  color: var(--chat-accent);
  font-size: 14px;
  font-weight: 750;
}
.adviser-chat-heading { min-width: 0; flex: 1; }
.adviser-chat-kicker {
  margin: 0 0 3px;
  color: var(--chat-accent);
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .09em;
  text-transform: uppercase;
}
.adviser-chat-heading h1 {
  overflow: hidden;
  margin: 0;
  color: #172033;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.25;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.adviser-chat-project {
  overflow: hidden;
  margin: 3px 0 0;
  color: #64748b;
  font-size: 12px;
  line-height: 1.4;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.adviser-chat-presence {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 7px;
  padding: 7px 10px;
  border: 1px solid #e5eaf0;
  border-radius: 999px;
  color: #64748b;
  font-size: 11px;
  font-weight: 650;
}
.adviser-chat-presence::before {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #16a34a;
  content: '';
}
.adviser-chat-thread-wrap { position: relative; min-height: 0; background: #f6f8fc; }
.adviser-message-list {
  display: flex;
  height: 100%;
  min-height: 0;
  flex-direction: column;
  gap: 14px;
  overflow-y: auto;
  margin: 0;
  padding: 20px clamp(14px, 3vw, 28px) 24px;
  list-style: none;
  overscroll-behavior: contain;
  scrollbar-color: #cbd5e1 transparent;
  scrollbar-width: thin;
}
.adviser-day-divider { display: flex; justify-content: center; padding: 3px 0; }
.adviser-day-divider span {
  padding: 5px 10px;
  border: 1px solid #e5eaf0;
  border-radius: 999px;
  background: rgba(255, 255, 255, .88);
  color: #748198;
  font-size: 10px;
  font-weight: 650;
}
.adviser-message {
  display: flex;
  max-width: min(82%, 740px);
  align-items: flex-end;
  gap: 8px;
  margin: 0;
}
.adviser-message.is-mine { align-self: flex-end; }
.adviser-message-avatar {
  display: grid;
  flex: 0 0 28px;
  width: 28px;
  height: 28px;
  place-items: center;
  border: 1px solid #dce5f1;
  border-radius: 50%;
  background: #fff;
  color: var(--chat-accent);
  font-size: 9px;
  font-weight: 750;
}
.adviser-message-bubble {
  min-width: 120px;
  max-width: 100%;
  padding: 10px 14px 11px;
  border: 1px solid #e2e8f0;
  border-radius: 15px 15px 15px 5px;
  background: #fff;
  color: #253249;
  box-shadow: 0 2px 5px rgba(31, 49, 78, .035);
}
.adviser-message.is-mine .adviser-message-bubble {
  margin-left: auto;
  border-color: var(--chat-accent);
  border-radius: 15px 15px 5px 15px;
  background: var(--chat-accent);
  color: #fff;
}
.adviser-message-meta {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px 16px;
  color: #64748b;
  font-size: 10px;
  line-height: 1.35;
}
.adviser-message-meta strong { color: #34435b; font-size: 11px; font-weight: 700; }
.adviser-message.is-mine .adviser-message-meta,
.adviser-message.is-mine .adviser-message-meta strong { color: rgba(255, 255, 255, .82); }
.adviser-message-body {
  margin: 6px 0 0;
  color: inherit;
  font-size: 13px;
  line-height: 1.55;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.adviser-chat-empty {
  display: grid;
  min-height: 100%;
  place-content: center;
  padding: 30px;
  color: #64748b;
  text-align: center;
}
.adviser-chat-empty strong { display: block; margin-bottom: 5px; color: #34435b; font-size: 14px; }
.adviser-chat-empty p { margin: 0; font-size: 12px; }
.adviser-jump-latest {
  position: absolute;
  right: 22px;
  bottom: 14px;
  min-height: 34px;
  padding: 7px 12px;
  border: 1px solid #d9e1ec;
  border-radius: 999px;
  background: #fff;
  color: #34435b;
  box-shadow: 0 4px 14px rgba(31, 49, 78, .12);
  font: inherit;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}
.adviser-jump-latest:hover { border-color: var(--chat-accent); color: var(--chat-accent); }
.adviser-jump-latest:focus-visible,
.adviser-message-form textarea:focus-visible,
.adviser-message-form button:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--chat-accent) 30%, transparent);
  outline-offset: 2px;
}
.adviser-chat-compose {
  padding: 14px 18px 16px;
  border-top: 1px solid #e8edf3;
  background: #fff;
}
.adviser-message-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: end;
  gap: 10px;
  margin: 0;
}
.adviser-composer-field {
  min-width: 0;
  padding: 8px 11px 6px;
  border: 1px solid #d8e0ea;
  border-radius: 13px;
  background: #f8fafc;
}
.adviser-message-form textarea {
  display: block;
  width: 100%;
  min-height: 42px;
  max-height: 128px;
  padding: 4px 2px;
  overflow-y: auto;
  border: 0;
  background: transparent;
  color: #172033;
  font: inherit;
  font-size: 13px;
  line-height: 1.5;
  resize: vertical;
}
.adviser-message-form textarea:focus { outline: 0; }
.adviser-composer-meta {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding-top: 4px;
  color: #8894a7;
  font-size: 10px;
}
.adviser-message-form button {
  display: inline-flex;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 9px 16px;
  border: 0;
  border-radius: 11px;
  background: var(--chat-accent);
  color: #fff;
  font: inherit;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: filter .18s ease, transform .18s ease;
}
.adviser-message-form button:hover { filter: brightness(.92); }
.adviser-message-form button:active { transform: translateY(1px); }
.adviser-message-error,
.adviser-chat-closed {
  margin: 0 0 10px;
  padding: 9px 12px;
  border: 1px solid #fecaca;
  border-radius: 9px;
  background: #fef2f2;
  color: #991b1b;
  font-size: 12px;
}
.adviser-chat-closed { border-color: #e5eaf0; background: #f8fafc; color: #64748b; }
.adviser-visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
@media (max-width: 640px) {
  .adviser-messages { height: min(680px, calc(100dvh - 150px)); min-height: 360px; }
  .adviser-chat-header { gap: 11px; padding: 15px; }
  .adviser-chat-avatar { flex-basis: 40px; width: 40px; height: 40px; }
  .adviser-chat-heading h1 { font-size: 16px; }
  .adviser-chat-presence { padding: 6px 8px; font-size: 10px; }
  .adviser-message-list { gap: 12px; padding: 16px 12px 20px; }
  .adviser-message { max-width: 94%; }
  .adviser-message-bubble { min-width: 0; padding: 9px 11px; }
  .adviser-chat-compose { padding: 12px; }
  .adviser-message-form { grid-template-columns: minmax(0, 1fr) auto; gap: 8px; }
  .adviser-message-form button { min-height: 40px; padding: 8px 12px; }
}
@media (max-width: 390px) {
  .adviser-chat-presence { display: none; }
  .adviser-composer-meta span:last-child { display: none; }
}
@media (prefers-reduced-motion: reduce) {
  .adviser-message-form button { transition: none; }
  .adviser-message-list { scroll-behavior: auto; }
}
</style>
<main class="adviser-messages" aria-labelledby="conversation-title">
  <header class="adviser-chat-header">
    <div class="adviser-chat-avatar" aria-hidden="true"><?= adviserRequestMessageEscape($partnerInitials) ?></div>
    <div class="adviser-chat-heading">
      <p class="adviser-chat-kicker">Private adviser request</p>
      <h1 id="conversation-title"><?= adviserRequestMessageEscape($partnerName) ?></h1>
      <p class="adviser-chat-project"><?= adviserRequestMessageEscape($request['project_title']) ?></p>
    </div>
    <span class="adviser-chat-presence">Private conversation</span>
  </header>

  <section class="adviser-chat-thread-wrap" aria-label="Conversation">
    <ol class="adviser-message-list" id="adviser-message-list" tabindex="0" role="log" aria-live="polite" aria-relevant="additions text" aria-label="Messages">
      <?php if (!$messages): ?>
        <li class="adviser-chat-empty">
          <div><strong>Start the conversation</strong><p>Send a message about this adviser request.</p></div>
        </li>
      <?php else: ?>
        <?php $previousMessageDay = null; ?>
        <?php foreach ($messages as $message): ?>
          <?php
          $senderName = trim((string) $message['first_name'] . ' ' . (string) $message['last_name']);
          $senderName = $senderName !== '' ? $senderName : 'Project participant';
          $isMine = (int) $message['sender_id'] === $userId;
          $messageTimestamp = strtotime((string) $message['created_at']);
          $messageDay = date('Y-m-d', $messageTimestamp);
          if ($messageDay === date('Y-m-d')) {
              $messageDayLabel = 'Today';
          } elseif ($messageDay === date('Y-m-d', strtotime('yesterday'))) {
              $messageDayLabel = 'Yesterday';
          } else {
              $messageDayLabel = date('F j, Y', $messageTimestamp);
          }
          ?>
          <?php if ($messageDay !== $previousMessageDay): ?>
            <li class="adviser-day-divider"><span><?= adviserRequestMessageEscape($messageDayLabel) ?></span></li>
            <?php $previousMessageDay = $messageDay; ?>
          <?php endif; ?>
          <li class="adviser-message<?= $isMine ? ' is-mine' : '' ?>">
            <?php if (!$isMine): ?><span class="adviser-message-avatar" aria-hidden="true"><?= adviserRequestMessageEscape($partnerInitials) ?></span><?php endif; ?>
            <article class="adviser-message-bubble">
              <div class="adviser-message-meta">
                <strong><?= adviserRequestMessageEscape($senderName) ?></strong>
                <time datetime="<?= adviserRequestMessageEscape(date(DATE_ATOM, $messageTimestamp)) ?>" title="<?= adviserRequestMessageEscape(date('F j, Y g:i A', $messageTimestamp)) ?>"><?= adviserRequestMessageEscape(date('g:i A', $messageTimestamp)) ?></time>
              </div>
              <p class="adviser-message-body"><?= adviserRequestMessageEscape($message['message']) ?></p>
            </article>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ol>
    <button class="adviser-jump-latest" id="adviser-jump-latest" type="button" hidden>↓ Latest messages</button>
  </section>

  <div class="adviser-chat-compose">
    <?php if ($error !== ''): ?><p class="adviser-message-error" role="alert"><?= adviserRequestMessageEscape($error) ?></p><?php endif; ?>
    <?php if ((string) $request['status'] !== 'declined'): ?>
      <form class="adviser-message-form" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
        <div class="adviser-composer-field">
          <label class="adviser-visually-hidden" for="request-message">Your message</label>
          <textarea id="request-message" name="message" maxlength="5000" rows="2" required placeholder="Write a message…" aria-describedby="message-composer-help message-character-count"></textarea>
          <div class="adviser-composer-meta" id="message-composer-help"><span>Ctrl + Enter to send</span><span id="message-character-count">0 / 5000</span></div>
        </div>
        <button type="submit"><span>Send</span><span aria-hidden="true">↑</span></button>
      </form>
    <?php else: ?>
      <p class="adviser-chat-closed" role="status">Messaging is closed for a declined adviser request.</p>
    <?php endif; ?>
  </div>
</main>
<script>
(function () {
  const thread = document.getElementById('adviser-message-list');
  const jumpButton = document.getElementById('adviser-jump-latest');
  const textarea = document.getElementById('request-message');
  const characterCount = document.getElementById('message-character-count');

  if (thread) {
    const updateJumpButton = () => {
      const distanceFromLatest = thread.scrollHeight - thread.scrollTop - thread.clientHeight;
      jumpButton.hidden = distanceFromLatest < 96;
    };
    const scrollToLatest = () => {
      thread.scrollTop = thread.scrollHeight;
      updateJumpButton();
    };

    requestAnimationFrame(scrollToLatest);
    thread.addEventListener('scroll', updateJumpButton, { passive: true });
    jumpButton.addEventListener('click', () => {
      thread.scrollTo({
        top: thread.scrollHeight,
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
      });
    });
    window.addEventListener('load', scrollToLatest, { once: true });
  }

  if (textarea && characterCount) {
    const updateCharacterCount = () => {
      characterCount.textContent = `${textarea.value.length} / 5000`;
    };
    textarea.addEventListener('input', updateCharacterCount);
    textarea.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        if (textarea.value.trim() !== '') {
          textarea.form.requestSubmit();
        }
      }
    });
    updateCharacterCount();
  }
})();
</script>
<?php if ($user['role'] === 'faculty'): renderFacultyShellClose(); else: renderStudentShellClose(); endif; ?>
