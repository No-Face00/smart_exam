<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('admin');

$db = Database::getConnection();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_teacher'])) {
        $email  = post('email');
        $name   = post('full_name');
        $empCode= post('employee_code');
        $deptId = (int)post('department_id');
        $pass   = post('password');

        // Check duplicate
        $chk = $db->prepare("SELECT teacher_id FROM teachers WHERE email = ? OR employee_code = ?");
        $chk->execute([$email, $empCode]);
        if ($chk->fetch()) {
            setFlash('danger', 'Email or employee code already exists.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            setFlash('danger', 'Invalid email or password too short.');
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
            $db->prepare("
                INSERT INTO teachers (department_id, full_name, email, password_hash, employee_code)
                VALUES (?,?,?,?,?)
            ")->execute([$deptId, $name, $email, $hash, $empCode]);
            setFlash('success', 'Teacher added successfully.');
        }
        header('Location: teachers.php'); exit;
    }

    if (isset($_POST['toggle_block'])) {
        $tid     = (int)$_POST['teacher_id'];
        $blocked = (int)$_POST['current_blocked'];
        $db->prepare("UPDATE teachers SET is_blocked = ? WHERE teacher_id = ?")
           ->execute([$blocked ? 0 : 1, $tid]);
        setFlash('success', $blocked ? 'Teacher unblocked.' : 'Teacher blocked.');
        header('Location: teachers.php'); exit;
    }

    if (isset($_POST['delete_teacher'])) {
        $tid = (int)$_POST['teacher_id'];
        $db->prepare("DELETE FROM teachers WHERE teacher_id = ?")->execute([$tid]);
        setFlash('success', 'Teacher deleted.');
        header('Location: teachers.php'); exit;
    }
}

$teachers = $db->query("
    SELECT t.*, d.dept_name, d.dept_code,
           COUNT(DISTINCT e.exam_id) AS exam_count
    FROM teachers t
    JOIN departments d ON d.department_id = t.department_id
    LEFT JOIN exams e ON e.teacher_id = t.teacher_id
    GROUP BY t.teacher_id
    ORDER BY t.created_at DESC
")->fetchAll();

$depts = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$flash = getFlash();

renderHead('Teacher Management');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','Teachers'); ?>

<div class="main-content">
<?php renderTopbar('Teacher Management'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

  <!-- Teachers Table -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-person-badge-fill" style="color:var(--brand)"></i>
        Teachers (<?= count($teachers) ?>)
      </h3>
      <input type="text" id="teacherSearch" class="form-control" placeholder="Search…"
             style="width:180px;" oninput="filterTable('teacherSearch','teachersTable')">
    </div>
    <div class="table-wrap">
      <table class="data-table" id="teachersTable">
        <thead>
          <tr><th>Teacher</th><th>Employee ID</th><th>Department</th><th>Exams</th><th>Last Login</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($teachers as $t): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:50%;
                            background:<?= $t['is_blocked'] ? 'var(--danger)' : '#8B5CF6' ?>;
                            display:flex;align-items:center;justify-content:center;
                            color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">
                  <?= strtoupper(substr($t['full_name'],0,2)) ?>
                </div>
                <div>
                  <div style="font-weight:600;"><?= sanitize($t['full_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($t['email']) ?></div>
                </div>
              </div>
            </td>
            <td style="font-family:monospace;font-size:13px;"><?= sanitize($t['employee_code']) ?></td>
            <td><span class="badge-pill badge-info"><?= sanitize($t['dept_code']) ?></span></td>
            <td style="text-align:center;"><?= $t['exam_count'] ?></td>
            <td style="font-size:12px;color:var(--text-muted);">
              <?= $t['last_login'] ? date('M j, g:i a', strtotime($t['last_login'])) : 'Never' ?>
            </td>
            <td>
              <?php if ($t['is_blocked']): ?>
              <span class="badge-pill badge-danger">Blocked</span>
              <?php else: ?>
              <span class="badge-pill badge-success">Active</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:4px;">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="teacher_id" value="<?= $t['teacher_id'] ?>">
                  <input type="hidden" name="current_blocked" value="<?= $t['is_blocked'] ?>">
                  <input type="hidden" name="toggle_block" value="1">
                  <button class="btn-primary btn-sm <?= $t['is_blocked'] ? 'btn-success' : 'btn-warning' ?>"
                          title="<?= $t['is_blocked'] ? 'Unblock' : 'Block' ?>">
                    <i class="bi bi-<?= $t['is_blocked'] ? 'unlock' : 'lock' ?>"></i>
                  </button>
                </form>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Delete teacher and all their exams?')">
                  <input type="hidden" name="teacher_id" value="<?= $t['teacher_id'] ?>">
                  <input type="hidden" name="delete_teacher" value="1">
                  <button class="btn-primary btn-sm btn-danger" title="Delete">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$teachers): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
            No teachers added yet.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Add Teacher Form -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header">
      <h3><i class="bi bi-person-plus-fill" style="color:var(--success)"></i> Add Teacher</h3>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="full_name" class="form-control" placeholder="Full name" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-control" placeholder="teacher@uni.edu" required>
        </div>
        <div class="form-group">
          <label class="form-label">Employee Code *</label>
          <input type="text" name="employee_code" class="form-control" placeholder="e.g. EMP-2024-001" required>
        </div>
        <div class="form-group">
          <label class="form-label">Department *</label>
          <select name="department_id" class="form-control" required>
            <option value="">— Select —</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= $d['department_id'] ?>"><?= sanitize($d['dept_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Password *</label>
          <input type="password" name="password" class="form-control" placeholder="Min 6 chars" required>
        </div>
        <button type="submit" name="add_teacher" class="btn-primary" style="width:100%;justify-content:center;padding:11px;">
          <i class="bi bi-plus-lg"></i> Add Teacher
        </button>
      </form>
    </div>
  </div>

</div>
</div>
</div>
<?php renderFooter(); ?>
