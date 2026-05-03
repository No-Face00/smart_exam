<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/CheatingEngine.php';

requireLogin('admin');

$db     = Database::getConnection();
$engine = new CheatingEngine();

$examId    = (int)get('exam_id');
$studentA  = (int)get('student_a');
$studentB  = (int)get('student_b');
$studentId = (int)get('student_id'); // single-student investigation mode

if (!$examId) { header('Location: investigate.php'); exit; }

// If single student_id mode, pick top matched pair
if ($studentId && !$studentA) {
    $studentA = $studentId;
    $matrix = $engine->similarityMatrix($examId, 0);
    foreach ($matrix as $pair) {
        if ($pair['student_a'] == $studentId) { $studentB = $pair['student_b']; break; }
        if ($pair['student_b'] == $studentId) { $studentA = $pair['student_a']; $studentB = $studentId; break; }
    }
}

// Exam info
$examStmt = $db->prepare("SELECT e.*, t.full_name AS teacher_name FROM exams e
                           JOIN teachers t ON t.teacher_id=e.teacher_id WHERE e.exam_id=?");
$examStmt->execute([$examId]);
$exam = $examStmt->fetch();
if (!$exam) { header('Location: investigate.php'); exit; }

// All students in this exam
$students = $db->prepare("
    SELECT s.student_id, s.full_name, s.student_id_no, ea.attempt_id, ea.score, ea.time_taken_secs
    FROM exam_attempts ea JOIN students s ON s.student_id = ea.student_id
    WHERE ea.exam_id = ? AND ea.status = 'submitted'
    ORDER BY s.full_name
");
$students->execute([$examId]);
$students = $students->fetchAll();

// Default to first two
if (!$studentA && count($students) >= 1) $studentA = $students[0]['student_id'];
if (!$studentB && count($students) >= 2) $studentB = $students[1]['student_id'];

// Get attempt IDs
function getAttempt(PDO $db, int $examId, int $studentId): ?array {
    $s = $db->prepare("SELECT attempt_id, score, time_taken_secs, ip_address, submit_time
                       FROM exam_attempts WHERE exam_id=? AND student_id=?");
    $s->execute([$examId, $studentId]);
    return $s->fetch() ?: null;
}
function getStudent(PDO $db, int $sid): ?array {
    $s = $db->prepare("SELECT * FROM students WHERE student_id=?");
    $s->execute([$sid]);
    return $s->fetch() ?: null;
}

$attemptA = $studentA ? getAttempt($db, $examId, $studentA) : null;
$attemptB = $studentB ? getAttempt($db, $examId, $studentB) : null;
$sA       = $studentA ? getStudent($db, $studentA) : null;
$sB       = $studentB ? getStudent($db, $studentB) : null;

// Compare answers
$comparison = ($attemptA && $attemptB)
    ? $engine->compareAnswers($attemptA['attempt_id'], $attemptB['attempt_id'])
    : [];

// Stats
$stats = ['both_correct'=>0,'both_wrong_same'=>0,'different'=>0,'one_skipped'=>0,'both_skipped'=>0];
foreach ($comparison as $q) {
    $stats[$q['match_type']] = ($stats[$q['match_type']] ?? 0) + 1;
}
$total      = count($comparison);
$similarity = $total ? round(($stats['both_correct'] + $stats['both_wrong_same']) / $total * 100, 1) : 0;
$wrongMatch = $stats['both_wrong_same'];

// Similarity matrix for selector
$matrix = $engine->similarityMatrix($examId, 0);

renderHead('Answer Comparison');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<style>
.compare-header {
  display:grid;
  grid-template-columns:1fr 60px 1fr;
  gap:0;
  background:var(--bg-card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  margin-bottom:20px;
  overflow:hidden;
}
.compare-student {
  padding:20px;
  text-align:center;
}
.compare-vs {
  display:flex;
  align-items:center;
  justify-content:center;
  background:var(--bg);
  font-family:'Syne',sans-serif;
  font-weight:800;
  font-size:14px;
  color:var(--text-muted);
  border-left:1px solid var(--border);
  border-right:1px solid var(--border);
}
.compare-avatar {
  width:56px;height:56px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;
  margin:0 auto 10px;
}
.q-row {
  display:grid;
  grid-template-columns:1fr 48px 1fr;
  gap:0;
  border-bottom:1px solid var(--border);
}
.q-row:last-child { border-bottom:none; }
.q-cell {
  padding:14px 16px;
}
.q-center {
  display:flex;
  align-items:center;
  justify-content:center;
  background:var(--bg);
  border-left:1px solid var(--border);
  border-right:1px solid var(--border);
  font-size:18px;
}
.answer-badge {
  display:inline-flex;
  width:32px;height:32px;
  border-radius:50%;
  align-items:center;justify-content:center;
  font-weight:800;font-size:14px;
  flex-shrink:0;
}
.ans-correct  { background:#ECFDF5;color:#065F46;border:2px solid var(--success); }
.ans-wrong    { background:#FEF2F2;color:#991B1B;border:2px solid var(--danger); }
.ans-skipped  { background:var(--bg);color:var(--text-muted);border:2px solid var(--border); }
</style>

<div class="layout">
<?php renderSidebar('admin','Cheating Flags'); ?>

<div class="main-content">
<?php renderTopbar('Side-by-Side Answer Comparison'); ?>

<!-- Exam banner -->
<div style="background:var(--brand);color:#fff;border-radius:var(--radius);padding:14px 20px;
            margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:12px;opacity:.7;">Forensic Analysis —</div>
    <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;"><?= sanitize($exam['title']) ?></div>
  </div>
  <a href="investigate.php?exam_id=<?= $examId ?>&tab=similarity" class="btn-ghost"
     style="color:#fff;border-color:rgba(255,255,255,.4);padding:8px 16px;font-size:13px;">
    <i class="bi bi-arrow-left"></i> Back to Investigation
  </a>
</div>

<!-- Student selectors -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 16px;">
    <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <div>
        <label class="form-label">Student A</label>
        <select name="student_a" class="form-control" style="width:220px;">
          <?php foreach ($students as $s): ?>
          <option value="<?= $s['student_id'] ?>" <?= $studentA==$s['student_id']?'selected':'' ?>>
            <?= sanitize($s['full_name']) ?> (<?= sanitize($s['student_id_no']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:6px;">VS</div>
      <div>
        <label class="form-label">Student B</label>
        <select name="student_b" class="form-control" style="width:220px;">
          <?php foreach ($students as $s): ?>
          <option value="<?= $s['student_id'] ?>" <?= $studentB==$s['student_id']?'selected':'' ?>>
            <?= sanitize($s['full_name']) ?> (<?= sanitize($s['student_id_no']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-primary"><i class="bi bi-columns-gap"></i> Compare</button>
    </form>
  </div>
</div>

<?php if ($sA && $sB && $attemptA && $attemptB): ?>

<!-- Similarity summary badges -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;">
  <?php
  $summaryItems = [
    ['Overall Similarity', $similarity . '%', $similarity >= 80 ? 'var(--danger)' : ($similarity >= 60 ? 'var(--warning)' : 'var(--success)')],
    ['Both Correct',       $stats['both_correct'],     'var(--success)'],
    ['Both Wrong (Same)',  $stats['both_wrong_same'],  $wrongMatch > 0 ? 'var(--danger)' : 'var(--text-muted)'],
    ['Different Answers',  $stats['different'],        'var(--brand)'],
    ['Skipped',            $stats['one_skipped'] + $stats['both_skipped'], 'var(--text-muted)'],
  ];
  foreach ($summaryItems as [$label, $val, $color]): ?>
  <div class="card" style="padding:16px;text-align:center;">
    <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:<?= $color ?>;"><?= $val ?></div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($wrongMatch > 0): ?>
<div class="flash-alert flash-danger" style="margin-bottom:20px;">
  <i class="bi bi-exclamation-octagon-fill"></i>
  <strong>⚠ Strong evidence of copying:</strong>
  <?= $wrongMatch ?> identical wrong answers detected.
  Students independently choosing the same wrong options on <?= $wrongMatch ?> question(s) is statistically improbable.
</div>
<?php endif; ?>

<!-- Student headers -->
<div class="compare-header">
  <div class="compare-student">
    <div class="compare-avatar" style="background:var(--brand);">
      <?= strtoupper(substr($sA['full_name'],0,2)) ?>
    </div>
    <div style="font-weight:800;font-size:16px;"><?= sanitize($sA['full_name']) ?></div>
    <div style="font-size:12px;color:var(--text-muted);"><?= sanitize($sA['student_id_no']) ?></div>
    <div style="margin-top:8px;font-size:13px;">
      Score: <strong><?= round($attemptA['score'] ?? 0) ?>%</strong>
      &nbsp;·&nbsp;
      Time: <strong><?= sprintf('%02d:%02d', intdiv($attemptA['time_taken_secs'] ?? 0, 60), ($attemptA['time_taken_secs'] ?? 0) % 60) ?></strong>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;font-family:monospace;"><?= sanitize($attemptA['ip_address']) ?></div>
  </div>
  <div class="compare-vs">VS</div>
  <div class="compare-student">
    <div class="compare-avatar" style="background:var(--danger);">
      <?= strtoupper(substr($sB['full_name'],0,2)) ?>
    </div>
    <div style="font-weight:800;font-size:16px;"><?= sanitize($sB['full_name']) ?></div>
    <div style="font-size:12px;color:var(--text-muted);"><?= sanitize($sB['student_id_no']) ?></div>
    <div style="margin-top:8px;font-size:13px;">
      Score: <strong><?= round($attemptB['score'] ?? 0) ?>%</strong>
      &nbsp;·&nbsp;
      Time: <strong><?= sprintf('%02d:%02d', intdiv($attemptB['time_taken_secs'] ?? 0, 60), ($attemptB['time_taken_secs'] ?? 0) % 60) ?></strong>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;font-family:monospace;"><?= sanitize($attemptB['ip_address']) ?></div>
  </div>
</div>

<!-- Question comparison grid -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-columns-gap" style="color:var(--brand)"></i> Question-by-Question Comparison</h3>
    <div style="display:flex;gap:12px;font-size:12px;">
      <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:var(--success);display:inline-block;"></span>Correct</span>
      <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:var(--danger);display:inline-block;"></span>Wrong</span>
      <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:var(--border);display:inline-block;"></span>Same Wrong ⚠</span>
    </div>
  </div>
  <div>
    <?php foreach ($comparison as $i => $q): ?>
    <?php
      $isMatch    = $q['answer_a'] && $q['answer_b'] && $q['answer_a'] === $q['answer_b'];
      $bothWrong  = $q['match_type'] === 'both_wrong_same';
      $rowBg      = $bothWrong
                      ? 'background:rgba(239,68,68,.04);'
                      : ($isMatch && $q['match_type'] === 'both_correct'
                          ? 'background:rgba(16,185,129,.03);'
                          : '');

      // Answer badge class
      $clsA = !$q['answer_a'] ? 'ans-skipped'
            : ($q['answer_a'] === $q['correct_option'] ? 'ans-correct' : 'ans-wrong');
      $clsB = !$q['answer_b'] ? 'ans-skipped'
            : ($q['answer_b'] === $q['correct_option'] ? 'ans-correct' : 'ans-wrong');

      // Match icon
      $matchIcon = match($q['match_type']) {
        'both_correct'    => '<i class="bi bi-check2-circle" style="color:var(--success);font-size:18px;"></i>',
        'both_wrong_same' => '<i class="bi bi-exclamation-triangle-fill" style="color:var(--danger);font-size:16px;"></i>',
        'different'       => '<i class="bi bi-arrow-left-right" style="color:var(--text-muted);font-size:14px;"></i>',
        default           => '<i class="bi bi-dash" style="color:var(--text-muted);"></i>',
      };
    ?>
    <div class="q-row" style="<?= $rowBg ?>">
      <!-- Student A answer -->
      <div class="q-cell">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Q<?= $i+1 ?></div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;line-height:1.4;">
          <?= sanitize(substr($q['question_text'], 0, 80)) ?>
          <?= strlen($q['question_text']) > 80 ? '…' : '' ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="answer-badge <?= $clsA ?>"><?= $q['answer_a'] ?? '—' ?></span>
          <?php if ($q['answer_a']): ?>
          <span style="font-size:12px;">
            <?= sanitize($q['option_' . strtolower($q['answer_a'])]) ?>
          </span>
          <?php else: ?>
          <span style="font-size:12px;color:var(--text-muted);">Not answered</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Match indicator -->
      <div class="q-center"><?= $matchIcon ?></div>

      <!-- Student B answer -->
      <div class="q-cell">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">
          Correct: <strong><?= $q['correct_option'] ?></strong>
          <?php if ($bothWrong): ?><span style="color:var(--danger);"> ⚠ Both Wrong</span><?php endif; ?>
        </div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;line-height:1.4;opacity:0;">
          placeholder
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="answer-badge <?= $clsB ?>"><?= $q['answer_b'] ?? '—' ?></span>
          <?php if ($q['answer_b']): ?>
          <span style="font-size:12px;">
            <?= sanitize($q['option_' . strtolower($q['answer_b'])]) ?>
          </span>
          <?php else: ?>
          <span style="font-size:12px;color:var(--text-muted);">Not answered</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php else: ?>
<div style="text-align:center;padding:60px;color:var(--text-muted);">
  Select two students above to compare their answers side by side.
</div>
<?php endif; ?>

</div>
</div>
<?php renderFooter(); ?>
