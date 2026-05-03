<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('student');

$db  = Database::getConnection();
$sid = currentUser()['id'];

$profile = $db->prepare("SELECT department_id FROM students WHERE student_id = ?");
$profile->execute([$sid]);
$deptId = $profile->fetch()['department_id'];

// Dept filter
$filterDept = (int)get('dept', $deptId);

$leaderboard = $db->prepare("
    SELECT
      s.student_id, s.full_name, s.student_id_no, d.dept_name,
      COUNT(ea.attempt_id)               AS exams_taken,
      SUM(ea.is_passed)                  AS exams_passed,
      ROUND(AVG(ea.score), 1)            AS avg_score,
      MAX(ea.score)                      AS best_score,
      RANK() OVER (ORDER BY AVG(ea.score) DESC, COUNT(ea.attempt_id) DESC) AS rank_pos
    FROM students s
    JOIN departments d ON d.department_id = s.department_id
    JOIN exam_attempts ea ON ea.student_id = s.student_id AND ea.status = 'submitted'
    WHERE (? = 0 OR s.department_id = ?)
    GROUP BY s.student_id
    ORDER BY rank_pos
    LIMIT 50
");
$leaderboard->execute([$filterDept == 0 ? 0 : $filterDept, $filterDept]);
$leaders = $leaderboard->fetchAll();

$depts = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();

// My rank
$myRankRow = array_filter($leaders, fn($r) => $r['student_id'] == $sid);
$myRank    = $myRankRow ? reset($myRankRow)['rank_pos'] : null;

renderHead('Leaderboard');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','Leaderboard'); ?>

<div class="main-content">
<?php renderTopbar('Leaderboard'); ?>

<!-- Filter -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap;">
  <div style="font-weight:600;font-size:14px;">Filter by Department:</div>
  <a href="leaderboard.php?dept=0" class="btn-<?= $filterDept==0?'primary':'ghost' ?>" style="padding:7px 16px;border-radius:99px;">All</a>
  <?php foreach ($depts as $d): ?>
  <a href="leaderboard.php?dept=<?= $d['department_id'] ?>"
     class="btn-<?= $filterDept==$d['department_id']?'primary':'ghost' ?>"
     style="padding:7px 16px;border-radius:99px;font-size:13px;">
    <?= sanitize($d['dept_code']) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- My Rank Banner -->
<?php if ($myRank): ?>
<div style="background:linear-gradient(135deg,#1E3A8A,#2563EB);border-radius:var(--radius);padding:20px 24px;
            margin-bottom:24px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
  <div>
    <div style="font-size:12px;opacity:.7;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;">Your Current Rank</div>
    <div style="font-family:'Syne',sans-serif;font-size:32px;font-weight:800;">#<?= $myRank ?></div>
  </div>
  <div style="font-size:40px;opacity:.3;"><i class="bi bi-trophy-fill"></i></div>
</div>
<?php endif; ?>

<!-- Leaderboard Table -->
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-bar-chart-steps" style="color:var(--brand)"></i>
      Top Students <?= $filterDept ? '— ' . sanitize($depts[array_search($filterDept,array_column($depts,'department_id'))]['dept_name'] ?? '') : '(All Departments)' ?>
    </h3>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:60px;">Rank</th>
          <th>Student</th>
          <th>Department</th>
          <th>Exams</th>
          <th>Pass Rate</th>
          <th>Avg Score</th>
          <th>Best</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leaders as $i => $l): ?>
        <?php $isMe = $l['student_id'] == $sid; ?>
        <tr style="<?= $isMe ? 'background:var(--brand-light);font-weight:600;' : '' ?>">
          <td style="text-align:center;">
            <?php if ($l['rank_pos'] == 1): ?>
              <span style="font-size:22px;">🥇</span>
            <?php elseif ($l['rank_pos'] == 2): ?>
              <span style="font-size:22px;">🥈</span>
            <?php elseif ($l['rank_pos'] == 3): ?>
              <span style="font-size:22px;">🥉</span>
            <?php else: ?>
              <span style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--text-muted);">
                #<?= $l['rank_pos'] ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;border-radius:50%;background:<?= $isMe ? 'var(--brand)' : 'var(--bg)' ?>;
                          display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;
                          color:<?= $isMe ? '#fff' : 'var(--text-muted)' ?>;flex-shrink:0;">
                <?= strtoupper(substr($l['full_name'],0,2)) ?>
              </div>
              <div>
                <div><?= sanitize($l['full_name']) ?> <?= $isMe ? '<span style="color:var(--brand);font-size:11px;">(You)</span>' : '' ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($l['student_id_no']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:13px;"><?= sanitize($l['dept_name']) ?></td>
          <td style="text-align:center;"><?= $l['exams_taken'] ?></td>
          <td>
            <?php $passRate = $l['exams_taken'] ? round($l['exams_passed']/$l['exams_taken']*100) : 0; ?>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="progress-bar-wrap" style="width:60px;">
                <div class="progress-bar-fill" style="width:<?= $passRate ?>%;background:var(--success);"></div>
              </div>
              <span style="font-size:13px;"><?= $passRate ?>%</span>
            </div>
          </td>
          <td>
            <span style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;">
              <?= $l['avg_score'] ?>%
            </span>
          </td>
          <td>
            <span style="color:var(--amber);font-weight:700;"><?= round($l['best_score']) ?>%</span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$leaders): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
          No results yet for this department.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>
<?php renderFooter(); ?>
