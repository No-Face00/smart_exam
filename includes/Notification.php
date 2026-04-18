<?php
// includes/Notification.php

require_once __DIR__ . '/../config/database.php';

class Notification {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // Send a notification
    public function send(string $recipientType, int $recipientId, string $title, string $message, string $type = 'info'): void {
        $this->db->prepare("
            INSERT INTO notifications (recipient_type, recipient_id, title, message, type)
            VALUES (?,?,?,?,?)
        ")->execute([$recipientType, $recipientId, $title, $message, $type]);
    }

    // Broadcast to all of a role
    public function broadcast(string $recipientType, string $title, string $message, string $type = 'info'): int {
        $table  = match($recipientType) {
            'student' => 'students',
            'teacher' => 'teachers',
            'admin'   => 'admins',
            default   => null,
        };
        $idCol = match($recipientType) {
            'student' => 'student_id',
            'teacher' => 'teacher_id',
            'admin'   => 'admin_id',
        };
        if (!$table) return 0;

        $ids = $this->db->query("SELECT {$idCol} AS id FROM {$table} WHERE is_active = 1")->fetchAll();
        foreach ($ids as $row) {
            $this->send($recipientType, $row['id'], $title, $message, $type);
        }
        return count($ids);
    }

    // Get for a user (paginated)
    public function getForUser(string $type, int $id, int $limit = 30, bool $unreadOnly = false): array {
        $extra = $unreadOnly ? 'AND is_read = 0' : '';
        $stmt  = $this->db->prepare("
            SELECT * FROM notifications
            WHERE recipient_type = ? AND recipient_id = ? {$extra}
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$type, $id]);
        return $stmt->fetchAll();
    }

    // Unread count
    public function unreadCount(string $type, int $id): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM notifications WHERE recipient_type=? AND recipient_id=? AND is_read=0"
        );
        $stmt->execute([$type, $id]);
        return (int) $stmt->fetchColumn();
    }

    // Mark all read
    public function markAllRead(string $type, int $id): void {
        $this->db->prepare(
            "UPDATE notifications SET is_read=1 WHERE recipient_type=? AND recipient_id=?"
        )->execute([$type, $id]);
    }

    // Mark single read
    public function markRead(int $notifId): void {
        $this->db->prepare("UPDATE notifications SET is_read=1 WHERE notif_id=?")->execute([$notifId]);
    }

    // Delete old read notifications
    public function cleanup(int $daysOld = 30): int {
        $stmt = $this->db->prepare(
            "DELETE FROM notifications WHERE is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }
}
