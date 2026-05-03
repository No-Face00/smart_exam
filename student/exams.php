<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('student');

$db      = Database::getConnection();
$student = currentUser();
$sid     = $student['id'];

$profile = $db->prepare("SELECT department_id FROM students WHERE student_id = ?");
$profile->execute([$sid]);
$deptId = $profile->fetch()['department_id'];

$examModel = new Exam();
$exams     = $examModel->availableForStudent($sid, $deptId);

// Also get past exams (completed)
$past = $db->prepare("
    SELECT ea.*, e.title, e.duration_mins, e.scheduled_start, t.full_name AS teacher_name
    FROM exam_attempts ea
    JOIN exams    e ON e.exam_id   = ea.exam_id
    JOIN teachers t ON t.teacher_id = e.teacher_id
    WHERE ea.student_id = ? AND ea.status IN ('submitted','timed_out','abandoned')
    ORDER BY ea.start_time DESC
    LIMIT 20
");
$past->execute([$sid]);
$pastExams = $past->fetchAll();

renderHead('My Exams');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','My Exams'); ?>
<div class="main-content">
<?php renderTopbar('My Exams'); ?>

<!-- Available Exams -->
<div style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
  <h2 style="font-size:16px;font-weight:700;">Available Exams</h2>
  <span style="font-size:13px;color:var(--text-muted);"><?= count($exams) ?> exam(s)</span>
</div>

<?php if ($exams): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:32px;">
  <?php foreach ($exams as $ex): ?>
  <?php
    $now   = time();
    $start = strtotime($ex['scheduled_start']);
    $end   = strtotime($ex['scheduled_end']);
    $taken = !empty($ex['attempt_id']);
    $submitted  = $ex['attempt_status'] === 'submitted';
    $inProgress = $ex['attempt_status'] === 'in_progress';
    $canTake    = !$taken && $now >= $start && $now <= $end;
    $notStarted = $now < $start;
  ?>
  <div class="card" style="<?= $canTake ? 'border-color:var(--brand);' : '' ?>">
    <div style="padding:20px 20px 0;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
        <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;line-height:1.3;flex:1;padding-right:8px;">
          <?= sanitize($ex['title']) ?>
        </div>
        <?php if ($submitted): ?>
          <span class="badge-pill badge-success">Done</span>
        <?php elseif ($inProgress): ?>
          <span class="badge-pill badge-warning">In Progress</span>
        <?php elseif ($canTake): ?>
          <span class="badge-pill" style="background:#EFF6FF;color:var(--brand);">Open Now</span>
        <?php elseif ($notStarted): ?>
          <span class="badge-pill badge-info">Upcoming</span>
        <?php else: ?>
          <span class="badge-pill badge-secondary">Closed</span>
        <?php endif; ?>
      </div>

      <div style="font-size:12px;color:var(--text-muted);line-height:2;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;"><i class="bi bi-person-fill"></i><?= sanitize($ex['teacher_name']) ?></div>
        <div style="display:flex;align-items:center;gap:8px;"><i class="bi bi-clock-fill"></i><?= $ex['duration_mins'] ?> minutes</div>
        <div style="display:flex;align-items:center;gap:8px;"><i class="bi bi-calendar-fill"></i><?= date('M j, Y g:i A', $start) ?></div>
        <div style="display:flex;align-items:center;gap:8px;"><i class="bi bi-hourglass-split"></i>Ends: <?= date('M j, Y g:i A', $end) ?></div>
      </div>
    </div>

    <div style="padding:0 20px 20px;">
      <?php if ($submitted): ?>
        <a href="result.php?attempt_id=<?= $ex['attempt_id'] ?>" class="btn-primary btn-sm" style="width:100%;justify-content:center;">
          <i class="bi bi-eye"></i> View Result (<?= round($ex['score']) ?>%)
        </a>
      <?php elseif ($inProgress): ?>
        <a href="take_exam.php?exam_id=<?= $ex['exam_id'] ?>" class="btn-primary btn-sm" style="width:100%;justify-content:center;background:var(--warning);">
          <i class="bi bi-play-fill"></i> Resume Exam
        </a>
      <?php elseif ($canTake): ?>
        <a href="take_exam.php?exam_id=<?= $ex['exam_id'] ?>"
           onclick="return confirm('Start exam now?\nDuration: <?= $ex['duration_mins'] ?> minutes\nTimer starts immediately.')"
           class="btn-primary btn-sm" style="width:100%;justify-content:center;">
          <i class="bi bi-play-fill"></i> Start Exam
        </a>
      <?php elseif ($notStarted): ?>
        <div style="text-align:center;padding:8px;font-size:13px;color:var(--brand);font-weight:600;">
          <i class="bi bi-clock"></i> Starts in
          <span id="cd-<?= $ex['exam_id'] ?>">…</span>
          <script>
          (function(){
            const el = document.getElementById('cd-<?= $ex['exam_id'] ?>');
            // Use server-side diff to avoid client timezone issues
            const secsLeft = <?= max(0, $start - time()) ?>;
            let remaining = secsLeft;
            function upd() {
              if (remaining <= 0) { el.textContent = 'Starting now…'; location.reload(); return; }
              const h = Math.floor(remaining/3600);
              const m = Math.floor((remaining%3600)/60);
              const s = remaining%60;
              el.textContent = (h>0?h+'h ':'')+m+'m '+s+'s';
              remaining--;
              setTimeout(upd, 1000);
            }
            upd();
          })();
          </script>
        </div>
      <?php else: ?>
        <div style="text-align:center;font-size:12px;color:var(--text-muted);padding:8px;">Exam window has closed</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div style="text-align:center;padding:48px;color:var(--text-muted);margin-bottom:32px;">
  <i class="bi bi-journal-x" style="font-size:48px;display:block;margin-bottom:12px;"></i>
  No available exams for your department right now.
</div>
<?php endif; ?>

<!-- Past Exams -->
<?php if ($pastExams): ?>
<div style="margin-bottom:8px;">
  <h2 style="font-size:16px;font-weight:700;">Completed Exams</h2>
</div>
<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Exam</th><th>Teacher</th><th>Score</th><th>Result</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pastExams as $r): ?>
        <tr>
          <td style="font-weight:600;"><?= sanitize($r['title']) ?></td>
          <td style="font-size:13px;"><?= sanitize($r['teacher_name']) ?></td>
          <td>
            <?php if ($r['score']!==null): ?>
            <span style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;
              color:<?= $r['is_passed']?'var(--success)':'var(--danger)' ?>;">
              <?= round($r['score']) ?>%
            </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span class="badge-pill <?= $r['is_passed']?'badge-success':'badge-danger' ?>">
              <?= $r['is_passed']?'Passed':'Failed' ?>
            </span>
          </td>
          <td style="font-size:12px;color:var(--text-muted);"><?= date('M j, Y', strtotime($r['start_time'])) ?></td>
          <td>
            <a href="result.php?attempt_id=<?= $r['attempt_id'] ?>" class="btn-ghost btn-sm">
              <i class="bi bi-eye"></i> Review
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div>
</div>
<?php renderFooter(); ?>
