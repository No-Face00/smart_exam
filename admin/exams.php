<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('admin');

$db = Database::getConnection();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $eid    = (int)$_POST['exam_id'];
        $status = $_POST['new_status'];
        if (in_array($status, ['draft','scheduled','running','completed','cancelled'])) {
            $db->prepare("UPDATE exams SET status = ? WHERE exam_id = ?")->execute([$status, $eid]);
            setFlash('success', 'Exam status updated.');
        }
        header('Location: exams.php'); exit;
    }
    if (isset($_POST['delete_exam'])) {
        $eid = (int)$_POST['exam_id'];
        $db->prepare("DELETE FROM exams WHERE exam_id = ?")->execute([$eid]);
        setFlash('success', 'Exam deleted.');
        header('Location: exams.php'); exit;
    }
}

// Filters
$search    = get('q','');
$statusFlt = get('status','');
$deptFlt   = (int)get('dept',0);

$where  = ['1=1'];
$params = [];
if ($search)    { $where[] = 'e.title LIKE ?'; $params[] = "%$search%"; }
if ($statusFlt) { $where[] = 'e.status = ?'; $params[] = $statusFlt; }
if ($deptFlt)   { $where[] = 'e.department_id = ?'; $params[] = $deptFlt; }

$exams = $db->prepare("
    SELECT e.*, t.full_name AS teacher_name, d.dept_name, d.dept_code,
           COUNT(DISTINCT ea.attempt_id) AS attempt_count,
           COUNT(DISTINCT cf.flag_id)   AS flag_count,
           ROUND(AVG(ea.score),1)       AS avg_score,
           SUM(ea.is_passed=1)          AS passed_count
    FROM exams e
    JOIN teachers    t  ON t.teacher_id   = e.teacher_id
    JOIN departments d  ON d.department_id = e.department_id
    LEFT JOIN exam_attempts  ea ON ea.exam_id = e.exam_id
    LEFT JOIN cheating_flags cf ON cf.exam_id = e.exam_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY e.exam_id
    ORDER BY e.scheduled_start DESC
");
$exams->execute($params);
$exams = $exams->fetchAll();

// Summary stats
$summary = $db->query("
    SELECT
      COUNT(*)                       AS total,
      SUM(status='running')          AS running,
      SUM(status='scheduled')        AS scheduled,
      SUM(status='completed')        AS completed
    FROM exams
")->fetch();

$depts = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$flash = getFlash();

renderHead('Exam Overview');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','Exams'); ?>

<div class="main-content">
<?php renderTopbar('Exam Overview'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <?= statCard('Total Exams',  $summary['total'],     'journal-text',    'blue')   ?>
  <?= statCard('Running',      $summary['running'],   'play-circle-fill','green')  ?>
  <?= statCard('Scheduled',    $summary['scheduled'], 'calendar-event',  'amber')  ?>
  <?= statCard('Completed',    $summary['completed'], 'check-circle',    'purple') ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:14px 16px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
      <div>
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control" placeholder="Exam title…"
               value="<?= sanitize($search) ?>" style="width:200px;">
      </div>
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-control" style="width:140px;">
          <option value="">All</option>
          <?php foreach (['draft','scheduled','running','completed','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFlt===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Department</label>
        <select name="dept" class="form-control" style="width:180px;">
          <option value="0">All</option>
          <?php foreach ($depts as $d): ?>
          <option value="<?= $d['department_id'] ?>" <?= $deptFlt==$d['department_id']?'selected':'' ?>>
            <?= sanitize($d['dept_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-primary"><i class="bi bi-search"></i> Filter</button>
      <a href="exams.php" class="btn-ghost" style="padding:9px 16px;">Reset</a>
    </form>
  </div>
</div>

<!-- Exams Table -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-journal-text" style="color:var(--brand)"></i>
      All Exams <span style="font-size:13px;font-weight:500;color:var(--text-muted);">(<?= count($exams) ?>)</span>
    </h3>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Exam</th><th>Teacher</th><th>Dept</th><th>Scheduled</th><th>Duration</th><th>Status</th><th>Attempts</th><th>Avg Score</th><th>Flags</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($exams as $ex): ?>
        <?php
          $statusMap = ['draft'=>['secondary','Draft'],'scheduled'=>['info','Scheduled'],
            'running'=>['success','Running'],'completed'=>['secondary','Done'],'cancelled'=>['danger','Cancelled']];
          [$cls,$lbl] = $statusMap[$ex['status']] ?? ['secondary',$ex['status']];
        ?>
        <tr>
          <td>
            <div style="font-weight:700;max-width:200px;"><?= sanitize($ex['title']) ?></div>
            <?php if ($ex['is_randomized']): ?>
            <span style="font-size:10px;color:var(--text-muted);"><i class="bi bi-shuffle"></i> Randomized</span>
            <?php endif; ?>
          </td>
          <td style="font-size:13px;"><?= sanitize($ex['teacher_name']) ?></td>
          <td><span class="badge-pill badge-info"><?= sanitize($ex['dept_code']) ?></span></td>
          <td style="font-size:12px;">
            <?= date('M j, Y', strtotime($ex['scheduled_start'])) ?><br>
            <span style="color:var(--text-muted);"><?= date('g:i A', strtotime($ex['scheduled_start'])) ?></span>
          </td>
          <td><?= $ex['duration_mins'] ?>m</td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
              <select name="new_status" class="form-control"
                      style="width:110px;padding:5px 8px;font-size:12px;"
                      onchange="this.closest('form').querySelector('[name=update_status]').click()">
                <?php foreach (['draft','scheduled','running','completed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $ex['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="update_status" value="1" style="display:none;"></button>
            </form>
          </td>
          <td style="text-align:center;"><?= $ex['attempt_count'] ?></td>
          <td>
            <?php if ($ex['avg_score']): ?>
            <span style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;
              color:<?= $ex['avg_score']>=70?'var(--success)':($ex['avg_score']>=50?'var(--warning)':'var(--danger)') ?>;">
              <?= $ex['avg_score'] ?>%
            </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php if ($ex['flag_count'] > 0): ?>
            <a href="cheating.php?exam=<?= $ex['exam_id'] ?>" class="badge-pill badge-danger">
              <?= $ex['flag_count'] ?> ⚠
            </a>
            <?php else: ?>
            <span style="color:var(--success);"><i class="bi bi-shield-check"></i></span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:4px;">
              <a href="<?= BASE_URL ?>/api/export.php?exam_id=<?= $ex['exam_id'] ?>&format=csv"
                 class="btn-primary btn-sm btn-success" title="Export CSV">
                <i class="bi bi-download"></i>
              </a>
              <a href="<?= BASE_URL ?>/api/export.php?exam_id=<?= $ex['exam_id'] ?>&format=pdf"
                 target="_blank" class="btn-primary btn-sm" style="background:var(--danger);" title="View PDF">
                <i class="bi bi-file-earmark-pdf"></i>
              </a>
              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Delete this exam and all data?')">
                <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
                <input type="hidden" name="delete_exam" value="1">
                <button class="btn-primary btn-sm btn-danger" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$exams): ?>
        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">
          No exams found.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>
<?php renderFooter(); ?>
