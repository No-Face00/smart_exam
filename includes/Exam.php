<?php
// includes/Exam.php — Exam business logic

require_once __DIR__ . '/../config/database.php';

class Exam {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── Available exams for a student ──
    public function availableForStudent(int $studentId, int $deptId): array {
        $stmt = $this->db->prepare("
            SELECT e.*,
                   t.full_name     AS teacher_name,
                   ea.attempt_id,
                   ea.status       AS attempt_status,
                   ea.score
            FROM exams e
            JOIN teachers t ON t.teacher_id = e.teacher_id
            LEFT JOIN exam_attempts ea
                   ON ea.exam_id = e.exam_id AND ea.student_id = :sid
            WHERE e.department_id = :dept
              AND e.status IN ('scheduled','running','completed')
            ORDER BY e.scheduled_start DESC
            LIMIT 20
        ");
        $stmt->execute([':sid' => $studentId, ':dept' => $deptId]);
        return $stmt->fetchAll();
    }

    // ── Get a single exam by ID ──
    public function getById(int $examId): ?array {
        $stmt = $this->db->prepare("
            SELECT e.*, t.full_name AS teacher_name, d.dept_name
            FROM exams e
            JOIN teachers    t ON t.teacher_id   = e.teacher_id
            JOIN departments d ON d.department_id = e.department_id
            WHERE e.exam_id = ?
        ");
        $stmt->execute([$examId]);
        return $stmt->fetch() ?: null;
    }

    // ── Get questions for an exam ──
    public function getQuestions(int $examId, bool $randomize = false): array {
        $order = $randomize ? 'RAND()' : 'display_order ASC, question_id ASC';
        $stmt  = $this->db->prepare(
            "SELECT * FROM questions WHERE exam_id = ? ORDER BY {$order}"
        );
        $stmt->execute([$examId]);
        return $stmt->fetchAll();
    }

    // ── Start or resume an exam attempt ──
    public function startAttempt(int $examId, int $studentId, string $ip, string $ua): array {
        $existing = $this->db->prepare(
            "SELECT * FROM exam_attempts WHERE exam_id = ? AND student_id = ?"
        );
        $existing->execute([$examId, $studentId]);
        $attempt = $existing->fetch();

        if ($attempt) {
            if ($attempt['status'] === 'submitted') {
                return ['ok' => false, 'error' => 'You have already submitted this exam.'];
            }
            return ['ok' => true, 'attempt_id' => (int)$attempt['attempt_id'], 'resumed' => true];
        }

        $exam = $this->getById($examId);
        if (!$exam) return ['ok' => false, 'error' => 'Exam not found.'];

        $now   = new DateTime();
        $start = new DateTime($exam['scheduled_start']);
        $end   = new DateTime($exam['scheduled_end']);

        if ($now < $start) return ['ok' => false, 'error' => 'Exam has not started yet.'];
        if ($now > $end)   return ['ok' => false, 'error' => 'Exam window has closed.'];

        $ins = $this->db->prepare("
            INSERT INTO exam_attempts (exam_id, student_id, ip_address, user_agent, start_time, status)
            VALUES (?, ?, ?, ?, NOW(), 'in_progress')
        ");
        $ins->execute([$examId, $studentId, $ip, $ua]);
        return ['ok' => true, 'attempt_id' => (int)$this->db->lastInsertId(), 'resumed' => false];
    }

    // ── Submit an exam attempt ──
    public function submitAttempt(int $attemptId, int $studentId): array {
        $attempt = $this->db->prepare(
            "SELECT ea.*, e.total_marks, e.pass_marks, e.duration_mins
             FROM exam_attempts ea JOIN exams e ON e.exam_id = ea.exam_id
             WHERE ea.attempt_id = ? AND ea.student_id = ?"
        );
        $attempt->execute([$attemptId, $studentId]);
        $att = $attempt->fetch();

        if (!$att) return ['ok' => false, 'error' => 'Attempt not found.'];
        if ($att['status'] === 'submitted') return ['ok' => false, 'error' => 'Already submitted.'];

        $answers = $this->db->prepare("
            SELECT sa.selected_option, q.correct_option, q.marks
            FROM student_answers sa
            JOIN questions q ON q.question_id = sa.question_id
            WHERE sa.attempt_id = ?
        ");
        $answers->execute([$attemptId]);
        $rows = $answers->fetchAll();

        $earned = 0;
        foreach ($rows as $row) {
            if ($row['selected_option'] === $row['correct_option']) {
                $earned += $row['marks'];
            }
        }

        $total    = max(1, (int)$att['total_marks']);
        $score    = round(($earned / $total) * 100, 2);
        $isPassed = $score >= $att['pass_marks'];
        $startDt  = new DateTime($att['start_time']);
        $nowDt    = new DateTime();
        $elapsed  = $nowDt->getTimestamp() - $startDt->getTimestamp();
        $timeTaken = min($elapsed, $att['duration_mins'] * 60);

        $this->db->prepare("
            UPDATE exam_attempts
            SET status='submitted', submit_time=NOW(),
                score=?, is_passed=?, time_taken_secs=?
            WHERE attempt_id=?
        ")->execute([$score, $isPassed ? 1 : 0, $timeTaken, $attemptId]);

        return ['ok' => true, 'score' => $score, 'is_passed' => $isPassed];
    }

    // ── Save a single answer ──
    public function saveAnswer(int $attemptId, int $questionId, ?string $option): bool {
        try {
            $this->db->prepare("
                INSERT INTO student_answers (attempt_id, question_id, selected_option)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE selected_option = VALUES(selected_option), answered_at = NOW()
            ")->execute([$attemptId, $questionId, $option]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Get result detail for a submitted attempt ──
    public function getResult(int $attemptId, int $studentId): ?array {
        $stmt = $this->db->prepare("
            SELECT ea.*, e.title, e.total_marks, e.pass_marks, e.duration_mins,
                   t.full_name AS teacher_name, d.dept_name
            FROM exam_attempts ea
            JOIN exams       e ON e.exam_id     = ea.exam_id
            JOIN teachers    t ON t.teacher_id  = e.teacher_id
            JOIN departments d ON d.department_id = e.department_id
            WHERE ea.attempt_id = ? AND ea.student_id = ?
        ");
        $stmt->execute([$attemptId, $studentId]);
        $attempt = $stmt->fetch();
        if (!$attempt) return null;

        $qa = $this->db->prepare("
            SELECT q.*, sa.selected_option
            FROM questions q
            LEFT JOIN student_answers sa
                   ON sa.question_id = q.question_id AND sa.attempt_id = :aid
            WHERE q.exam_id = :eid
            ORDER BY q.display_order, q.question_id
        ");
        $qa->execute([':aid' => $attemptId, ':eid' => $attempt['exam_id']]);
        $attempt['questions'] = $qa->fetchAll();

        return $attempt;
    }

    // ── Exams by teacher (with attempt_count and flag_count for dashboard) ──
    public function getByTeacher(int $teacherId): array {
        $stmt = $this->db->prepare("
            SELECT e.*, d.dept_name,
                   COUNT(DISTINCT ea.attempt_id)   AS total_attempts,
                   COUNT(DISTINCT ea.attempt_id)   AS attempt_count,
                   SUM(ea.status='submitted')       AS submitted,
                   SUM(ea.is_passed=1)              AS passed,
                   ROUND(AVG(ea.score),1)           AS avg_score,
                   COUNT(DISTINCT cf.flag_id)       AS flag_count
            FROM exams e
            JOIN departments d ON d.department_id = e.department_id
            LEFT JOIN exam_attempts  ea ON ea.exam_id = e.exam_id
            LEFT JOIN cheating_flags cf ON cf.exam_id = e.exam_id
            WHERE e.teacher_id = ?
            GROUP BY e.exam_id
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    }

    // ── Alias used by teacher pages ──
    public function byTeacher(int $teacherId): array {
        return $this->getByTeacher($teacherId);
    }

    // ── Create a new exam ──
    public function create(array $d): int {
        $stmt = $this->db->prepare("
            INSERT INTO exams
              (teacher_id, department_id, title, description, total_marks, pass_marks,
               duration_mins, scheduled_start, scheduled_end, is_randomized, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $d['teacher_id'],
            $d['department_id'],
            $d['title'],
            $d['description'] ?? '',
            $d['total_marks']  ?? 100,
            $d['pass_marks']   ?? 40,
            $d['duration_mins'],
            $d['scheduled_start'],
            $d['scheduled_end'],
            $d['is_randomized'] ?? 0,
            $d['status'] ?? 'draft',
        ]);
        return (int)$this->db->lastInsertId();
    }

    // ── Update an existing exam ──
    public function update(int $examId, array $d): bool {
        $stmt = $this->db->prepare("
            UPDATE exams SET
              title=?, description=?, total_marks=?, pass_marks=?,
              duration_mins=?, scheduled_start=?, scheduled_end=?,
              is_randomized=?, status=?
            WHERE exam_id=?
        ");
        return $stmt->execute([
            $d['title'],
            $d['description'] ?? '',
            $d['total_marks']  ?? 100,
            $d['pass_marks']   ?? 40,
            $d['duration_mins'],
            $d['scheduled_start'],
            $d['scheduled_end'],
            $d['is_randomized'] ?? 0,
            $d['status'] ?? 'draft',
            $examId,
        ]);
    }

    // ── Delete an exam ──
    public function delete(int $examId): bool {
        return $this->db->prepare("DELETE FROM exams WHERE exam_id=?")
                        ->execute([$examId]);
    }

    // ── Add a question to an exam ──
    public function addQuestion(array $d): int {
        $stmt = $this->db->prepare("
            INSERT INTO questions
              (exam_id, question_text, option_a, option_b, option_c, option_d, correct_option, marks, display_order)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $d['exam_id'],
            $d['question_text'],
            $d['option_a'],
            $d['option_b'],
            $d['option_c'],
            $d['option_d'],
            strtoupper($d['correct_option']),
            $d['marks'] ?? 1,
            $d['display_order'] ?? 0,
        ]);
        return (int)$this->db->lastInsertId();
    }

    // ── Delete a question ──
    public function deleteQuestion(int $questionId): bool {
        return $this->db->prepare("DELETE FROM questions WHERE question_id=?")
                        ->execute([$questionId]);
    }

    // ── Run cheating detection stored procedure ──
    public function runCheatingDetection(int $examId): void {
        try {
            $this->db->exec("CALL sp_detect_cheating($examId)");
        } catch (\PDOException $e) {
            error_log('Cheating detection error: ' . $e->getMessage());
        }
    }

    // ── All exams (admin view) ──
    public function getAll(): array {
        $stmt = $this->db->query("
            SELECT e.*, t.full_name AS teacher_name, d.dept_name,
                   COUNT(DISTINCT ea.attempt_id) AS total_attempts,
                   COUNT(DISTINCT ea.attempt_id) AS attempt_count,
                   SUM(ea.status='submitted')    AS submitted,
                   COUNT(DISTINCT cf.flag_id)    AS flag_count
            FROM exams e
            JOIN teachers    t ON t.teacher_id   = e.teacher_id
            JOIN departments d ON d.department_id = e.department_id
            LEFT JOIN exam_attempts  ea ON ea.exam_id = e.exam_id
            LEFT JOIN cheating_flags cf ON cf.exam_id = e.exam_id
            GROUP BY e.exam_id
            ORDER BY e.created_at DESC
        ");
        return $stmt->fetchAll();
    }
}
