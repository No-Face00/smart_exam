<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('student');

$db      = Database::getConnection();
$student = currentUser();
$sid     = $student['id'];

// Fetch student profile (need dept_id)
$profile = $db->prepare("SELECT * FROM students WHERE student_id = ?");
$profile->execute([$sid]);
$profile = $profile->fetch();

$examModel = new Exam();

// Stats
$stats = $db->prepare("
    SELECT
      COUNT(*)                                           AS total_attempts,
      SUM(status = 'submitted')                         AS completed,
      SUM(is_passed = 1)                                AS passed,
      ROUND(AVG(CASE WHEN score IS NOT NULL THEN score END), 1) AS avg_score,
      MAX(score)                                        AS best_score
    FROM exam_attempts
    WHERE student_id = ?
");
$stats->execute([$sid]);
$stats = $stats->fetch();

// Available exams
$available = $examModel->availableForStudent($sid, $profile['department_id']);

// Past results
$results = $db->prepare("
    SELECT ea.*, e.title, e.total_marks, e.pass_marks,
           t.full_name AS teacher_name
    FROM exam_attempts ea
    JOIN exams   e ON e.exam_id   = ea.exam_id
    JOIN teachers t ON t.teacher_id = e.teacher_id
    WHERE ea.student_id = ?
    ORDER BY ea.start_time DESC
    LIMIT 6
");
$results->execute([$sid]);
$results = $results->fetchAll();

// Leaderboard rank for this student
$rank = $db->prepare("
    SELECT rank_pos FROM (
        SELECT student_id,
               RANK() OVER (ORDER BY AVG(score) DESC) AS rank_pos
        FROM exam_attempts
        WHERE status = 'submitted'
        GROUP BY student_id
    ) r WHERE student_id = ?
");
$rank->execute([$sid]);
$rankRow = $rank->fetch();
$myRank  = $rankRow ? $rankRow['rank_pos'] : '—';

// Upcoming exams count
$upcoming = $db->prepare("
    SELECT COUNT(*) AS cnt FROM exams
    WHERE department_id = ?
      AND status IN ('scheduled','running')
      AND exam_id NOT IN (
        SELECT exam_id FROM exam_attempts WHERE student_id = ?
      )
");
$upcoming->execute([$profile['department_id'], $sid]);
$upcomingCnt = $upcoming->fetch()['cnt'];

// Flash
$flash = getFlash();

renderHead('Student Dashboard');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','Dashboard'); ?>

<div class="main-content">
<?php renderTopbar('My Dashboard'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>">
  <i class="bi bi-info-circle-fill"></i> <?= sanitize($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Greeting -->
<div style="margin-bottom:24px;">
  <h2 style="font-size:22px;font-weight:800;">
    Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>,
    <?= sanitize(explode(' ', $student['name'])[0]) ?> 👋
  </h2>
  <p style="color:var(--text-muted);font-size:14px;">
    <?= sanitize($profile['roll_number']) ?> · <?= sanitize($profile['batch_year']) ?> Batch
  </p>
</div>

<!-- Stats -->
<div class="stats-grid">
  <?= statCard('Exams Taken',    $stats['completed'] ?? 0,   'journal-check',     'blue')   ?>
  <?= statCard('Passed',         $stats['passed'] ?? 0,       'trophy-fill',       'green')  ?>
  <?= statCard('Avg Score',      ($stats['avg_score'] ?? 0) . '%', 'bar-chart-fill', 'purple') ?>
  <?= statCard('Best Score',     ($stats['best_score'] ?? 0) . '%', 'star-fill',    'amber')  ?>
  <?= statCard('Upcoming',       $upcomingCnt,                'calendar-event',    'cyan')   ?>
  <?= statCard('My Rank',        '#' . $myRank,               'award-fill',        'red')    ?>
</div>

<!-- Main Grid -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

  <!-- Available Exams -->
  <div class="card" style="grid-column:span 2;">
    <div class="card-header">
      <h3><i class="bi bi-journal-text" style="color:var(--brand)"></i> Available Exams</h3>
      <a href="exams.php" class="btn-ghost btn-sm">See All</a>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if ($available): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:20px;">
        <?php foreach ($available as $ex): ?>
        <?php
          $now    = time();
          $start  = strtotime($ex['scheduled_start']);
          $end    = strtotime($ex['scheduled_end']);
          $taken  = !empty($ex['attempt_id']);
          $submitted = $ex['attempt_status'] === 'submitted';
          $inProgress = $ex['attempt_status'] === 'in_progress';
          $canTake = !$taken && $now >= $start && $now <= $end;
          $notStarted = $now < $start;
        ?>
        <div style="background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius);padding:18px;
                    <?= $canTake ? 'border-color:var(--brand);' : '' ?>">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
            <div style="font-weight:700;font-size:15px;line-height:1.3;">
              <?= sanitize($ex['title']) ?>
            </div>
            <?php if ($submitted): ?>
              <span class="badge-pill badge-success">Done</span>
            <?php elseif ($inProgress): ?>
              <span class="badge-pill badge-warning">In Progress</span>
            <?php elseif ($canTake): ?>
              <span class="badge-pill badge-success" style="background:#EFF6FF;color:var(--brand);">Open</span>
            <?php elseif ($notStarted): ?>
              <span class="badge-pill badge-info">Upcoming</span>
            <?php else: ?>
              <span class="badge-pill badge-secondary">Closed</span>
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.8;">
            <div><i class="bi bi-person"></i> <?= sanitize($ex['teacher_name']) ?></div>
            <div><i class="bi bi-clock"></i> <?= $ex['duration_mins'] ?> minutes</div>
            <div><i class="bi bi-calendar"></i> <?= date('M j, Y g:i A', strtotime($ex['scheduled_start'])) ?></div>
          </div>
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
               onclick="return confirm('Start exam: <?= sanitize($ex['title']) ?>?\nDuration: <?= $ex['duration_mins'] ?> minutes\nOnce started, the timer begins.')"
               class="btn-primary btn-sm" style="width:100%;justify-content:center;">
              <i class="bi bi-play-fill"></i> Start Exam
            </a>
          <?php elseif ($notStarted): ?>
            <div style="font-size:12px;color:var(--text-muted);text-align:center;padding:4px 0;">
              Starts in <strong id="countdown-<?= $ex['exam_id'] ?>">…</strong>
              <script>
                (function() {
                  const target = <?= $start ?> * 1000;
                  const el = document.getElementById('countdown-<?= $ex['exam_id'] ?>');
                  function update() {
                    const diff = Math.max(0, target - Date.now());
                    const h = Math.floor(diff/3600000), m = Math.floor((diff%3600000)/60000), s = Math.floor((diff%60000)/1000);
                    el.textContent = h+'h '+m+'m '+s+'s';
                    if (diff > 0) setTimeout(update, 1000);
                  }
                  update();
                })();
              </script>
            </div>
          <?php else: ?>
            <div style="font-size:12px;color:var(--text-muted);text-align:center;">Exam window closed</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:48px;color:var(--text-muted);">
        <i class="bi bi-journal" style="font-size:40px;display:block;margin-bottom:12px;"></i>
        No exams available right now. Check back later.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Results -->
  <div class="card" style="grid-column:span 2;">
    <div class="card-header">
      <h3><i class="bi bi-trophy-fill" style="color:var(--amber)"></i> Recent Results</h3>
      <a href="results.php" class="btn-ghost btn-sm">All Results</a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Exam</th><th>Teacher</th><th>Score</th><th>Status</th><th>Time Taken</th><th>Date</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r): ?>
          <?php $timeTaken = $r['time_taken_secs'] ? sprintf('%02d:%02d', intdiv($r['time_taken_secs'],60), $r['time_taken_secs']%60) : '—'; ?>
          <tr>
            <td style="font-weight:600;"><?= sanitize($r['title']) ?></td>
            <td style="font-size:13px;"><?= sanitize($r['teacher_name']) ?></td>
            <td>
              <?php if ($r['score'] !== null): ?>
              <div style="display:flex;align-items:center;gap:8px;">
                <div class="progress-bar-wrap" style="width:60px;">
                  <div class="progress-bar-fill" style="width:<?= min(100,$r['score']) ?>%;
                    background:<?= $r['is_passed'] ? 'var(--success)' : 'var(--danger)' ?>;"></div>
                </div>
                <span style="font-weight:700;"><?= round($r['score']) ?>%</span>
              </div>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ($r['status'] === 'submitted'): ?>
                <span class="badge-pill <?= $r['is_passed'] ? 'badge-success' : 'badge-danger' ?>">
                  <?= $r['is_passed'] ? 'Passed' : 'Failed' ?>
                </span>
              <?php elseif ($r['status'] === 'in_progress'): ?>
                <span class="badge-pill badge-warning">In Progress</span>
              <?php else: ?>
                <span class="badge-pill badge-secondary"><?= ucfirst($r['status']) ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px;font-family:monospace;"><?= $timeTaken ?></td>
            <td style="font-size:12px;color:var(--text-muted);">
              <?= date('M j, Y', strtotime($r['start_time'])) ?>
            </td>
            <td>
              <?php if ($r['status'] === 'submitted'): ?>
              <a href="result.php?attempt_id=<?= $r['attempt_id'] ?>" class="btn-ghost btn-sm">
                <i class="bi bi-eye"></i> Review
              </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$results): ?>
          <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">
            No results yet. Take your first exam!
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- grid -->
</div><!-- .main-content -->
</div><!-- .layout -->

<?php renderFooter(); ?>
