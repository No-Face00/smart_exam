<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('admin');
$db = Database::getConnection();

// ── Handle action (warn / ban / ignore) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flag_id'])) {
    $flagId = (int)$_POST['flag_id'];
    $action = in_array($_POST['action'], ['warned','banned','ignored']) ? $_POST['action'] : 'none';
    $adminId = currentUser()['id'];

    $db->prepare("
        UPDATE cheating_flags
        SET action_taken = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE flag_id = ?
    ")->execute([$action, $adminId, $flagId]);

    // If banned, block the student
    if ($action === 'banned') {
        $sid = (int)$_POST['student_id'];
        $db->prepare("UPDATE students SET is_blocked = 1 WHERE student_id = ?")->execute([$sid]);
    }

    setFlash('success', 'Action applied successfully.');
    header('Location: cheating.php'); exit;
}

// ── Filters ────────────────────────────────────────────
$riskFilter = get('risk', '');
$typeFilter = get('type', '');
$examFilter = (int)get('exam', 0);

$where = ['1=1'];
$params = [];

if ($riskFilter && in_array($riskFilter, ['low','medium','high'])) {
    $where[] = 'cf.risk_level = ?'; $params[] = $riskFilter;
}
if ($typeFilter) {
    $where[] = 'cf.flag_type = ?'; $params[] = $typeFilter;
}
if ($examFilter > 0) {
    $where[] = 'cf.exam_id = ?'; $params[] = $examFilter;
}

$whereSql = implode(' AND ', $where);

$flags = $db->prepare("
    SELECT cf.*, s.full_name, s.roll_number, s.email,
           e.title AS exam_title, e.exam_id,
           a.full_name AS reviewer_name
    FROM cheating_flags cf
    JOIN students s ON s.student_id = cf.student_id
    JOIN exams    e ON e.exam_id    = cf.exam_id
    LEFT JOIN admins a ON a.admin_id = cf.reviewed_by
    WHERE {$whereSql}
    ORDER BY
      FIELD(cf.risk_level,'high','medium','low'),
      cf.detected_at DESC
");
$flags->execute($params);
$flags = $flags->fetchAll();

// Summary stats
$summary = $db->query("
    SELECT
      COUNT(*)                                                AS total,
      SUM(risk_level='high')                                 AS high_cnt,
      SUM(risk_level='medium')                               AS med_cnt,
      SUM(risk_level='low')                                  AS low_cnt,
      SUM(action_taken='none')                               AS pending,
      SUM(action_taken='banned')                             AS banned_cnt
    FROM cheating_flags
")->fetch();

$exams = $db->query("SELECT exam_id, title FROM exams ORDER BY title")->fetchAll();

$flagTypes = [
  'shared_ip'            => 'Shared IP',
  'identical_answers'    => 'Identical Answers',
  'fast_submission'      => 'Fast Submission',
  'close_timestamps'     => 'Close Timestamps',
  'multiple_logins'      => 'Multiple Logins',
  'answer_pattern_match' => 'Answer Pattern',
];

renderHead('Cheating Flags');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin', 'Cheating Flags'); ?>

<div class="main-content">
<?php renderTopbar('Cheating Detection Report'); ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- ── Summary Stats ──────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);">
  <?= statCard('Total Flags',  $summary['total'],     'shield-exclamation', 'blue')   ?>
  <?= statCard('High Risk',    $summary['high_cnt'],   'exclamation-triangle-fill', 'red')    ?>
  <?= statCard('Medium Risk',  $summary['med_cnt'],    'exclamation-circle', 'amber')  ?>
  <?= statCard('Pending',      $summary['pending'],    'clock-fill',        'purple') ?>
  <?= statCard('Banned',       $summary['banned_cnt'], 'person-x-fill',     'red')    ?>
</div>

<!-- ── Filters ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div>
        <label class="form-label">Risk Level</label>
        <select name="risk" class="form-control" style="width:140px;">
          <option value="">All Levels</option>
          <option value="high"   <?= $riskFilter==='high'   ? 'selected' : '' ?>>High</option>
          <option value="medium" <?= $riskFilter==='medium' ? 'selected' : '' ?>>Medium</option>
          <option value="low"    <?= $riskFilter==='low'    ? 'selected' : '' ?>>Low</option>
        </select>
      </div>
      <div>
        <label class="form-label">Flag Type</label>
        <select name="type" class="form-control" style="width:180px;">
          <option value="">All Types</option>
          <?php foreach ($flagTypes as $k => $v): ?>
          <option value="<?= $k ?>" <?= $typeFilter===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Exam</label>
        <select name="exam" class="form-control" style="width:200px;">
          <option value="">All Exams</option>
          <?php foreach ($exams as $ex): ?>
          <option value="<?= $ex['exam_id'] ?>" <?= $examFilter==$ex['exam_id'] ? 'selected' : '' ?>>
            <?= sanitize($ex['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-primary">
        <i class="bi bi-funnel"></i> Filter
      </button>
      <a href="cheating.php" class="btn-ghost" style="padding:9px 16px;">Reset</a>
    </form>
  </div>
</div>

<!-- ── Flags Table ────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-shield-exclamation" style="color:var(--danger)"></i>
      Detected Cheating Flags
      <span style="font-size:13px;font-weight:500;color:var(--text-muted);">(<?= count($flags) ?> results)</span>
    </h3>
    <div style="display:flex;gap:8px;">
      <input type="text" id="flagSearch" class="form-control"
             placeholder="Search…" style="width:200px;"
             oninput="filterTable('flagSearch','flagsTable')">
    </div>
  </div>
  <div class="table-wrap">
    <table class="data-table" id="flagsTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Exam</th>
          <th>Flag Type</th>
          <th>Risk</th>
          <th>Score</th>
          <th>Description</th>
          <th>Detected</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($flags as $i => $f): ?>
        <tr>
          <td style="color:var(--text-muted);font-size:12px;"><?= $i+1 ?></td>
          <td>
            <div style="font-weight:700;"><?= sanitize($f['full_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($f['roll_number']) ?></div>
          </td>
          <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;">
            <?= sanitize($f['exam_title']) ?>
          </td>
          <td>
            <span style="font-size:12px;background:var(--bg);padding:3px 8px;border-radius:6px;">
              <i class="bi bi-<?= match($f['flag_type']) {
                'shared_ip'           => 'wifi',
                'identical_answers'   => 'files',
                'fast_submission'     => 'lightning',
                'close_timestamps'    => 'clock',
                'multiple_logins'     => 'person-plus',
                default               => 'flag'
              } ?>"></i>
              <?= $flagTypes[$f['flag_type']] ?? $f['flag_type'] ?>
            </span>
          </td>
          <td>
            <span class="badge-pill risk-<?= $f['risk_level'] ?>">
              <?= strtoupper($f['risk_level']) ?>
            </span>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="progress-bar-wrap" style="width:60px;">
                <div class="progress-bar-fill" style="width:<?= $f['risk_score'] ?>%;
                  background:<?= $f['risk_level']==='high' ? 'var(--danger)' : ($f['risk_level']==='medium' ? 'var(--warning)' : 'var(--success)') ?>;">
                </div>
              </div>
              <span style="font-size:12px;font-weight:700;"><?= $f['risk_score'] ?></span>
            </div>
          </td>
          <td style="max-width:200px;font-size:12px;color:var(--text-muted);">
            <?= sanitize(substr($f['description'], 0, 80)) ?>…
          </td>
          <td style="font-size:11px;color:var(--text-muted);">
            <?= date('M j, g:i a', strtotime($f['detected_at'])) ?>
          </td>
          <td>
            <?php
            $actionMap = [
              'none'    => ['secondary', 'Pending'],
              'warned'  => ['warning',   'Warned'],
              'banned'  => ['danger',    'Banned'],
              'ignored' => ['secondary', 'Ignored'],
            ];
            [$cls, $lbl] = $actionMap[$f['action_taken']];
            ?>
            <span class="badge-pill badge-<?= $cls ?>"><?= $lbl ?></span>
            <?php if ($f['reviewer_name']): ?>
            <div style="font-size:10px;color:var(--text-muted);">by <?= sanitize($f['reviewer_name']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($f['action_taken'] === 'none'): ?>
            <div style="display:flex;gap:4px;">
              <form method="POST" style="display:inline;">
                <input type="hidden" name="flag_id" value="<?= $f['flag_id'] ?>">
                <input type="hidden" name="student_id" value="<?= $f['student_id'] ?>">
                <input type="hidden" name="action" value="warned">
                <button class="btn-primary btn-sm btn-warning" title="Warn student">
                  <i class="bi bi-exclamation-triangle"></i>
                </button>
              </form>
              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Ban this student? They will be blocked from the system.');">
                <input type="hidden" name="flag_id" value="<?= $f['flag_id'] ?>">
                <input type="hidden" name="student_id" value="<?= $f['student_id'] ?>">
                <input type="hidden" name="action" value="banned">
                <button class="btn-primary btn-sm btn-danger" title="Ban student">
                  <i class="bi bi-person-x"></i>
                </button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="flag_id" value="<?= $f['flag_id'] ?>">
                <input type="hidden" name="student_id" value="<?= $f['student_id'] ?>">
                <input type="hidden" name="action" value="ignored">
                <button class="btn-ghost btn-sm" title="Ignore">
                  <i class="bi bi-x-lg"></i>
                </button>
              </form>
            </div>
            <?php else: ?>
            <span style="font-size:12px;color:var(--text-muted);">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$flags): ?>
        <tr>
          <td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">
            <i class="bi bi-shield-check" style="font-size:36px;color:var(--success);display:block;margin-bottom:8px;"></i>
            No cheating flags match the selected filters.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- .main-content -->
</div><!-- .layout -->

<?php renderFooter(); ?>
