<?php
// includes/CheatingEngine.php — Advanced cheating analysis engine

require_once __DIR__ . '/../config/database.php';

class CheatingEngine {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── Run the SQL stored procedure detection ─────────────────────
    public function runDetection(int $examId): array {
        $before = $this->countFlags($examId);
        try {
            $this->db->exec("CALL sp_detect_cheating({$examId})");
        } catch (\PDOException $e) {
            error_log('CheatingEngine::runDetection error: ' . $e->getMessage());
        }
        $after = $this->countFlags($examId);
        return ['flags_before' => $before, 'flags_after' => $after, 'new_flags' => max(0, $after - $before)];
    }

    // ── Clear all flags then re-run ────────────────────────────────
    public function rerunDetection(int $examId): array {
        $this->db->prepare("DELETE FROM cheating_flags WHERE exam_id = ?")->execute([$examId]);
        return $this->runDetection($examId);
    }

    private function countFlags(int $examId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM cheating_flags WHERE exam_id = ?");
        $stmt->execute([$examId]);
        return (int)$stmt->fetchColumn();
    }

    // ── Exam summary stats ─────────────────────────────────────────
    public function examSummary(int $examId): array {
        $stmt = $this->db->prepare("
            SELECT
              COUNT(*)                                                AS total_flags,
              COUNT(DISTINCT student_id)                             AS flagged_students,
              SUM(risk_level = 'high')                               AS high_flags,
              SUM(risk_level = 'medium')                             AS medium_flags,
              SUM(risk_level = 'low')                                AS low_flags,
              SUM(action_taken = 'none')                             AS pending,
              MAX(risk_score)                                        AS peak_score
            FROM cheating_flags WHERE exam_id = ?
        ");
        $stmt->execute([$examId]);
        return $stmt->fetch() ?: [];
    }

    // ── Get flags for an exam (with student info + exam score) ─────
    public function flagsForExam(int $examId, string $risk = '', string $type = ''): array {
        $where  = ['cf.exam_id = ?'];
        $params = [$examId];
        if ($risk) { $where[] = 'cf.risk_level = ?'; $params[] = $risk; }
        if ($type) { $where[] = 'cf.flag_type = ?';  $params[] = $type; }
        $sql = "
            SELECT cf.*, s.full_name, s.student_id_no,
                   ea.score, ea.time_taken_secs
            FROM cheating_flags cf
            JOIN students     s  ON s.student_id  = cf.student_id
            JOIN exam_attempts ea ON ea.exam_id   = cf.exam_id AND ea.student_id = cf.student_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(cf.risk_level,'high','medium','low'), cf.risk_score DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Risk roster (per student aggregated) ──────────────────────
    public function riskRoster(int $examId): array {
        $stmt = $this->db->prepare("
            SELECT cf.student_id, s.full_name, s.student_id_no,
                   COUNT(*)                            AS total_flags,
                   SUM(cf.risk_level='high')           AS high_flags,
                   SUM(cf.risk_level='medium')         AS medium_flags,
                   SUM(cf.risk_level='low')            AS low_flags,
                   COUNT(DISTINCT cf.flag_type)        AS distinct_flag_types,
                   MAX(cf.risk_score)                  AS peak_risk_score,
                   CASE
                     WHEN SUM(cf.risk_level='high') >= 2 THEN 'CRITICAL'
                     WHEN SUM(cf.risk_level='high') >= 1 THEN 'HIGH'
                     WHEN SUM(cf.risk_level='medium') >= 2 THEN 'MEDIUM'
                     ELSE 'LOW'
                   END AS overall_risk
            FROM cheating_flags cf
            JOIN students s ON s.student_id = cf.student_id
            WHERE cf.exam_id = ? AND cf.action_taken = 'none'
            GROUP BY cf.student_id
            ORDER BY high_flags DESC, peak_risk_score DESC
        ");
        $stmt->execute([$examId]);
        return $stmt->fetchAll();
    }

    // ── IP network groups ─────────────────────────────────────────
    public function ipNetwork(int $examId): array {
        $stmt = $this->db->prepare("
            SELECT ea.ip_address,
                   COUNT(DISTINCT ea.student_id)          AS student_count,
                   GROUP_CONCAT(DISTINCT s.full_name ORDER BY s.full_name SEPARATOR ', ') AS names,
                   GROUP_CONCAT(DISTINCT s.student_id_no ORDER BY s.student_id_no SEPARATOR ', ') AS student_ids,
                   MIN(ea.submit_time)                    AS first_submit,
                   MAX(ea.submit_time)                    AS last_submit,
                   TIMESTAMPDIFF(SECOND, MIN(ea.submit_time), MAX(ea.submit_time)) AS span_secs
            FROM exam_attempts ea
            JOIN students s ON s.student_id = ea.student_id
            WHERE ea.exam_id = ? AND ea.status = 'submitted'
            GROUP BY ea.ip_address
            ORDER BY student_count DESC, ea.ip_address
        ");
        $stmt->execute([$examId]);
        return $stmt->fetchAll();
    }

    // ── Submission timeline ───────────────────────────────────────
    public function submissionTimeline(int $examId): array {
        $stmt = $this->db->prepare("
            SELECT ea.student_id, ea.submit_time, ea.score, ea.time_taken_secs,
                   ea.ip_address, s.full_name, s.student_id_no,
                   TIMESTAMPDIFF(SECOND,
                     (SELECT MIN(ea2.submit_time) FROM exam_attempts ea2
                      WHERE ea2.exam_id = ? AND ea2.status = 'submitted' AND ea2.submit_time IS NOT NULL),
                     ea.submit_time
                   ) AS secs_after_first,
                   (SELECT COUNT(*) FROM exam_attempts ea3
                    WHERE ea3.exam_id = ? AND ea3.status = 'submitted'
                      AND ea3.submit_time IS NOT NULL
                      AND ea3.submit_time <= ea.submit_time
                   ) AS submit_rank
            FROM exam_attempts ea
            JOIN students s ON s.student_id = ea.student_id
            WHERE ea.exam_id = ? AND ea.status = 'submitted' AND ea.submit_time IS NOT NULL
            ORDER BY ea.submit_time
        ");
        $stmt->execute([$examId, $examId, $examId]);
        return $stmt->fetchAll();
    }

    // ── Answer similarity matrix ──────────────────────────────────
    public function similarityMatrix(int $examId, float $threshold = 60.0): array {
        $stmt = $this->db->prepare("
            SELECT
                ea1.student_id     AS student_a,
                s1.full_name       AS name_a,
                s1.student_id_no     AS student_id_no_a,
                ea2.student_id     AS student_b,
                s2.full_name       AS name_b,
                s2.student_id_no     AS student_id_no_b,
                COUNT(sa1.question_id)                                          AS total_questions,
                SUM(sa1.selected_option = sa2.selected_option)                 AS matching_answers,
                SUM(sa1.selected_option = sa2.selected_option
                    AND sa1.selected_option != q.correct_option)               AS matching_wrong,
                ROUND(
                  SUM(sa1.selected_option = sa2.selected_option) /
                  GREATEST(COUNT(sa1.question_id), 1) * 100, 1
                )                                                               AS similarity_pct
            FROM exam_attempts ea1
            JOIN exam_attempts ea2  ON ea2.exam_id = ea1.exam_id
                                   AND ea2.student_id > ea1.student_id
                                   AND ea2.status = 'submitted'
            JOIN students s1        ON s1.student_id = ea1.student_id
            JOIN students s2        ON s2.student_id = ea2.student_id
            JOIN student_answers sa1 ON sa1.attempt_id = ea1.attempt_id
            JOIN student_answers sa2 ON sa2.attempt_id = ea2.attempt_id
                                    AND sa2.question_id = sa1.question_id
            JOIN questions q        ON q.question_id = sa1.question_id
            WHERE ea1.exam_id = ? AND ea1.status = 'submitted'
            GROUP BY ea1.student_id, ea2.student_id
            HAVING similarity_pct >= ?
            ORDER BY similarity_pct DESC
        ");
        $stmt->execute([$examId, $threshold]);
        return $stmt->fetchAll();
    }

    // ── Compare two students' answers side-by-side ────────────────
    public function compareAnswers(int $attemptA, int $attemptB): array {
        $stmt = $this->db->prepare("
            SELECT q.question_id, q.question_text,
                   q.option_a, q.option_b, q.option_c, q.option_d,
                   q.correct_option,
                   sa1.selected_option AS answer_a,
                   sa2.selected_option AS answer_b
            FROM questions q
            JOIN exam_attempts ea ON ea.attempt_id = ?
            LEFT JOIN student_answers sa1 ON sa1.question_id = q.question_id AND sa1.attempt_id = ?
            LEFT JOIN student_answers sa2 ON sa2.question_id = q.question_id AND sa2.attempt_id = ?
            WHERE q.exam_id = ea.exam_id
            ORDER BY q.display_order, q.question_id
        ");
        $stmt->execute([$attemptA, $attemptA, $attemptB]);
        return $stmt->fetchAll();
    }

    // ── Apply action on a single flag ─────────────────────────────
    public function applyAction(int $flagId, string $action, int $adminId): void {
        if (!in_array($action, ['warned','banned','ignored','none'])) return;
        $this->db->prepare("
            UPDATE cheating_flags
            SET action_taken = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE flag_id = ?
        ")->execute([$action, $adminId, $flagId]);

        // If banned, block the student
        if ($action === 'banned') {
            $sid = $this->db->prepare("SELECT student_id FROM cheating_flags WHERE flag_id=?");
            $sid->execute([$flagId]);
            $row = $sid->fetch();
            if ($row) {
                $this->db->prepare("UPDATE students SET is_blocked=1 WHERE student_id=?")
                         ->execute([$row['student_id']]);
            }
        }
    }

    // ── Bulk action: all flags for a student in an exam ───────────
    public function bulkAction(int $studentId, int $examId, string $action, int $adminId): int {
        if (!in_array($action, ['warned','banned','ignored','none'])) return 0;
        $stmt = $this->db->prepare("
            UPDATE cheating_flags
            SET action_taken = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE student_id = ? AND exam_id = ?
        ");
        $stmt->execute([$action, $adminId, $studentId, $examId]);
        if ($action === 'banned') {
            $this->db->prepare("UPDATE students SET is_blocked=1 WHERE student_id=?")
                     ->execute([$studentId]);
        }
        return $stmt->rowCount();
    }

    // ── Static helpers ─────────────────────────────────────────────
    public static function flagTypeIcon(string $type): string {
        return match($type) {
            'shared_ip'            => 'bi-wifi',
            'identical_answers'    => 'bi-files',
            'fast_submission'      => 'bi-lightning-fill',
            'close_timestamps'     => 'bi-clock-fill',
            'multiple_logins'      => 'bi-person-plus-fill',
            'answer_pattern_match' => 'bi-grid-3x3-gap-fill',
            'score_time_anomaly'   => 'bi-graph-up-arrow',
            default                => 'bi-flag-fill',
        };
    }

    public static function flagTypeLabel(string $type): string {
        return match($type) {
            'shared_ip'            => 'Shared IP Address',
            'identical_answers'    => 'Identical Answers',
            'fast_submission'      => 'Fast Submission',
            'close_timestamps'     => 'Close Timestamps',
            'multiple_logins'      => 'Multiple Logins',
            'answer_pattern_match' => 'Answer Pattern Match',
            'score_time_anomaly'   => 'Score-Time Anomaly',
            default                => ucwords(str_replace('_', ' ', $type)),
        };
    }

    public static function riskColor(string $level): string {
        return match($level) {
            'high'   => 'var(--danger)',
            'medium' => 'var(--warning)',
            'low'    => 'var(--success)',
            default  => 'var(--text-muted)',
        };
    }
}
