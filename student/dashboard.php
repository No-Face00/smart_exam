<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('student');

$db      = Database::getConnection();
$auth    = new Auth();
$student = currentUser();
$sid     = $student['id'];

$profile = $db->prepare("
    SELECT s.*, sec.section_name, sem.semester_name, d.dept_name
    FROM students s
    JOIN sections    sec ON sec.section_id  = s.section_id
    JOIN semesters   sem ON sem.semester_id = s.semester_id
    JOIN departments d   ON d.department_id = s.department_id
    WHERE s.student_id = ?
");
$profile->execute([$sid]);
$profile = $profile->fetch();

$examModel   = new Exam();
$needsUpdate = $auth->studentNeedsUpdate($sid);
$available   = $examModel->availableForStudent($sid, $profile['department_id']);

$stats = $db->prepare("
    SELECT COUNT(*) AS total_attempts,
           SUM(status='submitted') AS completed,
           SUM(is_passed=1) AS passed,
           ROUND(AVG(CASE WHEN score IS NOT NULL THEN score END),1) AS avg_score,
           MAX(score) AS best_score
    FROM exam_attempts WHERE student_id = ?
");
$stats->execute([$sid]); $stats = $stats->fetch();

$results = $db->prepare("
    SELECT ea.*, e.title, e.total_marks, e.pass_marks, t.full_name AS teacher_name
    FROM exam_attempts ea
    JOIN exams   e ON e.exam_id    = ea.exam_id
    JOIN teachers t ON t.teacher_id = e.teacher_id
    WHERE ea.student_id = ?
    ORDER BY ea.start_time DESC LIMIT 6
");
$results->execute([$sid]); $results = $results->fetchAll();

$rankRow = $db->prepare("SELECT rank_pos FROM (SELECT student_id, RANK() OVER (ORDER BY AVG(score) DESC) AS rank_pos FROM exam_attempts WHERE status='submitted' GROUP BY student_id) r WHERE student_id = ?");
$rankRow->execute([$sid]); $rankRow = $rankRow->fetch();
$myRank = $rankRow ? '#' . $rankRow['rank_pos'] : '—';

$upcomingCnt = $db->prepare("SELECT COUNT(*) FROM exams WHERE department_id=? AND status IN('scheduled','running') AND exam_id NOT IN (SELECT exam_id FROM exam_attempts WHERE student_id=?)");
$upcomingCnt->execute([$profile['department_id'], $sid]);
$upcomingCnt = (int)$upcomingCnt->fetchColumn();

$flash = getFlash();
$greeting = date('H') < 12 ? 'Good morning' : (date('H') < 17 ? 'Good afternoon' : 'Good evening');
$fname = explode(' ', trim($student['name']))[0];

renderHead('Student Dashboard');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','Dashboard'); ?>

<div class="main-content">
<?php renderTopbar('My Dashboard'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?> fade-in">
  <i class="bi bi-info-circle-fill"></i><span><?= sanitize($flash['msg']) ?></span>
</div>
<?php endif; ?>

<?php if ($needsUpdate): ?>
<div class="update-banner fade-in">
  <i class="bi bi-calendar-exclamation-fill"></i>
  <div class="update-banner-body">
    <strong>Semester Update Recommended</strong>
    <span>It's been over 4 months since your last update. Please update your semester and section to keep exam access current.</span>
  </div>
  <a href="<?= BASE_URL ?>/student/update_semester.php?forced=1" class="btn btn-warning btn-sm">
    <i class="bi bi-arrow-repeat"></i> Update Now
  </a>
</div>
<?php endif; ?>

<!-- Greeting -->
<div class="page-header">
  <div class="page-header-left">
    <h1><?= $greeting ?>, <?= sanitize($fname) ?> 👋</h1>
    <p>Student ID: <strong><?= sanitize($profile['student_id_no'] ?? '—') ?></strong></p>
  </div>
</div>

<!-- Info Strip -->
<div class="info-strip fade-in">
  <div class="info-strip-item"><i class="bi bi-mortarboard-fill"></i><strong><?= sanitize($profile['semester_name']) ?></strong></div>
  <span class="info-strip-sep">·</span>
  <div class="info-strip-item"><i class="bi bi-people-fill"></i>Section <strong><?= sanitize($profile['section_name']) ?></strong></div>
  <span class="info-strip-sep">·</span>
  <div class="info-strip-item"><i class="bi bi-building"></i><strong><?= sanitize($profile['dept_name']) ?></strong></div>
  <a href="<?= BASE_URL ?>/student/update_semester.php" style="margin-left:auto;font-size:12.5px;color:var(--primary);font-weight:600;display:flex;align-items:center;gap:4px;">
    <i class="bi bi-pencil-square"></i> Update Semester
  </a>
</div>

<!-- Stats -->
<div class="stats-grid">
  <?= statCard('Exams Taken',  $stats['completed'] ?? 0,                'journal-check',   'blue') ?>
  <?= statCard('Passed',       $stats['passed'] ?? 0,                   'trophy-fill',     'green') ?>
  <?= statCard('Avg Score',    ($stats['avg_score'] ?? 0).'%',          'bar-chart-fill',  'purple') ?>
  <?= statCard('Best Score',   ($stats['best_score'] ?? 0).'%',         'star-fill',       'amber') ?>
  <?= statCard('Upcoming',     $upcomingCnt,                            'calendar-event',  'cyan') ?>
  <?= statCard('My Rank',      $myRank,                                 'award-fill',      'red') ?>
</div>

<!-- Main Grid -->
<div style="display:grid;grid-template-columns:1fr;gap:24px;">

  <!-- Available Exams -->
  <div class="card fade-in">
    <div class="card-header">
      <h3><i class="bi bi-journal-text"></i> Available Exams</h3>
      <a href="exams.php" class="btn btn-ghost btn-sm">View All <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php if ($available): ?>
    <div class="exam-grid">
      <?php foreach ($available as $ex):
        $now       = time();
        $start     = strtotime($ex['scheduled_start']);
        $end       = strtotime($ex['scheduled_end']);
        $submitted = $ex['attempt_status'] === 'submitted';
        $inProg    = $ex['attempt_status'] === 'in_progress';
        $canTake   = !$ex['attempt_id'] && $now >= $start && $now <= $end;
        $notYet    = $now < $start;

        $cardClass = $submitted ? 'exam-done' : ($canTake ? 'exam-open' : ($now > $end && !$submitted ? 'exam-missed' : ''));
        $typeMap   = ['quiz'=>['badge-info','Quiz'],'midterm'=>['badge-warning','Midterm'],'final'=>['badge-danger','Final']];
        [$tbadge,$tlabel] = $typeMap[$ex['exam_type'] ?? 'quiz'] ?? ['badge-info','Quiz'];
      ?>
      <div class="exam-card <?= $cardClass ?>">
        <div class="exam-card-header">
          <div class="exam-card-title"><?= sanitize($ex['title']) ?></div>
          <span class="badge-pill <?= $tbadge ?>"><?= $tlabel ?></span>
        </div>

        <div class="exam-card-meta">
          <div class="exam-card-meta-item"><i class="bi bi-person-fill"></i><?= sanitize($ex['teacher_name']) ?></div>
          <div class="exam-card-meta-item"><i class="bi bi-clock-fill"></i><?= $ex['duration_mins'] ?> minutes</div>
          <div class="exam-card-meta-item"><i class="bi bi-calendar-fill"></i><?= date('M j, Y · g:i A', $start) ?></div>
          <?php if (!empty($ex['semester_name'])): ?>
          <div class="exam-card-meta-item"><i class="bi bi-mortarboard-fill"></i><?= sanitize($ex['semester_name']) ?></div>
          <?php endif; ?>
        </div>

        <?php if ($submitted): ?>
          <a href="result.php?attempt_id=<?= $ex['attempt_id'] ?>" class="btn btn-secondary btn-sm btn-full">
            <i class="bi bi-eye-fill"></i> View Result — <?= round($ex['score']) ?>%
          </a>
        <?php elseif ($inProg): ?>
          <a href="take_exam.php?exam_id=<?= $ex['exam_id'] ?>" class="btn btn-warning btn-sm btn-full">
            <i class="bi bi-play-fill"></i> Resume Exam
          </a>
        <?php elseif ($canTake): ?>
          <a href="take_exam.php?exam_id=<?= $ex['exam_id'] ?>"
             onclick="return confirm('Start: <?= sanitize($ex['title']) ?>?\nDuration: <?= $ex['duration_mins'] ?> min. Timer begins now.')"
             class="btn btn-primary btn-sm btn-full">
            <i class="bi bi-play-fill"></i> Start Exam
          </a>
        <?php elseif ($notYet): ?>
          <div style="font-size:12.5px;color:var(--text-muted);display:flex;align-items:center;gap:6px;justify-content:center;padding:4px;">
            <i class="bi bi-clock"></i> Starts in <strong id="cd-<?= $ex['exam_id'] ?>">…</strong>
            <script>
              (function(){
                const t=<?= $start ?>*1000, el=document.getElementById('cd-<?= $ex['exam_id'] ?>');
                function u(){const d=Math.max(0,t-Date.now()),h=~~(d/3600000),m=~~(d%3600000/60000),s=~~(d%60000/1000);el.textContent=h+'h '+m+'m '+s+'s';if(d>0)setTimeout(u,1000);}u();
              })();
            </script>
          </div>
        <?php else: ?>
          <div style="font-size:12.5px;color:var(--text-muted);text-align:center;padding:4px;">Exam window closed</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <i class="bi bi-journal"></i>
      <h4>No exams available</h4>
      <p>No exams are currently assigned to your semester and section. Check back later.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent Results -->
  <div class="card fade-in">
    <div class="card-header">
      <h3><i class="bi bi-trophy-fill"></i> Recent Results</h3>
      <a href="results.php" class="btn btn-ghost btn-sm">All Results <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Exam</th><th>Score</th><th>Status</th><th>Time</th><th>Date</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r):
            $tt = $r['time_taken_secs'] ? sprintf('%02d:%02d', intdiv($r['time_taken_secs'],60), $r['time_taken_secs']%60) : '—';
          ?>
          <tr>
            <td style="font-weight:600;color:var(--text);"><?= sanitize($r['title']) ?></td>
            <td>
              <?php if ($r['score'] !== null): ?>
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="progress-bar-wrap" style="width:56px;">
                  <div class="progress-bar-fill" style="width:<?= min(100,$r['score']) ?>%;
                    background:<?= $r['is_passed'] ? 'var(--success)' : 'var(--danger)' ?>;"></div>
                </div>
                <span style="font-weight:700;"><?= round($r['score']) ?>%</span>
              </div>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ($r['status']==='submitted'): ?>
                <span class="badge-pill <?= $r['is_passed']?'badge-success':'badge-danger' ?>">
                  <?= $r['is_passed'] ? 'Passed' : 'Failed' ?>
                </span>
              <?php elseif ($r['status']==='in_progress'): ?>
                <span class="badge-pill badge-warning">In Progress</span>
              <?php else: ?>
                <span class="badge-pill badge-gray"><?= ucfirst($r['status']) ?></span>
              <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:13px;"><?= $tt ?></td>
            <td style="color:var(--text-muted);font-size:12.5px;"><?= date('M j, Y', strtotime($r['start_time'])) ?></td>
            <td>
              <?php if ($r['status']==='submitted'): ?>
              <a href="result.php?attempt_id=<?= $r['attempt_id'] ?>" class="btn btn-ghost btn-sm">
                <i class="bi bi-eye"></i> Review
              </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$results): ?>
          <tr><td colspan="6"><div class="empty-state" style="padding:32px 0;">
            <i class="bi bi-journal"></i><h4>No results yet</h4><p>Take your first exam to see results here.</p>
          </div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- grid -->
</div><!-- .main-content -->
</div><!-- .layout -->
<?php renderFooter(); ?>
