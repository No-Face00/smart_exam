<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$departments = $db->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth();
    $data = [
        'full_name'     => post('full_name'),
        'email'         => post('email'),
        'password'      => post('password'),
        'roll_number'   => post('roll_number'),
        'department_id' => (int)post('department_id'),
        'batch_year'    => (int)post('batch_year'),
    ];

    $confirmPass = post('confirm_password');

    if (strlen($data['full_name']) < 3) {
        $error = 'Full name must be at least 3 characters.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($data['password']) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($data['password'] !== $confirmPass) {
        $error = 'Passwords do not match.';
    } elseif (!$data['roll_number']) {
        $error = 'Roll number is required.';
    } elseif (!$data['department_id']) {
        $error = 'Please select a department.';
    } elseif ($data['batch_year'] < 2000 || $data['batch_year'] > (int)date('Y') + 1) {
        $error = 'Enter a valid batch year.';
    } else {
        $result = $auth->registerStudent($data);
        if ($result['ok']) {
            $success = 'Registration successful! You can now log in.';
        } else {
            $error = $result['error'];
        }
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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
</head>
<body>

<div class="login-page">

  <!-- Hero -->
  <div class="login-hero">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:48px;">
      <div class="brand-icon" style="width:44px;height:44px;font-size:22px;">
        <i class="bi bi-mortarboard-fill"></i>
      </div>
      <span style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#fff;">SmartExam</span>
    </div>
    <h1 class="login-hero-title">Join SmartExam<br>Today</h1>
    <p class="login-hero-sub">Create your student account to access scheduled exams, view results, and track your academic progress.</p>
    <div class="login-features">
      <div class="login-feature"><i class="bi bi-journal-check"></i><span>Access all your department exams</span></div>
      <div class="login-feature"><i class="bi bi-graph-up-arrow"></i><span>Track scores and performance</span></div>
      <div class="login-feature"><i class="bi bi-shield-check-fill"></i><span>Secure, fair exam environment</span></div>
      <div class="login-feature"><i class="bi bi-star-fill"></i><span>Compete on the leaderboard</span></div>
    </div>
  </div>

  <!-- Form -->
  <div class="login-form-wrap" style="overflow-y:auto;">
    <div class="login-form-inner">
      <h2 class="login-form-title">Create Account</h2>
      <p class="login-form-sub">Student registration — fill in your details below</p>

      <?php if ($success): ?>
      <div class="flash-alert flash-success">
        <i class="bi bi-check-circle-fill"></i> <?= sanitize($success) ?>
        <a href="<?= BASE_URL ?>/index.php" style="margin-left:12px;font-weight:700;color:var(--success);">Login now →</a>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="flash-alert flash-danger">
        <i class="bi bi-exclamation-circle-fill"></i> <?= sanitize($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-control"
                   placeholder="Your full name" value="<?= sanitize(post('full_name')) ?>" required>
          </div>

          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control"
                   placeholder="you@university.edu" value="<?= sanitize(post('email')) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Roll Number</label>
            <input type="text" name="roll_number" class="form-control"
                   placeholder="e.g. CSE-2021-001" value="<?= sanitize(post('roll_number')) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Batch Year</label>
            <input type="number" name="batch_year" class="form-control"
                   placeholder="<?= date('Y') ?>" min="2000" max="<?= date('Y')+1 ?>"
                   value="<?= sanitize(post('batch_year', date('Y'))) ?>" required>
          </div>

          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-control" required>
              <option value="">— Select your department —</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>"
                <?= post('department_id') == $d['department_id'] ? 'selected' : '' ?>>
                <?= sanitize($d['dept_name']) ?> (<?= sanitize($d['dept_code']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control"
                   placeholder="Min 6 characters" required>
          </div>

          <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control"
                   placeholder="Repeat password" required>
          </div>
        </div>

        <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:12px;margin-top:4px;">
          <i class="bi bi-person-plus-fill"></i> Create Account
        </button>
      </form>

      <div style="text-align:center;margin-top:24px;font-size:13px;color:var(--text-muted);">
        Already have an account?
        <a href="<?= BASE_URL ?>/index.php" style="color:var(--brand);font-weight:600;">Sign In</a>
      </div>
    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
