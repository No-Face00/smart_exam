<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';
if (isLoggedIn()) { header('Location: '.BASE_URL.'/index.php'); exit; }

$error = $success = ''; $step = 'form';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth();
    $email = post('email'); $type = post('user_type','student');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (!in_array($type,['student','teacher'])) $error = 'Invalid account type.';
    else {
        $r = $auth->createPasswordReset($email, $type);
        if ($r['ok']) {
            $resetUrl = BASE_URL . '/reset_password.php?token=' . $r['token'];
            // Show the link only in development; in production, email it instead
            $devNote = (defined('APP_ENV') && APP_ENV === 'production')
                ? 'A reset link has been sent to your email address.'
                : "Reset link generated for <strong>" . sanitize($email) . "</strong>.<br><small style='opacity:.8;'>Dev mode only — in production this would be emailed. <a href='{$resetUrl}' style='color:var(--success);font-weight:700;'>Click here to reset →</a></small>";
            $success = $devNote;
            $step = 'sent';
        } else $error = $r['error'];
    }
}
?>
<!DOCTYPE html><html lang="en" data-theme="light"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — SmartExam</title>
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
</head><body>
<div class="auth-page-centered">
  <div class="auth-center-card">
    <div style="text-align:center;margin-bottom:28px;">
      <div style="width:56px;height:56px;background:linear-gradient(135deg,var(--primary),var(--secondary));border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;color:#fff;">
        <i class="bi bi-key-fill"></i>
      </div>
      <h2 style="font-size:24px;font-weight:800;letter-spacing:-.4px;">Forgot Password?</h2>
      <p style="color:var(--text-muted);font-size:14px;margin-top:6px;">Enter your email to receive a password reset link</p>
    </div>

    <?php if ($error): ?>
    <div class="flash-alert flash-danger"><i class="bi bi-exclamation-triangle-fill"></i><span><?= sanitize($error) ?></span></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="flash-alert flash-success"><i class="bi bi-check-circle-fill"></i><span><?= $success ?></span></div>
    <div style="text-align:center;margin-top:20px;">
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Back to Login</a>
    </div>
    <?php else: ?>

    <div class="role-tabs" style="margin-bottom:20px;">
      <div class="role-tab active" data-type="student" onclick="selType('student')"><i class="bi bi-person-fill"></i> Student</div>
      <div class="role-tab" data-type="teacher" onclick="selType('teacher')"><i class="bi bi-person-badge-fill"></i> Teacher</div>
    </div>

    <form method="POST">
      <input type="hidden" name="user_type" id="userType" value="student">
      <div class="form-group">
        <label class="form-label">Registered Email Address</label>
        <div class="form-control-icon">
          <i class="bi bi-envelope-fill"></i>
          <input type="email" name="email" class="form-control" placeholder="you@university.edu"
                 value="<?= sanitize(post('email')) ?>" required autofocus>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-full btn-lg">
        <i class="bi bi-send-fill"></i> Send Reset Link
      </button>
    </form>

    <div style="text-align:center;margin-top:20px;font-size:13px;color:var(--text-muted);">
      Remember it? <a href="<?= BASE_URL ?>/index.php" style="color:var(--primary);font-weight:700;">Back to Login →</a>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
function selType(t){document.getElementById('userType').value=t;document.querySelectorAll('.role-tab').forEach(x=>x.classList.toggle('active',x.dataset.type===t));}
</script>
</body></html>
