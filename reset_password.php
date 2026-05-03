<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';
if (isLoggedIn()) { header('Location: '.BASE_URL.'/index.php'); exit; }
$token = sanitize($_GET['token'] ?? '');
if (!$token) { header('Location: '.BASE_URL.'/index.php'); exit; }
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth();
    $np = post('password'); $cp = post('confirm_password');
    if ($np !== $cp) $error = 'Passwords do not match.';
    else { $r = $auth->resetPassword($token, $np); $r['ok'] ? $success = 'Password reset successfully!' : $error = $r['error']; }
}
?>
<!DOCTYPE html><html lang="en" data-theme="light"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — SmartExam</title>
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
      <div style="width:56px;height:56px;background:linear-gradient(135deg,var(--success),#34D399);border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;color:#fff;">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <h2 style="font-size:24px;font-weight:800;letter-spacing:-.4px;">Set New Password</h2>
      <p style="color:var(--text-muted);font-size:14px;margin-top:6px;">Choose a strong password for your account</p>
    </div>

    <?php if ($error): ?>
    <div class="flash-alert flash-danger"><i class="bi bi-exclamation-triangle-fill"></i><span><?= sanitize($error) ?></span></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="flash-alert flash-success"><i class="bi bi-check-circle-fill"></i><span><?= sanitize($success) ?></span></div>
    <div style="text-align:center;margin-top:20px;">
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Sign In Now</a>
    </div>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="token" value="<?= sanitize($token) ?>">
      <div class="form-group">
        <label class="form-label">New Password</label>
        <div class="form-control-icon">
          <i class="bi bi-lock-fill"></i>
          <input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required autofocus>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <div class="form-control-icon">
          <i class="bi bi-lock-fill"></i>
          <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-full btn-lg"><i class="bi bi-check-lg"></i> Reset Password</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body></html>
