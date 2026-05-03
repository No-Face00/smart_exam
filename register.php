<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php'); exit;
}

$db          = Database::getConnection();
$departments = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$semesters   = $db->query("SELECT * FROM semesters ORDER BY semester_id DESC")->fetchAll();

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth();
    $data = [
        'full_name'     => post('full_name'),
        'email'         => post('email'),
        'password'      => post('password'),
        'student_id_no' => post('student_id_no'),
        'department_id' => (int)post('department_id'),
        'semester_id'   => (int)post('semester_id'),
        'section_id'    => (int)post('section_id'),
    ];
    $confirm = post('confirm_password');

    if (strlen($data['full_name']) < 3)      $error = 'Full name must be at least 3 characters.';
    elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (strlen($data['password']) < 6)   $error = 'Password must be at least 6 characters.';
    elseif ($data['password'] !== $confirm)  $error = 'Passwords do not match.';
    elseif (!$data['student_id_no'])         $error = 'Student ID is required.';
    elseif (!$data['department_id'])         $error = 'Please select a department.';
    elseif (!$data['semester_id'])           $error = 'Please select a semester.';
    elseif (!$data['section_id'])            $error = 'Please select a section.';
    else {
        $result = $auth->registerStudent($data);
        $result['ok'] ? ($success = 'Account created! You can now sign in.') : ($error = $result['error']);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — SmartExam</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link id="mainCss" rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<script>
  window.BASE_URL = '<?= addslashes(BASE_URL) ?>';
  // Robust CSS fallback: if absolute URL fails, try multiple relative paths
  (function() {
    var el = document.getElementById('mainCss');
    var tries = [
      'assets/css/main.css',
      '../assets/css/main.css',
      '../../assets/css/main.css'
    ];
    var attempt = 0;
    function tryNext() {
      if (attempt >= tries.length) return;
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = tries[attempt++];
      l.onerror = tryNext;
      document.head.appendChild(l);
    }
    if (el) el.onerror = tryNext;
  })();
</script>
</head>
<body>

<div class="auth-page">

  <!-- Hero -->
  <div class="auth-hero">
    <div class="auth-hero-content">
      <div class="auth-brand">
        <div class="auth-brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
        <div><div class="auth-brand-name">Smart<span>Exam</span></div></div>
      </div>

      <h1 class="auth-hero-title">Join<br><span>SmartExam</span><br>Today</h1>
      <p class="auth-hero-sub">Create your student account. Your semester and section determine which exams you can access — keeping everything fair and organized.</p>

      <div class="auth-features" style="margin-top:36px;">
        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-journal-check"></i></div>
          <span class="auth-feature-text">Access exams assigned to your section</span>
        </div>
        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-arrow-repeat"></i></div>
          <span class="auth-feature-text">Update semester every new term</span>
        </div>
        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-graph-up-arrow"></i></div>
          <span class="auth-feature-text">Track your scores and rankings</span>
        </div>
        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-shield-check-fill"></i></div>
          <span class="auth-feature-text">Secure, fair academic environment</span>
        </div>
      </div>
    </div>

    <div class="auth-hero-footer">
      <p style="font-size:12px;color:rgba(255,255,255,.35);letter-spacing:.3px;">
        SEMESTER-BASED ACCESS CONTROL · SECTION FILTERING
      </p>
    </div>
  </div>

  <!-- Form -->
  <div class="auth-form-wrap" style="overflow-y:auto; padding-top: 40px; padding-bottom: 40px;">
    <div class="auth-form-inner" style="max-width:460px;">

      <div class="auth-form-header">
        <h1 class="auth-form-title">Create Account</h1>
        <p class="auth-form-sub">Student registration — all fields are required</p>
      </div>

      <?php if ($success): ?>
      <div class="flash-alert flash-success">
        <i class="bi bi-check-circle-fill"></i>
        <div>
          <?= sanitize($success) ?>
          <a href="<?= BASE_URL ?>/index.php" style="margin-left:8px;font-weight:700;color:var(--success);">Sign in now →</a>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="flash-alert flash-danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= sanitize($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="">

        <div class="form-group">
          <label class="form-label">Full Name</label>
          <div class="form-control-icon">
            <i class="bi bi-person-fill"></i>
            <input type="text" name="full_name" class="form-control"
                   placeholder="Your full name" value="<?= sanitize(post('full_name')) ?>" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email Address</label>
            <div class="form-control-icon">
              <i class="bi bi-envelope-fill"></i>
              <input type="email" name="email" class="form-control"
                     placeholder="you@university.edu" value="<?= sanitize(post('email')) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Student ID</label>
            <div class="form-control-icon">
              <i class="bi bi-card-text"></i>
              <input type="text" name="student_id_no" class="form-control"
                     placeholder="e.g. CSE-2025-001" value="<?= sanitize(post('student_id_no')) ?>" required>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Department</label>
          <select name="department_id" id="deptSelect" class="form-control" required
                  onchange="loadSections(this.value)">
            <option value="">— Select your department —</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['department_id'] ?>" <?= post('department_id')==$d['department_id']?'selected':'' ?>>
              <?= sanitize($d['dept_name']) ?> (<?= sanitize($d['dept_code']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Semester</label>
            <select name="semester_id" class="form-control" required>
              <option value="">— Select semester —</option>
              <?php foreach ($semesters as $sem): ?>
              <option value="<?= $sem['semester_id'] ?>" <?= post('semester_id')==$sem['semester_id']?'selected':'' ?>>
                <?= sanitize($sem['semester_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Section</label>
            <select name="section_id" id="sectionSelect" class="form-control" required>
              <option value="">— Select dept first —</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Password</label>
            <div class="form-control-icon">
              <i class="bi bi-lock-fill"></i>
              <input type="password" name="password" class="form-control"
                     placeholder="Min 6 characters" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <div class="form-control-icon">
              <i class="bi bi-lock-fill"></i>
              <input type="password" name="confirm_password" class="form-control"
                     placeholder="Re-enter password" required>
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:4px;">
          <i class="bi bi-person-plus-fill"></i> Create My Account
        </button>
      </form>

      <div style="text-align:center;margin-top:24px;font-size:13px;color:var(--text-muted);">
        Already registered?
        <a href="<?= BASE_URL ?>/index.php" style="color:var(--primary);font-weight:700;margin-left:4px;">Sign In →</a>
      </div>

    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
async function loadSections(deptId) {
  const sel = document.getElementById('sectionSelect');
  sel.innerHTML = '<option value="">Loading…</option>';
  if (!deptId) { sel.innerHTML = '<option value="">— Select dept first —</option>'; return; }
  try {
    const r = await fetch(BASE_URL + '/api/get_sections.php?dept_id=' + deptId);
    const d = await r.json();
    sel.innerHTML = '<option value="">— Select section —</option>';
    d.forEach(s => {
      const o = document.createElement('option');
      o.value = s.section_id;
      o.textContent = 'Section ' + s.section_name;
      if (s.section_id == '<?= (int)post('section_id') ?>') o.selected = true;
      sel.appendChild(o);
    });
  } catch(e) { sel.innerHTML = '<option value="">— Error loading —</option>'; }
}
<?php if (post('department_id')): ?>loadSections(<?= (int)post('department_id') ?>);<?php endif; ?>
</script>
</body>
</html>
