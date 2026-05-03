<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('teacher');

$db  = Database::getConnection();
$tid = currentUser()['id'];

$exams = (new Exam())->byTeacher($tid);

// Stats
$stats = $db->prepare("
    SELECT
      COUNT(DISTINCT e.exam_id)                              AS total_exams,
      SUM(e.status = 'running')                             AS running,
      SUM(e.status = 'completed')                           AS completed,
      COUNT(DISTINCT ea.student_id)                         AS total_students,
      SUM(ea.is_passed = 1)                                 AS total_passed,
      COUNT(DISTINCT cf.flag_id)                            AS total_flags
    FROM exams e
    LEFT JOIN exam_attempts ea ON ea.exam_id = e.exam_id
    LEFT JOIN cheating_flags cf ON cf.exam_id = e.exam_id
    WHERE e.teacher_id = ?
");
$stats->execute([$tid]);
$stats = $stats->fetch();

// Recent attempts (live monitoring)
$liveAttempts = $db->prepare("
    SELECT ea.*, s.full_name, s.student_id_no, e.title AS exam_title, e.duration_mins,
           TIMESTAMPDIFF(MINUTE, ea.start_time, NOW()) AS mins_elapsed
    FROM exam_attempts ea
    JOIN students s ON s.student_id = ea.student_id
    JOIN exams    e ON e.exam_id    = ea.exam_id
    WHERE e.teacher_id = ? AND ea.status = 'in_progress'
    ORDER BY ea.start_time DESC
");
$liveAttempts->execute([$tid]);
$live = $liveAttempts->fetchAll();

// Cheating flags for this teacher's exams
$flags = $db->prepare("
    SELECT cf.*, s.full_name, e.title AS exam_title
    FROM cheating_flags cf
    JOIN students s ON s.student_id = cf.student_id
    JOIN exams    e ON e.exam_id    = cf.exam_id
    WHERE e.teacher_id = ? AND cf.action_taken = 'none'
    ORDER BY cf.detected_at DESC
    LIMIT 5
");
$flags->execute([$tid]);
$flags = $flags->fetchAll();

renderHead('Teacher Dashboard');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('teacher','Dashboard'); ?>

<div class="main-content">
<?php renderTopbar('Teacher Dashboard'); ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <?= statCard('My Exams',      $stats['total_exams'],      'journal-text',      'blue')   ?>
  <?= statCard('Running',       $stats['running'],           'play-circle-fill',  'green')  ?>
  <?= statCard('Completed',     $stats['completed'],         'check-circle-fill', 'purple') ?>
  <?= statCard('Students',      $stats['total_students'],    'people-fill',       'cyan')   ?>
  <?= statCard('Passed',        $stats['total_passed'],      'trophy-fill',       'amber')  ?>
  <?= statCard('Cheat Flags',   $stats['total_flags'],       'shield-exclamation','red')    ?>
</div>

<!-- Grid -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

  <!-- My Exams -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-journal-text" style="color:var(--brand)"></i> My Exams</h3>
      <a href="exams.php" class="btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Exam</a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Title</th><th>Status</th><th>Students</th><th>Flags</th><th></th></tr></thead>
        <tbody>
          <?php foreach (array_slice($exams,0,8) as $ex): ?>
          <?php
            $statusMap = ['draft'=>['secondary','Draft'],'scheduled'=>['info','Scheduled'],
              'running'=>['success','Running'],'completed'=>['secondary','Done'],'cancelled'=>['danger','Cancelled']];
            [$cls,$lbl] = $statusMap[$ex['status']] ?? ['secondary',$ex['status']];
          ?>
          <tr>
            <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= sanitize($ex['title']) ?>
            </td>
            <td><span class="badge-pill badge-<?= $cls ?>"><?= $lbl ?></span></td>
            <td><?= $ex['attempt_count'] ?></td>
            <td>
              <?php if ($ex['flag_count'] > 0): ?>
              <span class="badge-pill badge-danger"><?= $ex['flag_count'] ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <a href="exams.php?edit=<?= $ex['exam_id'] ?>" class="btn-ghost btn-sm">
                <i class="bi bi-pencil"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$exams): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);">
            No exams yet. <a href="exams.php" style="color:var(--brand);">Create one →</a>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Live Students -->
  <div class="card">
    <div class="card-header">
      <h3>
        <span style="display:inline-block;width:8px;height:8px;background:var(--success);border-radius:50%;margin-right:6px;animation:pulse 1.5s infinite;"></span>
        Live Attempts (<?= count($live) ?>)
      </h3>
      <style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}</style>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Student</th><th>Exam</th><th>Elapsed</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($live as $l): ?>
          <tr>
            <td>
              <div style="font-weight:600;"><?= sanitize($l['full_name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($l['student_id_no']) ?></div>
            </td>
            <td style="font-size:13px;"><?= sanitize($l['exam_title']) ?></td>
            <td>
              <span style="font-family:monospace;font-size:13px;color:var(--brand);">
                <?= $l['mins_elapsed'] ?>m
              </span>
              / <?= $l['duration_mins'] ?>m
            </td>
            <td style="font-size:11px;font-family:monospace;color:var(--text-muted);">
              <?= sanitize($l['ip_address']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$live): ?>
          <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted);">
            <i class="bi bi-person-check" style="font-size:28px;display:block;margin-bottom:8px;"></i>
            No students currently taking exams.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pending Cheating Flags -->
  <div class="card" style="grid-column:span 2;">
    <div class="card-header">
      <h3><i class="bi bi-shield-exclamation" style="color:var(--danger)"></i> Pending Cheating Flags</h3>
      <a href="cheating.php" class="btn-primary btn-sm" style="background:var(--danger);">Review All</a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Student</th><th>Exam</th><th>Flag Type</th><th>Risk</th><th>Detected</th></tr></thead>
        <tbody>
          <?php foreach ($flags as $f): ?>
          <tr>
            <td style="font-weight:600;"><?= sanitize($f['full_name']) ?></td>
            <td><?= sanitize($f['exam_title']) ?></td>
            <td style="font-size:13px;"><?= str_replace('_',' ',$f['flag_type']) ?></td>
            <td><span class="badge-pill risk-<?= $f['risk_level'] ?>"><?= strtoupper($f['risk_level']) ?></span></td>
            <td style="font-size:12px;color:var(--text-muted);"><?= date('M j, g:i a', strtotime($f['detected_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$flags): ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">
            <i class="bi bi-shield-check" style="color:var(--success);font-size:24px;"></i> No pending flags
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</div>
</div>
<?php renderFooter(); ?>
