<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Notification.php';

requireLogin('admin');

$db     = Database::getConnection();
$notif  = new Notification();
$admin  = currentUser();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['send_notif'])) {
        $title   = trim(post('title'));
        $message = trim(post('message'));
        $type    = in_array(post('type'), ['info','warning','success','danger']) ? post('type') : 'info';
        $target  = post('target');  // 'all_students', 'all_teachers', 'all', or specific ID

        if (!$title || !$message) {
            setFlash('danger', 'Title and message are required.');
        } else {
            $sent = 0;
            if ($target === 'all_students') {
                $sent = $notif->broadcast('student', $title, $message, $type);
            } elseif ($target === 'all_teachers') {
                $sent = $notif->broadcast('teacher', $title, $message, $type);
            } elseif ($target === 'all') {
                $sent += $notif->broadcast('student', $title, $message, $type);
                $sent += $notif->broadcast('teacher', $title, $message, $type);
            } elseif (str_starts_with($target, 'student_')) {
                $sid = (int)substr($target, 8);
                $notif->send('student', $sid, $title, $message, $type);
                $sent = 1;
            } elseif (str_starts_with($target, 'teacher_')) {
                $tid = (int)substr($target, 8);
                $notif->send('teacher', $tid, $title, $message, $type);
                $sent = 1;
            }
            setFlash('success', "Notification sent to {$sent} recipient(s).");
        }
        header('Location: notifications.php'); exit;
    }

    if (isset($_POST['cleanup'])) {
        $deleted = $notif->cleanup(30);
        setFlash('success', "Cleaned up {$deleted} old notifications.");
        header('Location: notifications.php'); exit;
    }
}

// Recent notifications sent (latest 50)
$recent = $db->query("
    SELECT n.*,
      CASE n.recipient_type
        WHEN 'student' THEN (SELECT full_name FROM students WHERE student_id = n.recipient_id)
        WHEN 'teacher' THEN (SELECT full_name FROM teachers WHERE teacher_id = n.recipient_id)
        WHEN 'admin'   THEN (SELECT full_name FROM admins   WHERE admin_id   = n.recipient_id)
      END AS recipient_name
    FROM notifications n
    ORDER BY n.created_at DESC
    LIMIT 50
")->fetchAll();

// Stats
$notifStats = $db->query("
    SELECT
      COUNT(*)              AS total,
      SUM(is_read = 0)      AS unread,
      SUM(type = 'danger')  AS danger_cnt,
      SUM(type = 'warning') AS warning_cnt
    FROM notifications
")->fetch();

// Students + teachers for targeted send
$students = $db->query("SELECT student_id, full_name FROM students WHERE is_active=1 ORDER BY full_name")->fetchAll();
$teachers = $db->query("SELECT teacher_id, full_name FROM teachers WHERE is_active=1 ORDER BY full_name")->fetchAll();

$flash = getFlash();
renderHead('Notifications');
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('admin','Notifications'); ?>

<div class="main-content">
<?php renderTopbar('Notification Center'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <?= statCard('Total Sent',   $notifStats['total'],       'bell-fill',       'blue')   ?>
  <?= statCard('Unread',       $notifStats['unread'],      'bell',            'amber')  ?>
  <?= statCard('Alerts',       $notifStats['danger_cnt'],  'exclamation-octagon', 'red') ?>
  <?= statCard('Warnings',     $notifStats['warning_cnt'], 'exclamation-triangle', 'purple') ?>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">

  <!-- Notification History -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-bell-fill" style="color:var(--brand)"></i> Recent Notifications</h3>
      <form method="POST" style="display:inline;">
        <button type="submit" name="cleanup" class="btn-ghost btn-sm"
                onclick="return confirm('Delete all read notifications older than 30 days?')">
          <i class="bi bi-trash"></i> Cleanup
        </button>
      </form>
    </div>
    <div style="max-height:600px;overflow-y:auto;">
      <?php foreach ($recent as $n): ?>
      <?php
        $typeConfig = [
          'info'    => ['bi-info-circle-fill',    '#2563EB', '#EFF6FF'],
          'success' => ['bi-check-circle-fill',   '#10B981', '#ECFDF5'],
          'warning' => ['bi-exclamation-triangle-fill', '#F59E0B', '#FFFBEB'],
          'danger'  => ['bi-exclamation-octagon-fill',  '#EF4444', '#FEF2F2'],
        ];
        [$icon, $color, $bg] = $typeConfig[$n['type']] ?? $typeConfig['info'];
      ?>
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);
                  <?= !$n['is_read'] ? 'background:var(--bg);' : '' ?>
                  display:flex;align-items:flex-start;gap:12px;">
        <div style="width:36px;height:36px;border-radius:10px;background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:16px;"></i>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:14px;margin-bottom:2px;"><?= sanitize($n['title']) ?></div>
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:6px;line-height:1.5;">
            <?= sanitize($n['message']) ?>
          </div>
          <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-muted);">
            <span><i class="bi bi-person"></i> <?= sanitize($n['recipient_name'] ?? 'Unknown') ?></span>
            <span><i class="bi bi-tag"></i> <?= ucfirst($n['recipient_type']) ?></span>
            <span><i class="bi bi-clock"></i> <?= date('M j, g:i a', strtotime($n['created_at'])) ?></span>
            <?php if (!$n['is_read']): ?>
            <span style="background:#EFF6FF;color:var(--brand);padding:2px 7px;border-radius:4px;font-weight:600;">New</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$recent): ?>
      <div style="text-align:center;padding:48px;color:var(--text-muted);">
        <i class="bi bi-bell-slash" style="font-size:36px;display:block;margin-bottom:8px;"></i>
        No notifications sent yet.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Send Form -->
  <div style="position:sticky;top:80px;">
    <div class="card">
      <div class="card-header">
        <h3><i class="bi bi-send-fill" style="color:var(--success)"></i> Send Notification</h3>
      </div>
      <div class="card-body">
        <form method="POST">

          <div class="form-group">
            <label class="form-label">Title *</label>
            <input type="text" name="title" class="form-control" placeholder="Notification title" required>
          </div>

          <div class="form-group">
            <label class="form-label">Message *</label>
            <textarea name="message" class="form-control" rows="4" placeholder="Enter your message…" required></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Type</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <?php foreach (['info'=>['bi-info-circle','#2563EB'],'success'=>['bi-check-circle','#10B981'],'warning'=>['bi-exclamation-triangle','#F59E0B'],'danger'=>['bi-exclamation-octagon','#EF4444']] as $t => [$ico, $col]): ?>
              <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1.5px solid var(--border);
                            border-radius:var(--radius-sm);cursor:pointer;font-size:13px;font-weight:500;
                            transition:border-color .2s;" class="type-option">
                <input type="radio" name="type" value="<?= $t ?>" style="display:none;" <?= $t==='info'?'checked':'' ?>>
                <i class="bi <?= $ico ?>" style="color:<?= $col ?>;font-size:16px;"></i>
                <?= ucfirst($t) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Send To *</label>
            <select name="target" class="form-control" required>
              <optgroup label="Broadcast">
                <option value="all">Everyone (Students + Teachers)</option>
                <option value="all_students">All Students</option>
                <option value="all_teachers">All Teachers</option>
              </optgroup>
              <optgroup label="Individual Student">
                <?php foreach ($students as $s): ?>
                <option value="student_<?= $s['student_id'] ?>">↳ <?= sanitize($s['full_name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Individual Teacher">
                <?php foreach ($teachers as $t): ?>
                <option value="teacher_<?= $t['teacher_id'] ?>">↳ <?= sanitize($t['full_name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>

          <button type="submit" name="send_notif" class="btn-primary" style="width:100%;justify-content:center;padding:12px;">
            <i class="bi bi-send-fill"></i> Send Notification
          </button>
        </form>
      </div>
    </div>

    <!-- Quick Templates -->
    <div class="card" style="margin-top:16px;">
      <div class="card-header">
        <h3><i class="bi bi-lightning-fill" style="color:var(--amber)"></i> Quick Templates</h3>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
        <?php
        $templates = [
          ['Exam Reminder', 'You have an upcoming exam scheduled. Please check your exam portal for details.', 'info'],
          ['Results Published', 'Your exam results are now available. Login to view your score and detailed feedback.', 'success'],
          ['System Maintenance', 'SmartExam will undergo scheduled maintenance tonight. Please complete any in-progress work.', 'warning'],
          ['Cheating Warning', 'Your account has been flagged for suspicious activity. Further violations may result in a ban.', 'danger'],
        ];
        foreach ($templates as [$t, $m, $ty]):
        ?>
        <button onclick="fillTemplate(<?= json_encode($t) ?>, <?= json_encode($m) ?>, <?= json_encode($ty) ?>)"
                class="btn-ghost" style="text-align:left;padding:10px 14px;font-size:13px;">
          <i class="bi bi-lightning" style="color:var(--brand);"></i> <?= $t ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
</div>
</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
// Type radio visual feedback
document.querySelectorAll('.type-option').forEach(label => {
  label.addEventListener('click', () => {
    document.querySelectorAll('.type-option').forEach(l => l.style.borderColor = 'var(--border)');
    label.style.borderColor = 'var(--brand)';
    label.style.background  = 'var(--brand-light)';
  });
});

function fillTemplate(title, message, type) {
  document.querySelector('[name="title"]').value   = title;
  document.querySelector('[name="message"]').value = message;
  const radio = document.querySelector(`[name="type"][value="${type}"]`);
  if (radio) {
    radio.checked = true;
    radio.closest('.type-option').click();
  }
}
</script>
