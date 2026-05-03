-- ==================================================================
--  SmartExam — Cheating Detection Engine
--  MariaDB 10.4+ compatible | Run AFTER schema.sql
--  Import via phpMyAdmin: set delimiter to // before importing
-- ==================================================================

USE smart_exam_db;

ALTER TABLE cheating_flags
  MODIFY COLUMN flag_type ENUM(
    'shared_ip','identical_answers','fast_submission',
    'close_timestamps','multiple_logins','answer_pattern_match',
    'score_time_anomaly'
  ) NOT NULL;

DROP PROCEDURE IF EXISTS sp_detect_cheating;
DROP PROCEDURE IF EXISTS sp_detect_all_exams;
DROP PROCEDURE IF EXISTS sp_rerun_detection;

DELIMITER //

CREATE PROCEDURE sp_detect_cheating(IN p_exam_id INT UNSIGNED)
BEGIN

  -- CHECK 1: Shared IP Address (HIGH risk, score 85)
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
      ' was shared by ', ip_counts.cnt, ' student(s). ',
      'Submitted at ', TIME(ea.submit_time), '.'
    ),
    (
      SELECT CONCAT('[', GROUP_CONCAT(ea2.student_id ORDER BY ea2.student_id), ']')
      FROM exam_attempts ea2
      WHERE ea2.exam_id    = p_exam_id
        AND ea2.ip_address = ea.ip_address
        AND ea2.student_id != ea.student_id
        AND ea2.status     = 'submitted'
    )
  FROM exam_attempts ea
  JOIN (
    SELECT ip_address, COUNT(DISTINCT student_id) AS cnt
    FROM exam_attempts
    WHERE exam_id = p_exam_id AND status = 'submitted'
    GROUP BY ip_address
    HAVING cnt > 1
  ) ip_counts ON ip_counts.ip_address = ea.ip_address
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
  ON DUPLICATE KEY UPDATE
    risk_score   = VALUES(risk_score),
    description  = VALUES(description),
    matched_with = VALUES(matched_with);

  -- CHECK 2: Fast Submission (MEDIUM/HIGH risk)
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
    AND ea.time_taken_secs IS NOT NULL
    AND ea.time_taken_secs > 0
    AND ea.time_taken_secs < (e.duration_mins * 60 * 0.25)
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    risk_level  = VALUES(risk_level),
    description = VALUES(description);

  -- CHECK 3: Close Submission Timestamps (MEDIUM risk, score 65)
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    p_exam_id,
    'close_timestamps',
    'medium',
    65,
    CONCAT(
      'Submitted within ',
      ABS(TIMESTAMPDIFF(SECOND, ea.submit_time, ea2.submit_time)),
      ' seconds of ', s2.full_name, ' (', s2.student_id_no, '). ',
      'Both submitted at approximately ', TIME(ea.submit_time), '.'
    ),
    CONCAT('[', ea2.student_id, ']')
  FROM exam_attempts ea
  JOIN exam_attempts ea2
    ON  ea2.exam_id    = p_exam_id
    AND ea2.student_id != ea.student_id
    AND ea.student_id  < ea2.student_id
    AND ABS(TIMESTAMPDIFF(SECOND, ea.submit_time, ea2.submit_time)) <= 30
  JOIN students s2 ON s2.student_id = ea2.student_id
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
    AND ea2.status = 'submitted'
    AND ea.submit_time  IS NOT NULL
    AND ea2.submit_time IS NOT NULL
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- CHECK 4: Identical Answer Patterns (HIGH risk)
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
      sub.name_b, ' (', sub.student_id_no_b, '). ',
      sub.total_q, ' questions compared, ',
      sub.wrong_match_wrong, ' shared wrong answers.'
    ),
    CONCAT('[', sub.student_id_b, ']')
  FROM (
    SELECT
      ea1.attempt_id                                           AS attempt_id_a,
      ea1.student_id                                          AS student_id_a,
      ea2.attempt_id                                          AS attempt_id_b,
      ea2.student_id                                          AS student_id_b,
      s2.full_name                                            AS name_b,
      s2.student_id_no                                        AS student_id_no_b,
      COUNT(*)                                                AS total_q,
      SUM(sa1.selected_option = sa2.selected_option)         AS wrong_match,
      SUM(sa1.selected_option = sa2.selected_option
          AND sa1.selected_option != q.correct_option)       AS wrong_match_wrong,
      ROUND(
        SUM(sa1.selected_option = sa2.selected_option)
        / COUNT(*) * 100, 2
      )                                                       AS match_pct
    FROM student_answers sa1
    JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id AND ea1.exam_id = p_exam_id
    JOIN student_answers sa2 ON sa2.question_id = sa1.question_id
    JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = p_exam_id
    JOIN questions     q   ON q.question_id  = sa1.question_id
    JOIN students      s2  ON s2.student_id  = ea2.student_id
    WHERE ea1.student_id < ea2.student_id
      AND ea1.status = 'submitted'
      AND ea2.status = 'submitted'
    GROUP BY ea1.attempt_id, ea1.student_id, ea2.attempt_id, ea2.student_id, s2.full_name, s2.student_id_no
    HAVING match_pct >= 80 AND total_q >= 3
  ) sub
  ON DUPLICATE KEY UPDATE
    risk_score   = VALUES(risk_score),
    risk_level   = VALUES(risk_level),
    description  = VALUES(description),
    matched_with = VALUES(matched_with);

  -- CHECK 5: Multiple Logins Before Exam (MEDIUM risk, score 60)
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
      ' login events in the 60 minutes before exam start. ',
      'Distinct IPs used: ', login_data.distinct_ips, '.'
    ),
    NULL
  FROM exam_attempts ea
  JOIN exams e ON e.exam_id = ea.exam_id
  JOIN (
    SELECT
      sl.user_id,
      COUNT(*)                      AS login_count,
      COUNT(DISTINCT sl.ip_address) AS distinct_ips
    FROM submission_logs sl
    JOIN exams ex ON ex.exam_id = p_exam_id
    WHERE sl.user_type = 'student'
      AND sl.action    = 'login'
      AND sl.logged_at >= DATE_SUB(ex.scheduled_start, INTERVAL 60 MINUTE)
      AND sl.logged_at <= ex.scheduled_start
    GROUP BY sl.user_id
    HAVING login_count >= 3
  ) login_data ON login_data.user_id = ea.student_id
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
  ON DUPLICATE KEY UPDATE
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- CHECK 6: Score-Time Anomaly (HIGH risk, score 80)
  -- Uses correlated subqueries instead of PERCENT_RANK() for MariaDB compatibility
  INSERT INTO cheating_flags
    (attempt_id, student_id, exam_id, flag_type, risk_level, risk_score, description, matched_with)
  SELECT
    ea.attempt_id,
    ea.student_id,
    p_exam_id,
    'score_time_anomaly',
    'high',
    80,
    CONCAT(
      'Statistical anomaly: scored ', ROUND(ea.score, 1),
      '% while finishing in ',
      FLOOR(ea.time_taken_secs/60), 'm ',
      MOD(ea.time_taken_secs, 60), 's. ',
      'Class avg time: ', FLOOR(stats.avg_time/60), 'm ', MOD(ROUND(stats.avg_time), 60), 's.'
    ),
    NULL
  FROM exam_attempts ea
  JOIN (
    SELECT
      COUNT(*)             AS student_count,
      AVG(time_taken_secs) AS avg_time
    FROM exam_attempts
    WHERE exam_id = p_exam_id
      AND status = 'submitted'
      AND score IS NOT NULL
      AND time_taken_secs IS NOT NULL
      AND time_taken_secs > 0
  ) stats ON stats.student_count >= 3
  WHERE ea.exam_id = p_exam_id
    AND ea.status  = 'submitted'
    AND ea.score   IS NOT NULL
    AND ea.time_taken_secs IS NOT NULL
    AND ea.time_taken_secs > 0
    AND (
      SELECT COUNT(*) FROM exam_attempts ea2
      WHERE ea2.exam_id = p_exam_id AND ea2.status = 'submitted'
        AND ea2.score IS NOT NULL AND ea2.score < ea.score
    ) >= FLOOR(stats.student_count * 0.80)
    AND (
      SELECT COUNT(*) FROM exam_attempts ea3
      WHERE ea3.exam_id = p_exam_id AND ea3.status = 'submitted'
        AND ea3.time_taken_secs IS NOT NULL AND ea3.time_taken_secs > 0
        AND ea3.time_taken_secs > ea.time_taken_secs
    ) >= FLOOR(stats.student_count * 0.90)
    AND ea.time_taken_secs < stats.avg_time * 0.35
  ON DUPLICATE KEY UPDATE
    risk_level  = VALUES(risk_level),
    risk_score  = VALUES(risk_score),
    description = VALUES(description);

  -- CHECK 7: Wrong-Answer Cluster Match (HIGH risk, score 88)
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
      ' wrong answers identical (',
      ROUND(pair.shared_wrong / pair.total_wrong_a * 100, 1),
      '%). Statistically improbable by chance.'
    ),
    CONCAT('[', pair.student_id_b, ']')
  FROM (
    SELECT
      ea1.attempt_id                                          AS attempt_id_a,
      ea1.student_id                                         AS student_id_a,
      ea2.attempt_id                                         AS attempt_id_b,
      ea2.student_id                                         AS student_id_b,
      s2.full_name                                           AS name_b,
      s2.student_id_no                                       AS roll_b,
      SUM(sa1.selected_option != q.correct_option
          AND sa1.selected_option IS NOT NULL)               AS total_wrong_a,
      SUM(sa1.selected_option != q.correct_option
          AND sa1.selected_option IS NOT NULL
          AND sa1.selected_option = sa2.selected_option)     AS shared_wrong
    FROM student_answers sa1
    JOIN exam_attempts ea1 ON ea1.attempt_id = sa1.attempt_id AND ea1.exam_id = p_exam_id
    JOIN student_answers sa2 ON sa2.question_id = sa1.question_id
    JOIN exam_attempts ea2 ON ea2.attempt_id = sa2.attempt_id AND ea2.exam_id = p_exam_id
    JOIN questions q        ON q.question_id  = sa1.question_id
    JOIN students  s2       ON s2.student_id  = ea2.student_id
    WHERE ea1.student_id < ea2.student_id
      AND ea1.status = 'submitted'
      AND ea2.status = 'submitted'
    GROUP BY ea1.attempt_id, ea1.student_id, ea2.attempt_id, ea2.student_id, s2.full_name, s2.student_id_no
    HAVING total_wrong_a > 0
       AND (shared_wrong / total_wrong_a) >= 0.70
  ) pair
  ON DUPLICATE KEY UPDATE
    risk_score   = VALUES(risk_score),
    description  = VALUES(description),
    matched_with = VALUES(matched_with);

  -- Escalate risk level for multi-flag students
  UPDATE cheating_flags cf
  JOIN (
    SELECT
      student_id,
      exam_id,
      COUNT(DISTINCT flag_type) AS flag_variety,
      MAX(risk_score)           AS max_score
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

END//

CREATE PROCEDURE sp_detect_all_exams()
BEGIN
  DECLARE done  INT DEFAULT 0;
  DECLARE v_eid INT UNSIGNED;
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
END//

CREATE PROCEDURE sp_rerun_detection(IN p_exam_id INT UNSIGNED)
BEGIN
  DELETE FROM cheating_flags WHERE exam_id = p_exam_id;
  CALL sp_detect_cheating(p_exam_id);
END//

DELIMITER ;

-- VIEWS
CREATE OR REPLACE VIEW v_student_risk_profile AS
SELECT
  s.student_id, s.full_name, s.student_id_no, s.email,
  d.dept_name, d.dept_code,
  COUNT(DISTINCT cf.flag_id)     AS total_flags,
  COUNT(DISTINCT cf.exam_id)     AS exams_flagged,
  SUM(cf.risk_level = 'high')   AS high_flags,
  SUM(cf.risk_level = 'medium') AS medium_flags,
  SUM(cf.risk_level = 'low')    AS low_flags,
  MAX(cf.risk_score)            AS peak_risk_score,
  ROUND(AVG(cf.risk_score), 1)  AS avg_risk_score,
  COUNT(DISTINCT cf.flag_type)  AS distinct_flag_types,
  CASE
    WHEN SUM(cf.risk_level = 'high') >= 2   THEN 'CRITICAL'
    WHEN SUM(cf.risk_level = 'high') = 1    THEN 'HIGH'
    WHEN SUM(cf.risk_level = 'medium') >= 2 THEN 'MEDIUM'
    WHEN COUNT(cf.flag_id) > 0             THEN 'LOW'
    ELSE 'CLEAN'
  END                           AS overall_risk,
  MAX(cf.detected_at)           AS last_detected,
  SUM(cf.action_taken = 'none') AS pending_actions,
  s.is_blocked
FROM students s
JOIN departments d ON d.department_id = s.department_id
LEFT JOIN cheating_flags cf ON cf.student_id = s.student_id
GROUP BY s.student_id, d.dept_name, d.dept_code;

CREATE OR REPLACE VIEW v_exam_cheat_health AS
SELECT
  e.exam_id, e.title, e.status, e.scheduled_start,
  t.full_name AS teacher_name, d.dept_name,
  COUNT(DISTINCT ea.attempt_id)  AS total_attempts,
  COUNT(DISTINCT cf.student_id)  AS flagged_students,
  COUNT(DISTINCT cf.flag_id)     AS total_flags,
  SUM(cf.risk_level = 'high')   AS high_risk_flags,
  ROUND(COUNT(DISTINCT cf.student_id) / NULLIF(COUNT(DISTINCT ea.attempt_id),0) * 100, 1) AS cheat_rate_pct,
  GREATEST(0, ROUND(
    100 - (COUNT(DISTINCT cf.flag_id) / NULLIF(COUNT(DISTINCT ea.attempt_id),0) * 50)
        - (SUM(cf.risk_level='high')  / NULLIF(COUNT(DISTINCT ea.attempt_id),0) * 30), 1
  )) AS integrity_score
FROM exams e
JOIN teachers    t ON t.teacher_id   = e.teacher_id
JOIN departments d ON d.department_id = e.department_id
LEFT JOIN exam_attempts  ea ON ea.exam_id = e.exam_id AND ea.status = 'submitted'
LEFT JOIN cheating_flags cf ON cf.exam_id = e.exam_id
GROUP BY e.exam_id, t.full_name, d.dept_name;

CREATE OR REPLACE VIEW v_ip_network AS
SELECT
  ea1.ip_address,
  ea1.student_id AS student_a, s1.full_name AS name_a,
  ea2.student_id AS student_b, s2.full_name AS name_b,
  COUNT(DISTINCT ea1.exam_id) AS shared_exams,
  GROUP_CONCAT(DISTINCT e.title ORDER BY e.title SEPARATOR ' | ') AS exam_list
FROM exam_attempts ea1
JOIN exam_attempts ea2 ON ea2.ip_address = ea1.ip_address
                       AND ea2.exam_id   = ea1.exam_id
                       AND ea2.student_id > ea1.student_id
JOIN students s1 ON s1.student_id = ea1.student_id
JOIN students s2 ON s2.student_id = ea2.student_id
JOIN exams    e  ON e.exam_id     = ea1.exam_id
GROUP BY ea1.ip_address, ea1.student_id, s1.full_name, ea2.student_id, s2.full_name;

CREATE OR REPLACE VIEW v_answer_similarity AS
SELECT
  ea1.exam_id,
  ea1.student_id AS student_a, s1.full_name AS name_a,
  ea2.student_id AS student_b, s2.full_name AS name_b,
  COUNT(*) AS total_questions,
  SUM(sa1.selected_option = sa2.selected_option) AS matching_answers,
  SUM(sa1.selected_option != q.correct_option AND sa1.selected_option = sa2.selected_option) AS matching_wrong,
  ROUND(SUM(sa1.selected_option = sa2.selected_option) / COUNT(*) * 100, 2) AS similarity_pct
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
