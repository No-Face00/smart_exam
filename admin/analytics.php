<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('admin');
$db = Database::getConnection();

// ── Score distribution (buckets of 10) ──────────────────
$scoreDist = $db->query("
    SELECT
      FLOOR(score / 10) * 10 AS bucket,
      COUNT(*)               AS cnt
    FROM exam_attempts
    WHERE status = 'submitted' AND score IS NOT NULL
    GROUP BY bucket
    ORDER BY bucket
")->fetchAll();

$distLabels = [];
$distData   = [];
for ($i = 0; $i <= 90; $i += 10) {
    $distLabels[] = "{$i}–" . ($i + 9) . "%";
    $found = array_filter($scoreDist, fn($r) => (int)$r['bucket'] === $i);
    $distData[] = $found ? reset($found)['cnt'] : 0;
}

// ── Exams per month (last 6 months) ──────────────────────
$examsByMonth = $db->query("
    SELECT DATE_FORMAT(scheduled_start, '%b %Y') AS month_label,
           DATE_FORMAT(scheduled_start, '%Y-%m') AS month_key,
           COUNT(*) AS cnt
    FROM exams
    WHERE scheduled_start >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key
")->fetchAll();

$monthLabels    = array_column($examsByMonth, 'month_label');
$monthExamData  = array_column($examsByMonth, 'cnt');

// ── Pass vs Fail per department ───────────────────────────
$passFail = $db->query("
    SELECT d.dept_name, d.dept_code,
           SUM(ea.is_passed = 1)  AS passed,
           SUM(ea.is_passed = 0)  AS failed
    FROM exam_attempts ea
    JOIN students    s  ON s.student_id   = ea.student_id
    JOIN departments d  ON d.department_id = s.department_id
    WHERE ea.status = 'submitted'
    GROUP BY d.department_id
    ORDER BY d.dept_name
")->fetchAll();

$deptLabels   = array_column($passFail, 'dept_code');
$deptPassed   = array_column($passFail, 'passed');
$deptFailed   = array_column($passFail, 'failed');

// ── Cheating flags by type ────────────────────────────────
$flagTypes = $db->query("
    SELECT flag_type, COUNT(*) AS cnt
    FROM cheating_flags
    GROUP BY flag_type
    ORDER BY cnt DESC
")->fetchAll();

$flagTypeLabels = array_map(fn($r) => ucwords(str_replace('_',' ',$r['flag_type'])), $flagTypes);
$flagTypeCounts = array_column($flagTypes, 'cnt');

// ── Top 5 exams by avg score ──────────────────────────────
$topExams = $db->query("
    SELECT e.title, ROUND(AVG(ea.score),1) AS avg_score, COUNT(*) AS attempts
    FROM exam_attempts ea
    JOIN exams e ON e.exam_id = ea.exam_id
    WHERE ea.status = 'submitted'
    GROUP BY ea.exam_id
    ORDER BY avg_score DESC
    LIMIT 5
")->fetchAll();

// ── Attempts over last 7 days ─────────────────────────────
$daily = $db->query("
    SELECT DATE(start_time) AS day, COUNT(*) AS cnt
    FROM exam_attempts
    WHERE start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY day ORDER BY day
")->fetchAll();

$dayLabels = [];
$dayData   = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dayLabels[] = date('M j', strtotime($d));
    $found = array_filter($daily, fn($r) => $r['day'] === $d);
    $dayData[] = $found ? reset($found)['cnt'] : 0;
}

// ── Overall KPIs ─────────────────────────────────────────
$kpi = $db->query("
    SELECT
      COUNT(*)                                         AS total_attempts,
      ROUND(AVG(score),1)                              AS overall_avg,
      ROUND(SUM(is_passed)/COUNT(*)*100, 1)            AS pass_rate,
      ROUND(AVG(time_taken_secs)/60, 1)               AS avg_time_mins
    FROM exam_attempts
    WHERE status = 'submitted'
")->fetch();

renderHead('Analytics');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','Analytics'); ?>

<div class="main-content">
<?php renderTopbar('Analytics Dashboard'); ?>

<!-- KPI Row -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:28px;">
  <?= statCard('Total Attempts',  number_format($kpi['total_attempts']),        'journal-check',   'blue')   ?>
  <?= statCard('Overall Avg',     ($kpi['overall_avg'] ?? 0) . '%',             'bar-chart-fill',  'green')  ?>
  <?= statCard('Pass Rate',       ($kpi['pass_rate']    ?? 0) . '%',            'trophy-fill',     'amber')  ?>
  <?= statCard('Avg Time',        ($kpi['avg_time_mins'] ?? 0) . ' min',        'clock-fill',      'purple') ?>
</div>

<!-- Row 1: Activity line + score dist -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Daily activity -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-activity" style="color:var(--brand)"></i> Exam Attempts — Last 7 Days</h3>
    </div>
    <div class="card-body">
      <canvas id="chartDaily" height="180"></canvas>
    </div>
  </div>

  <!-- Score distribution -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-distribute-horizontal" style="color:var(--purple, #8B5CF6)"></i> Score Distribution</h3>
    </div>
    <div class="card-body">
      <canvas id="chartScoreDist" height="180"></canvas>
    </div>
  </div>

</div>

<!-- Row 2: Pass/Fail by dept + Exams per month -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-people-fill" style="color:var(--success)"></i> Pass / Fail by Department</h3>
    </div>
    <div class="card-body">
      <canvas id="chartPassFail" height="200"></canvas>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-calendar3" style="color:var(--cyan, #06B6D4)"></i> Exams Created — Last 6 Months</h3>
    </div>
    <div class="card-body">
      <canvas id="chartExamsMonth" height="200"></canvas>
    </div>
  </div>

</div>

<!-- Row 3: Flag types donut + Top exams table -->
<div style="display:grid;grid-template-columns:320px 1fr;gap:20px;margin-bottom:20px;">

  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-shield-exclamation" style="color:var(--danger)"></i> Flags by Type</h3>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;align-items:center;gap:16px;">
      <?php if ($flagTypeCounts): ?>
      <canvas id="chartFlagTypes" width="200" height="200"></canvas>
      <div style="width:100%;">
        <?php
        $palette = ['#EF4444','#F97316','#F59E0B','#8B5CF6','#06B6D4','#10B981'];
        foreach ($flagTypeLabels as $i => $lbl):
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;">
          <span style="width:12px;height:12px;border-radius:3px;background:<?= $palette[$i % count($palette)] ?>;flex-shrink:0;"></span>
          <span style="flex:1;"><?= sanitize($lbl) ?></span>
          <strong><?= $flagTypeCounts[$i] ?></strong>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:32px;color:var(--text-muted);">
        <i class="bi bi-shield-check" style="font-size:36px;color:var(--success);"></i>
        <p style="margin-top:8px;">No cheating flags yet</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-star-fill" style="color:var(--amber)"></i> Top 5 Exams by Average Score</h3>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>Exam Title</th><th>Attempts</th><th>Avg Score</th><th>Rating</th></tr>
        </thead>
        <tbody>
          <?php foreach ($topExams as $i => $ex): ?>
          <tr>
            <td style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;text-align:center;color:var(--text-muted);">
              <?= ['🥇','🥈','🥉','4','5'][$i] ?? ($i+1) ?>
            </td>
            <td style="font-weight:600;"><?= sanitize($ex['title']) ?></td>
            <td style="text-align:center;"><?= $ex['attempts'] ?></td>
            <td>
              <span style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;
                color:<?= $ex['avg_score'] >= 70 ? 'var(--success)' : ($ex['avg_score'] >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;">
                <?= $ex['avg_score'] ?>%
              </span>
            </td>
            <td style="min-width:140px;">
              <div class="progress-bar-wrap">
                <div class="progress-bar-fill" style="width:<?= $ex['avg_score'] ?>%;
                  background:<?= $ex['avg_score'] >= 70 ? 'var(--success)' : ($ex['avg_score'] >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topExams): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);">No data yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

</div><!-- .main-content -->
</div><!-- .layout -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';
const gridColor  = () => isDark() ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
const labelColor = () => isDark() ? '#94A3B8' : '#64748B';

const chartDefaults = {
  responsive: true,
  plugins: { legend: { display: false } },
  scales: {
    x: { grid: { color: gridColor() }, ticks: { color: labelColor(), font: { family: "'DM Sans'" } } },
    y: { grid: { color: gridColor() }, ticks: { color: labelColor(), font: { family: "'DM Sans'" } } }
  }
};

// Daily Attempts
new Chart(document.getElementById('chartDaily'), {
  type: 'line',
  data: {
    labels: <?= json_encode($dayLabels) ?>,
    datasets: [{
      label: 'Attempts',
      data: <?= json_encode($dayData) ?>,
      borderColor: '#2563EB',
      backgroundColor: 'rgba(37,99,235,.08)',
      borderWidth: 2.5,
      fill: true,
      tension: .4,
      pointBackgroundColor: '#2563EB',
      pointRadius: 4,
    }]
  },
  options: { ...chartDefaults, plugins: { legend: { display: false } } }
});

// Score Distribution
new Chart(document.getElementById('chartScoreDist'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($distLabels) ?>,
    datasets: [{
      label: 'Students',
      data: <?= json_encode($distData) ?>,
      backgroundColor: <?= json_encode(array_map(fn($v,$i) =>
        $i < 4 ? '#FEF2F2' : ($i < 7 ? '#FFFBEB' : '#ECFDF5'), $distData, array_keys($distData))) ?>,
      borderColor: <?= json_encode(array_map(fn($v,$i) =>
        $i < 4 ? '#EF4444' : ($i < 7 ? '#F59E0B' : '#10B981'), $distData, array_keys($distData))) ?>,
      borderWidth: 2,
      borderRadius: 6,
    }]
  },
  options: chartDefaults
});

// Pass/Fail by Department
new Chart(document.getElementById('chartPassFail'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($deptLabels) ?>,
    datasets: [
      { label: 'Passed', data: <?= json_encode($deptPassed) ?>, backgroundColor: '#10B981', borderRadius: 4 },
      { label: 'Failed', data: <?= json_encode($deptFailed) ?>, backgroundColor: '#EF4444', borderRadius: 4 }
    ]
  },
  options: {
    ...chartDefaults,
    plugins: { legend: { display: true, position: 'top', labels: { color: labelColor(), font: { family: "'DM Sans'" } } } },
    scales: {
      x: { stacked: false, grid: { color: gridColor() }, ticks: { color: labelColor() } },
      y: { stacked: false, grid: { color: gridColor() }, ticks: { color: labelColor() } }
    }
  }
});

// Exams per Month
new Chart(document.getElementById('chartExamsMonth'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($monthLabels) ?>,
    datasets: [{
      label: 'Exams',
      data: <?= json_encode($monthExamData) ?>,
      backgroundColor: 'rgba(37,99,235,.7)',
      borderRadius: 6,
    }]
  },
  options: chartDefaults
});

// Flag Types Donut
<?php if ($flagTypeCounts): ?>
new Chart(document.getElementById('chartFlagTypes'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($flagTypeLabels) ?>,
    datasets: [{
      data: <?= json_encode($flagTypeCounts) ?>,
      backgroundColor: ['#EF4444','#F97316','#F59E0B','#8B5CF6','#06B6D4','#10B981'],
      borderWidth: 0,
      hoverOffset: 8,
    }]
  },
  options: {
    responsive: false,
    cutout: '65%',
    plugins: { legend: { display: false } }
  }
});
<?php endif; ?>
</script>
</body>
</html>
