<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Auth.php';

requireLogin('admin');

$db   = Database::getConnection();
$auth = new Auth();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_student'])) {
        $result = $auth->registerStudent([
            'full_name'     => post('full_name'),
            'email'         => post('email'),
            'password'      => post('password'),
            'student_id_no'   => post('student_id_no'),
            'department_id' => (int)post('department_id'),
            'semester_name'    => (int)post('semester_name'),
        ]);
        setFlash($result['ok'] ? 'success' : 'danger',
                 $result['ok'] ? 'Student added successfully.' : $result['error']);
        header('Location: students.php'); exit;
    }

    if (isset($_POST['toggle_block'])) {
        $sid     = (int)$_POST['student_id'];
        $blocked = (int)$_POST['current_blocked'];
        $db->prepare("UPDATE students SET is_blocked = ? WHERE student_id = ?")
           ->execute([$blocked ? 0 : 1, $sid]);
        setFlash('success', $blocked ? 'Student unblocked.' : 'Student blocked.');
        header('Location: students.php'); exit;
    }

    if (isset($_POST['delete_student'])) {
        $sid = (int)$_POST['student_id'];
        $db->prepare("DELETE FROM students WHERE student_id = ?")->execute([$sid]);
        setFlash('success', 'Student deleted.');
        header('Location: students.php'); exit;
    }
}

// Filters
$search   = get('q', '');
$deptFlt  = (int)get('dept', 0);
$blockFlt = get('blocked', '');

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(s.full_name LIKE ? OR s.email LIKE ? OR s.student_id_no LIKE ?)';
    $params  = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($deptFlt) { $where[] = 's.department_id = ?'; $params[] = $deptFlt; }
if ($blockFlt !== '') { $where[] = 's.is_blocked = ?'; $params[] = (int)$blockFlt; }
$whereSql = implode(' AND ', $where);

$students = $db->prepare("
    SELECT s.*, d.dept_name, d.dept_code,
           sem.semester_name, sec.section_name,
           COUNT(DISTINCT ea.attempt_id)  AS exam_count,
           COUNT(DISTINCT cf.flag_id)     AS flag_count
    FROM students s
    JOIN departments d   ON d.department_id = s.department_id
    JOIN semesters   sem ON sem.semester_id = s.semester_id
    JOIN sections    sec ON sec.section_id  = s.section_id
    LEFT JOIN exam_attempts  ea ON ea.student_id  = s.student_id
    LEFT JOIN cheating_flags cf ON cf.student_id  = s.student_id
    WHERE {$whereSql}
    GROUP BY s.student_id
    ORDER BY s.created_at DESC
");
$students->execute($params);
$students = $students->fetchAll();

$depts = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$flash = getFlash();

renderHead('Student Management');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','Students'); ?>

<div class="main-content">
<?php renderTopbar('Student Management'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

  <!-- Students Table -->
  <div>
    <!-- Filters -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-body" style="padding:14px 16px;">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
          <div>
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" placeholder="Name, email, roll…"
                   value="<?= sanitize($search) ?>" style="width:200px;">
          </div>
          <div>
            <label class="form-label">Department</label>
            <select name="dept" class="form-control" style="width:180px;">
              <option value="0">All Departments</option>
              <?php foreach ($depts as $d): ?>
              <option value="<?= $d['department_id'] ?>" <?= $deptFlt==$d['department_id']?'selected':'' ?>>
                <?= sanitize($d['dept_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Status</label>
            <select name="blocked" class="form-control" style="width:130px;">
              <option value="">All</option>
              <option value="0" <?= $blockFlt==='0'?'selected':'' ?>>Active</option>
              <option value="1" <?= $blockFlt==='1'?'selected':'' ?>>Blocked</option>
            </select>
          </div>
          <button type="submit" class="btn-primary">
            <i class="bi bi-search"></i> Filter
          </button>
          <a href="students.php" class="btn-ghost" style="padding:9px 16px;">Reset</a>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3><i class="bi bi-people-fill" style="color:var(--brand)"></i>
          Students <span style="font-size:13px;font-weight:500;color:var(--text-muted);">(<?= count($students) ?>)</span>
        </h3>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Student</th><th>Roll No.</th><th>Department</th><th>Batch</th><th>Exams</th><th>Flags</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s): ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div style="width:34px;height:34px;border-radius:50%;
                              background:<?= $s['is_blocked'] ? 'var(--danger)' : 'var(--brand)' ?>;
                              display:flex;align-items:center;justify-content:center;
                              color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">
                    <?= strtoupper(substr($s['full_name'],0,2)) ?>
                  </div>
                  <div>
                    <div style="font-weight:600;"><?= sanitize($s['full_name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted);"><?= sanitize($s['email']) ?></div>
                  </div>
                </div>
              </td>
              <td style="font-family:monospace;font-size:13px;"><?= sanitize($s['student_id_no']) ?></td>
              <td>
                <span class="badge-pill badge-info"><?= sanitize($s['dept_code']) ?></span>
              </td>
              <td><?= $s['semester_name'] ?></td>
              <td style="text-align:center;"><?= $s['exam_count'] ?></td>
              <td style="text-align:center;">
                <?php if ($s['flag_count'] > 0): ?>
                <span class="badge-pill badge-danger"><?= $s['flag_count'] ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <?php if ($s['is_blocked']): ?>
                <span class="badge-pill badge-danger">Blocked</span>
                <?php elseif (!$s['is_active']): ?>
                <span class="badge-pill badge-secondary">Inactive</span>
                <?php else: ?>
                <span class="badge-pill badge-success">Active</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:4px;">
                  <!-- Block/Unblock -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                    <input type="hidden" name="current_blocked" value="<?= $s['is_blocked'] ?>">
                    <input type="hidden" name="toggle_block" value="1">
                    <button class="btn-primary btn-sm <?= $s['is_blocked'] ? 'btn-success' : 'btn-warning' ?>"
                            title="<?= $s['is_blocked'] ? 'Unblock' : 'Block' ?>">
                      <i class="bi bi-<?= $s['is_blocked'] ? 'unlock' : 'lock' ?>"></i>
                    </button>
                  </form>
                  <!-- Delete -->
                  <form method="POST" style="display:inline;"
                        onsubmit="return confirm('Delete student and all their data?')">
                    <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                    <input type="hidden" name="delete_student" value="1">
                    <button class="btn-primary btn-sm btn-danger" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?>
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">
              No students found matching your filters.
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Add Student Form -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header">
      <h3><i class="bi bi-person-plus-fill" style="color:var(--success)"></i> Add Student</h3>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="full_name" class="form-control" placeholder="Full name" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
        </div>
        <div class="form-group">
          <label class="form-label">Roll Number *</label>
          <input type="text" name="student_id_no" class="form-control" placeholder="e.g. CSE-2024-001" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group">
            <label class="form-label">Batch Year</label>
            <input type="number" name="semester_name" class="form-control"
                   value="<?= date('Y') ?>" min="2000" max="<?= date('Y')+1 ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Department *</label>
            <select name="department_id" class="form-control" required>
              <option value="">— Select —</option>
              <?php foreach ($depts as $d): ?>
              <option value="<?= $d['department_id'] ?>"><?= sanitize($d['dept_code']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password *</label>
          <input type="password" name="password" class="form-control" placeholder="Min 6 chars" required>
        </div>
        <button type="submit" name="add_student" class="btn-primary" style="width:100%;justify-content:center;padding:11px;">
          <i class="bi bi-plus-lg"></i> Add Student
        </button>
      </form>
    </div>
  </div>

</div>
</div>
</div>
<?php renderFooter(); ?>
