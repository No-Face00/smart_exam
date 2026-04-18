<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('admin');

$db = Database::getConnection();

$actionFlt = get('action','');
$roleFlt   = get('role','');
$ipFlt     = get('ip','');

$where  = ['1=1'];
$params = [];
if ($actionFlt) { $where[] = 'sl.action = ?'; $params[] = $actionFlt; }
if ($roleFlt)   { $where[] = 'sl.user_type = ?'; $params[] = $roleFlt; }
if ($ipFlt)     { $where[] = 'sl.ip_address LIKE ?'; $params[] = "%$ipFlt%"; }

$logs = $db->prepare("
    SELECT sl.*,
           CASE sl.user_type
             WHEN 'student' THEN (SELECT full_name FROM students WHERE student_id = sl.user_id)
             WHEN 'teacher' THEN (SELECT full_name FROM teachers WHERE teacher_id = sl.user_id)
             WHEN 'admin'   THEN (SELECT full_name FROM admins   WHERE admin_id   = sl.user_id)
           END AS user_name
    FROM submission_logs sl
    WHERE " . implode(' AND ', $where) . "
    ORDER BY sl.logged_at DESC
    LIMIT 200
");
$logs->execute($params);
$logs = $logs->fetchAll();

// Distinct actions for filter
$actions = $db->query("SELECT DISTINCT action FROM submission_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$actionIconMap = [
    'login'       => ['bi-box-arrow-in-right', 'success'],
    'logout'      => ['bi-box-arrow-left',     'secondary'],
    'exam_start'  => ['bi-play-circle',         'info'],
    'submit'      => ['bi-check-circle',        'success'],
    'exam_failed' => ['bi-x-circle',            'danger'],
];

renderHead('System Logs');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','System Logs'); ?>

<div class="main-content">
<?php renderTopbar('System Logs'); ?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:14px 16px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
      <div>
        <label class="form-label">Action</label>
        <select name="action" class="form-control" style="width:160px;">
          <option value="">All Actions</option>
          <?php foreach ($actions as $a): ?>
          <option value="<?= $a ?>" <?= $actionFlt===$a?'selected':'' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Role</label>
        <select name="role" class="form-control" style="width:130px;">
          <option value="">All</option>
          <option value="admin"   <?= $roleFlt==='admin'?'selected':'' ?>>Admin</option>
          <option value="teacher" <?= $roleFlt==='teacher'?'selected':'' ?>>Teacher</option>
          <option value="student" <?= $roleFlt==='student'?'selected':'' ?>>Student</option>
        </select>
      </div>
      <div>
        <label class="form-label">IP Address</label>
        <input type="text" name="ip" class="form-control" placeholder="e.g. 192.168" value="<?= sanitize($ipFlt) ?>" style="width:150px;">
      </div>
      <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
      <a href="logs.php" class="btn-ghost" style="padding:9px 16px;">Reset</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-clock-history" style="color:var(--brand)"></i> Activity Log (<?= count($logs) ?> entries)</h3>
    <input type="text" id="logSearch" class="form-control" placeholder="Search…"
           style="width:200px;" oninput="filterTable('logSearch','logsTable')">
  </div>
  <div class="table-wrap">
    <table class="data-table" id="logsTable">
      <thead>
        <tr><th>#</th><th>User</th><th>Role</th><th>Action</th><th>IP Address</th><th>Details</th><th>Time</th></tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $i => $log): ?>
        <?php
          [$icon, $cls] = $actionIconMap[$log['action']] ?? ['bi-dot','secondary'];
        ?>
        <tr>
          <td style="color:var(--text-muted);font-size:12px;"><?= $i+1 ?></td>
          <td style="font-weight:600;"><?= sanitize($log['user_name'] ?? 'Unknown') ?></td>
          <td><span class="badge-pill badge-info"><?= ucfirst($log['user_type']) ?></span></td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
              <i class="bi <?= $icon ?>" style="color:var(--<?= $cls==='success'?'success':'text-muted' ?>);"></i>
              <?= sanitize($log['action']) ?>
            </span>
          </td>
          <td style="font-family:monospace;font-size:12px;"><?= sanitize($log['ip_address']) ?></td>
          <td style="font-size:12px;color:var(--text-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;">
            <?= $log['extra_data'] ? sanitize(substr($log['extra_data'],0,60)) : '—' ?>
          </td>
          <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;">
            <?= date('M j, Y g:i:s a', strtotime($log['logged_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">
          No log entries found.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>
<?php renderFooter(); ?>
