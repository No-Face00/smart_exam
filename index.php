<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php'); exit;
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
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $result = $auth->login($email, $pass, $role);
        if ($result['ok']) { header('Location: ' . BASE_URL . '/' . $role . '/dashboard.php'); exit; }
        $error = $result['error'];
    }
}
$selRole = sanitize(post('role','student'));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — SmartExam</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link id="mainCss" rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<script>
  window.BASE_URL = '<?= addslashes(BASE_URL) ?>';
  (function() {
    var el = document.getElementById('mainCss');
    var tries = ['assets/css/main.css','../assets/css/main.css','../../assets/css/main.css'];
    var attempt = 0;
    function tryNext() {
      if (attempt >= tries.length) return;
      var l = document.createElement('link'); l.rel = 'stylesheet';
      l.href = tries[attempt++]; l.onerror = tryNext;
      document.head.appendChild(l);
    }
    if (el) el.onerror = tryNext;
  })();
</script>
<style>
/* ── Hero title: two distinct lines, no overlap ── */
.auth-hero-title {
  font-size: 42px !important;
  font-weight: 900 !important;
  line-height: 1.12 !important;
  letter-spacing: -1.2px !important;
  margin-bottom: 18px !important;
  color: #fff !important;
}
.auth-hero-title .line1 {
  display: block;
  color: rgba(255,255,255,0.55);
  font-size: 28px;
  font-weight: 600;
  letter-spacing: -0.4px;
  margin-bottom: 4px;
}
.auth-hero-title .line2 {
  display: block;
  color: #fff;
  font-size: 46px;
  font-weight: 900;
}
.auth-hero-title .line2 span {
  color: #a5b4fc;
}

/* ── Feature list polish ── */
.auth-feature {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.auth-feature:last-child { border-bottom: none; }
.auth-feature-icon {
  width: 38px; height: 38px;
  background: rgba(255,255,255,0.1);
  border: 1px solid rgba(255,255,255,0.14);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
  flex-shrink: 0;
  margin-top: 1px;
}
.auth-feature-icon i { color: #a5b4fc; }
.auth-feature-body { display: flex; flex-direction: column; gap: 2px; }
.auth-feature-title { font-size: 13.5px; font-weight: 600; color: #fff; }
.auth-feature-desc  { font-size: 12px; color: rgba(255,255,255,0.5); line-height: 1.5; }

/* ── Password field fix ── */
.pass-wrap { position: relative; }
.pass-wrap .form-control { padding-left: 40px; padding-right: 46px; }
.pass-wrap .field-icon-left {
  position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
  color: var(--text-faint); font-size: 15px; pointer-events: none; z-index: 2;
}
.pass-toggle {
  position: absolute; right: 0; top: 0; bottom: 0;
  width: 44px; display: flex; align-items: center; justify-content: center;
  background: none; border: none; cursor: pointer;
  color: var(--text-faint); font-size: 16px; z-index: 2;
}
.pass-toggle:hover { color: var(--text-muted); }

/* ── Brand name ── */
.auth-brand-name { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.4px; }
.auth-brand-name span { color: #a5b4fc; }
</style>
</head>
<body>

<div class="auth-page">

  <!-- ── Hero Panel ── -->
  <div class="auth-hero">
    <div class="auth-hero-content">

      <div class="auth-brand">
        <div class="auth-brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
        <div><div class="auth-brand-name">Smart<span>Exam</span></div></div>
      </div>

      <h1 class="auth-hero-title">
        <span class="line1">Academic Exam Platform</span>
        <span class="line2"><span>Smarter</span> Exams,<br>Fairer Results</span>
      </h1>

      <p class="auth-hero-sub" style="margin-bottom:32px;">
        A semester-aware exam system with built-in anti-cheating detection,
        automated grading, and real-time performance insights.
      </p>

      <div class="auth-features">

        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-shield-check-fill"></i></div>
          <div class="auth-feature-body">
            <div class="auth-feature-title">AI-assisted cheating detection</div>
            <div class="auth-feature-desc">Flags answer similarity, shared IPs, and suspicious timing patterns</div>
          </div>
        </div>

        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-diagram-3-fill"></i></div>
          <div class="auth-feature-body">
            <div class="auth-feature-title">Semester &amp; section access control</div>
            <div class="auth-feature-desc">Students only see exams assigned to their section and semester</div>
          </div>
        </div>

        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-bar-chart-fill"></i></div>
          <div class="auth-feature-body">
            <div class="auth-feature-title">Instant results &amp; analytics</div>
            <div class="auth-feature-desc">Auto-graded scores, leaderboards, and performance trends</div>
          </div>
        </div>

        <div class="auth-feature">
          <div class="auth-feature-icon"><i class="bi bi-clock-history"></i></div>
          <div class="auth-feature-body">
            <div class="auth-feature-title">Timed exams with auto-submit</div>
            <div class="auth-feature-desc">Countdown timer auto-submits when time expires, answers saved live</div>
          </div>
        </div>

      </div>
    </div>

    <!-- Footer: clean tagline, no stats -->
    <div class="auth-hero-footer">
      <p style="font-size:12px;color:rgba(255,255,255,.3);letter-spacing:.4px;margin-top:32px;">
        BCRYPT AUTH &nbsp;·&nbsp; SESSION PROTECTION &nbsp;·&nbsp; ROLE-BASED ACCESS
      </p>
    </div>
  </div>

  <!-- ── Form Panel ── -->
  <div class="auth-form-wrap">
    <div class="auth-form-inner">

      <div class="auth-form-header">
        <h1 class="auth-form-title">Welcome back</h1>
        <p class="auth-form-sub">Sign in to your SmartExam account to continue</p>
      </div>

      <!-- Role Selector -->
      <div class="role-tabs" id="roleTabs">
        <div class="role-tab <?= $selRole==='student'?'active':'' ?>" data-role="student" onclick="selectRole('student')">
          <i class="bi bi-person-fill"></i> Student
        </div>
        <div class="role-tab <?= $selRole==='teacher'?'active':'' ?>" data-role="teacher" onclick="selectRole('teacher')">
          <i class="bi bi-person-badge-fill"></i> Teacher
        </div>
        <div class="role-tab <?= $selRole==='admin'?'active':'' ?>" data-role="admin" onclick="selectRole('admin')">
          <i class="bi bi-shield-fill"></i> Admin
        </div>
      </div>

      <?php if ($error): ?>
      <div class="flash-alert flash-danger" style="margin-bottom:20px;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= sanitize($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="role" id="roleInput" value="<?= $selRole ?>">

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="form-control-icon">
            <i class="bi bi-envelope-fill"></i>
            <input type="email" name="email" class="form-control"
                   placeholder="you@university.edu"
                   value="<?= sanitize(post('email')) ?>" required autofocus>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Password</span>
            <a href="<?= BASE_URL ?>/forgot_password.php" id="forgotLink"
               style="font-weight:600;color:var(--primary);font-size:12px;text-decoration:none;">
              Forgot password?
            </a>
          </label>
          <!-- Fixed password field: proper z-index layering, no button conflicts -->
          <div class="pass-wrap">
            <i class="bi bi-lock-fill field-icon-left"></i>
            <input type="password" name="password" id="passField" class="form-control"
                   placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="pass-toggle" id="passToggle" tabindex="-1" title="Show/hide password">
              <i class="bi bi-eye" id="passEye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:12px;">
          <i class="bi bi-box-arrow-in-right"></i>
          Sign In
        </button>
      </form>

      <div style="text-align:center;margin-top:28px;font-size:13px;color:var(--text-muted);">
        Don't have an account?
        <a href="<?= BASE_URL ?>/register.php" style="color:var(--primary);font-weight:700;margin-left:4px;">
          Register as Student →
        </a>
      </div>

      <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--border);text-align:center;">
        <p style="font-size:11px;color:var(--text-faint);letter-spacing:.3px;">
          SECURED BY SMARTEXAM · BCRYPT AUTH · SESSION PROTECTION
        </p>
      </div>

    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
function selectRole(role) {
  document.getElementById('roleInput').value = role;
  document.querySelectorAll('.role-tab').forEach(t =>
    t.classList.toggle('active', t.dataset.role === role));
  const fl = document.getElementById('forgotLink');
  if (fl) fl.style.display = role === 'admin' ? 'none' : '';
}

// Fixed password toggle — uses id, not inline onclick
document.addEventListener('DOMContentLoaded', function() {
  var toggle = document.getElementById('passToggle');
  var field  = document.getElementById('passField');
  var eye    = document.getElementById('passEye');
  if (toggle && field && eye) {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      var isPass = field.type === 'password';
      field.type = isPass ? 'text' : 'password';
      eye.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }
});

selectRole('<?= $selRole ?>');
</script>
</body>
</html>