<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';
requireLogin('teacher');

$db        = Database::getConnection();
$tid       = currentUser()['id'];
$examModel = new Exam();

$tRow = $db->prepare("SELECT department_id FROM teachers WHERE teacher_id = ?");
$tRow->execute([$tid]); $teacherDeptId = $tRow->fetch()['department_id'];

$semesters = $db->query("SELECT * FROM semesters ORDER BY semester_id DESC")->fetchAll();
$sections  = $db->prepare("SELECT * FROM sections WHERE department_id = ? ORDER BY section_name");
$sections->execute([$teacherDeptId]); $sections = $sections->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_exam'])) {
        $errs = [];
        $start = post('scheduled_start'); $end = post('scheduled_end');
        $sectionIds = array_map('intval', (array)($_POST['section_ids'] ?? []));
        $examType   = post('exam_type','quiz');
        $semId      = (int)post('semester_id') ?: null;
        if (!post('title'))      $errs[] = 'Exam title is required.';
        if (!$start||!$end)      $errs[] = 'Start and end dates are required.';
        if ($start&&$end&&strtotime($end)<=strtotime($start)) $errs[] = 'End must be after start.';
        if (empty($sectionIds))  $errs[] = 'Select at least one section.';
        if (in_array($examType,['midterm','final']) && !$semId) $errs[] = 'Semester is required for Midterm/Final.';
        if (!$errs) {
            $eid = $examModel->create(['teacher_id'=>$tid,'department_id'=>$teacherDeptId,'semester_id'=>$semId,
                'exam_type'=>$examType,'title'=>post('title'),'description'=>post('description'),
                'total_marks'=>(int)post('total_marks',100),'pass_marks'=>(int)post('pass_marks',40),
                'duration_mins'=>(int)post('duration_mins',60),'scheduled_start'=>$start,'scheduled_end'=>$end,
                'is_randomized'=>post('is_randomized')?1:0]);
            $ins = $db->prepare("INSERT IGNORE INTO exam_sections (exam_id,section_id) VALUES (?,?)");
            foreach ($sectionIds as $sid) $ins->execute([$eid,$sid]);
            setFlash('success','Exam created! Now add questions.');
            header('Location: questions.php?exam_id='.$eid); exit;
        } else { setFlash('danger', implode(' ', $errs)); }
    }
    if (isset($_POST['delete_exam'])) {
        $eid = (int)$_POST['exam_id'];
        $chk = $db->prepare("SELECT exam_id FROM exams WHERE exam_id=? AND teacher_id=?");
        $chk->execute([$eid,$tid]);
        if ($chk->fetch()) $db->prepare("DELETE FROM exams WHERE exam_id=?")->execute([$eid]);
        setFlash('success','Exam deleted.'); header('Location: exams.php'); exit;
    }
    if (isset($_POST['update_status'])) {
        $eid = (int)$_POST['exam_id']; $status = $_POST['new_status'];
        if (in_array($status,['draft','scheduled','running','completed','cancelled']))
            $db->prepare("UPDATE exams SET status=? WHERE exam_id=? AND teacher_id=?")->execute([$status,$eid,$tid]);
        setFlash('success','Status updated.'); header('Location: exams.php'); exit;
    }
    if (isset($_POST['run_detection'])) {
        $examModel->runCheatingDetection((int)$_POST['exam_id']);
        setFlash('success','Detection complete. Check the Cheating report.');
        header('Location: exams.php'); exit;
    }
}

$exams = $examModel->byTeacher($tid);
$flash = getFlash();
renderHead('My Exams');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('teacher','My Exams'); ?>
<div class="main-content">
<?php renderTopbar('Exam Management'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?> fade-in">
  <i class="bi bi-info-circle-fill"></i><span><?= sanitize($flash['msg']) ?></span>
</div>
<?php endif; ?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Exam Management</h1>
    <p>Create and manage your department exams and section-wise quizzes</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start;">

  <!-- Exams Table -->
  <div class="card fade-in">
    <div class="card-header">
      <h3><i class="bi bi-journal-text"></i> My Exams (<?= count($exams) ?>)</h3>
      <div class="search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" class="form-control" placeholder="Search exams…" style="width:200px;"
               oninput="filterTable(this,'examsTable')">
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table" id="examsTable">
        <thead>
          <tr><th>Exam</th><th>Type</th><th>Semester</th><th>Sections</th><th>Scheduled</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($exams as $ex):
            $sm = ['draft'=>['badge-gray','Draft'],'scheduled'=>['badge-primary','Scheduled'],
                   'running'=>['badge-success','Running'],'completed'=>['badge-gray','Done'],'cancelled'=>['badge-danger','Cancelled']];
            [$sc,$sl] = $sm[$ex['status']] ?? ['badge-gray',$ex['status']];
            $tm = ['quiz'=>['badge-info','Quiz'],'midterm'=>['badge-warning','Midterm'],'final'=>['badge-danger','Final']];
            [$tc,$tl] = $tm[$ex['exam_type']??'quiz'] ?? ['badge-info','Quiz'];
            $secs = $db->prepare("SELECT s.section_name FROM exam_sections es JOIN sections s ON s.section_id=es.section_id WHERE es.exam_id=? ORDER BY s.section_name");
            $secs->execute([$ex['exam_id']]); $secList = $secs->fetchAll(PDO::FETCH_COLUMN);
          ?>
          <tr>
            <td>
              <div style="font-weight:700;color:var(--text);"><?= sanitize($ex['title']) ?></div>
              <?php if ($ex['is_randomized']): ?>
              <span class="badge badge-gray" style="margin-top:4px;font-size:10px;"><i class="bi bi-shuffle"></i> Randomized</span>
              <?php endif; ?>
            </td>
            <td><span class="badge-pill <?= $tc ?>"><?= $tl ?></span></td>
            <td style="font-size:12.5px;color:var(--text-muted);"><?= sanitize($ex['semester_name']??'—') ?></td>
            <td style="font-size:12.5px;">
              <?= $secList ? implode(', ', array_map(fn($s)=>"§$s", $secList)) : '<span style="color:var(--text-faint)">—</span>' ?>
            </td>
            <td style="font-size:12.5px;">
              <?= date('M j, Y', strtotime($ex['scheduled_start'])) ?><br>
              <span style="color:var(--text-muted);"><?= date('g:i A', strtotime($ex['scheduled_start'])) ?></span>
            </td>
            <td><span class="badge-pill <?= $sc ?>"><?= $sl ?></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <a href="questions.php?exam_id=<?= $ex['exam_id'] ?>" class="btn btn-primary btn-sm" title="Add/View Questions">
                  <i class="bi bi-question-circle"></i>
                </a>
                <a href="results.php?exam_id=<?= $ex['exam_id'] ?>" class="btn btn-ghost btn-sm" title="View Results">
                  <i class="bi bi-bar-chart"></i>
                </a>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
                  <input type="hidden" name="update_status" value="1">
                  <select name="new_status" class="form-control"
                          style="width:108px;padding:5px 8px;font-size:12px;display:inline-block;"
                          onchange="this.form.submit()">
                    <?php foreach(['draft','scheduled','running','completed','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $ex['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <?php if ($ex['attempt_count'] > 0 || $ex['status']==='completed'): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Run cheating detection?')">
                  <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
                  <input type="hidden" name="run_detection" value="1">
                  <button class="btn btn-warning btn-sm" title="Run Detection"><i class="bi bi-shield-check"></i></button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this exam permanently?')">
                  <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
                  <input type="hidden" name="delete_exam" value="1">
                  <button class="btn btn-danger btn-sm" title="Delete"><i class="bi bi-trash3"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$exams): ?>
          <tr><td colspan="7">
            <div class="empty-state"><i class="bi bi-journal-plus"></i><h4>No exams yet</h4><p>Create your first exam using the form →</p></div>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Create Form -->
  <div class="card fade-in" style="position:sticky;top:80px;">
    <div class="card-header">
      <h3><i class="bi bi-plus-circle-fill" style="color:var(--success)"></i> Create New Exam</h3>
    </div>
    <div class="card-body">
      <form method="POST">

        <div class="form-group">
          <label class="form-label">Exam Type *</label>
          <select name="exam_type" id="examType" class="form-control" required onchange="toggleSemester(this.value)">
            <option value="quiz">📝 Section-wise Quiz</option>
            <option value="midterm">📖 Midterm Exam</option>
            <option value="final">🎓 Semester Final</option>
          </select>
        </div>

        <div class="form-group" id="semGroup">
          <label class="form-label">Semester <span id="semStar" style="color:var(--danger)">*</span>
            <span id="semOpt" style="color:var(--text-faint);font-weight:400;text-transform:none;">(optional for quiz)</span>
          </label>
          <select name="semester_id" id="semSelect" class="form-control">
            <option value="">— Optional for Quiz —</option>
            <?php foreach ($semesters as $sem): ?>
            <option value="<?= $sem['semester_id'] ?>"><?= sanitize($sem['semester_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Sections * <small style="font-weight:400;color:var(--text-faint);">select one or more</small></label>
          <div class="section-chips">
            <?php foreach ($sections as $sec): ?>
            <input type="checkbox" name="section_ids[]" value="<?= $sec['section_id'] ?>"
                   id="sec<?= $sec['section_id'] ?>" class="section-chip-input">
            <label for="sec<?= $sec['section_id'] ?>" class="section-chip-label">
              Section <?= sanitize($sec['section_name']) ?>
            </label>
            <?php endforeach; ?>
            <?php if (!$sections): ?>
            <span style="font-size:12.5px;color:var(--text-muted);padding:4px;">No sections found. Add in admin panel.</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Exam Title *</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. CSE Midterm Exam 2025" required>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Optional instructions…"></textarea>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Total Marks</label>
            <input type="number" name="total_marks" class="form-control" value="100" min="1" required>
          </div>
          <div class="form-group">
            <label class="form-label">Pass Marks</label>
            <input type="number" name="pass_marks" class="form-control" value="40" min="1" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Duration (minutes)</label>
          <input type="number" name="duration_mins" class="form-control" value="60" min="5" required>
        </div>

        <div class="form-group">
  <label class="form-label">Start Date &amp; Time</label>
  <input type="datetime-local" name="scheduled_start" id="schedStart" 
         class="form-control" style="width:100%;" required>
</div>
<div class="form-group">
  <label class="form-label">End Date &amp; Time</label>
  <input type="datetime-local" name="scheduled_end" id="schedEnd" 
         class="form-control" style="width:100%;" required>
</div>
        <p style="font-size:11px;color:var(--text-faint);margin-top:-10px;margin-bottom:12px;">
          <i class="bi bi-info-circle"></i> Times are in Bangladesh Standard Time (UTC+6)
        </p>

        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px;background:var(--bg);border-radius:var(--radius-sm);margin-bottom:16px;">
          <input type="checkbox" name="is_randomized" style="width:16px;height:16px;accent-color:var(--primary);cursor:pointer;">
          <span style="font-size:13.5px;font-weight:500;">Randomize question order</span>
        </label>

        <button type="submit" name="create_exam" class="btn btn-primary btn-full btn-lg">
          <i class="bi bi-plus-lg"></i> Create Exam
        </button>
      </form>
    </div>
  </div>

</div>
</div>
</div>
<?php renderFooter(); ?>
<script>
function toggleSemester(t) {
  const r = (t==='midterm'||t==='final');
  document.getElementById('semSelect').required = r;
  document.getElementById('semStar').style.display = r ? '' : 'none';
  document.getElementById('semOpt').style.display  = r ? 'none' : '';
  document.querySelector('#semSelect option:first-child').textContent = r ? '— Select Semester —' : '— Optional for Quiz —';
}
function filterTable(inp, id) {
  const val = inp.value.toLowerCase();
  document.querySelectorAll('#'+id+' tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(val) ? '' : 'none';
  });
}
toggleSemester('quiz');

// Set datetime inputs to current Bangladesh time as default/min
(function() {
  // Format date as datetime-local string: YYYY-MM-DDTHH:MM
  function toLocalDT(date) {
    const pad = n => String(n).padStart(2, '0');
    return date.getFullYear() + '-' + pad(date.getMonth()+1) + '-' + pad(date.getDate())
      + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }
  const now   = new Date();
  const in1h  = new Date(now.getTime() + 60*60*1000);
  const in2h  = new Date(now.getTime() + 2*60*60*1000);
  const startEl = document.getElementById('schedStart');
  const endEl   = document.getElementById('schedEnd');
  if (startEl) { startEl.min = toLocalDT(now); startEl.value = toLocalDT(in1h); }
  if (endEl)   { endEl.min   = toLocalDT(now); endEl.value   = toLocalDT(in2h); }
  // When start changes, update end min
  if (startEl) startEl.addEventListener('change', function() {
    if (endEl) { endEl.min = this.value; if (endEl.value <= this.value) endEl.value = ''; }
  });
})();
</script>
