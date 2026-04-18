-- ============================================================
--  Smart Exam Management System — Database Schema
--  MySQL 8.0+ | Normalized to 3NF
-- ============================================================

CREATE DATABASE IF NOT EXISTS smart_exam_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_exam_db;

-- ============================================================
-- 1. DEPARTMENTS
-- ============================================================
CREATE TABLE departments (
  department_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dept_name       VARCHAR(100) NOT NULL UNIQUE,
  dept_code       VARCHAR(20)  NOT NULL UNIQUE,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 2. ADMINS
-- ============================================================
CREATE TABLE admins (
  admin_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(120) NOT NULL,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_login      TIMESTAMP    NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 3. TEACHERS
-- ============================================================
CREATE TABLE teachers (
  teacher_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id   INT UNSIGNED NOT NULL,
  full_name       VARCHAR(120) NOT NULL,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  employee_code   VARCHAR(30)  NOT NULL UNIQUE,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_blocked      TINYINT(1)   NOT NULL DEFAULT 0,
  last_login      TIMESTAMP    NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teacher_dept FOREIGN KEY (department_id)
    REFERENCES departments(department_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 4. STUDENTS
-- ============================================================
CREATE TABLE students (
  student_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id   INT UNSIGNED NOT NULL,
  full_name       VARCHAR(120) NOT NULL,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  roll_number     VARCHAR(40)  NOT NULL UNIQUE,
  batch_year      YEAR         NOT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_blocked      TINYINT(1)   NOT NULL DEFAULT 0,
  last_login      TIMESTAMP    NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_dept FOREIGN KEY (department_id)
    REFERENCES departments(department_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 5. EXAMS
-- ============================================================
CREATE TABLE exams (
  exam_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id      INT UNSIGNED NOT NULL,
  department_id   INT UNSIGNED NOT NULL,
  title           VARCHAR(200) NOT NULL,
  description     TEXT,
  total_marks     INT UNSIGNED NOT NULL DEFAULT 100,
  pass_marks      INT UNSIGNED NOT NULL DEFAULT 40,
  duration_mins   INT UNSIGNED NOT NULL COMMENT 'Allowed time in minutes',
  scheduled_start DATETIME     NOT NULL,
  scheduled_end   DATETIME     NOT NULL,
  is_randomized   TINYINT(1)   NOT NULL DEFAULT 0,
  status          ENUM('draft','scheduled','running','completed','cancelled')
                               NOT NULL DEFAULT 'draft',
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_exam_teacher FOREIGN KEY (teacher_id)
    REFERENCES teachers(teacher_id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_dept FOREIGN KEY (department_id)
    REFERENCES departments(department_id) ON DELETE RESTRICT,
  CONSTRAINT chk_exam_dates CHECK (scheduled_end > scheduled_start)
) ENGINE=InnoDB;

-- ============================================================
-- 6. QUESTIONS  (MCQ — 4 choices)
-- ============================================================
CREATE TABLE questions (
  question_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id         INT UNSIGNED NOT NULL,
  question_text   TEXT         NOT NULL,
  option_a        VARCHAR(500) NOT NULL,
  option_b        VARCHAR(500) NOT NULL,
  option_c        VARCHAR(500) NOT NULL,
  option_d        VARCHAR(500) NOT NULL,
  correct_option  ENUM('A','B','C','D') NOT NULL,
  marks           INT UNSIGNED NOT NULL DEFAULT 1,
  display_order   INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_question_exam FOREIGN KEY (exam_id)
    REFERENCES exams(exam_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 7. EXAM ATTEMPTS
-- ============================================================
CREATE TABLE exam_attempts (
  attempt_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id         INT UNSIGNED NOT NULL,
  student_id      INT UNSIGNED NOT NULL,
  ip_address      VARCHAR(45)  NOT NULL COMMENT 'IPv4 or IPv6',
  user_agent      TEXT,
  start_time      DATETIME     NOT NULL,
  submit_time     DATETIME     NULL,
  time_taken_secs INT UNSIGNED NULL,
  score           DECIMAL(6,2) NULL,
  is_passed       TINYINT(1)   NULL,
  status          ENUM('in_progress','submitted','timed_out','abandoned')
                               NOT NULL DEFAULT 'in_progress',
  UNIQUE KEY uq_attempt (exam_id, student_id),  -- one attempt per student per exam
  CONSTRAINT fk_attempt_exam FOREIGN KEY (exam_id)
    REFERENCES exams(exam_id) ON DELETE CASCADE,
  CONSTRAINT fk_attempt_student FOREIGN KEY (student_id)
    REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 8. STUDENT ANSWERS
-- ============================================================
CREATE TABLE student_answers (
  answer_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id      INT UNSIGNED NOT NULL,
  question_id     INT UNSIGNED NOT NULL,
  selected_option ENUM('A','B','C','D') NULL COMMENT 'NULL = unanswered',
  answered_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_answer (attempt_id, question_id),
  CONSTRAINT fk_ans_attempt FOREIGN KEY (attempt_id)
    REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_ans_question FOREIGN KEY (question_id)
    REFERENCES questions(question_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 9. SUBMISSION LOGS  (audit trail)
-- ============================================================
CREATE TABLE submission_logs (
  log_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_type       ENUM('admin','teacher','student') NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  action          VARCHAR(80)  NOT NULL  COMMENT 'e.g. login, exam_start, submit',
  ip_address      VARCHAR(45)  NOT NULL,
  user_agent      TEXT,
  extra_data      JSON         NULL      COMMENT 'Flexible metadata',
  logged_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_user   (user_type, user_id),
  INDEX idx_log_action (action),
  INDEX idx_log_ip     (ip_address)
) ENGINE=InnoDB;

-- ============================================================
-- 10. CHEATING FLAGS
-- ============================================================
CREATE TABLE cheating_flags (
  flag_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id      INT UNSIGNED NOT NULL,
  student_id      INT UNSIGNED NOT NULL,
  exam_id         INT UNSIGNED NOT NULL,
  flag_type       ENUM(
                    'shared_ip',
                    'identical_answers',
                    'fast_submission',
                    'close_timestamps',
                    'multiple_logins',
                    'answer_pattern_match'
                  ) NOT NULL,
  risk_level      ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  risk_score      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
  description     TEXT         NOT NULL,
  matched_with    JSON         NULL  COMMENT 'IDs of other students involved',
  action_taken    ENUM('none','warned','banned','ignored') NOT NULL DEFAULT 'none',
  reviewed_by     INT UNSIGNED NULL COMMENT 'admin_id who reviewed',
  reviewed_at     TIMESTAMP    NULL,
  detected_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_flag_attempt FOREIGN KEY (attempt_id)
    REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_flag_student FOREIGN KEY (student_id)
    REFERENCES students(student_id) ON DELETE CASCADE,
  CONSTRAINT fk_flag_exam FOREIGN KEY (exam_id)
    REFERENCES exams(exam_id) ON DELETE CASCADE,
  CONSTRAINT fk_flag_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES admins(admin_id) ON DELETE SET NULL,
  INDEX idx_flag_risk  (risk_level),
  INDEX idx_flag_type  (flag_type),
  INDEX idx_flag_exam  (exam_id)
) ENGINE=InnoDB;

-- ============================================================
-- 11. NOTIFICATIONS
-- ============================================================
CREATE TABLE notifications (
  notif_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_type  ENUM('admin','teacher','student') NOT NULL,
  recipient_id    INT UNSIGNED NOT NULL,
  title           VARCHAR(200) NOT NULL,
  message         TEXT         NOT NULL,
  type            ENUM('info','warning','success','danger') NOT NULL DEFAULT 'info',
  is_read         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_recipient (recipient_type, recipient_id, is_read)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO departments (dept_name, dept_code) VALUES
  ('Computer Science & Engineering', 'CSE'),
  ('Electrical & Electronic Engineering', 'EEE'),
  ('Business Administration', 'BBA'),
  ('Mathematics', 'MATH');

-- Default admin  (password: Admin@1234)
INSERT INTO admins (full_name, email, password_hash) VALUES
  ('System Administrator', 'admin@smartexam.com',
   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- ============================================================
-- VIEWS  — handy for dashboard queries
-- ============================================================

CREATE OR REPLACE VIEW v_exam_stats AS
SELECT
  e.exam_id,
  e.title,
  e.status,
  e.scheduled_start,
  e.duration_mins,
  t.full_name         AS teacher_name,
  d.dept_name,
  COUNT(DISTINCT ea.student_id)                          AS total_attempts,
  SUM(CASE WHEN ea.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
  SUM(CASE WHEN ea.is_passed = 1 THEN 1 ELSE 0 END)        AS passed,
  AVG(ea.score)                                          AS avg_score
FROM exams e
JOIN teachers   t ON t.teacher_id   = e.teacher_id
JOIN departments d ON d.department_id = e.department_id
LEFT JOIN exam_attempts ea ON ea.exam_id = e.exam_id
GROUP BY e.exam_id;

CREATE OR REPLACE VIEW v_cheating_summary AS
SELECT
  s.student_id,
  s.full_name,
  s.roll_number,
  d.dept_name,
  COUNT(cf.flag_id)                                      AS total_flags,
  SUM(CASE WHEN cf.risk_level='high'   THEN 1 ELSE 0 END) AS high_flags,
  SUM(CASE WHEN cf.risk_level='medium' THEN 1 ELSE 0 END) AS medium_flags,
  MAX(cf.risk_score)                                     AS max_risk_score
FROM students s
JOIN departments d  ON d.department_id = s.department_id
LEFT JOIN cheating_flags cf ON cf.student_id = s.student_id
GROUP BY s.student_id;

-- ============================================================
-- STORED PROCEDURE: Run all cheating detection checks for an exam
-- ============================================================
DELIMITER $$

CREATE PROCEDURE sp_detect_cheating(IN p_exam_id INT UNSIGNED)
BEGIN

  -- ① Shared IP address
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    ea.exam_id,
    'shared_ip',
    'high',
    85,
    CONCAT('IP address ', ea.ip_address, ' was used by multiple students in this exam.'),
    (
      SELECT JSON_ARRAYAGG(ea2.student_id)
      FROM exam_attempts ea2
      WHERE ea2.exam_id = p_exam_id
        AND ea2.ip_address = ea.ip_address
        AND ea2.student_id != ea.student_id
    )
  FROM exam_attempts ea
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
  GROUP BY ea.ip_address, ea.attempt_id, ea.student_id, ea.exam_id
  HAVING COUNT(*) OVER (PARTITION BY ea.ip_address) > 1
  ON DUPLICATE KEY UPDATE risk_score = VALUES(risk_score);

  -- ② Abnormally fast submission (< 20% of allowed time)
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    ea.exam_id,
    'fast_submission',
    'medium',
    70,
    CONCAT('Submitted in ', ea.time_taken_secs, ' seconds. Exam allows ',
           (e.duration_mins * 60), ' seconds (flagged if < 20%).'),
    NULL
  FROM exam_attempts ea
  JOIN exams e ON e.exam_id = ea.exam_id
  WHERE ea.exam_id  = p_exam_id
    AND ea.status   = 'submitted'
    AND ea.time_taken_secs < (e.duration_mins * 60 * 0.20)
  ON DUPLICATE KEY UPDATE risk_score = VALUES(risk_score);

  -- ③ Submissions within 30 seconds of each other (close timestamps)
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    ea.exam_id,
    'close_timestamps',
    'medium',
    65,
    CONCAT('Submitted within 30 seconds of another student (', ea2.full_name, ').'),
    JSON_ARRAY(ea2.student_id)
  FROM exam_attempts ea
  JOIN exam_attempts ea_other
    ON ea_other.exam_id   = p_exam_id
   AND ea_other.student_id != ea.student_id
   AND ABS(TIMESTAMPDIFF(SECOND, ea.submit_time, ea_other.submit_time)) <= 30
  JOIN students ea2 ON ea2.student_id = ea_other.student_id
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
  ON DUPLICATE KEY UPDATE risk_score = VALUES(risk_score);

  -- ④ Identical answer patterns (≥ 80% same answers)
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    a1.attempt_id,
    a1.student_id,
    p_exam_id,
    'identical_answers',
    'high',
    90,
    CONCAT('Answer pattern matches ', ROUND(match_pct, 1), '% with student ID ', a2.student_id),
    JSON_ARRAY(a2.student_id)
  FROM (
    SELECT
      sa1.attempt_id,
      ea1.student_id,
      sa2.attempt_id AS other_attempt_id,
      ea2.student_id AS other_student_id,
      COUNT(*)       AS total_q,
      SUM(CASE WHEN sa1.selected_option = sa2.selected_option THEN 1 ELSE 0 END) AS matched,
      (SUM(CASE WHEN sa1.selected_option = sa2.selected_option THEN 1 ELSE 0 END)
       / COUNT(*) * 100) AS match_pct
    FROM student_answers sa1
    JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id AND ea1.exam_id = p_exam_id
    JOIN student_answers sa2 ON sa2.question_id = sa1.question_id AND sa2.attempt_id != sa1.attempt_id
    JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = p_exam_id
    GROUP BY sa1.attempt_id, ea1.student_id, sa2.attempt_id, ea2.student_id
    HAVING match_pct >= 80 AND total_q >= 5
  ) AS sub
  JOIN exam_attempts a1 ON a1.attempt_id = sub.attempt_id
  JOIN exam_attempts a2 ON a2.attempt_id = sub.other_attempt_id
  WHERE a1.student_id < a2.student_id  -- avoid duplicate pairs
  ON DUPLICATE KEY UPDATE risk_score = VALUES(risk_score);

END$$

DELIMITER ;
