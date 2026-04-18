<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('student');

$db  = Database::getConnection();
$sid = currentUser()['id'];

// All results for this student
$results = $db->prepare("
    SELECT ea.*, e.title, e.total_marks, e.pass_marks, e.duration_mins,
           t.full_name AS teacher_name, d.dept_name,
           COUNT(DISTINCT cf.flag_id) AS flag_count
    FROM exam_attempts ea
    JOIN exams       e  ON e.exam_id      = ea.exam_id
    JOIN teachers    t  ON t.teacher_id   = e.teacher_id
    JOIN departments d  ON d.department_id = e.department_id
    LEFT JOIN cheating_flags cf ON cf.attempt_id = ea.attempt_id
    WHERE ea.student_id = ?
    GROUP BY ea.attempt_id
    ORDER BY ea.start_time DESC
");
$results->execute([$sid]);
$results = $results->fetchAll();

// Personal stats
$submitted = array_filter($results, fn($r)=>$r['status']==='submitted');
$scores    = array_filter(array_column(array_values($submitted),'score'), fn($s)=>$s!==null);
$passed    = count(array_filter($submitted, fn($r)=>$r['is_passed']));

$stats = [
    'total'    => count($results),
    'done'     => count($submitted),
    'passed'   => $passed,
    'failed'   => count($submitted) - $passed,
    'avg'      => $scores ? round(array_sum($scores)/count($scores),1) : 0,
    'best'     => $scores ? round(max($scores),1) : 0,
];

renderHead('My Results');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','My Results'); ?>
<div class="main-content">
<?php renderTopbar('My Results'); ?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:24px;">
  <?= statCard('Exams Taken',  $stats['total'],  'journal-check',   'blue')   ?>
  <?= statCard('Completed',    $stats['done'],   'check-circle',    'purple') ?>
  <?= statCard('Passed',       $stats['passed'], 'trophy-fill',     'green')  ?>
  <?= statCard('Failed',       $stats['failed'], 'x-circle',        'red')    ?>
  <?= statCard('Avg Score',    $stats['avg'].'%','bar-chart-fill',  'amber')  ?>
  <?= statCard('Best Score',   $stats['best'].'%','star-fill',      'cyan')   ?>
</div>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-trophy-fill" style="color:var(--amber)"></i> All Results</h3>
    <input type="text" id="resSearch" class="form-control" placeholder="Search…"
           style="width:180px;" oninput="filterTable('resSearch','resTable')">
  </div>
  <div class="table-wrap">
    <table class="data-table" id="resTable">
      <thead>
        <tr><th>Exam</th><th>Teacher</th><th>Dept</th><th>Score</th><th>Status</th><th>Time Taken</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <?php $timeTaken = $r['time_taken_secs'] ? sprintf('%02d:%02d', intdiv($r['time_taken_secs'],60), $r['time_taken_secs']%60) : '—'; ?>
        <tr>
          <td style="font-weight:700;"><?= sanitize($r['title']) ?></td>
          <td style="font-size:13px;"><?= sanitize($r['teacher_name']) ?></td>
          <td><span class="badge-pill badge-info" style="font-size:10px;"><?= sanitize($r['dept_name']) ?></span></td>
          <td>
            <?php if ($r['score'] !== null): ?>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="progress-bar-wrap" style="width:70px;">
                <div class="progress-bar-fill" style="width:<?= min(100,$r['score']) ?>%;
                  background:<?= $r['is_passed']?'var(--success)':'var(--danger)' ?>;"></div>
              </div>
              <span style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;">
                <?= round($r['score']) ?>%
              </span>
            </div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php if ($r['status']==='submitted'): ?>
              <span class="badge-pill <?= $r['is_passed']?'badge-success':'badge-danger' ?>">
                <?= $r['is_passed']?'Passed':'Failed' ?>
              </span>
            <?php elseif ($r['status']==='in_progress'): ?>
              <span class="badge-pill badge-warning">In Progress</span>
            <?php else: ?>
              <span class="badge-pill badge-secondary"><?= ucfirst($r['status']) ?></span>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:13px;"><?= $timeTaken ?></td>
          <td style="font-size:12px;color:var(--text-muted);"><?= date('M j, Y', strtotime($r['start_time'])) ?></td>
          <td>
            <?php if ($r['status']==='submitted'): ?>
            <a href="result.php?attempt_id=<?= $r['attempt_id'] ?>" class="btn-ghost btn-sm">
              <i class="bi bi-eye"></i> Review
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$results): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">
          No results yet. Take your first exam!
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>
<?php renderFooter(); ?>
