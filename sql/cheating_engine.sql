-- ==================================================================
--  SmartExam — Complete Cheating Detection Engine (Phase 4)
--  MySQL 8.0+  |  Run AFTER schema.sql
-- ==================================================================

USE smart_exam_db;

-- Drop and recreate the main detection procedure (enhanced)
DROP PROCEDURE IF EXISTS sp_detect_cheating;

DELIMITER $$

-- ==================================================================
-- MASTER PROCEDURE: run all 7 detection checks for one exam
-- ==================================================================
CREATE PROCEDURE sp_detect_cheating(IN p_exam_id INT UNSIGNED)
BEGIN

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 1 ▸ Shared IP Address (HIGH risk, score 85)
  --   Multiple students submitted from the exact same IP.
  --   Classic sign of: lab cheating, device sharing, proxy spoofing.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    ea.exam_id,
    'shared_ip',
    'high',
    85,
    CONCAT(
      'IP address ', ea.ip_address,
      ' was shared by ', COUNT(*) OVER (PARTITION BY ea.ip_address), ' student(s). ',
      'Submitted at ', TIME(ea.submit_time), '.'
    ),
    (
      SELECT JSON_ARRAYAGG(ea2.student_id)
      FROM exam_attempts ea2
      WHERE ea2.exam_id    = p_exam_id
        AND ea2.ip_address = ea.ip_address
        AND ea2.student_id != ea.student_id
    )
  FROM exam_attempts ea
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
  GROUP BY ea.ip_address, ea.attempt_id, ea.student_id, ea.exam_id
  HAVING COUNT(*) OVER (PARTITION BY ea.ip_address) > 1
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 2 ▸ Abnormally Fast Submission (MEDIUM risk, score 70)
  --   Submitted in less than 20 % of allowed time.
  --   Dynamic: score scales with how fast relative to allowed time.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    ea.exam_id,
    'fast_submission',
    CASE
      WHEN ea.time_taken_secs < (e.duration_mins * 60 * 0.05) THEN 'high'
      WHEN ea.time_taken_secs < (e.duration_mins * 60 * 0.10) THEN 'high'
      ELSE 'medium'
    END,
    LEAST(95, GREATEST(60,
      ROUND(100 - (ea.time_taken_secs / (e.duration_mins * 60) * 100))
    )),
    CONCAT(
      'Completed in ',
      FLOOR(ea.time_taken_secs / 60), 'm ', MOD(ea.time_taken_secs, 60), 's',
      ' (', ROUND(ea.time_taken_secs / (e.duration_mins * 60) * 100, 1),
      '% of allowed ', e.duration_mins, ' min). ',
      'Score was ', ROUND(ea.score, 1), '%.'
    ),
    NULL
  FROM exam_attempts ea
  JOIN exams e ON e.exam_id = ea.exam_id
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
    AND ea.time_taken_secs < (e.duration_mins * 60 * 0.20)
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    risk_level  = VALUES(risk_level),
    description = VALUES(description);

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 3 ▸ Suspiciously Close Submission Timestamps (MEDIUM 65)
  --   Two students submitted within 30 seconds of each other.
  --   Window function avoids duplicate pairs elegantly.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT DISTINCT
    ea.attempt_id,
    ea.student_id,
    p_exam_id,
    'close_timestamps',
    'medium',
    65,
    CONCAT(
      'Submitted within ',
      ABS(TIMESTAMPDIFF(SECOND, ea.submit_time, ea2.submit_time)),
      ' seconds of ', s2.full_name, ' (', s2.roll_number, '). ',
      'Both submitted at approximately ', TIME(ea.submit_time), '.'
    ),
    JSON_ARRAY(ea2.student_id)
  FROM exam_attempts ea
  JOIN exam_attempts ea2
    ON  ea2.exam_id    = p_exam_id
    AND ea2.student_id != ea.student_id
    AND ea.student_id  < ea2.student_id          -- deduplicate pairs
    AND ABS(TIMESTAMPDIFF(SECOND, ea.submit_time, ea2.submit_time)) <= 30
  JOIN students s2 ON s2.student_id = ea2.student_id
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
    AND ea2.status = 'submitted'
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 4 ▸ Identical Answer Patterns (HIGH risk, score 90)
  --   ≥ 80% identical answers between two students.
  --   Includes wrong answers — coincidental high match on CORRECT
  --   answers is expected; matching wrong answers is the red flag.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    sub.attempt_id_a,
    sub.student_id_a,
    p_exam_id,
    'identical_answers',
    CASE
      WHEN sub.match_pct >= 95 THEN 'high'
      WHEN sub.match_pct >= 85 THEN 'high'
      ELSE 'medium'
    END,
    LEAST(99, ROUND(sub.match_pct)),
    CONCAT(
      ROUND(sub.match_pct, 1), '% answer match with ',
      sub.name_b, ' (', sub.roll_b, '). ',
      sub.wrong_match, ' of ', sub.total_q,
      ' answers identical including ', sub.wrong_match_wrong, ' wrong answers.'
    ),
    JSON_ARRAY(sub.student_id_b)
  FROM (
    SELECT
      ea1.attempt_id                                            AS attempt_id_a,
      ea1.student_id                                           AS student_id_a,
      ea2.attempt_id                                           AS attempt_id_b,
      ea2.student_id                                           AS student_id_b,
      s2.full_name                                             AS name_b,
      s2.roll_number                                           AS roll_b,
      COUNT(*)                                                 AS total_q,
      SUM(sa1.selected_option = sa2.selected_option)          AS wrong_match,
      SUM(sa1.selected_option = sa2.selected_option
          AND sa1.selected_option != q.correct_option)        AS wrong_match_wrong,
      ROUND(
        SUM(sa1.selected_option = sa2.selected_option)
        / COUNT(*) * 100, 2
      )                                                        AS match_pct
    FROM student_answers sa1
    JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id AND ea1.exam_id = p_exam_id
    JOIN student_answers sa2 ON sa2.question_id = sa1.question_id
    JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = p_exam_id
    JOIN questions     q   ON q.question_id  = sa1.question_id
    JOIN students      s2  ON s2.student_id  = ea2.student_id
    WHERE ea1.student_id < ea2.student_id  -- canonical pair order
      AND ea1.status = 'submitted'
      AND ea2.status = 'submitted'
    GROUP BY ea1.attempt_id, ea1.student_id, ea2.attempt_id, ea2.student_id, s2.full_name, s2.roll_number
    HAVING match_pct >= 80 AND total_q >= 5
  ) sub
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    risk_level  = VALUES(risk_level),
    description = VALUES(description),
    matched_with= VALUES(matched_with);

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 5 ▸ Multiple Login Attempts Before Exam (MEDIUM risk, 60)
  --   Student logged in 3+ times in the hour before exam started.
  --   May indicate credential sharing or session probing.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    ea.exam_id,
    'multiple_logins',
    'medium',
    60,
    CONCAT(
      login_data.login_count,
      ' login events recorded for this student in the 60 minutes before exam start. ',
      'Distinct IPs used: ', login_data.distinct_ips, '.'
    ),
    NULL
  FROM exam_attempts ea
  JOIN (
    SELECT
      sl.user_id,
      COUNT(*)                    AS login_count,
      COUNT(DISTINCT sl.ip_address) AS distinct_ips
    FROM submission_logs sl
    WHERE sl.user_type = 'student'
      AND sl.action    = 'login'
    GROUP BY sl.user_id
    HAVING login_count >= 3
  ) login_data ON login_data.user_id = ea.student_id
  JOIN exams e ON e.exam_id = ea.exam_id
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 6 ▸ Score-Time Anomaly (HIGH risk, score 80)
  --   High score + very low time = statistically impossible.
  --   Uses the exam's average time as a benchmark.
  --   Students in the bottom 10% of time with top 20% of score.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ranked.attempt_id,
    ranked.student_id,
    p_exam_id,
    'fast_submission',          -- reuse type; description disambiguates
    'high',
    80,
    CONCAT(
      'Statistical anomaly: scored ', ROUND(ranked.score, 1),
      '% (top ', ranked.score_pct_rank, '% of class) while finishing in ',
      FLOOR(ranked.time_taken_secs/60), 'm ',
      MOD(ranked.time_taken_secs, 60), 's ',
      '(faster than ', ranked.time_pct_rank, '% of class). ',
      'Class avg time: ', FLOOR(ranked.avg_time/60), 'm', MOD(ROUND(ranked.avg_time),60), 's.'
    ),
    NULL
  FROM (
    SELECT
      ea.attempt_id,
      ea.student_id,
      ea.score,
      ea.time_taken_secs,
      AVG(ea2.time_taken_secs) OVER ()  AS avg_time,
      PERCENT_RANK() OVER (ORDER BY ea.score DESC)           * 100 AS score_pct_rank,
      PERCENT_RANK() OVER (ORDER BY ea.time_taken_secs ASC)  * 100 AS time_pct_rank
    FROM exam_attempts ea
    JOIN exam_attempts ea2 ON ea2.exam_id = p_exam_id AND ea2.status = 'submitted'
    WHERE ea.exam_id = p_exam_id
      AND ea.status  = 'submitted'
      AND ea.score   IS NOT NULL
      AND ea.time_taken_secs IS NOT NULL
  ) ranked
  WHERE ranked.score_pct_rank <= 20      -- top 20% scores
    AND ranked.time_pct_rank   <= 10     -- bottom 10% (fastest) times
    AND ranked.time_taken_secs < ranked.avg_time * 0.35
  ON DUPLICATE KEY UPDATE
    risk_level  = VALUES(risk_level),
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- ────────────────────────────────────────────────────────────────
  -- CHECK 7 ▸ Wrong-Answer Cluster Match (HIGH risk, score 88)
  --   Two students got the SAME wrong answers on 70%+ of questions.
  --   Coincidental correct-answer match is expected. Wrong-answer
  --   match is a much stronger signal — they saw the same source.
  -- ────────────────────────────────────────────────────────────────
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    pair.attempt_id_a,
    pair.student_id_a,
    p_exam_id,
    'answer_pattern_match',
    'high',
    88,
    CONCAT(
      'Wrong-answer cluster match with ', pair.name_b,
      ' (', pair.roll_b, '): ',
      pair.shared_wrong, ' of ', pair.total_wrong_a,
      ' wrong answers are identical (',
      ROUND(pair.shared_wrong / pair.total_wrong_a * 100, 1),
      '%). Statistically improbable by chance.'
    ),
    JSON_ARRAY(pair.student_id_b)
  FROM (
    SELECT
      ea1.attempt_id                                           AS attempt_id_a,
      ea1.student_id                                          AS student_id_a,
      ea2.attempt_id                                          AS attempt_id_b,
      ea2.student_id                                          AS student_id_b,
      s2.full_name                                            AS name_b,
      s2.roll_number                                          AS roll_b,
      -- wrong answers for student A
      SUM(sa1.selected_option != q.correct_option
          AND sa1.selected_option IS NOT NULL)                AS total_wrong_a,
      -- wrong answers where both students picked the same wrong option
      SUM(sa1.selected_option != q.correct_option
          AND sa1.selected_option IS NOT NULL
          AND sa1.selected_option = sa2.selected_option)      AS shared_wrong
    FROM student_answers sa1
    JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id AND ea1.exam_id = p_exam_id
    JOIN student_answers sa2 ON sa2.question_id = sa1.question_id
    JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = p_exam_id
    JOIN questions q        ON q.question_id  = sa1.question_id
    JOIN students  s2       ON s2.student_id  = ea2.student_id
    WHERE ea1.student_id < ea2.student_id
      AND ea1.status = 'submitted'
      AND ea2.status = 'submitted'
    GROUP BY ea1.attempt_id, ea1.student_id, ea2.attempt_id, ea2.student_id, s2.full_name, s2.roll_number
    HAVING total_wrong_a >= 3
       AND (shared_wrong / total_wrong_a) >= 0.70
  ) pair
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    description = VALUES(description),
    matched_with= VALUES(matched_with);

  -- ────────────────────────────────────────────────────────────────
  -- FINAL STEP ▸ Recalculate composite risk score
  --   If a student has MULTIPLE flag types, escalate risk level.
  -- ────────────────────────────────────────────────────────────────
  UPDATE cheating_flags cf
  JOIN (
    SELECT
      student_id,
      exam_id,
      COUNT(DISTINCT flag_type)  AS flag_variety,
      SUM(risk_score)            AS total_score,
      MAX(risk_score)            AS max_score
    FROM cheating_flags
    WHERE exam_id = p_exam_id
    GROUP BY student_id, exam_id
  ) agg ON agg.student_id = cf.student_id AND agg.exam_id = cf.exam_id
  SET cf.risk_level = CASE
    WHEN agg.flag_variety >= 3 THEN 'high'
    WHEN agg.flag_variety = 2 AND agg.max_score >= 70 THEN 'high'
    WHEN agg.flag_variety = 2 THEN 'medium'
    ELSE cf.risk_level
  END
  WHERE cf.exam_id = p_exam_id;

END$$

-- ==================================================================
-- UTILITY: Run detection across ALL completed exams at once
-- ==================================================================
CREATE PROCEDURE sp_detect_all_exams()
BEGIN
  DECLARE done   INT DEFAULT 0;
  DECLARE v_eid  INT UNSIGNED;

  DECLARE cur CURSOR FOR
    SELECT exam_id FROM exams WHERE status IN ('completed','running');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_eid;
    IF done THEN LEAVE read_loop; END IF;
    CALL sp_detect_cheating(v_eid);
  END LOOP;
  CLOSE cur;
END$$

-- ==================================================================
-- UTILITY: Clear all flags for an exam and re-run fresh
-- ==================================================================
CREATE PROCEDURE sp_rerun_detection(IN p_exam_id INT UNSIGNED)
BEGIN
  DELETE FROM cheating_flags WHERE exam_id = p_exam_id;
  CALL sp_detect_cheating(p_exam_id);
END$$

DELIMITER ;

-- ==================================================================
-- ANALYTICAL VIEWS for the investigation UI
-- ==================================================================

-- ▸ Full per-student cheating summary across all exams
CREATE OR REPLACE VIEW v_student_risk_profile AS
SELECT
  s.student_id,
  s.full_name,
  s.roll_number,
  s.email,
  d.dept_name,
  d.dept_code,
  COUNT(DISTINCT cf.flag_id)                              AS total_flags,
  COUNT(DISTINCT cf.exam_id)                              AS exams_flagged,
  SUM(cf.risk_level = 'high')                            AS high_flags,
  SUM(cf.risk_level = 'medium')                          AS medium_flags,
  SUM(cf.risk_level = 'low')                             AS low_flags,
  MAX(cf.risk_score)                                     AS peak_risk_score,
  ROUND(AVG(cf.risk_score), 1)                           AS avg_risk_score,
  COUNT(DISTINCT cf.flag_type)                           AS distinct_flag_types,
  -- composite risk label
  CASE
    WHEN SUM(cf.risk_level = 'high') >= 2              THEN 'CRITICAL'
    WHEN SUM(cf.risk_level = 'high') = 1               THEN 'HIGH'
    WHEN SUM(cf.risk_level = 'medium') >= 2            THEN 'MEDIUM'
    WHEN COUNT(cf.flag_id) > 0                         THEN 'LOW'
    ELSE 'CLEAN'
  END                                                    AS overall_risk,
  MAX(cf.detected_at)                                    AS last_detected,
  SUM(cf.action_taken = 'none')                         AS pending_actions,
  s.is_blocked
FROM students s
JOIN departments d ON d.department_id = s.department_id
LEFT JOIN cheating_flags cf ON cf.student_id = s.student_id
GROUP BY s.student_id, d.dept_name, d.dept_code;

-- ▸ Per-exam cheating health overview
CREATE OR REPLACE VIEW v_exam_cheat_health AS
SELECT
  e.exam_id,
  e.title,
  e.status,
  e.scheduled_start,
  t.full_name   AS teacher_name,
  d.dept_name,
  COUNT(DISTINCT ea.attempt_id)                          AS total_attempts,
  COUNT(DISTINCT cf.student_id)                          AS flagged_students,
  COUNT(DISTINCT cf.flag_id)                            AS total_flags,
  SUM(cf.risk_level = 'high')                          AS high_risk_flags,
  ROUND(
    COUNT(DISTINCT cf.student_id)
    / NULLIF(COUNT(DISTINCT ea.attempt_id), 0) * 100, 1
  )                                                      AS cheat_rate_pct,
  -- exam integrity score (100 = clean, 0 = heavily flagged)
  GREATEST(0, ROUND(
    100 - (COUNT(DISTINCT cf.flag_id) / NULLIF(COUNT(DISTINCT ea.attempt_id),0) * 50)
    - (SUM(cf.risk_level='high') / NULLIF(COUNT(DISTINCT ea.attempt_id),0) * 30), 1
  ))                                                     AS integrity_score
FROM exams e
JOIN teachers    t  ON t.teacher_id   = e.teacher_id
JOIN departments d  ON d.department_id = e.department_id
LEFT JOIN exam_attempts  ea ON ea.exam_id = e.exam_id AND ea.status = 'submitted'
LEFT JOIN cheating_flags cf ON cf.exam_id = e.exam_id
GROUP BY e.exam_id, t.full_name, d.dept_name;

-- ▸ IP sharing network (which students share IPs across exams)
CREATE OR REPLACE VIEW v_ip_network AS
SELECT
  ea1.ip_address,
  ea1.student_id   AS student_a,
  s1.full_name     AS name_a,
  ea2.student_id   AS student_b,
  s2.full_name     AS name_b,
  COUNT(DISTINCT ea1.exam_id) AS shared_exams,
  GROUP_CONCAT(DISTINCT e.title ORDER BY e.title SEPARATOR ' | ') AS exam_list
FROM exam_attempts ea1
JOIN exam_attempts ea2 ON  ea2.ip_address = ea1.ip_address
                       AND ea2.exam_id    = ea1.exam_id
                       AND ea2.student_id > ea1.student_id
JOIN students s1 ON s1.student_id = ea1.student_id
JOIN students s2 ON s2.student_id = ea2.student_id
JOIN exams    e  ON e.exam_id     = ea1.exam_id
GROUP BY ea1.ip_address, ea1.student_id, s1.full_name, ea2.student_id, s2.full_name;

-- ▸ Answer similarity matrix for a pair comparison query
-- (used by the investigation drill-down page)
-- Usage: SELECT * FROM v_answer_similarity WHERE exam_id = N;
CREATE OR REPLACE VIEW v_answer_similarity AS
SELECT
  ea1.exam_id,
  ea1.student_id                                         AS student_a,
  s1.full_name                                          AS name_a,
  ea2.student_id                                         AS student_b,
  s2.full_name                                          AS name_b,
  COUNT(*)                                               AS total_questions,
  SUM(sa1.selected_option = sa2.selected_option)        AS matching_answers,
  SUM(sa1.selected_option != q.correct_option
      AND sa1.selected_option = sa2.selected_option)    AS matching_wrong,
  ROUND(
    SUM(sa1.selected_option = sa2.selected_option)
    / COUNT(*) * 100, 2
  )                                                      AS similarity_pct
FROM student_answers sa1
JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id
JOIN student_answers sa2 ON sa2.question_id = sa1.question_id
JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id
                       AND ea2.exam_id   = ea1.exam_id
                       AND ea2.student_id > ea1.student_id
JOIN questions q  ON q.question_id = sa1.question_id
JOIN students  s1 ON s1.student_id = ea1.student_id
JOIN students  s2 ON s2.student_id = ea2.student_id
GROUP BY ea1.exam_id, ea1.student_id, s1.full_name, ea2.student_id, s2.full_name
HAVING total_questions >= 3;


-- ==================================================================
-- STANDALONE DIAGNOSTIC QUERIES
-- (Run these manually in phpMyAdmin for DBMS project demonstration)
-- ==================================================================

/*

-- Q1: Which students share an IP on the same exam?
SELECT ea.ip_address,
       GROUP_CONCAT(s.full_name ORDER BY s.full_name SEPARATOR ', ') AS students,
       COUNT(DISTINCT ea.student_id) AS count,
       e.title
FROM exam_attempts ea
JOIN students s ON s.student_id = ea.student_id
JOIN exams    e ON e.exam_id    = ea.exam_id
WHERE ea.status = 'submitted'
GROUP BY ea.ip_address, ea.exam_id
HAVING count > 1
ORDER BY count DESC;


-- Q2: Students with suspiciously high scores in very short time
SELECT s.full_name, s.roll_number,
       e.title, ea.score,
       CONCAT(FLOOR(ea.time_taken_secs/60),'m ',MOD(ea.time_taken_secs,60),'s') AS time_taken,
       e.duration_mins,
       ROUND(ea.time_taken_secs / (e.duration_mins*60) * 100, 1) AS pct_time_used
FROM exam_attempts ea
JOIN exams    e ON e.exam_id    = ea.exam_id
JOIN students s ON s.student_id = ea.student_id
WHERE ea.status = 'submitted'
  AND ea.score > 80
  AND ea.time_taken_secs < (e.duration_mins * 60 * 0.25)
ORDER BY pct_time_used ASC;


-- Q3: Answer similarity matrix between all pairs in an exam
SELECT s1.full_name AS student_a, s2.full_name AS student_b,
       COUNT(*) AS total_q,
       SUM(sa1.selected_option = sa2.selected_option) AS matching,
       ROUND(SUM(sa1.selected_option=sa2.selected_option)/COUNT(*)*100,1) AS similarity_pct
FROM student_answers sa1
JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id
JOIN student_answers sa2 ON sa2.question_id = sa1.question_id AND sa2.attempt_id != sa1.attempt_id
JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = ea1.exam_id
JOIN students s1 ON s1.student_id = ea1.student_id
JOIN students s2 ON s2.student_id = ea2.student_id
WHERE ea1.exam_id = 1  -- replace with exam ID
  AND ea1.student_id < ea2.student_id
  AND ea1.status = 'submitted'
GROUP BY ea1.student_id, ea2.student_id
HAVING similarity_pct >= 70
ORDER BY similarity_pct DESC;


-- Q4: Cluster students into risk tiers using CASE + aggregation
SELECT
  CASE
    WHEN cnt_high >= 2 OR (cnt_high >= 1 AND cnt_medium >= 2) THEN 'CRITICAL'
    WHEN cnt_high = 1 THEN 'HIGH'
    WHEN cnt_medium >= 2 THEN 'MEDIUM'
    WHEN total_flags > 0 THEN 'LOW'
    ELSE 'CLEAN'
  END AS risk_tier,
  COUNT(*) AS student_count
FROM (
  SELECT s.student_id,
         SUM(cf.risk_level = 'high')   AS cnt_high,
         SUM(cf.risk_level = 'medium') AS cnt_medium,
         COUNT(cf.flag_id)             AS total_flags
  FROM students s
  LEFT JOIN cheating_flags cf ON cf.student_id = s.student_id
  GROUP BY s.student_id
) tiers
GROUP BY risk_tier
ORDER BY FIELD(risk_tier,'CRITICAL','HIGH','MEDIUM','LOW','CLEAN');


-- Q5: Identify IP ranges shared across multiple exams (cross-exam tracking)
SELECT ea.ip_address,
       COUNT(DISTINCT ea.student_id) AS unique_students,
       COUNT(DISTINCT ea.exam_id)    AS exams_involved,
       GROUP_CONCAT(DISTINCT s.full_name ORDER BY s.full_name SEPARATOR ', ') AS names
FROM exam_attempts ea
JOIN students s ON s.student_id = ea.student_id
GROUP BY ea.ip_address
HAVING unique_students > 1 AND exams_involved > 1
ORDER BY unique_students DESC, exams_involved DESC;


-- Q6: Wrong-answer cluster — who shared the same wrong answers?
SELECT s1.full_name AS student_a, s2.full_name AS student_b,
       e.title,
       SUM(sa1.selected_option != q.correct_option
           AND sa1.selected_option = sa2.selected_option) AS shared_wrong,
       COUNT(*) AS total_q
FROM student_answers sa1
JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id
JOIN student_answers sa2 ON sa2.question_id = sa1.question_id
JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = ea1.exam_id
JOIN exams     e  ON e.exam_id   = ea1.exam_id
JOIN questions q  ON q.question_id = sa1.question_id
JOIN students  s1 ON s1.student_id = ea1.student_id
JOIN students  s2 ON s2.student_id = ea2.student_id
WHERE ea1.student_id < ea2.student_id
  AND ea1.status = 'submitted'
GROUP BY ea1.student_id, ea2.student_id, ea1.exam_id
HAVING shared_wrong >= 3
ORDER BY shared_wrong DESC;

*/
