<?php
// api/export.php — Export exam results as CSV or PDF
// Usage: /api/export.php?exam_id=5&format=csv  OR  &format=pdf

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

requireLogin();

$user   = currentUser();
$examId = (int)get('exam_id');
$format = get('format', 'csv');

if (!$examId) {
    http_response_code(400); echo 'Missing exam_id'; exit;
}

$db = Database::getConnection();

// Verify access: admin can export any; teacher can export their own
$examStmt = $db->prepare("
    SELECT e.*, t.full_name AS teacher_name, d.dept_name
    FROM exams e
    JOIN teachers t ON t.teacher_id = e.teacher_id
    JOIN departments d ON d.department_id = e.department_id
    WHERE e.exam_id = ?
");
$examStmt->execute([$examId]);
$exam = $examStmt->fetch();

if (!$exam) { http_response_code(404); echo 'Exam not found'; exit; }

if ($user['type'] === 'teacher') {
    $check = $db->prepare("SELECT 1 FROM exams WHERE exam_id = ? AND teacher_id = ?");
    $check->execute([$examId, $user['id']]);
    if (!$check->fetch()) { http_response_code(403); echo 'Forbidden'; exit; }
}
if ($user['type'] === 'student') { http_response_code(403); echo 'Forbidden'; exit; }

// Fetch results
$results = $db->prepare("
    SELECT
        s.roll_number,
        s.full_name,
        s.email,
        d.dept_name,
        ea.start_time,
        ea.submit_time,
        ea.time_taken_secs,
        ea.score,
        ea.is_passed,
        ea.status,
        ea.ip_address,
        COUNT(cf.flag_id)  AS flag_count,
        MAX(cf.risk_level) AS highest_risk
    FROM exam_attempts ea
    JOIN students    s  ON s.student_id   = ea.student_id
    JOIN departments d  ON d.department_id = s.department_id
    LEFT JOIN cheating_flags cf ON cf.attempt_id = ea.attempt_id
    WHERE ea.exam_id = ?
    GROUP BY ea.attempt_id
    ORDER BY ea.score DESC
");
$results->execute([$examId]);
$rows = $results->fetchAll();

$filename = preg_replace('/[^a-z0-9_]/i', '_', $exam['title']) . '_results';

/* ──────────────────────────────────────────
   CSV Export
────────────────────────────────────────── */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    // Exam info header
    fputcsv($out, ['Exam Title:', $exam['title']]);
    fputcsv($out, ['Teacher:', $exam['teacher_name']]);
    fputcsv($out, ['Department:', $exam['dept_name']]);
    fputcsv($out, ['Duration (mins):', $exam['duration_mins']]);
    fputcsv($out, ['Pass Mark (%):', $exam['pass_marks']]);
    fputcsv($out, ['Exported At:', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    // Column headers
    fputcsv($out, [
        'Roll Number', 'Full Name', 'Email', 'Department',
        'Score (%)', 'Pass/Fail', 'Time Taken (mm:ss)',
        'Start Time', 'Submit Time', 'IP Address',
        'Status', 'Cheat Flags', 'Highest Risk'
    ]);

    foreach ($rows as $r) {
        $timeFmt = $r['time_taken_secs']
            ? sprintf('%02d:%02d', intdiv($r['time_taken_secs'],60), $r['time_taken_secs']%60)
            : '—';
        fputcsv($out, [
            $r['roll_number'],
            $r['full_name'],
            $r['email'],
            $r['dept_name'],
            round($r['score'] ?? 0, 2),
            $r['is_passed'] ? 'Pass' : 'Fail',
            $timeFmt,
            $r['start_time'],
            $r['submit_time'] ?? '—',
            $r['ip_address'],
            ucfirst($r['status']),
            $r['flag_count'],
            $r['highest_risk'] ?? 'none',
        ]);
    }

    // Summary row
    $scores   = array_filter(array_column($rows,'score'), fn($s)=>$s!==null);
    $passCount = count(array_filter($rows, fn($r)=>$r['is_passed']));
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Total Attempts', count($rows)]);
    fputcsv($out, ['Passed', $passCount]);
    fputcsv($out, ['Failed', count($rows) - $passCount]);
    fputcsv($out, ['Average Score', $scores ? round(array_sum($scores)/count($scores),2).'%' : '—']);
    fputcsv($out, ['Highest Score', $scores ? round(max($scores),2).'%' : '—']);
    fputcsv($out, ['Lowest Score',  $scores ? round(min($scores),2).'%' : '—']);

    fclose($out);
    exit;
}

/* ──────────────────────────────────────────
   PDF Export (pure PHP — no external libs)
   Generates a clean HTML page with print CSS
────────────────────────────────────────── */
if ($format === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');

    $scores    = array_filter(array_column($rows,'score'), fn($s)=>$s!==null);
    $passCount = count(array_filter($rows, fn($r)=>$r['is_passed']));
    $avgScore  = $scores ? round(array_sum($scores)/count($scores),1) : 0;
    $passRate  = count($rows) ? round($passCount/count($rows)*100,1) : 0;

    echo '<!DOCTYPE html><html lang="en"><head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($exam['title']) . ' — Results</title>
    <style>
      @import url("https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap");
      * { margin:0; padding:0; box-sizing:border-box; }
      body { font-family:"DM Sans",sans-serif; font-size:13px; color:#1E293B; padding:32px; }
      h1 { font-family:"Syne",sans-serif; font-size:26px; font-weight:800; margin-bottom:4px; }
      .meta { color:#64748B; font-size:12px; margin-bottom:24px; }
      .kpis { display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
      .kpi  { background:#F0F4FF; border-radius:10px; padding:12px 18px; text-align:center; min-width:100px; }
      .kpi-v{ font-family:"Syne",sans-serif; font-size:22px; font-weight:800; }
      .kpi-l{ font-size:11px; color:#64748B; text-transform:uppercase; letter-spacing:.5px; }
      table { width:100%; border-collapse:collapse; }
      th    { background:#F0F4FF; padding:8px 10px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748B; border-bottom:1.5px solid #E2E8F0; }
      td    { padding:9px 10px; border-bottom:1px solid #E2E8F0; vertical-align:middle; }
      tr:last-child td { border-bottom:none; }
      .pass   { color:#065F46; font-weight:700; background:#ECFDF5; padding:2px 8px; border-radius:99px; font-size:11px; }
      .fail   { color:#991B1B; font-weight:700; background:#FEF2F2; padding:2px 8px; border-radius:99px; font-size:11px; }
      .flag   { color:#991B1B; }
      .footer { margin-top:24px; text-align:center; font-size:11px; color:#94A3B8; }
      @media print { body { padding:16px; } button { display:none; } }
    </style>
    </head><body>';

    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
      <div>
        <h1>' . htmlspecialchars($exam['title']) . '</h1>
        <p class="meta">
          Teacher: ' . htmlspecialchars($exam['teacher_name']) . ' &nbsp;·&nbsp;
          Dept: ' . htmlspecialchars($exam['dept_name']) . ' &nbsp;·&nbsp;
          Duration: ' . $exam['duration_mins'] . ' min &nbsp;·&nbsp;
          Pass Mark: ' . $exam['pass_marks'] . '%
        </p>
      </div>
      <button onclick="window.print()" style="background:#2563EB;color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:13px;font-family:inherit;">
        🖨 Print / Save PDF
      </button>
    </div>';

    echo '<div class="kpis">
      <div class="kpi"><div class="kpi-v">' . count($rows) . '</div><div class="kpi-l">Attempts</div></div>
      <div class="kpi"><div class="kpi-v">' . $passCount  . '</div><div class="kpi-l">Passed</div></div>
      <div class="kpi"><div class="kpi-v">' . (count($rows)-$passCount) . '</div><div class="kpi-l">Failed</div></div>
      <div class="kpi"><div class="kpi-v">' . $avgScore . '%</div><div class="kpi-l">Avg Score</div></div>
      <div class="kpi"><div class="kpi-v">' . $passRate . '%</div><div class="kpi-l">Pass Rate</div></div>
    </div>';

    echo '<table>
      <thead><tr>
        <th>#</th><th>Roll No.</th><th>Name</th><th>Score</th><th>Status</th><th>Time</th><th>IP</th><th>Flags</th>
      </tr></thead>
      <tbody>';

    foreach ($rows as $i => $r) {
        $timeFmt = $r['time_taken_secs']
            ? sprintf('%02d:%02d', intdiv($r['time_taken_secs'],60), $r['time_taken_secs']%60)
            : '—';
        $statusCls = $r['is_passed'] ? 'pass' : 'fail';
        $statusLbl = $r['is_passed'] ? 'Pass' : 'Fail';
        echo '<tr>
          <td style="color:#94A3B8;">' . ($i+1) . '</td>
          <td style="font-family:monospace;">' . htmlspecialchars($r['roll_number']) . '</td>
          <td><strong>' . htmlspecialchars($r['full_name']) . '</strong></td>
          <td><strong>' . round($r['score'] ?? 0,1) . '%</strong></td>
          <td><span class="' . $statusCls . '">' . $statusLbl . '</span></td>
          <td style="font-family:monospace;">' . $timeFmt . '</td>
          <td style="font-family:monospace;font-size:11px;">' . htmlspecialchars($r['ip_address']) . '</td>
          <td class="' . ($r['flag_count']>0?'flag':'') . '">' . ($r['flag_count']>0 ? '⚠ '.$r['flag_count'] : '—') . '</td>
        </tr>';
    }

    echo '</tbody></table>
    <p class="footer">Generated by SmartExam · ' . date('F j, Y g:i A') . '</p>
    </body></html>';
    exit;
}

http_response_code(400); echo 'Unsupported format';
