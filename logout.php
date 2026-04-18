<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/Auth.php';

if (isLoggedIn()) {
    $u = currentUser();
    (new Auth())->logAction($u['type'], $u['id'], 'logout');
}
logout();
