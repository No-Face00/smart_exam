<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('teacher');

$db        = Database::getConnection();
$tid       = currentUser()['id'];
$examModel = new Exam();
$examId    = (int)get('exam_id', 0);

$myExams = $examModel->byTeacher($tid);
if (!$examId && $myExams) $examId = $myExams[0]['exam_id'];

// Run detection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_detection'])) {
    $eid = (int)$_POST['exam_id'];
    $examModel->runCheatingDetection($eid);
    setFlash('success', 'Detection complete! Flags listed below.');
    header('Location: cheating.php?exam_id=' . $eid); exit;
}

$flags = [];
$examInfo = null;
if ($examId) {
    $stmt = $db->prepare("SELECT * FROM exams WHERE exam_id = ? AND teacher_id = ?");
    $stmt->execute([$examId, $tid]);
    $examInfo = $stmt->fetch();

    if ($examInfo) {
        $flagStmt = $db->prepare("
            SELECT cf.*, s.full_name, s.student_id_no, s.email
            FROM cheating_flags cf
            JOIN students s ON s.student_id = cf.student_id
            WHERE cf.exam_id = ?
            ORDER BY FIELD(cf.risk_level,'high','medium','low'), cf.detected_at DESC
        ");
        $flagStmt->execute([$examId]);
        $flags = $flagStmt->fetchAll();
    }
}

$summary = [
    'total'  => count($flags),
    'high'   => count(array_filter($flags, fn($f)=>$f['risk_level']==='high')),
    'medium' => count(array_filter($flags, fn($f)=>$f['risk_level']==='medium')),
    'low'    => count(array_filter($flags, fn($f)=>$f['risk_level']==='low')),
];

$flagTypes = [
  'shared_ip'            => ['bi-wifi',              'Shared IP'],
  'identical_answers'    => ['bi-files',             'Identical Answers'],
  'fast_submission'      => ['bi-lightning-fill',    'Fast Submission'],
  'close_timestamps'     => ['bi-clock-fill',        'Close Timestamps'],
  'multiple_logins'      => ['bi-person-plus-fill',  'Multiple Logins'],
  'answer_pattern_match' => ['bi-grid-3x3-gap-fill', 'Answer Pattern'],
  'score_time_anomaly'   => ['bi-graph-up-arrow',    'Score-Time Anomaly'],
];

$flash = getFlash();
renderHead('Cheating Report');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('teacher','Cheating Report'); ?>

<div class="main-content">
<?php renderTopbar('Cheating Detection Report'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- Controls -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 16px;">
    <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
      <form method="GET" style="display:inline-flex;align-items:flex-end;gap:12px;">
        <div>
          <label class="form-label">Select Exam</label>
          <select name="exam_id" class="form-control" style="width:280px;" onchange="this.form.submit()">
            <?php foreach ($myExams as $ex): ?>
            <option value="<?= $ex['exam_id'] ?>" <?= $examId==$ex['exam_id']?'selected':'' ?>>
              <?= sanitize($ex['title']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <?php if ($examId): ?>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Run full cheating analysis on this exam?')">
        <input type="hidden" name="exam_id" value="<?= $examId ?>">
        <button type="submit" name="run_detection" class="btn-primary" style="background:var(--warning);">
          <i class="bi bi-shield-check"></i> Run Detection Now
        </button>
      </form>

      <a href="<?= BASE_URL ?>/api/export.php?exam_id=<?= $examId ?>&format=csv"
         class="btn-primary btn-sm" style="background:var(--success);padding:9px 16px;">
        <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
      </a>
      <a href="<?= BASE_URL ?>/api/export.php?exam_id=<?= $examId ?>&format=pdf"
         target="_blank" class="btn-primary btn-sm" style="background:var(--danger);padding:9px 16px;">
        <i class="bi bi-file-earmark-pdf"></i> Export PDF
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($examInfo && $flags): ?>

<!-- Summary Cards -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <?= statCard('Total Flags', $summary['total'],  'shield-exclamation', 'blue')   ?>
  <?= statCard('High Risk',   $summary['high'],   'exclamation-triangle-fill', 'red')    ?>
  <?= statCard('Medium Risk', $summary['medium'], 'exclamation-circle', 'amber')  ?>
  <?= statCard('Low Risk',    $summary['low'],    'info-circle',        'green')  ?>
</div>

<!-- Flags Table -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-shield-exclamation" style="color:var(--danger)"></i>
      Detected Flags — <?= sanitize($examInfo['title']) ?>
    </h3>
    <input type="text" id="flagSearch" class="form-control" placeholder="Search…"
           style="width:180px;" oninput="filterTable('flagSearch','flagTable')">
  </div>
  <div class="table-wrap">
    <table class="data-table" id="flagTable">
      <thead>
        <tr><th>#</th><th>Student</th><th>Flag Type</th><th>Risk</th><th>Score</th><th>Description</th><th>Detected</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($flags as $i => $f): ?>
        <?php [$ico, $typeLabel] = $flagTypes[$f['flag_type']] ?? ['bi-flag','Unknown']; ?>
        <tr>
          <td style="color:var(--text-muted);font-size:12px;"><?= $i+1 ?></td>
          <td>
            <div style="font-weight:700;"><?= sanitize($f['full_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($f['student_id_no']) ?></div>
          </td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;background:var(--bg);padding:4px 10px;border-radius:6px;">
              <i class="bi <?= $ico ?>"></i> <?= $typeLabel ?>
            </span>
          </td>
          <td>
            <span class="badge-pill risk-<?= $f['risk_level'] ?>">
              <?= strtoupper($f['risk_level']) ?>
            </span>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="progress-bar-wrap" style="width:50px;">
                <div class="progress-bar-fill" style="width:<?= $f['risk_score'] ?>%;
                  background:<?= $f['risk_level']==='high'?'var(--danger)':($f['risk_level']==='medium'?'var(--warning)':'var(--success)') ?>;"></div>
              </div>
              <strong style="font-size:13px;"><?= $f['risk_score'] ?></strong>
            </div>
          </td>
          <td style="max-width:220px;font-size:12px;color:var(--text-muted);"><?= sanitize($f['description']) ?></td>
          <td style="font-size:11px;color:var(--text-muted);"><?= date('M j, g:i a', strtotime($f['detected_at'])) ?></td>
          <td>
            <?php
            $aMap = ['none'=>['secondary','Pending'],'warned'=>['warning','Warned'],'banned'=>['danger','Banned'],'ignored'=>['secondary','Ignored']];
            [$cls,$lbl] = $aMap[$f['action_taken']];
            ?>
            <span class="badge-pill badge-<?= $cls ?>"><?= $lbl ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($examId): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted);">
  <i class="bi bi-shield-check" style="font-size:48px;display:block;margin-bottom:12px;color:var(--success);"></i>
  <p style="font-size:16px;">No cheating flags for this exam.</p>
  <p style="font-size:13px;margin-top:6px;">Click <strong>Run Detection Now</strong> to analyze submissions.</p>
</div>
<?php endif; ?>

</div>
</div>
<?php renderFooter(); ?>
