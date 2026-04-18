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

// Get teacher's exams for the selector
$myExams = $examModel->byTeacher($tid);

// Default to first exam
if (!$examId && $myExams) $examId = $myExams[0]['exam_id'];

// Run detection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_detection'])) {
    $eid = (int)$_POST['exam_id'];
    $examModel->runCheatingDetection($eid);
    setFlash('success', 'Cheating detection complete!');
    header('Location: results.php?exam_id=' . $eid); exit;
}

// Results for selected exam
$results = [];
$examInfo = null;
if ($examId) {
    $stmt = $db->prepare("SELECT * FROM exams WHERE exam_id = ? AND teacher_id = ?");
    $stmt->execute([$examId, $tid]);
    $examInfo = $stmt->fetch();

    if ($examInfo) {
        $results = $db->prepare("
            SELECT ea.*, s.full_name, s.roll_number,
                   COUNT(DISTINCT cf.flag_id) AS flag_count,
                   MAX(cf.risk_level)         AS highest_risk
            FROM exam_attempts ea
            JOIN students s ON s.student_id = ea.student_id
            LEFT JOIN cheating_flags cf ON cf.attempt_id = ea.attempt_id
            WHERE ea.exam_id = ?
            GROUP BY ea.attempt_id
            ORDER BY ea.score DESC
        ");
        $results->execute([$examId]);
        $results = $results->fetchAll();
    }
}

// Summary
$summary = ['total'=>0,'passed'=>0,'avg'=>0,'highest'=>0,'lowest'=>100,'flags'=>0];
if ($results) {
    $submitted = array_filter($results, fn($r) => $r['status']==='submitted');
    $scores    = array_column($submitted, 'score');
    $summary = [
        'total'   => count($results),
        'passed'  => count(array_filter($results, fn($r)=>$r['is_passed'])),
        'avg'     => $scores ? round(array_sum($scores)/count($scores), 1) : 0,
        'highest' => $scores ? round(max($scores), 1) : 0,
        'lowest'  => $scores ? round(min($scores), 1) : 0,
        'flags'   => array_sum(array_column($results,'flag_count')),
    ];
}

$flash = getFlash();
renderHead('Results');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('teacher','Results'); ?>

<div class="main-content">
<?php renderTopbar('Exam Results'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- Exam Selector -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 16px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
    <form method="GET" style="display:inline-flex;align-items:flex-end;gap:12px;">
      <div>
        <label class="form-label">Select Exam</label>
        <select name="exam_id" class="form-control" style="width:280px;" onchange="this.form.submit()">
          <option value="">— Choose an exam —</option>
          <?php foreach ($myExams as $ex): ?>
          <option value="<?= $ex['exam_id'] ?>" <?= $examId==$ex['exam_id']?'selected':'' ?>>
            <?= sanitize($ex['title']) ?> (<?= $ex['attempt_count'] ?? $ex['total_attempts'] ?> attempts)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <?php if ($examId && $examInfo): ?>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <button type="submit" name="run_detection" class="btn-primary"
              style="background:var(--warning);"
              onclick="return confirm('Run SQL-based cheating detection for this exam?')">
        <i class="bi bi-shield-check"></i> Run Cheating Detection
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($examInfo && $results): ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:24px;">
  <?= statCard('Total',   $summary['total'],   'people-fill',      'blue')   ?>
  <?= statCard('Passed',  $summary['passed'],  'trophy-fill',      'green')  ?>
  <?= statCard('Avg',     $summary['avg'].'%', 'bar-chart-fill',   'purple') ?>
  <?= statCard('Highest', $summary['highest'].'%','arrow-up-circle','amber') ?>
  <?= statCard('Lowest',  $summary['lowest'].'%', 'arrow-down-circle','cyan') ?>
  <?= statCard('Flags',   $summary['flags'],   'shield-exclamation','red')   ?>
</div>

<!-- Results Table -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-bar-chart-fill" style="color:var(--brand)"></i>
      Results: <?= sanitize($examInfo['title']) ?>
    </h3>
    <input type="text" id="resultSearch" class="form-control" placeholder="Search…"
           style="width:180px;" oninput="filterTable('resultSearch','resultsTable')">
  </div>
  <div class="table-wrap">
    <table class="data-table" id="resultsTable">
      <thead>
        <tr><th>Rank</th><th>Student</th><th>Score</th><th>Status</th><th>Time</th><th>IP</th><th>Flags</th></tr>
      </thead>
      <tbody>
        <?php
        $rank = 1;
        foreach ($results as $r):
        $timeTaken = $r['time_taken_secs']
          ? sprintf('%02d:%02d', intdiv($r['time_taken_secs'],60), $r['time_taken_secs']%60)
          : '—';
        ?>
        <tr>
          <td style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;text-align:center;color:var(--text-muted);">
            <?php if ($r['status']==='submitted'): ?>
              <?= $rank <= 3 ? ['🥇','🥈','🥉'][$rank-1] : '#'.$rank ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;"><?= sanitize($r['full_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($r['roll_number']) ?></div>
          </td>
          <td>
            <?php if ($r['score'] !== null): ?>
            <div style="display:flex;align-items:center;gap:10px;">
              <div class="progress-bar-wrap" style="width:80px;">
                <div class="progress-bar-fill" style="width:<?= min(100,$r['score']) ?>%;
                  background:<?= $r['is_passed'] ? 'var(--success)' : 'var(--danger)' ?>;"></div>
              </div>
              <span style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800;">
                <?= round($r['score']) ?>%
              </span>
            </div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php
            $statusMap = [
              'submitted'   => $r['is_passed'] ? ['success','Passed'] : ['danger','Failed'],
              'in_progress' => ['warning','In Progress'],
              'timed_out'   => ['danger','Timed Out'],
              'abandoned'   => ['secondary','Abandoned'],
            ];
            [$cls,$lbl] = $statusMap[$r['status']] ?? ['secondary',ucfirst($r['status'])];
            ?>
            <span class="badge-pill badge-<?= $cls ?>"><?= $lbl ?></span>
          </td>
          <td style="font-size:13px;font-family:monospace;"><?= $timeTaken ?></td>
          <td style="font-size:11px;font-family:monospace;color:var(--text-muted);"><?= sanitize($r['ip_address']) ?></td>
          <td>
            <?php if ($r['flag_count'] > 0): ?>
            <span class="badge-pill <?= $r['highest_risk']==='high' ? 'risk-high' : ($r['highest_risk']==='medium' ? 'risk-medium' : 'risk-low') ?>">
              <?= $r['flag_count'] ?> flag<?= $r['flag_count']>1?'s':'' ?>
            </span>
            <?php else: ?>
            <span style="color:var(--success);font-size:13px;"><i class="bi bi-shield-check"></i></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($r['status']==='submitted') $rank++; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($examId && !$results): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted);">
  <i class="bi bi-journal-x" style="font-size:48px;display:block;margin-bottom:12px;"></i>
  No attempts recorded for this exam yet.
</div>
<?php elseif (!$examId): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted);">
  <i class="bi bi-arrow-up" style="font-size:32px;display:block;margin-bottom:12px;"></i>
  Select an exam above to view results.
</div>
<?php endif; ?>

</div>
</div>
<?php renderFooter(); ?>
