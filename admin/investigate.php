<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/CheatingEngine.php';

requireLogin('admin');

$db     = Database::getConnection();
$engine = new CheatingEngine();
$admin  = currentUser();

// ── POST actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['run_detection'])) {
        $eid    = (int)$_POST['exam_id'];
        $result = $engine->runDetection($eid);
        setFlash('success', "Detection complete — {$result['flags_after']} flags total, {$result['new_flags']} new.");
        header("Location: investigate.php?exam_id={$eid}"); exit;
    }

    if (isset($_POST['rerun_detection'])) {
        $eid    = (int)$_POST['exam_id'];
        $result = $engine->rerunDetection($eid);
        setFlash('success', "Re-ran detection. {$result['flags']} flags found.");
        header("Location: investigate.php?exam_id={$eid}"); exit;
    }

    if (isset($_POST['apply_action'])) {
        $flagId = (int)$_POST['flag_id'];
        $action = $_POST['action'];
        $engine->applyAction($flagId, $action, $admin['id']);
        setFlash('success', 'Action applied.');
        header("Location: investigate.php?exam_id=" . (int)$_POST['exam_id'] . "&tab=roster"); exit;
    }

    if (isset($_POST['bulk_action'])) {
        $sid    = (int)$_POST['student_id'];
        $eid    = (int)$_POST['exam_id'];
        $action = $_POST['action'];
        $n = $engine->bulkAction($sid, $eid, $action, $admin['id']);
        setFlash('success', "Applied '$action' to $n flag(s).");
        header("Location: investigate.php?exam_id={$eid}&tab=roster"); exit;
    }
}

// ── State ────────────────────────────────────────────────────────────
$examId  = (int)get('exam_id', 0);
$tab     = get('tab', 'overview');
$riskFlt = get('risk', '');
$typeFlt = get('type', '');

$exams = $db->query("
    SELECT e.exam_id, e.title, e.status, e.scheduled_start,
           t.full_name AS teacher_name
    FROM exams e JOIN teachers t ON t.teacher_id = e.teacher_id
    ORDER BY e.scheduled_start DESC
")->fetchAll();

if (!$examId && $exams) $examId = $exams[0]['exam_id'];

$examInfo  = null;
$summary   = [];
$flags     = [];
$roster    = [];
$ipNetwork = [];
$timeline  = [];
$simMatrix = [];

if ($examId) {
    $stmt = $db->prepare("SELECT e.*, t.full_name AS teacher_name, d.dept_name
                          FROM exams e JOIN teachers t ON t.teacher_id=e.teacher_id
                          JOIN departments d ON d.department_id=e.department_id
                          WHERE e.exam_id=?");
    $stmt->execute([$examId]);
    $examInfo = $stmt->fetch();

    if ($examInfo) {
        $summary   = $engine->examSummary($examId);
        $flags     = $engine->flagsForExam($examId, $riskFlt, $typeFlt);
        $roster    = $engine->riskRoster($examId);
        $ipNetwork = $engine->ipNetwork($examId);
        $timeline  = $engine->submissionTimeline($examId);
        $simMatrix = $engine->similarityMatrix($examId, 60.0);
    }
}

$flash = getFlash();
renderHead('Investigation Hub');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','Cheating Flags'); ?>

<div class="main-content">
<?php renderTopbar('Cheating Investigation Hub'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- ── Exam Selector + Run Controls ──────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <div style="flex:1;min-width:240px;">
        <label class="form-label">Investigating Exam</label>
        <select id="examSelector" class="form-control" onchange="location='investigate.php?exam_id='+this.value">
          <?php foreach ($exams as $ex): ?>
          <option value="<?= $ex['exam_id'] ?>" <?= $examId==$ex['exam_id']?'selected':'' ?>>
            <?= sanitize($ex['title']) ?> — <?= sanitize($ex['teacher_name']) ?>
            (<?= ucfirst($ex['status']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($examId): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;">
        <form method="POST" style="display:inline;">
          <input type="hidden" name="exam_id" value="<?= $examId ?>">
          <button type="submit" name="run_detection" class="btn-primary" style="background:var(--warning);">
            <i class="bi bi-shield-check"></i> Run Detection
          </button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Clear all flags and re-run fresh?')">
          <input type="hidden" name="exam_id" value="<?= $examId ?>">
          <button type="submit" name="rerun_detection" class="btn-primary btn-danger">
            <i class="bi bi-arrow-repeat"></i> Re-Run Fresh
          </button>
        </form>
        <a href="<?= BASE_URL ?>/api/export.php?exam_id=<?= $examId ?>&format=csv" class="btn-primary btn-success">
          <i class="bi bi-file-earmark-spreadsheet"></i> CSV
        </a>
        <a href="<?= BASE_URL ?>/api/export.php?exam_id=<?= $examId ?>&format=pdf" target="_blank" class="btn-primary" style="background:var(--danger);">
          <i class="bi bi-file-earmark-pdf"></i> PDF
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($examInfo && ($summary['total_flags'] ?? 0) > 0): ?>

<!-- ── Summary KPIs ──────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:20px;">
  <?= statCard('Total Flags',      $summary['total_flags'],     'shield-exclamation',    'blue')   ?>
  <?= statCard('Students Flagged', $summary['flagged_students'],'people-fill',           'red')    ?>
  <?= statCard('High Risk',        $summary['high_flags'],      'exclamation-triangle-fill','red') ?>
  <?= statCard('Medium Risk',      $summary['medium_flags'],    'exclamation-circle',    'amber')  ?>
  <?= statCard('Pending Review',   $summary['pending'],         'clock-fill',            'purple') ?>
  <?= statCard('Peak Score',       $summary['peak_score'],      'graph-up-arrow',        'cyan')   ?>
</div>

<!-- ── Tab Navigation ────────────────────────────────────────────── -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border);padding-bottom:0;">
  <?php
  $tabs = [
    ['overview',  'bi-grid-fill',       'Overview'],
    ['roster',    'bi-people-fill',     'Risk Roster'],
    ['flags',     'bi-flag-fill',       'All Flags'],
    ['timeline',  'bi-clock-history',   'Timeline'],
    ['network',   'bi-diagram-3-fill',  'IP Network'],
    ['similarity','bi-files',           'Similarity Matrix'],
  ];
  foreach ($tabs as [$id, $ico, $label]):
  ?>
  <a href="investigate.php?exam_id=<?= $examId ?>&tab=<?= $id ?>"
     style="padding:10px 18px;font-size:13px;font-weight:600;border-radius:8px 8px 0 0;
            display:flex;align-items:center;gap:7px;
            <?= $tab===$id
              ? 'background:var(--brand);color:#fff;'
              : 'color:var(--text-muted);' ?>">
    <i class="bi <?= $ico ?>"></i> <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════
     TAB: OVERVIEW
═════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'overview'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

  <!-- Flag breakdown by type -->
  <div class="card">
    <div class="card-header"><h3><i class="bi bi-pie-chart-fill" style="color:var(--brand)"></i> Flags by Type</h3></div>
    <div class="card-body" style="padding:0;">
      <?php
      $byType = [];
      foreach ($flags as $f) {
          $byType[$f['flag_type']] = ($byType[$f['flag_type']] ?? 0) + 1;
      }
      arsort($byType);
      $maxCount = $byType ? max($byType) : 1;
      foreach ($byType as $type => $count):
        $ico = CheatingEngine::flagTypeIcon($type);
        $lbl = CheatingEngine::flagTypeLabel($type);
        $pct = round($count / $maxCount * 100);
      ?>
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;">
            <i class="bi <?= $ico ?>" style="color:var(--brand);font-size:15px;"></i>
            <?= sanitize($lbl) ?>
          </div>
          <span style="font-family:'Syne',sans-serif;font-weight:800;font-size:16px;"><?= $count ?></span>
        </div>
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:var(--brand);"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Flag breakdown by risk -->
  <div class="card">
    <div class="card-header"><h3><i class="bi bi-shield-fill-exclamation" style="color:var(--danger)"></i> Risk Distribution</h3></div>
    <div class="card-body">
      <?php
      $riskCounts = ['high'=>0,'medium'=>0,'low'=>0];
      foreach ($flags as $f) $riskCounts[$f['risk_level']]++;
      $totalFlags = max(1, array_sum($riskCounts));
      $riskConfig = [
        'high'   => ['var(--danger)',  'Critical flags — immediate action needed'],
        'medium' => ['var(--warning)', 'Suspicious patterns — review recommended'],
        'low'    => ['var(--success)', 'Minor anomalies — monitor closely'],
      ];
      foreach ($riskCounts as $level => $cnt):
        [$color, $desc] = $riskConfig[$level];
        $pct = round($cnt / $totalFlags * 100);
      ?>
      <div style="margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <div>
            <span class="badge-pill risk-<?= $level ?>" style="margin-right:8px;"><?= strtoupper($level) ?></span>
            <span style="font-size:12px;color:var(--text-muted);"><?= sanitize($desc) ?></span>
          </div>
          <span style="font-family:'Syne',sans-serif;font-weight:800;"><?= $cnt ?> <span style="font-size:12px;font-weight:400;color:var(--text-muted);">(<?= $pct ?>%)</span></span>
        </div>
        <div class="progress-bar-wrap" style="height:12px;">
          <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;height:12px;border-radius:99px;"></div>
        </div>
      </div>
      <?php endforeach; ?>

      <div style="margin-top:24px;padding:16px;background:var(--bg);border-radius:var(--radius-sm);">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Exam Integrity Score</div>
        <?php
        $integrityScore = max(0, round(
          100 - ($summary['total_flags'] / max(1, count($timeline)) * 50)
              - ($summary['high_flags']  / max(1, count($timeline)) * 30)
        ));
        $intColor = $integrityScore >= 80 ? 'var(--success)' : ($integrityScore >= 60 ? 'var(--warning)' : 'var(--danger)');
        ?>
        <div style="font-family:'Syne',sans-serif;font-size:36px;font-weight:800;color:<?= $intColor ?>;">
          <?= $integrityScore ?><span style="font-size:18px;">/100</span>
        </div>
        <div class="progress-bar-wrap" style="height:10px;margin-top:8px;">
          <div class="progress-bar-fill" style="width:<?= $integrityScore ?>%;background:<?= $intColor ?>;height:10px;"></div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ════════════════════════════════════════════════════════════════
     TAB: RISK ROSTER
═════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'roster'): ?>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-people-fill" style="color:var(--danger)"></i> Flagged Student Risk Roster</h3>
    <input type="text" id="rosterSearch" class="form-control" placeholder="Search…"
           style="width:180px;" oninput="filterTable('rosterSearch','rosterTable')">
  </div>
  <div class="table-wrap">
    <table class="data-table" id="rosterTable">
      <thead>
        <tr><th>Student</th><th>Risk Level</th><th>Flags</th><th>Types</th><th>Peak Score</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($roster as $r): ?>
        <?php
          $riskColor = match($r['overall_risk']) {
            'CRITICAL' => 'var(--danger)',
            'HIGH'     => 'var(--danger)',
            'MEDIUM'   => 'var(--warning)',
            'LOW'      => 'var(--success)',
            default    => 'var(--text-muted)',
          };
          $riskBg = match($r['overall_risk']) {
            'CRITICAL','HIGH' => '#FEF2F2',
            'MEDIUM'          => '#FFFBEB',
            'LOW'             => '#ECFDF5',
            default           => 'var(--bg)',
          };
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:36px;height:36px;border-radius:50%;background:<?= $riskColor ?>;
                          display:flex;align-items:center;justify-content:center;
                          color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">
                <?= strtoupper(substr($r['full_name'],0,2)) ?>
              </div>
              <div>
                <div style="font-weight:700;"><?= sanitize($r['full_name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($r['student_id_no']) ?></div>
              </div>
            </div>
          </td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:6px;background:<?= $riskBg ?>;
                         color:<?= $riskColor ?>;padding:5px 12px;border-radius:20px;
                         font-size:12px;font-weight:800;letter-spacing:.5px;">
              <?= $r['overall_risk'] ?>
            </span>
          </td>
          <td>
            <div style="display:flex;gap:4px;">
              <?php if ($r['high_flags']): ?><span class="badge-pill risk-high"><?= $r['high_flags'] ?> HIGH</span><?php endif; ?>
              <?php if ($r['medium_flags']): ?><span class="badge-pill risk-medium"><?= $r['medium_flags'] ?> MED</span><?php endif; ?>
            </div>
          </td>
          <td style="font-size:13px;color:var(--text-muted);"><?= $r['distinct_flag_types'] ?> type(s)</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="progress-bar-wrap" style="width:60px;">
                <div class="progress-bar-fill" style="width:<?= $r['peak_risk_score'] ?>%;
                  background:<?= $riskColor ?>;"></div>
              </div>
              <strong><?= $r['peak_risk_score'] ?></strong>
            </div>
          </td>
          <td>
            <div style="display:flex;gap:4px;flex-wrap:wrap;">
              <a href="compare.php?exam_id=<?= $examId ?>&student_id=<?= $r['student_id'] ?>"
                 class="btn-ghost btn-sm" title="Investigate">
                <i class="bi bi-search"></i>
              </a>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="exam_id"    value="<?= $examId ?>">
                <input type="hidden" name="student_id" value="<?= $r['student_id'] ?>">
                <input type="hidden" name="action"     value="warned">
                <button type="submit" name="bulk_action" class="btn-primary btn-sm btn-warning" title="Warn">
                  <i class="bi bi-exclamation-triangle"></i>
                </button>
              </form>
              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Ban <?= sanitize($r['full_name']) ?>?')">
                <input type="hidden" name="exam_id"    value="<?= $examId ?>">
                <input type="hidden" name="student_id" value="<?= $r['student_id'] ?>">
                <input type="hidden" name="action"     value="banned">
                <button type="submit" name="bulk_action" class="btn-primary btn-sm btn-danger" title="Ban">
                  <i class="bi bi-person-x-fill"></i>
                </button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="exam_id"    value="<?= $examId ?>">
                <input type="hidden" name="student_id" value="<?= $r['student_id'] ?>">
                <input type="hidden" name="action"     value="ignored">
                <button type="submit" name="bulk_action" class="btn-ghost btn-sm" title="Ignore all">
                  <i class="bi bi-x"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     TAB: ALL FLAGS
═════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'flags'): ?>

<!-- Sub-filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:12px 16px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <input type="hidden" name="tab" value="flags">
      <div>
        <label class="form-label">Risk</label>
        <select name="risk" class="form-control" style="width:130px;">
          <option value="">All</option>
          <option value="high"   <?= $riskFlt==='high'?'selected':'' ?>>High</option>
          <option value="medium" <?= $riskFlt==='medium'?'selected':'' ?>>Medium</option>
          <option value="low"    <?= $riskFlt==='low'?'selected':'' ?>>Low</option>
        </select>
      </div>
      <div>
        <label class="form-label">Type</label>
        <select name="type" class="form-control" style="width:200px;">
          <option value="">All Types</option>
          <?php foreach (['shared_ip','identical_answers','fast_submission','close_timestamps','multiple_logins','answer_pattern_match'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFlt===$t?'selected':'' ?>><?= CheatingEngine::flagTypeLabel($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-flag-fill" style="color:var(--danger)"></i> All Flags (<?= count($flags) ?>)</h3>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Student</th><th>Flag Type</th><th>Risk</th><th>Score</th><th>Evidence</th><th>Exam Score</th><th>Time</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($flags as $f): ?>
        <tr>
          <td>
            <div style="font-weight:700;"><?= sanitize($f['full_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($f['student_id_no']) ?></div>
          </td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:7px;font-size:13px;">
              <i class="bi <?= CheatingEngine::flagTypeIcon($f['flag_type']) ?>" style="color:var(--brand);"></i>
              <?= CheatingEngine::flagTypeLabel($f['flag_type']) ?>
            </span>
          </td>
          <td><span class="badge-pill risk-<?= $f['risk_level'] ?>"><?= strtoupper($f['risk_level']) ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px;">
              <div class="progress-bar-wrap" style="width:50px;">
                <div class="progress-bar-fill" style="width:<?= $f['risk_score'] ?>%;
                  background:<?= CheatingEngine::riskColor($f['risk_level']) ?>;"></div>
              </div>
              <strong><?= $f['risk_score'] ?></strong>
            </div>
          </td>
          <td style="font-size:12px;color:var(--text-muted);max-width:220px;">
            <?= sanitize(substr($f['description'],0,90)) ?>…
            <a href="#" onclick="showEvidence(<?= json_encode($f['description']) ?>)" style="color:var(--brand);font-size:11px;">more</a>
          </td>
          <td>
            <?php if ($f['score'] !== null): ?>
            <span style="font-weight:700;color:<?= $f['score']>=50?'var(--success)':'var(--danger)' ?>;">
              <?= round($f['score'],1) ?>%
            </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--text-muted);">
            <?= $f['time_taken_secs'] ? sprintf('%02d:%02d', intdiv($f['time_taken_secs'],60), $f['time_taken_secs']%60) : '—' ?>
          </td>
          <td>
            <?php if ($f['action_taken'] === 'none'): ?>
            <div style="display:flex;gap:3px;">
              <form method="POST" style="display:inline;">
                <input type="hidden" name="flag_id"  value="<?= $f['flag_id'] ?>">
                <input type="hidden" name="exam_id"  value="<?= $examId ?>">
                <input type="hidden" name="action"   value="warned">
                <button type="submit" name="apply_action" class="btn-primary btn-sm btn-warning" title="Warn">
                  <i class="bi bi-exclamation-triangle"></i>
                </button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="flag_id"  value="<?= $f['flag_id'] ?>">
                <input type="hidden" name="exam_id"  value="<?= $examId ?>">
                <input type="hidden" name="action"   value="banned">
                <button type="submit" name="apply_action" class="btn-primary btn-sm btn-danger" title="Ban"
                        onclick="return confirm('Ban this student?')">
                  <i class="bi bi-person-x"></i>
                </button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="flag_id"  value="<?= $f['flag_id'] ?>">
                <input type="hidden" name="exam_id"  value="<?= $examId ?>">
                <input type="hidden" name="action"   value="ignored">
                <button type="submit" name="apply_action" class="btn-ghost btn-sm" title="Ignore">
                  <i class="bi bi-x"></i>
                </button>
              </form>
            </div>
            <?php else: ?>
            <span class="badge-pill badge-secondary"><?= ucfirst($f['action_taken']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     TAB: SUBMISSION TIMELINE
═════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'timeline'): ?>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-clock-history" style="color:var(--brand)"></i> Submission Timeline</h3>
    <span style="font-size:13px;color:var(--text-muted);"><?= count($timeline) ?> submissions</span>
  </div>
  <div class="card-body" style="padding:0;">
    <div style="position:relative;padding:20px 20px 20px 60px;">
      <!-- Vertical line -->
      <div style="position:absolute;left:40px;top:20px;bottom:20px;width:2px;background:var(--border);"></div>

      <?php foreach ($timeline as $i => $t): ?>
      <?php
        // Flag check
        $isFlagged = array_filter($flags, fn($f) => $f['student_id'] == $t['student_id']);
        $highRisk  = array_filter($isFlagged, fn($f) => $f['risk_level'] === 'high');
        $dotColor  = $highRisk ? 'var(--danger)' : ($isFlagged ? 'var(--warning)' : 'var(--success)');
        $timeTaken = $t['time_taken_secs'] ? sprintf('%02d:%02d', intdiv($t['time_taken_secs'],60), $t['time_taken_secs']%60) : '—';

        // Show gap to next submission
        $nextGap = '';
        if (isset($timeline[$i+1])) {
          $gap = (int)$timeline[$i+1]['secs_after_first'] - (int)$t['secs_after_first'];
          if ($gap <= 30) $nextGap = "⚡ {$gap}s gap to next";
        }
      ?>
      <div style="position:relative;margin-bottom:16px;padding-left:28px;">
        <!-- Dot -->
        <div style="position:absolute;left:-28px;top:10px;width:14px;height:14px;border-radius:50%;
                    background:<?= $dotColor ?>;border:2px solid var(--bg-card);box-shadow:0 0 0 2px <?= $dotColor ?>;"></div>

        <div style="background:var(--bg);border-radius:var(--radius-sm);padding:12px 16px;
                    border:1px solid <?= $isFlagged ? ($highRisk?'var(--danger)':'var(--warning)') : 'var(--border)' ?>;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
              <div style="font-weight:700;font-size:14px;">
                #<?= $t['submit_rank'] ?> — <?= sanitize($t['full_name']) ?>
                <span style="font-size:11px;color:var(--text-muted);">(<?= sanitize($t['student_id_no']) ?>)</span>
                <?php if ($highRisk): ?>
                <span class="badge-pill risk-high" style="margin-left:6px;font-size:10px;">HIGH RISK</span>
                <?php elseif ($isFlagged): ?>
                <span class="badge-pill risk-medium" style="margin-left:6px;font-size:10px;">FLAGGED</span>
                <?php endif; ?>
              </div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">
                Submitted: <?= date('H:i:s', strtotime($t['submit_time'])) ?>
                &nbsp;·&nbsp; Time taken: <?= $timeTaken ?>
                &nbsp;·&nbsp; Score: <strong><?= round($t['score'] ?? 0) ?>%</strong>
                &nbsp;·&nbsp; IP: <code style="font-size:11px;"><?= sanitize($t['ip_address']) ?></code>
              </div>
            </div>
            <div style="font-family:monospace;font-size:12px;color:var(--brand);font-weight:600;">
              +<?= gmdate('i:s', $t['secs_after_first']) ?> after first
            </div>
          </div>
          <?php if ($nextGap): ?>
          <div style="margin-top:6px;font-size:11px;color:var(--danger);font-weight:600;">
            <i class="bi bi-lightning-charge-fill"></i> <?= $nextGap ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     TAB: IP NETWORK
═════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'network'): ?>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-diagram-3-fill" style="color:var(--brand)"></i> IP Address Network</h3>
    <span style="font-size:13px;color:var(--text-muted);">Groups students by shared IP</span>
  </div>
  <div class="card-body" style="padding:0;">
    <?php foreach ($ipNetwork as $net): ?>
    <?php $isShared = $net['student_count'] > 1; ?>
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
      <div style="display:flex;align-items:flex-start;gap:14px;">
        <div style="width:44px;height:44px;border-radius:12px;
                    background:<?= $isShared ? '#FEF2F2' : '#ECFDF5' ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="bi bi-<?= $isShared ? 'wifi text-danger' : 'wifi text-success' ?>"
             style="color:<?= $isShared ? 'var(--danger)' : 'var(--success)' ?>;font-size:20px;"></i>
        </div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
            <code style="font-size:14px;font-weight:700;"><?= sanitize($net['ip_address']) ?></code>
            <?php if ($isShared): ?>
            <span class="badge-pill risk-high">SHARED — <?= $net['student_count'] ?> students</span>
            <?php else: ?>
            <span class="badge-pill badge-success">Unique</span>
            <?php endif; ?>
          </div>
          <div style="font-size:13px;margin-bottom:4px;"><?= sanitize($net['names']) ?></div>
          <div style="font-size:11px;color:var(--text-muted);">
            <?= sanitize($net['rolls']) ?>
            &nbsp;·&nbsp; First: <?= date('H:i:s', strtotime($net['first_submit'])) ?>
            &nbsp;·&nbsp; Last: <?= date('H:i:s', strtotime($net['last_submit'])) ?>
            <?php if ($net['span_secs'] !== null && $isShared): ?>
            &nbsp;·&nbsp; <strong>Span: <?= gmdate('i:s', $net['span_secs']) ?></strong>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     TAB: SIMILARITY MATRIX
═════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'similarity'): ?>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-files" style="color:var(--brand)"></i> Answer Similarity Matrix (≥ 60%)</h3>
    <span style="font-size:13px;color:var(--text-muted);"><?= count($simMatrix) ?> pairs</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Student A</th><th>Student B</th><th>Similarity</th><th>Matching</th><th>Same Wrong</th><th>Verdict</th><th>Compare</th></tr>
      </thead>
      <tbody>
        <?php foreach ($simMatrix as $pair): ?>
        <?php
          $sim = (float)$pair['similarity_pct'];
          $verdict = $sim >= 90 ? ['risk-high','Highly Suspicious'] :
                    ($sim >= 80 ? ['risk-high','Very Suspicious'] :
                    ($sim >= 70 ? ['risk-medium','Suspicious'] :
                    ['badge-secondary','Notable']));
          $simColor = $sim >= 80 ? 'var(--danger)' : ($sim >= 70 ? 'var(--warning)' : 'var(--text-muted)');
        ?>
        <tr>
          <td>
            <div style="font-weight:600;"><?= sanitize($pair['name_a']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);">Student A</div>
          </td>
          <td>
            <div style="font-weight:600;"><?= sanitize($pair['name_b']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);">Student B</div>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="progress-bar-wrap" style="width:80px;">
                <div class="progress-bar-fill" style="width:<?= $sim ?>%;background:<?= $simColor ?>;"></div>
              </div>
              <span style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:<?= $simColor ?>;">
                <?= $sim ?>%
              </span>
            </div>
          </td>
          <td style="text-align:center;"><?= $pair['matching_answers'] ?>/<?= $pair['total_questions'] ?></td>
          <td style="text-align:center;">
            <?php if ($pair['matching_wrong'] > 0): ?>
            <span class="badge-pill risk-high"><?= $pair['matching_wrong'] ?> ⚠</span>
            <?php else: ?>
            <span style="color:var(--success);">0</span>
            <?php endif; ?>
          </td>
          <td><span class="badge-pill <?= $verdict[0] ?>"><?= $verdict[1] ?></span></td>
          <td>
            <a href="compare.php?exam_id=<?= $examId ?>&student_a=<?= $pair['student_a'] ?>&student_b=<?= $pair['student_b'] ?>"
               class="btn-primary btn-sm">
              <i class="bi bi-columns-gap"></i> Compare
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$simMatrix): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
          <i class="bi bi-shield-check" style="font-size:36px;color:var(--success);display:block;margin-bottom:8px;"></i>
          No student pairs with similarity ≥ 60%. Exam looks clean.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // end tab ?>

<?php elseif ($examId): ?>
<!-- No flags yet -->
<div style="text-align:center;padding:80px 20px;">
  <i class="bi bi-shield-check" style="font-size:64px;color:var(--success);display:block;margin-bottom:16px;"></i>
  <h2 style="font-size:22px;font-weight:800;margin-bottom:8px;">No Cheating Flags</h2>
  <p style="color:var(--text-muted);max-width:400px;margin:0 auto 24px;">
    No flags have been detected for this exam yet. Run the detection engine to analyze all submissions.
  </p>
  <form method="POST" style="display:inline;">
    <input type="hidden" name="exam_id" value="<?= $examId ?>">
    <button type="submit" name="run_detection" class="btn-primary" style="padding:12px 28px;">
      <i class="bi bi-shield-check"></i> Run Detection Now
    </button>
  </form>
</div>
<?php endif; ?>

</div><!-- .main-content -->
</div><!-- .layout -->

<!-- Evidence modal -->
<div id="evidenceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;backdrop-filter:blur(4px);"
     onclick="this.style.display='none'">
  <div style="background:var(--bg-card);border-radius:var(--radius);padding:28px;max-width:520px;
              margin:10vh auto;position:relative;" onclick="event.stopPropagation()">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:12px;"><i class="bi bi-search"></i> Flag Evidence</h3>
    <p id="evidenceText" style="color:var(--text-muted);font-size:14px;line-height:1.7;"></p>
    <button onclick="document.getElementById('evidenceModal').style.display='none'"
            style="margin-top:16px;background:var(--bg);border:1px solid var(--border);
                   padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;">Close</button>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
function showEvidence(text) {
  document.getElementById('evidenceText').textContent = text;
  document.getElementById('evidenceModal').style.display = 'block';
}
</script>
