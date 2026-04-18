<?php
// includes/Auth.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

class Auth {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // Login — returns ['ok'=>bool, 'error'=>string]
    // ----------------------------------------------------------------
    public function login(string $email, string $password, string $role): array {
        $table = match($role) {
            'admin'   => 'admins',
            'teacher' => 'teachers',
            'student' => 'students',
            default   => null,
        };
        if (!$table) return ['ok' => false, 'error' => 'Invalid role.'];

        $idCol = match($role) {
            'admin'   => 'admin_id',
            'teacher' => 'teacher_id',
            'student' => 'student_id',
        };

        $stmt = $this->db->prepare(
            "SELECT {$idCol} AS uid, full_name, password_hash, is_active,
                    " . ($role !== 'admin' ? 'is_blocked' : '0 AS is_blocked') . "
             FROM {$table}
             WHERE email = :email
             LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user)
            return ['ok' => false, 'error' => 'No account found with that email.'];

        if (!$user['is_active'])
            return ['ok' => false, 'error' => 'Account is deactivated.'];

        if ($user['is_blocked'])
            return ['ok' => false, 'error' => 'Account is blocked. Contact admin.'];

        if (!password_verify($password, $user['password_hash']))
            return ['ok' => false, 'error' => 'Incorrect password.'];

        // Set session
        $_SESSION['user_id']   = $user['uid'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_type'] = $role;

        // Update last_login
        $this->db->prepare(
            "UPDATE {$table} SET last_login = NOW() WHERE {$idCol} = ?"
        )->execute([$user['uid']]);

        // Log action
        $this->logAction($role, $user['uid'], 'login');

        return ['ok' => true];
    }

    // ----------------------------------------------------------------
    // Register student
    // ----------------------------------------------------------------
    public function registerStudent(array $d): array {
        // Check duplicate email / roll
        $check = $this->db->prepare(
            "SELECT student_id FROM students WHERE email = ? OR roll_number = ? LIMIT 1"
        );
        $check->execute([$d['email'], $d['roll_number']]);
        if ($check->fetch())
            return ['ok' => false, 'error' => 'Email or Roll Number already exists.'];

        $hash = password_hash($d['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $this->db->prepare("
            INSERT INTO students
              (department_id, full_name, email, password_hash, roll_number, batch_year)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $d['department_id'],
            $d['full_name'],
            $d['email'],
            $hash,
            $d['roll_number'],
            $d['batch_year'],
        ]);
        return ['ok' => true, 'id' => $this->db->lastInsertId()];
    }

    // ----------------------------------------------------------------
    // Log actions
    // ----------------------------------------------------------------
    public function logAction(string $userType, int $userId, string $action, array $extra = []): void {
        $stmt = $this->db->prepare("
            INSERT INTO submission_logs
              (user_type, user_id, action, ip_address, user_agent, extra_data)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userType,
            $userId,
            $action,
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $extra ? json_encode($extra) : null,
        ]);
    }
}
