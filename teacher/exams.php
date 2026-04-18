<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('teacher');

$db        = Database::getConnection();
$tid       = currentUser()['id'];
$examModel = new Exam();

// Fetch teacher's dept
$teacherRow = $db->prepare("SELECT department_id FROM teachers WHERE teacher_id = ?");
$teacherRow->execute([$tid]);
$teacherDeptId = $teacherRow->fetch()['department_id'];

$depts = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['create_exam'])) {
        $errors = [];
        $start  = post('scheduled_start');
        $end    = post('scheduled_end');
        if (!post('title')) $errors[] = 'Title is required.';
        if (!$start || !$end) $errors[] = 'Dates are required.';
        if ($start && $end && strtotime($end) <= strtotime($start)) $errors[] = 'End must be after start.';

        if (!$errors) {
            $examId = $examModel->create([
                'teacher_id'      => $tid,
                'department_id'   => $teacherDeptId,
                'title'           => post('title'),
                'description'     => post('description'),
                'total_marks'     => (int)post('total_marks', 100),
                'pass_marks'      => (int)post('pass_marks', 40),
                'duration_mins'   => (int)post('duration_mins', 60),
                'scheduled_start' => $start,
                'scheduled_end'   => $end,
                'is_randomized'   => post('is_randomized') ? 1 : 0,
            ]);
            setFlash('success', 'Exam created! Now add questions.');
            header('Location: questions.php?exam_id=' . $examId); exit;
        } else {
            setFlash('danger', implode(' ', $errors));
        }
    }

    if (isset($_POST['delete_exam'])) {
        $eid = (int)$_POST['exam_id'];
        // Only if teacher owns it
        $check = $db->prepare("SELECT exam_id FROM exams WHERE exam_id = ? AND teacher_id = ?");
        $check->execute([$eid, $tid]);
        if ($check->fetch()) {
            $db->prepare("DELETE FROM exams WHERE exam_id = ?")->execute([$eid]);
            setFlash('success', 'Exam deleted.');
        }
        header('Location: exams.php'); exit;
    }

    if (isset($_POST['run_detection'])) {
        $eid = (int)$_POST['exam_id'];
        $examModel->runCheatingDetection($eid);
        setFlash('success', 'Cheating detection complete. Check the Cheating Report.');
        header('Location: exams.php'); exit;
    }

    if (isset($_POST['update_status'])) {
        $eid    = (int)$_POST['exam_id'];
        $status = $_POST['new_status'];
        if (in_array($status, ['draft','scheduled','running','completed','cancelled'])) {
            $db->prepare("UPDATE exams SET status = ? WHERE exam_id = ? AND teacher_id = ?")
               ->execute([$status, $eid, $tid]);
            setFlash('success', 'Exam status updated.');
        }
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
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

  <!-- Exams List -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-journal-text" style="color:var(--brand)"></i> All Exams (<?= count($exams) ?>)</h3>
      <input type="text" id="examSearch" class="form-control" placeholder="Search…"
             style="width:180px;" oninput="filterTable('examSearch','examsTable')">
    </div>
    <div class="table-wrap">
      <table class="data-table" id="examsTable">
        <thead>
          <tr><th>Title</th><th>Scheduled</th><th>Duration</th><th>Status</th><th>Students</th><th>Flags</th><th>Actions</th></tr>
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
              <div style="font-weight:700;"><?= sanitize($ex['title']) ?></div>
              <?php if ($ex['is_randomized']): ?>
              <span style="font-size:10px;background:var(--bg);padding:2px 7px;border-radius:4px;color:var(--text-muted);">
                <i class="bi bi-shuffle"></i> Randomized
              </span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <?= date('M j, Y', strtotime($ex['scheduled_start'])) ?><br>
              <span style="color:var(--text-muted);"><?= date('g:i A', strtotime($ex['scheduled_start'])) ?></span>
            </td>
            <td><?= $ex['duration_mins'] ?> min</td>
            <td><span class="badge-pill badge-<?= $cls ?>"><?= $lbl ?></span></td>
            <td><?= $ex['attempt_count'] ?></td>
            <td>
              <?php if ($ex['flag_count'] > 0): ?>
              <span class="badge-pill badge-danger"><?= $ex['flag_count'] ?></span>
              <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <a href="questions.php?exam_id=<?= $ex['exam_id'] ?>" class="btn-primary btn-sm" title="Questions">
                  <i class="bi bi-question-circle"></i>
                </a>
                <a href="../admin/results.php?exam_id=<?= $ex['exam_id'] ?>" class="btn-ghost btn-sm" title="Results">
                  <i class="bi bi-bar-chart"></i>
                </a>

                <!-- Status Change -->
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
                  <input type="hidden" name="update_status" value="1">
                  <select name="new_status" class="form-control"
                          style="width:110px;padding:5px 8px;font-size:12px;display:inline-block;"
                          onchange="this.form.submit()">
                    <?php foreach (['draft','scheduled','running','completed','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $ex['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>

                <!-- Run Detection -->
                <?php if ($ex['status'] === 'completed' || $ex['attempt_count'] > 0): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Run cheating detection for this exam?')">
                  <input type="hidden" name="exam_id" value="<?= $ex['exam_id'] ?>">
                  <input type="hidden" name="run_detection" value="1">
                  <button class="btn-primary btn-sm" style="background:var(--warning);" title="Run Cheating Detection">
                    <i class="bi bi-shield-check"></i>
                  </button>
                </form>
                <?php endif; ?>

                <!-- Delete -->
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Delete exam and all its data?')">
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
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
            No exams yet. Create your first exam using the form →
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Create Form -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header">
      <h3><i class="bi bi-plus-circle" style="color:var(--success)"></i> Create New Exam</h3>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Exam Title *</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Midterm 2024" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"
                    placeholder="Optional instructions…"></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group">
            <label class="form-label">Total Marks</label>
            <input type="number" name="total_marks" class="form-control" value="100" min="1" required>
          </div>
          <div class="form-group">
            <label class="form-label">Pass Marks (%)</label>
            <input type="number" name="pass_marks" class="form-control" value="40" min="1" max="100" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Duration (minutes)</label>
          <input type="number" name="duration_mins" class="form-control" value="60" min="5" required>
        </div>
        <div class="form-group">
          <label class="form-label">Start Date & Time</label>
          <input type="datetime-local" name="scheduled_start" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">End Date & Time</label>
          <input type="datetime-local" name="scheduled_end" class="form-control" required>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:10px;">
          <input type="checkbox" name="is_randomized" id="randCheck" style="width:18px;height:18px;cursor:pointer;">
          <label for="randCheck" style="cursor:pointer;font-size:14px;font-weight:500;">
            Randomize question order
          </label>
        </div>
        <button type="submit" name="create_exam" class="btn-primary" style="width:100%;justify-content:center;padding:12px;">
          <i class="bi bi-plus-lg"></i> Create Exam
        </button>
      </form>
    </div>
  </div>

</div>
</div>
</div>
<?php renderFooter(); ?>
