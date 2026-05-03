<?php
// debug_detect.php — place in smart_exam/ root, run once, then DELETE IT
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';

$db = Database::getConnection();

$examId = (int)($_GET['exam_id'] ?? 0);
if (!$examId) {
    // Show all exams to pick from
    $exams = $db->query("SELECT exam_id, title FROM exams ORDER BY exam_id DESC")->fetchAll();
    echo "<h2>Pick an exam:</h2><ul>";
    foreach ($exams as $e) {
        echo "<li><a href='?exam_id={$e['exam_id']}'>{$e['title']} (id={$e['exam_id']})</a></li>";
    }
    echo "</ul>";
    exit;
}

echo "<h2>Debug Detection for exam_id=$examId</h2><pre>";

// 1. Show attempts
echo "=== ATTEMPTS ===\n";
$rows = $db->prepare("SELECT attempt_id, student_id, status, time_taken_secs, score, ip_address FROM exam_attempts WHERE exam_id = ?");
$rows->execute([$examId]);
print_r($rows->fetchAll());

// 2. Check flags before
echo "\n=== FLAGS BEFORE ===\n";
$f = $db->prepare("SELECT COUNT(*) FROM cheating_flags WHERE exam_id = ?");
$f->execute([$examId]);
echo "Count: " . $f->fetchColumn() . "\n";

// 3. Call the procedure and catch real error
echo "\n=== CALLING sp_detect_cheating($examId) ===\n";
try {
    $db->exec("CALL sp_detect_cheating($examId)");
    echo "SUCCESS - no exception thrown\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "SQLSTATE: " . $e->getCode() . "\n";
}

// 4. Flags after
echo "\n=== FLAGS AFTER ===\n";
$f2 = $db->prepare("SELECT * FROM cheating_flags WHERE exam_id = ?");
$f2->execute([$examId]);
print_r($f2->fetchAll());

// 5. Check fast_submission manually
echo "\n=== MANUAL fast_submission CHECK ===\n";
$manual = $db->prepare("
    SELECT ea.attempt_id, ea.student_id, ea.time_taken_secs,
           e.duration_mins, (e.duration_mins * 60 * 0.25) AS threshold_secs,
           CASE WHEN ea.time_taken_secs < (e.duration_mins * 60 * 0.25)
                THEN 'SHOULD FLAG' ELSE 'ok' END AS verdict
    FROM exam_attempts ea
    JOIN exams e ON e.exam_id = ea.exam_id
    WHERE ea.exam_id = ? AND ea.status = 'submitted'
      AND ea.time_taken_secs IS NOT NULL AND ea.time_taken_secs > 0
");
$manual->execute([$examId]);
print_r($manual->fetchAll());

// 6. Check MySQL version
echo "\n=== MYSQL VERSION ===\n";
echo $db->query("SELECT VERSION()")->fetchColumn() . "\n";

// 7. Check procedure exists
echo "\n=== PROCEDURE EXISTS? ===\n";
$proc = $db->query("SHOW PROCEDURE STATUS WHERE Name = 'sp_detect_cheating'")->fetchAll();
print_r($proc);

echo "</pre>";
echo "<br><b style='color:red'>DELETE THIS FILE after debugging!</b>";
