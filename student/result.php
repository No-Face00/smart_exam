<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('student');

$examModel = new Exam();
$attemptId = (int)get('attempt_id');
if (!$attemptId) { header('Location: dashboard.php'); exit; }

$result = $examModel->getResult($attemptId, currentUser()['id']);
if (!$result || $result['student_id'] != currentUser()['id']) {
    header('Location: dashboard.php'); exit;
}

// Get per-question breakdown
$db = Database::getConnection();
$breakdown = $db->prepare("
    SELECT q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
           q.correct_option, q.marks,
           sa.selected_option,
           CASE WHEN sa.selected_option = q.correct_option THEN q.marks ELSE 0 END AS earned
    FROM questions q
    LEFT JOIN student_answers sa ON sa.question_id = q.question_id AND sa.attempt_id = ?
    WHERE q.exam_id = ?
    ORDER BY q.display_order, q.question_id
");
$breakdown->execute([$attemptId, $result['exam_id']]);
$questions = $breakdown->fetchAll();

$score   = (float)$result['score'];
$passed  = (bool)$result['is_passed'];
$timeSec = (int)$result['time_taken_secs'];
$timeStr = sprintf('%02d:%02d', intdiv($timeSec, 60), $timeSec % 60);

renderHead('Result: ' . $result['title']);
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<style>
.result-page { max-width: 800px; margin: 0 auto; padding: 40px 16px 60px; }
.result-hero {
  text-align: center;
  padding: 48px 24px;
  background: var(--bg-card);
  border-radius: var(--radius);
  border: 1px solid var(--border);
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
}
.result-hero::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
  background: <?= $passed ? 'var(--success)' : 'var(--danger)' ?>;
}
.score-ring {
  width: 140px; height: 140px;
  border-radius: 50%;
  background: <?= $passed ? '#ECFDF5' : '#FEF2F2' ?>;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  margin: 0 auto 20px;
  border: 6px solid <?= $passed ? 'var(--success)' : 'var(--danger)' ?>;
}
.score-pct {
  font-family: 'Syne', sans-serif;
  font-size: 36px;
  font-weight: 800;
  color: <?= $passed ? 'var(--success)' : 'var(--danger)' ?>;
  line-height: 1;
}
.score-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .5px;
  color: var(--text-muted);
}
.result-verdict {
  font-family: 'Syne', sans-serif;
  font-size: 26px;
  font-weight: 800;
  color: <?= $passed ? 'var(--success)' : 'var(--danger)' ?>;
  margin-bottom: 6px;
}
.result-meta {
  display: flex;
  justify-content: center;
  gap: 32px;
  flex-wrap: wrap;
  margin-top: 20px;
}
.result-meta-item {
  text-align: center;
}
.result-meta-val {
  font-family: 'Syne', sans-serif;
  font-size: 20px;
  font-weight: 800;
  display: block;
}
.result-meta-key {
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: .5px;
}
.q-review-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  margin-bottom: 12px;
  overflow: hidden;
}
.q-review-header {
  padding: 14px 16px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  cursor: pointer;
}
.q-review-icon {
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.q-correct   { background: #ECFDF5; color: var(--success); }
.q-incorrect { background: #FEF2F2; color: var(--danger); }
.q-skipped   { background: var(--bg); color: var(--text-muted); }
</style>

<div class="result-page">

  <!-- Result Hero -->
  <div class="result-hero">
    <div class="score-ring">
      <span class="score-pct"><?= round($score) ?>%</span>
      <span class="score-label">Score</span>
    </div>

    <div class="result-verdict">
      <?= $passed ? '🎉 Passed!' : '😔 Failed' ?>
    </div>
    <div style="color:var(--text-muted);font-size:14px;"><?= sanitize($result['title']) ?></div>

    <div class="result-meta">
      <div class="result-meta-item">
        <span class="result-meta-val"><?= round($score, 1) ?>%</span>
        <span class="result-meta-key">Your Score</span>
      </div>
      <div class="result-meta-item">
        <span class="result-meta-val"><?= $result['pass_marks'] ?>%</span>
        <span class="result-meta-key">Pass Mark</span>
      </div>
      <div class="result-meta-item">
        <span class="result-meta-val"><?= $timeStr ?></span>
        <span class="result-meta-key">Time Taken</span>
      </div>
      <div class="result-meta-item">
        <span class="result-meta-val">
          <?= count(array_filter($questions, fn($q) => $q['selected_option'] === $q['correct_option'])) ?>
          / <?= count($questions) ?>
        </span>
        <span class="result-meta-key">Correct</span>
      </div>
    </div>

    <div style="margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="dashboard.php" class="btn-primary">
        <i class="bi bi-house"></i> Back to Dashboard
      </a>
      <a href="results.php" class="btn-ghost" style="padding:9px 20px;">
        <i class="bi bi-trophy"></i> All My Results
      </a>
    </div>
  </div>

  <!-- Question Breakdown -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-list-check"></i> Answer Review</h3>
    </div>
    <div class="card-body">
      <?php foreach ($questions as $i => $q): ?>
      <?php
      $status = !$q['selected_option'] ? 'skipped'
              : ($q['selected_option'] === $q['correct_option'] ? 'correct' : 'incorrect');
      $iconCls = $status === 'correct' ? 'q-correct' : ($status === 'incorrect' ? 'q-incorrect' : 'q-skipped');
      $icon    = $status === 'correct' ? 'check-lg' : ($status === 'incorrect' ? 'x-lg' : 'dash-lg');
      ?>
      <div class="q-review-card">
        <div class="q-review-header" onclick="this.nextElementSibling.classList.toggle('d-none')">
          <div class="q-review-icon <?= $iconCls ?>">
            <i class="bi bi-<?= $icon ?>"></i>
          </div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;">Q<?= $i+1 ?>. <?= sanitize(substr($q['question_text'], 0, 80)) ?>…</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
              <?= $status === 'skipped' ? 'Not answered' : 'Your answer: ' . $q['selected_option'] ?>
              · Correct: <?= $q['correct_option'] ?>
              · <?= $q['earned'] ?>/<?= $q['marks'] ?> marks
            </div>
          </div>
          <i class="bi bi-chevron-down" style="color:var(--text-muted)"></i>
        </div>
        <div class="d-none" style="padding:0 16px 16px;">
          <?php foreach (['A','B','C','D'] as $opt): ?>
          <?php
          $optKey   = 'option_' . strtolower($opt);
          $isAnswer = $q['selected_option'] === $opt;
          $isCorrect = $q['correct_option'] === $opt;
          $rowStyle = $isCorrect ? 'background:#ECFDF5;border-color:var(--success);'
                    : ($isAnswer && !$isCorrect ? 'background:#FEF2F2;border-color:var(--danger);' : '');
          ?>
          <div style="padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:6px;font-size:13px;<?= $rowStyle ?>">
            <strong><?= $opt ?>.</strong> <?= sanitize($q[$optKey]) ?>
            <?php if ($isCorrect): ?><i class="bi bi-check-circle-fill" style="color:var(--success);float:right;"></i><?php endif; ?>
            <?php if ($isAnswer && !$isCorrect): ?><i class="bi bi-x-circle-fill" style="color:var(--danger);float:right;"></i><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
