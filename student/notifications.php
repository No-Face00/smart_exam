<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Notification.php';

requireLogin('student');

$notif = new Notification();
$user  = currentUser();

// Mark all read on visit
$notif->markAllRead('student', $user['id']);

$notifications = $notif->getForUser('student', $user['id'], 50);

renderHead('Notifications');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('student','Notifications'); ?>
<div class="main-content">
<?php renderTopbar('My Notifications'); ?>

<div style="max-width:700px;">
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-bell-fill" style="color:var(--brand)"></i> Notifications</h3>
      <span style="font-size:13px;color:var(--text-muted);"><?= count($notifications) ?> total</span>
    </div>
    <?php
    $typeConfig = [
      'info'    => ['bi-info-circle-fill',    '#2563EB', '#EFF6FF'],
      'success' => ['bi-check-circle-fill',   '#10B981', '#ECFDF5'],
      'warning' => ['bi-exclamation-triangle-fill', '#F59E0B', '#FFFBEB'],
      'danger'  => ['bi-exclamation-octagon-fill',  '#EF4444', '#FEF2F2'],
    ];
    foreach ($notifications as $n):
      [$icon, $color, $bg] = $typeConfig[$n['type']] ?? $typeConfig['info'];
    ?>
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:14px;">
      <div style="width:40px;height:40px;border-radius:12px;background:<?= $bg ?>;
                  display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:18px;"></i>
      </div>
      <div style="flex:1;">
        <div style="font-weight:700;margin-bottom:4px;"><?= sanitize($n['title']) ?></div>
        <div style="font-size:14px;color:var(--text-muted);line-height:1.6;"><?= sanitize($n['message']) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
          <i class="bi bi-clock"></i> <?= date('M j, Y g:i a', strtotime($n['created_at'])) ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$notifications): ?>
    <div style="text-align:center;padding:48px;color:var(--text-muted);">
      <i class="bi bi-bell-slash" style="font-size:40px;display:block;margin-bottom:12px;"></i>
      No notifications yet.
    </div>
    <?php endif; ?>
  </div>
</div>

</div>
</div>
<?php renderFooter(); ?>
