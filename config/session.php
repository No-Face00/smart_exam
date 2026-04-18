<?php
// config/session.php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,   // set true on HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ----------------------------------------------------------------
// Auth helpers
// ----------------------------------------------------------------

function isLoggedIn(): bool {
    return isset($_SESSION['user_id'], $_SESSION['user_type']);
}

function requireLogin(string $role = ''): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    if ($role && $_SESSION['user_type'] !== $role) {
        header('Location: ' . BASE_URL . '/index.php?error=unauthorized');
        exit;
    }
}

function currentUser(): array {
    return [
        'id'   => $_SESSION['user_id']   ?? null,
        'name' => $_SESSION['user_name'] ?? '',
        'type' => $_SESSION['user_type'] ?? '',
    ];
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// ----------------------------------------------------------------
// Input helpers
// ----------------------------------------------------------------

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function post(string $key, mixed $default = ''): mixed {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function get(string $key, mixed $default = ''): mixed {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

function getClientIP(): string {
    foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            if (filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                return trim($ip);
            }
        }
    }
    return '0.0.0.0';
}

// ----------------------------------------------------------------
// Flash messages
// ----------------------------------------------------------------

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
