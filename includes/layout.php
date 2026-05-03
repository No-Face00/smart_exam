<?php
// includes/layout.php — SmartExam v3 Layout System
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

function renderHead(string $title = 'SmartExam'): void {
    // Calculate relative path depth for asset fallback
    $depth = substr_count($_SERVER['PHP_SELF'] ?? '/', '/') - 1;
    $rel   = str_repeat('../', max(0, $depth - 1));
?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — SmartExam</title>
<!-- Fonts: try Google Fonts first, fall back to system stack via CSS -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<!-- Main CSS: absolute URL first, relative fallback via JS -->
<link id="mainCss" rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<script>
  // If absolute CSS path fails (wrong BASE_URL), load relative path
  window.BASE_URL = '<?= addslashes(BASE_URL) ?>';
  document.getElementById('mainCss').onerror = function() {
    var rel = document.createElement('link');
    rel.rel = 'stylesheet';
    rel.href = '<?= $rel ?>assets/css/main.css';
    document.head.appendChild(rel);
  };
</script>
</head>
<body>
<?php
}

function renderSidebar(string $role, string $active = ''): void {
    $user   = currentUser();
    $parts  = preg_split('/\s+/', trim($user['name']));
    $initials = strtoupper(substr($parts[0],0,1) . (count($parts)>1 ? substr(end($parts),0,1) : ''));

    $navs = match($role) {
        'student' => [
            ['icon'=>'bi-speedometer2',   'label'=>'Dashboard',       'href'=>'dashboard.php'],
            ['icon'=>'bi-journal-text',   'label'=>'My Exams',        'href'=>'exams.php'],
            ['icon'=>'bi-trophy-fill',    'label'=>'My Results',      'href'=>'results.php'],
            ['icon'=>'bi-bar-chart-line', 'label'=>'Leaderboard',     'href'=>'leaderboard.php'],
            ['icon'=>'bi-arrow-repeat',   'label'=>'Update Semester', 'href'=>'update_semester.php'],
            ['icon'=>'bi-bell-fill',      'label'=>'Notifications',   'href'=>'notifications.php'],
        ],
        'teacher' => [
            ['icon'=>'bi-speedometer2',      'label'=>'Dashboard', 'href'=>'dashboard.php'],
            ['icon'=>'bi-journal-plus',      'label'=>'My Exams',  'href'=>'exams.php'],
            ['icon'=>'bi-patch-question',    'label'=>'Questions', 'href'=>'questions.php'],
            ['icon'=>'bi-bar-chart-fill',    'label'=>'Results',   'href'=>'results.php'],
            ['icon'=>'bi-shield-exclamation','label'=>'Cheating',  'href'=>'cheating.php'],
        ],
        'admin' => [
            ['icon'=>'bi-speedometer2',      'label'=>'Dashboard',    'href'=>'dashboard.php'],
            ['icon'=>'bi-people-fill',       'label'=>'Students',     'href'=>'students.php'],
            ['icon'=>'bi-person-badge-fill', 'label'=>'Teachers',     'href'=>'teachers.php'],
            ['icon'=>'bi-journal-text',      'label'=>'All Exams',    'href'=>'exams.php'],
            ['icon'=>'bi-shield-exclamation','label'=>'Cheating',     'href'=>'cheating.php'],
            ['icon'=>'bi-graph-up',          'label'=>'Analytics',    'href'=>'analytics.php'],
            ['icon'=>'bi-bell-fill',         'label'=>'Notifications','href'=>'notifications.php'],
            ['icon'=>'bi-clock-history',     'label'=>'Audit Logs',   'href'=>'logs.php'],
        ],
        default => [],
    };
?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
    <div>
      <div class="brand-name">SmartExam</div>
      <div class="brand-version">v3.0</div>
    </div>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar"><?= $initials ?></div>
    <div style="overflow:hidden;min-width:0;">
      <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
      <div class="user-role"><?= ucfirst($role) ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navs as $nav): ?>
    <a href="<?= BASE_URL ?>/<?= $role ?>/<?= $nav['href'] ?>"
       class="nav-link <?= ($active===$nav['label'])?'active':'' ?>">
      <i class="bi <?= $nav['icon'] ?>"></i>
      <span><?= $nav['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= BASE_URL ?>/logout.php" class="nav-link" onclick="return confirm('Sign out?')">
      <i class="bi bi-box-arrow-left"></i><span>Logout</span>
    </a>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
      <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
    </button>
  </div>
</aside>
<?php
}

function renderTopbar(string $title, bool $showNotif = true): void {
    $user  = currentUser();
    $role  = $user['type'];
    $parts = preg_split('/\s+/', trim($user['name']));
    $initials = strtoupper(substr($parts[0],0,1) . (count($parts)>1 ? substr(end($parts),0,1) : ''));
    $unread = 0;
    try {
        $db   = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_type=? AND recipient_id=? AND is_read=0");
        $stmt->execute([$role, $user['id']]);
        $unread = (int)$stmt->fetchColumn();
    } catch(Exception $e) {}
?>
<header class="topbar">
  <button class="topbar-toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
  <div class="topbar-title"><?= htmlspecialchars($title) ?></div>
  <div class="topbar-right">
    <?php if ($showNotif): ?>
    <a href="<?= BASE_URL ?>/<?= $role ?>/notifications.php" class="topbar-btn" title="Notifications">
      <i class="bi bi-bell-fill"></i><?php if($unread>0): ?><span class="notif-dot"></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/<?= $role ?>/dashboard.php" class="topbar-avatar" title="<?= htmlspecialchars($user['name']) ?>"><?= $initials ?></a>
  </div>
</header>
<?php
}

function renderFooter(): void { ?>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body></html>
<?php
}

function statCard(string $label, mixed $value, string $icon, string $color = 'blue'): string {
    $v = htmlspecialchars((string)$value);
    $l = htmlspecialchars($label);
    return "<div class=\"stat-card stat-{$color} fade-in\"><div class=\"stat-icon\"><i class=\"bi bi-{$icon}\"></i></div><div class=\"stat-value\">{$v}</div><div class=\"stat-label\">{$l}</div></div>";
}
