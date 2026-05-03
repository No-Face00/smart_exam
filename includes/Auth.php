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
    // Login
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

        $_SESSION['user_id']   = $user['uid'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_type'] = $role;

        $this->db->prepare("UPDATE {$table} SET last_login = NOW() WHERE {$idCol} = ?")
                 ->execute([$user['uid']]);
        $this->logAction($role, $user['uid'], 'login');

        return ['ok' => true];
    }

    // ----------------------------------------------------------------
    // Register student (semester + section based)
    // ----------------------------------------------------------------
    public function registerStudent(array $d): array {
        $check = $this->db->prepare(
            "SELECT student_id FROM students WHERE email = ? OR student_id_no = ? LIMIT 1"
        );
        $check->execute([$d['email'], $d['student_id_no']]);
        if ($check->fetch())
            return ['ok' => false, 'error' => 'Email or Student ID already exists.'];

        // Verify section belongs to selected department
        $secCheck = $this->db->prepare(
            "SELECT section_id FROM sections WHERE section_id = ? AND department_id = ? LIMIT 1"
        );
        $secCheck->execute([$d['section_id'], $d['department_id']]);
        if (!$secCheck->fetch())
            return ['ok' => false, 'error' => 'Invalid section for selected department.'];

        $hash = password_hash($d['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $this->db->prepare("
            INSERT INTO students
              (department_id, semester_id, section_id, full_name, email, password_hash, student_id_no)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $d['department_id'],
            $d['semester_id'],
            $d['section_id'],
            $d['full_name'],
            $d['email'],
            $hash,
            $d['student_id_no'],
        ]);
        return ['ok' => true, 'id' => $this->db->lastInsertId()];
    }

    // ----------------------------------------------------------------
    // Update student semester/section
    // ----------------------------------------------------------------
    public function updateStudentSemesterSection(int $studentId, int $semesterId, int $sectionId): array {
        // Verify section belongs to student's department
        $student = $this->db->prepare("SELECT department_id FROM students WHERE student_id = ?");
        $student->execute([$studentId]);
        $s = $student->fetch();
        if (!$s) return ['ok' => false, 'error' => 'Student not found.'];

        $secCheck = $this->db->prepare(
            "SELECT section_id FROM sections WHERE section_id = ? AND department_id = ? LIMIT 1"
        );
        $secCheck->execute([$sectionId, $s['department_id']]);
        if (!$secCheck->fetch())
            return ['ok' => false, 'error' => 'Invalid section for your department.'];

        $this->db->prepare(
            "UPDATE students SET semester_id = ?, section_id = ?, last_updated_at = NOW() WHERE student_id = ?"
        )->execute([$semesterId, $sectionId, $studentId]);

        $this->logAction('student', $studentId, 'semester_update', [
            'semester_id' => $semesterId,
            'section_id'  => $sectionId,
        ]);

        return ['ok' => true];
    }

    // ----------------------------------------------------------------
    // Check if student needs semester update (older than ~4 months)
    // ----------------------------------------------------------------
    public function studentNeedsUpdate(int $studentId): bool {
        $stmt = $this->db->prepare(
            "SELECT last_updated_at FROM students WHERE student_id = ?"
        );
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if (!$row) return false;

        $lastUpdate = strtotime($row['last_updated_at']);
        $fourMonths = 60 * 60 * 24 * 120; // 120 days
        return (time() - $lastUpdate) >= $fourMonths;
    }

    // ----------------------------------------------------------------
    // Forgot password — generate reset token
    // ----------------------------------------------------------------
    public function createPasswordReset(string $email, string $userType): array {
        $table  = ($userType === 'teacher') ? 'teachers' : 'students';
        $idCol  = ($userType === 'teacher') ? 'teacher_id' : 'student_id';

        $stmt = $this->db->prepare("SELECT {$idCol} AS uid FROM {$table} WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user)
            return ['ok' => false, 'error' => 'No active account found with that email.'];

        // Invalidate old tokens
        $this->db->prepare(
            "DELETE FROM password_resets WHERE email = ? AND user_type = ?"
        )->execute([$email, $userType]);

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->db->prepare("
            INSERT INTO password_resets (user_type, user_id, email, token, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$userType, $user['uid'], $email, $token, $expires]);

        return ['ok' => true, 'token' => $token, 'email' => $email];
    }

    // ----------------------------------------------------------------
    // Reset password using token
    // ----------------------------------------------------------------
    public function resetPassword(string $token, string $newPassword): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM password_resets
             WHERE token = ? AND used = 0 AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset)
            return ['ok' => false, 'error' => 'Invalid or expired reset link.'];

        $table = ($reset['user_type'] === 'teacher') ? 'teachers' : 'students';
        $idCol = ($reset['user_type'] === 'teacher') ? 'teacher_id' : 'student_id';

        if (strlen($newPassword) < 6)
            return ['ok' => false, 'error' => 'Password must be at least 6 characters.'];

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->prepare("UPDATE {$table} SET password_hash = ? WHERE {$idCol} = ?")
                 ->execute([$hash, $reset['user_id']]);

        $this->db->prepare("UPDATE password_resets SET used = 1 WHERE reset_id = ?")
                 ->execute([$reset['reset_id']]);

        $this->logAction($reset['user_type'], $reset['user_id'], 'password_reset');
        return ['ok' => true];
    }

    // ----------------------------------------------------------------
    // Change password (authenticated)
    // ----------------------------------------------------------------
    public function changePassword(string $role, int $userId, string $oldPass, string $newPass): array {
        $table = match($role) {
            'admin'   => 'admins',
            'teacher' => 'teachers',
            'student' => 'students',
        };
        $idCol = match($role) {
            'admin'   => 'admin_id',
            'teacher' => 'teacher_id',
            'student' => 'student_id',
        };

        $stmt = $this->db->prepare("SELECT password_hash FROM {$table} WHERE {$idCol} = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($oldPass, $row['password_hash']))
            return ['ok' => false, 'error' => 'Current password is incorrect.'];
        if (strlen($newPass) < 6)
            return ['ok' => false, 'error' => 'New password must be at least 6 characters.'];

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->prepare("UPDATE {$table} SET password_hash = ? WHERE {$idCol} = ?")
                 ->execute([$hash, $userId]);

        $this->logAction($role, $userId, 'password_change');
        return ['ok' => true];
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
