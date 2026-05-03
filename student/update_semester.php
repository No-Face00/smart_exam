<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/Auth.php';

requireLogin('student');

$db        = Database::getConnection();
$auth      = new Auth();
$sid       = currentUser()['id'];

$studentRow = $db->prepare("SELECT s.*, sec.section_name, sem.semester_name, d.dept_name, d.department_id
    FROM students s
    JOIN sections sec  ON sec.section_id  = s.section_id
    JOIN semesters sem ON sem.semester_id = s.semester_id
    JOIN departments d ON d.department_id = s.department_id
    WHERE s.student_id = ?");
$studentRow->execute([$sid]);
$student = $studentRow->fetch();

$semesters = $db->query("SELECT * FROM semesters ORDER BY semester_id DESC")->fetchAll();
$sections  = $db->prepare("SELECT * FROM sections WHERE department_id = ? ORDER BY section_name");
$sections->execute([$student['department_id']]);
$sections  = $sections->fetchAll();

$error   = '';
$success = '';
$forced  = isset($_GET['forced']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSemId  = (int)post('semester_id');
    $newSecId  = (int)post('section_id');
    if (!$newSemId || !$newSecId) {
        $error = 'Please select both semester and section.';
    } else {
        $result = $auth->updateStudentSemesterSection($sid, $newSemId, $newSecId);
        if ($result['ok']) {
            setFlash('success', 'Semester and section updated successfully!');
            header('Location: ' . BASE_URL . '/student/dashboard.php'); exit;
        } else {
            $error = $result['error'];
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';
renderHead('Update Semester');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','Dashboard'); ?>
<div class="main-content">
<?php renderTopbar('Update Semester & Section'); ?>

<div style="max-width:560px;margin:0 auto;">

<?php if ($forced): ?>
<div class="flash-alert flash-warning" style="margin-bottom:20px;">
  <i class="bi bi-calendar-exclamation-fill"></i>
  <strong>Semester Update Recommended</strong> — It's been over 4 months since you last updated your semester and section. Please update to ensure correct exam access.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-arrow-repeat" style="color:var(--brand)"></i> Update Semester & Section</h3>
  </div>
  <div class="card-body">

    <!-- Current Data -->
    <div style="background:var(--bg);border-radius:var(--radius-sm);padding:16px;margin-bottom:24px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:10px;">Current Data</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:14px;">
        <div><span style="color:var(--text-muted);">Semester:</span> <strong><?= sanitize($student['semester_name']) ?></strong></div>
        <div><span style="color:var(--text-muted);">Section:</span> <strong>Section <?= sanitize($student['section_name']) ?></strong></div>
        <div><span style="color:var(--text-muted);">Department:</span> <strong><?= sanitize($student['dept_name']) ?></strong></div>
        <div><span style="color:var(--text-muted);">Last Updated:</span> <strong><?= date('M j, Y', strtotime($student['last_updated_at'])) ?></strong></div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="flash-alert flash-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label">New Semester *</label>
        <select name="semester_id" class="form-control" required>
          <option value="">— Select New Semester —</option>
          <?php foreach ($semesters as $sem): ?>
          <option value="<?= $sem['semester_id'] ?>" <?= $sem['semester_id'] == $student['semester_id'] ? 'selected' : '' ?>>
            <?= sanitize($sem['semester_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">New Section *</label>
        <select name="section_id" class="form-control" required>
          <option value="">— Select Section —</option>
          <?php foreach ($sections as $sec): ?>
          <option value="<?= $sec['section_id'] ?>" <?= $sec['section_id'] == $student['section_id'] ? 'selected' : '' ?>>
            Section <?= sanitize($sec['section_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;gap:12px;">
        <button type="submit" class="btn-primary" style="flex:1;justify-content:center;padding:12px;">
          <i class="bi bi-check-lg"></i> Update Now
        </button>
        <?php if (!$forced): ?>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn-ghost" style="flex:1;justify-content:center;padding:12px;">
          Cancel
        </a>
        <?php endif; ?>
      </div>
    </form>

    <div style="margin-top:20px;padding:14px;background:var(--brand-light);border-radius:var(--radius-sm);font-size:13px;color:var(--text-muted);">
      <i class="bi bi-info-circle" style="color:var(--brand);"></i>
      After updating, you will only be able to join exams assigned to your new semester and section. Your department cannot be changed — contact admin if needed.
    </div>
  </div>
</div>
</div>
</div>
</div>
<?php renderFooter(); ?>
