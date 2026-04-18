<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth  = new Auth();
    $email = post('email');
    $pass  = post('password');
    $role  = post('role');

    if (!in_array($role, ['admin','teacher','student'])) {
        $error = 'Invalid role selected.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($pass) < 4) {
        $error = 'Password too short.';
    } else {
        $result = $auth->login($email, $pass, $role);
        if ($result['ok']) {
            header('Location: ' . BASE_URL . '/' . $role . '/dashboard.php');
            exit;
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — SmartExam</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<script>window.BASE_URL = '<?= BASE_URL ?>';</script>
</head>
<body>

<div class="login-page">

  <!-- ── Hero Panel ── -->
  <div class="login-hero">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:48px;">
      <div class="brand-icon" style="width:44px;height:44px;font-size:22px;">
        <i class="bi bi-mortarboard-fill"></i>
      </div>
      <span style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#fff;">SmartExam</span>
    </div>

    <h1 class="login-hero-title">Smarter Exams,<br>Fairer Results</h1>
    <p class="login-hero-sub">
      A powerful online exam platform with AI-grade SQL-based cheating detection, real-time monitoring, and instant analytics.
    </p>

    <div class="login-features">
      <div class="login-feature">
        <i class="bi bi-shield-check-fill"></i>
        <span>Advanced cheating detection engine</span>
      </div>
      <div class="login-feature">
        <i class="bi bi-lightning-charge-fill"></i>
        <span>Real-time exam monitoring</span>
      </div>
      <div class="login-feature">
        <i class="bi bi-bar-chart-fill"></i>
        <span>Instant results &amp; analytics</span>
      </div>
      <div class="login-feature">
        <i class="bi bi-phone-fill"></i>
        <span>Mobile-friendly responsive design</span>
      </div>
    </div>

    <!-- Decorative floating cards -->
    <div style="position:absolute;bottom:40px;right:40px;opacity:.2;font-size:120px;line-height:1;">
      <i class="bi bi-journal-text"></i>
    </div>
  </div>

  <!-- ── Form Panel ── -->
  <div class="login-form-wrap">
    <div class="login-form-inner">
      <h2 class="login-form-title">Welcome back</h2>
      <p class="login-form-sub">Sign in to your account</p>

      <!-- Role Selector -->
      <div class="role-tabs" id="roleTabs">
        <div class="role-tab active" data-role="student"  onclick="selectRole('student')">
          <i class="bi bi-person"></i> Student
        </div>
        <div class="role-tab" data-role="teacher" onclick="selectRole('teacher')">
          <i class="bi bi-person-badge"></i> Teacher
        </div>
        <div class="role-tab" data-role="admin"   onclick="selectRole('admin')">
          <i class="bi bi-shield-fill"></i> Admin
        </div>
      </div>

      <?php if ($error): ?>
      <div class="flash-alert flash-danger">
        <i class="bi bi-exclamation-circle-fill"></i>
        <?= sanitize($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="role" id="roleInput" value="<?= sanitize(post('role','student')) ?>">

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control"
                 placeholder="you@university.edu"
                 value="<?= sanitize(post('email')) ?>" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label" style="display:flex;justify-content:space-between;">
            Password
            <a href="#" style="font-weight:400;color:var(--brand);font-size:12px;">Forgot password?</a>
          </label>
          <div style="position:relative;">
            <input type="password" name="password" id="passField" class="form-control"
                   placeholder="••••••••" required>
            <button type="button" onclick="togglePass()"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                           background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;">
              <i class="bi bi-eye" id="passEye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:12px;">
          <i class="bi bi-box-arrow-in-right"></i>
          Sign In
        </button>
      </form>

      <div style="text-align:center;margin-top:28px;font-size:13px;color:var(--text-muted);">
        Don't have an account?
        <a href="<?= BASE_URL ?>/register.php" style="color:var(--brand);font-weight:600;">
          Register as Student
        </a>
      </div>

      <!-- Demo credentials hint -->
      <div style="margin-top:24px;padding:14px;background:var(--bg);border-radius:var(--radius-sm);font-size:12px;color:var(--text-muted);">
        <strong style="color:var(--text);">Demo Credentials</strong><br>
        Admin: admin@smartexam.com / Admin@1234<br>
        (Create teacher &amp; student accounts via admin panel)
      </div>
    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
function selectRole(role) {
  document.getElementById('roleInput').value = role;
  document.querySelectorAll('.role-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.role === role);
  });
}

function togglePass() {
  const f = document.getElementById('passField');
  const e = document.getElementById('passEye');
  if (f.type === 'password') {
    f.type = 'text';
    e.className = 'bi bi-eye-slash';
  } else {
    f.type = 'password';
    e.className = 'bi bi-eye';
  }
}

// Pre-select role if form re-submitted
selectRole('<?= sanitize(post('role','student')) ?>');
</script>
</body>
</html>
