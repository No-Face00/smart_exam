-- Smart Exam Management System — Database Schema v2
-- MySQL 8.0+ | Semester + Section Access Control

CREATE DATABASE IF NOT EXISTS smart_exam_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_exam_db;

CREATE TABLE departments (
  department_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dept_name     VARCHAR(100) NOT NULL UNIQUE,
  dept_code     VARCHAR(20)  NOT NULL UNIQUE,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE semesters (
  semester_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  semester_name VARCHAR(50)  NOT NULL UNIQUE COMMENT 'e.g. Spring 2025',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE sections (
  section_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_name  VARCHAR(20)  NOT NULL COMMENT 'e.g. A, B, C',
  department_id INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_section_dept (section_name, department_id),
  CONSTRAINT fk_section_dept FOREIGN KEY (department_id)
    REFERENCES departments(department_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admins (
  admin_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login    TIMESTAMP    NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE teachers (
  teacher_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  employee_code VARCHAR(30)  NOT NULL UNIQUE,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  is_blocked    TINYINT(1)   NOT NULL DEFAULT 0,
  last_login    TIMESTAMP    NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teacher_dept FOREIGN KEY (department_id)
    REFERENCES departments(department_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE students (
  student_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  semester_id   INT UNSIGNED NOT NULL,
  section_id    INT UNSIGNED NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  student_id_no VARCHAR(40)  NOT NULL UNIQUE COMMENT 'University student ID',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  is_blocked    TINYINT(1)   NOT NULL DEFAULT 0,
  last_login    TIMESTAMP    NULL,
  last_updated_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_dept     FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE RESTRICT,
  CONSTRAINT fk_student_semester FOREIGN KEY (semester_id)   REFERENCES semesters(semester_id)    ON DELETE RESTRICT,
  CONSTRAINT fk_student_section  FOREIGN KEY (section_id)    REFERENCES sections(section_id)      ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE exams (
  exam_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id      INT UNSIGNED NOT NULL,
  department_id   INT UNSIGNED NOT NULL,
  semester_id     INT UNSIGNED NULL COMMENT 'NULL = section-only quiz',
  title           VARCHAR(200) NOT NULL,
  description     TEXT,
  exam_type       ENUM('quiz','midterm','final') NOT NULL DEFAULT 'quiz',
  total_marks     INT UNSIGNED NOT NULL DEFAULT 100,
  pass_marks      INT UNSIGNED NOT NULL DEFAULT 40,
  duration_mins   INT UNSIGNED NOT NULL,
  scheduled_start DATETIME     NOT NULL,
  scheduled_end   DATETIME     NOT NULL,
  is_randomized   TINYINT(1)   NOT NULL DEFAULT 0,
  status          ENUM('draft','scheduled','running','completed','cancelled') NOT NULL DEFAULT 'draft',
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_exam_teacher  FOREIGN KEY (teacher_id)    REFERENCES teachers(teacher_id)      ON DELETE CASCADE,
  CONSTRAINT fk_exam_dept     FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE RESTRICT,
  CONSTRAINT fk_exam_semester FOREIGN KEY (semester_id)   REFERENCES semesters(semester_id)    ON DELETE RESTRICT,
  CONSTRAINT chk_exam_dates   CHECK (scheduled_end > scheduled_start)
) ENGINE=InnoDB;

CREATE TABLE exam_sections (
  exam_id    INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (exam_id, section_id),
  CONSTRAINT fk_exsec_exam    FOREIGN KEY (exam_id)    REFERENCES exams(exam_id)       ON DELETE CASCADE,
  CONSTRAINT fk_exsec_section FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE questions (
  question_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id        INT UNSIGNED NOT NULL,
  question_text  TEXT         NOT NULL,
  option_a       VARCHAR(500) NOT NULL,
  option_b       VARCHAR(500) NOT NULL,
  option_c       VARCHAR(500) NOT NULL,
  option_d       VARCHAR(500) NOT NULL,
  correct_option ENUM('A','B','C','D') NOT NULL,
  marks          INT UNSIGNED NOT NULL DEFAULT 1,
  display_order  INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_question_exam FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE exam_attempts (
  attempt_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id         INT UNSIGNED NOT NULL,
  student_id      INT UNSIGNED NOT NULL,
  ip_address      VARCHAR(45)  NOT NULL,
  user_agent      TEXT,
  start_time      DATETIME     NOT NULL,
  submit_time     DATETIME     NULL,
  time_taken_secs INT UNSIGNED NULL,
  score           DECIMAL(6,2) NULL,
  is_passed       TINYINT(1)   NULL,
  status          ENUM('in_progress','submitted','timed_out','abandoned') NOT NULL DEFAULT 'in_progress',
  UNIQUE KEY uq_attempt (exam_id, student_id),
  CONSTRAINT fk_attempt_exam    FOREIGN KEY (exam_id)    REFERENCES exams(exam_id)       ON DELETE CASCADE,
  CONSTRAINT fk_attempt_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_answers (
  answer_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id      INT UNSIGNED NOT NULL,
  question_id     INT UNSIGNED NOT NULL,
  selected_option ENUM('A','B','C','D') NULL,
  answered_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_answer (attempt_id, question_id),
  CONSTRAINT fk_ans_attempt  FOREIGN KEY (attempt_id)  REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_ans_question FOREIGN KEY (question_id) REFERENCES questions(question_id)    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  reset_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_type  ENUM('student','teacher') NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  email      VARCHAR(150) NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  used       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_token (token),
  INDEX idx_reset_email (email, user_type)
) ENGINE=InnoDB;

CREATE TABLE submission_logs (
  log_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_type  ENUM('admin','teacher','student') NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(80)  NOT NULL,
  ip_address VARCHAR(45)  NOT NULL,
  user_agent TEXT,
  extra_data JSON         NULL,
  logged_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_user   (user_type, user_id),
  INDEX idx_log_action (action),
  INDEX idx_log_ip     (ip_address)
) ENGINE=InnoDB;

CREATE TABLE cheating_flags (
  flag_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id   INT UNSIGNED NOT NULL,
  student_id   INT UNSIGNED NOT NULL,
  exam_id      INT UNSIGNED NOT NULL,
  flag_type    ENUM('shared_ip','identical_answers','fast_submission',
                    'close_timestamps','multiple_logins','answer_pattern_match') NOT NULL,
  risk_level   ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  risk_score   INT UNSIGNED NOT NULL DEFAULT 0,
  description  TEXT         NOT NULL,
  matched_with JSON         NULL,
  action_taken ENUM('none','warned','banned','ignored') NOT NULL DEFAULT 'none',
  reviewed_by  INT UNSIGNED NULL,
  reviewed_at  TIMESTAMP    NULL,
  detected_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_flag_attempt  FOREIGN KEY (attempt_id)  REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_flag_student  FOREIGN KEY (student_id)  REFERENCES students(student_id)      ON DELETE CASCADE,
  CONSTRAINT fk_flag_exam     FOREIGN KEY (exam_id)     REFERENCES exams(exam_id)             ON DELETE CASCADE,
  CONSTRAINT fk_flag_reviewer FOREIGN KEY (reviewed_by) REFERENCES admins(admin_id)           ON DELETE SET NULL,
  UNIQUE KEY uq_flag_attempt_type (attempt_id, flag_type),
  INDEX idx_flag_risk (risk_level),
  INDEX idx_flag_type (flag_type),
  INDEX idx_flag_exam (exam_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  notif_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_type ENUM('admin','teacher','student') NOT NULL,
  recipient_id   INT UNSIGNED NOT NULL,
  title          VARCHAR(200) NOT NULL,
  message        TEXT         NOT NULL,
  type           ENUM('info','warning','success','danger') NOT NULL DEFAULT 'info',
  is_read        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
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

INSERT INTO semesters (semester_name, is_active) VALUES
  ('Spring 2025', 0), ('Summer 2025', 1), ('Fall 2025', 0);

INSERT INTO sections (section_name, department_id) VALUES
  ('A',1),('B',1),('C',1),
  ('A',2),('B',2),('C',2),
  ('A',3),('B',3),('C',3),
  ('A',4),('B',4),('C',4);

-- Default admin (password: Admin@1234)
INSERT INTO admins (full_name, email, password_hash) VALUES
  ('System Administrator', 'admin@smartexam.com',
   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
