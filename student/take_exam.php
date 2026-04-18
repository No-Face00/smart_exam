<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';
require_once __DIR__ . '/../includes/Auth.php';

requireLogin('student');

$examModel = new Exam();
$auth      = new Auth();
$student   = currentUser();

$examId = (int)get('exam_id');
if (!$examId) { header('Location: dashboard.php'); exit; }

$exam = $examModel->getById($examId);
if (!$exam || !in_array($exam['status'], ['scheduled','running'])) {
    setFlash('danger', 'This exam is not available.');
    header('Location: dashboard.php'); exit;
}

// Start or retrieve attempt
$startResult = $examModel->startAttempt(
    $examId,
    $student['id'],
    getClientIP(),
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

if (!$startResult['ok']) {
    setFlash('danger', $startResult['error']);
    header('Location: dashboard.php'); exit;
}
$attemptId = $startResult['attempt_id'];

// Log exam start (only on new attempt)
if (empty($startResult['resumed'])) {
    $auth->logAction('student', $student['id'], 'exam_start', ['exam_id' => $examId]);
}

// Load questions
$questions = $examModel->getQuestions($examId, (bool)$exam['is_randomized']);

// Get already-saved answers for this attempt
$db = Database::getConnection();
$savedStmt = $db->prepare(
    "SELECT question_id, selected_option FROM student_answers WHERE attempt_id = ?"
);
$savedStmt->execute([$attemptId]);
$savedAnswers = [];
foreach ($savedStmt->fetchAll() as $row) {
    $savedAnswers[$row['question_id']] = $row['selected_option'];
}

// Time remaining
$startStmt = $db->prepare("SELECT start_time FROM exam_attempts WHERE attempt_id = ?");
$startStmt->execute([$attemptId]);
$startTime = strtotime($startStmt->fetch()['start_time']);
$allowedSecs  = $exam['duration_mins'] * 60;
$elapsedSecs  = time() - $startTime;
$remainingSecs = max(0, $allowedSecs - $elapsedSecs);

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $result = $examModel->submitAttempt($attemptId, $student['id']);
    $auth->logAction('student', $student['id'], 'submit', ['exam_id' => $examId, 'score' => $result['score'] ?? 0]);
    header('Location: result.php?attempt_id=' . $attemptId);
    exit;
}

renderHead('Taking: ' . $exam['title']);
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>

<!-- No sidebar for exam — distraction-free mode -->
<style>
body { background: var(--bg); }
.exam-wrap {
  max-width: 860px;
  margin: 0 auto;
  padding: 20px 16px 60px;
}
.exam-header {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: var(--shadow);
  margin-bottom: 24px;
  flex-wrap: wrap;
  gap: 12px;
}
.exam-title {
  font-family: 'Syne', sans-serif;
  font-size: 18px;
  font-weight: 800;
}
.question-card {
  background: var(--bg-card);
  border: 2px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
  margin-bottom: 16px;
  transition: border-color .2s;
}
.question-card.answered {
  border-color: var(--brand);
  background: var(--brand-light);
}
[data-theme="dark"] .question-card.answered {
  background: rgba(37,99,235,.07);
}
.question-number {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .6px;
  color: var(--brand);
  margin-bottom: 8px;
}
.question-text {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 18px;
  line-height: 1.5;
}
.option-list { display: flex; flex-direction: column; gap: 10px; }
.option-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: var(--bg);
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: all .18s;
}
.option-item:hover {
  border-color: var(--brand);
  background: var(--brand-light);
}
.option-item input[type="radio"] { display: none; }
.option-item input:checked + .option-label { color: var(--brand); }
.option-item:has(input:checked) {
  border-color: var(--brand);
  background: var(--brand-light);
}
.option-badge {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: var(--border);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 12px;
  flex-shrink: 0;
  transition: all .18s;
}
.option-item:has(input:checked) .option-badge {
  background: var(--brand);
  color: #fff;
}
.option-label { font-size: 14px; font-weight: 500; }
</style>

<div class="exam-wrap">

  <!-- Sticky Header -->
  <div class="exam-header">
    <div>
      <div class="exam-title"><?= sanitize($exam['title']) ?></div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
        <?= count($questions) ?> questions · <?= $exam['duration_mins'] ?> minutes
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <!-- Progress -->
      <div style="text-align:center;min-width:120px;">
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" id="progressFill" style="width:0%"></div>
        </div>
        <div id="progressLabel" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          0 / <?= count($questions) ?> answered
        </div>
      </div>

      <!-- Timer -->
      <div class="exam-timer" id="examTimer">
        <i class="bi bi-clock"></i>
        <span id="examTimerDisplay">--:--</span>
      </div>
    </div>
  </div>

  <!-- Questions -->
  <form method="POST" id="examForm" onsubmit="return confirmSubmit(this)">
    <?php foreach ($questions as $i => $q): ?>
    <?php $saved = $savedAnswers[$q['question_id']] ?? ''; ?>
    <div class="question-card <?= $saved ? 'answered' : '' ?>" id="qcard-<?= $q['question_id'] ?>">

      <div class="question-number">Question <?= $i + 1 ?> of <?= count($questions) ?></div>
      <div class="question-text"><?= sanitize($q['question_text']) ?></div>

      <div class="option-list">
        <?php foreach (['A','B','C','D'] as $opt): ?>
        <?php $optKey = 'option_' . strtolower($opt); ?>
        <label class="option-item">
          <input type="radio"
                 name="answer_<?= $q['question_id'] ?>"
                 value="<?= $opt ?>"
                 class="answer-option"
                 data-attempt-id="<?= $attemptId ?>"
                 data-question-id="<?= $q['question_id'] ?>"
                 <?= $saved === $opt ? 'checked' : '' ?>>
          <div class="option-badge"><?= $opt ?></div>
          <span class="option-label"><?= sanitize($q[$optKey]) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>

    <div style="text-align:center;padding:20px 0;">
      <input type="hidden" name="submit_exam" value="1">
      <button type="submit" class="btn-primary" style="padding:14px 40px;font-size:16px;">
        <i class="bi bi-check2-all"></i> Submit Exam
      </button>
      <p style="font-size:12px;color:var(--text-muted);margin-top:10px;">
        Submitting is final. Make sure you've answered all questions.
      </p>
    </div>
  </form>

</div><!-- .exam-wrap -->

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
// Start countdown
const timer = new ExamTimer(<?= $remainingSecs ?>, function() {
  // Auto-submit when time runs out
  document.getElementById('examForm').submit();
});
timer.start();

// Re-check answered state on load (for saved answers)
document.querySelectorAll('.answer-option:checked').forEach(input => {
  const card = input.closest('.question-card');
  if (card) card.classList.add('answered');
});
updateProgress();
</script>
