<?php
// includes/layout.php — Shared layout components for SmartExam

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

// ─── renderHead ────────────────────────────────────────────
function renderHead(string $title = 'SmartExam'): void {
?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — SmartExam</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<script>window.BASE_URL = '<?= BASE_URL ?>';</script>
</head>
<body>
<?php
}

// ─── renderSidebar ──────────────────────────────────────────
function renderSidebar(string $role, string $active = ''): void {
    $user = currentUser();
    $initials = strtoupper(substr($user['name'], 0, 1));
    if (strpos($user['name'], ' ') !== false) {
        $parts = explode(' ', $user['name']);
        $initials = strtoupper($parts[0][0] . end($parts)[0]);
    }

    $navs = match($role) {
        'student' => [
            ['icon'=>'bi-speedometer2',  'label'=>'Dashboard',    'href'=>'dashboard.php'],
            ['icon'=>'bi-journal-text',  'label'=>'My Exams',     'href'=>'exams.php'],
            ['icon'=>'bi-trophy-fill',   'label'=>'My Results',   'href'=>'results.php'],
            ['icon'=>'bi-star-fill',     'label'=>'Leaderboard',  'href'=>'leaderboard.php'],
            ['icon'=>'bi-bell-fill',     'label'=>'Notifications','href'=>'notifications.php'],
        ],
        'teacher' => [
            ['icon'=>'bi-speedometer2',  'label'=>'Dashboard',    'href'=>'dashboard.php'],
            ['icon'=>'bi-journal-plus',  'label'=>'My Exams',     'href'=>'exams.php'],
            ['icon'=>'bi-patch-question','label'=>'Questions',    'href'=>'questions.php'],
            ['icon'=>'bi-bar-chart-fill','label'=>'Results',      'href'=>'results.php'],
            ['icon'=>'bi-shield-exclamation','label'=>'Cheating', 'href'=>'cheating.php'],
        ],
        'admin' => [
            ['icon'=>'bi-speedometer2',  'label'=>'Dashboard',    'href'=>'dashboard.php'],
            ['icon'=>'bi-people-fill',   'label'=>'Students',     'href'=>'students.php'],
            ['icon'=>'bi-person-badge',  'label'=>'Teachers',     'href'=>'teachers.php'],
            ['icon'=>'bi-journal-text',  'label'=>'All Exams',    'href'=>'exams.php'],
            ['icon'=>'bi-shield-exclamation','label'=>'Cheating', 'href'=>'cheating.php'],
            ['icon'=>'bi-graph-up',      'label'=>'Analytics',    'href'=>'analytics.php'],
            ['icon'=>'bi-bell-fill',     'label'=>'Notifications','href'=>'notifications.php'],
            ['icon'=>'bi-clock-history', 'label'=>'Audit Logs',   'href'=>'logs.php'],
        ],
        default => [],
    };
?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
    <span class="brand-name">SmartExam</span>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar"><?= $initials ?></div>
    <div style="overflow:hidden;">
      <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
      <div class="user-role"><?= ucfirst($role) ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navs as $nav): ?>
    <a href="<?= BASE_URL ?>/<?= $role ?>/<?= $nav['href'] ?>"
       class="nav-link <?= ($active === $nav['label']) ? 'active' : '' ?>">
      <i class="bi <?= $nav['icon'] ?>"></i>
      <?= $nav['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= BASE_URL ?>/logout.php" class="nav-link" onclick="return confirm('Sign out?')">
      <i class="bi bi-box-arrow-left"></i> Logout
    </a>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
      <i class="bi bi-moon-stars-fill"></i>
    </button>
  </div>
</aside>
<?php
}

// ─── renderTopbar ──────────────────────────────────────────
function renderTopbar(string $title, bool $showNotif = true): void {
    $user = currentUser();
    $role = $user['type'];
    $unread = 0;
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_type=? AND recipient_id=? AND is_read=0");
        $stmt->execute([$role, $user['id']]);
        $unread = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* ignore */ }
?>
<header class="topbar">
  <button class="topbar-toggle" onclick="toggleSidebar()">
    <i class="bi bi-list"></i>
  </button>
  <div class="topbar-title"><?= htmlspecialchars($title) ?></div>
  <div class="topbar-right">
    <?php if ($showNotif): ?>
    <a href="<?= BASE_URL ?>/<?= $role ?>/notifications.php" class="notif-bell" title="Notifications">
      <i class="bi bi-bell-fill"></i>
      <?php if ($unread > 0): ?>
      <span class="notif-dot"></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
    <div style="font-size:13px;font-weight:600;color:var(--text-muted);">
      <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>
    </div>
  </div>
</header>
<?php
}

// ─── renderFooter ──────────────────────────────────────────
function renderFooter(): void {
?>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
<?php
}

// ─── statCard helper ───────────────────────────────────────
function statCard(string $label, mixed $value, string $icon, string $color = 'blue'): string {
    return '<div class="stat-card stat-'.$color.'">
      <div class="stat-icon"><i class="bi bi-'.$icon.'"></i></div>
      <div>
        <div class="stat-value">'.htmlspecialchars((string)$value).'</div>
        <div class="stat-label">'.htmlspecialchars($label).'</div>
      </div>
    </div>';
}
