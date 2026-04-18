<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('admin');

$db = Database::getConnection();

// ── Stats ──────────────────────────────────────────────
$stats = $db->query("
    SELECT
      (SELECT COUNT(*) FROM students  WHERE is_active=1)  AS total_students,
      (SELECT COUNT(*) FROM teachers  WHERE is_active=1)  AS total_teachers,
      (SELECT COUNT(*) FROM exams)                         AS total_exams,
      (SELECT COUNT(*) FROM exams     WHERE status='running')  AS running_exams,
      (SELECT COUNT(*) FROM exams     WHERE status='completed') AS done_exams,
      (SELECT COUNT(*) FROM cheating_flags WHERE action_taken='none') AS open_flags,
      (SELECT COUNT(*) FROM cheating_flags WHERE risk_level='high')   AS high_risk,
      (SELECT COUNT(*) FROM exam_attempts  WHERE status='in_progress') AS live_students
")->fetch();

// ── Recent cheating flags ──────────────────────────────
$recentFlags = $db->query("
    SELECT cf.*, s.full_name, s.roll_number, e.title AS exam_title
    FROM cheating_flags cf
    JOIN students s ON s.student_id = cf.student_id
    JOIN exams    e ON e.exam_id    = cf.exam_id
    ORDER BY cf.detected_at DESC
    LIMIT 8
")->fetchAll();

// ── Recent exam activity ──────────────────────────────
$recentExams = $db->query("
    SELECT * FROM v_exam_stats ORDER BY scheduled_start DESC LIMIT 6
")->fetchAll();

// ── System logs ──────────────────────────────────────
$recentLogs = $db->query("
    SELECT sl.*, 
           CASE sl.user_type
             WHEN 'student' THEN (SELECT full_name FROM students WHERE student_id = sl.user_id)
             WHEN 'teacher' THEN (SELECT full_name FROM teachers WHERE teacher_id = sl.user_id)
             WHEN 'admin'   THEN (SELECT full_name FROM admins   WHERE admin_id   = sl.user_id)
           END AS user_name
    FROM submission_logs sl
    ORDER BY logged_at DESC
    LIMIT 10
")->fetchAll();

renderHead('Admin Dashboard');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin', 'Dashboard'); ?>

<div class="main-content">
<?php renderTopbar('Admin Dashboard'); ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>">
  <i class="bi bi-info-circle-fill"></i> <?= sanitize($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- ── Stats Row ───────────────────────────────────── -->
<div class="stats-grid">
  <?= statCard('Total Students',  number_format($stats['total_students']),  'people-fill',         'blue') ?>
  <?= statCard('Total Teachers',  number_format($stats['total_teachers']),  'person-badge-fill',   'green') ?>
  <?= statCard('Total Exams',     number_format($stats['total_exams']),     'journal-text',        'purple') ?>
  <?= statCard('Running Now',     number_format($stats['running_exams']),   'play-circle-fill',    'cyan') ?>
  <?= statCard('Live Students',   number_format($stats['live_students']),   'person-check-fill',   'amber') ?>
  <?= statCard('Cheating Flags',  number_format($stats['open_flags']),      'shield-exclamation',  'red') ?>
</div>

<!-- ── Main Grid ────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

  <!-- Recent Exams -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-journal-text" style="color:var(--brand)"></i> Recent Exams</h3>
      <a href="exams.php" class="btn-primary btn-sm">View All</a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Title</th><th>Teacher</th><th>Status</th><th>Attempts</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentExams as $ex): ?>
          <tr>
            <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= sanitize($ex['title']) ?>
            </td>
            <td><?= sanitize($ex['teacher_name']) ?></td>
            <td>
              <?php
              $statusMap = [
                'draft'     => ['secondary', 'Draft'],
                'scheduled' => ['info',      'Scheduled'],
                'running'   => ['success',   'Running'],
                'completed' => ['secondary', 'Done'],
                'cancelled' => ['danger',    'Cancelled'],
              ];
              [$cls, $lbl] = $statusMap[$ex['status']] ?? ['secondary', $ex['status']];
              ?>
              <span class="badge-pill badge-<?= $cls ?>"><?= $lbl ?></span>
            </td>
            <td><?= $ex['total_attempts'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Cheating Flags -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-shield-exclamation" style="color:var(--danger)"></i> Cheating Alerts</h3>
      <a href="cheating.php" class="btn-primary btn-sm" style="background:var(--danger)">Review All</a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Student</th><th>Exam</th><th>Type</th><th>Risk</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentFlags as $f): ?>
          <tr>
            <td>
              <div style="font-weight:600;"><?= sanitize($f['full_name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($f['roll_number']) ?></div>
            </td>
            <td style="font-size:13px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= sanitize($f['exam_title']) ?>
            </td>
            <td><span style="font-size:11px;color:var(--text-muted);"><?= str_replace('_', ' ', $f['flag_type']) ?></span></td>
            <td>
              <span class="badge-pill risk-<?= $f['risk_level'] ?>">
                <?= strtoupper($f['risk_level']) ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentFlags): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:24px;">
            <i class="bi bi-shield-check" style="font-size:24px;"></i><br>No flags detected
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ── System Activity Log ───────────────────────────── -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-clock-history" style="color:var(--info)"></i> Recent Activity</h3>
    <a href="logs.php" class="btn-ghost btn-sm">View All Logs</a>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>User</th><th>Role</th><th>Action</th><th>IP Address</th><th>Time</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentLogs as $log): ?>
        <tr>
          <td style="font-weight:600;"><?= sanitize($log['user_name'] ?? 'Unknown') ?></td>
          <td><span class="badge-pill badge-info"><?= ucfirst($log['user_type']) ?></span></td>
          <td><?= sanitize($log['action']) ?></td>
          <td style="font-family:monospace;font-size:12px;"><?= sanitize($log['ip_address']) ?></td>
          <td style="font-size:12px;color:var(--text-muted);">
            <?= date('M j, g:i a', strtotime($log['logged_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- .main-content -->
</div><!-- .layout -->

<?php renderFooter(); ?>
