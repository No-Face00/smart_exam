<?php
// api/save_answer.php — AJAX endpoint (JSON in/out)

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/Exam.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireLogin('student');

$body = json_decode(file_get_contents('php://input'), true);

$attemptId  = (int)($body['attempt_id']  ?? 0);
$questionId = (int)($body['question_id'] ?? 0);
$option     = strtoupper(trim($body['option'] ?? ''));

if (!$attemptId || !$questionId || !in_array($option, ['A','B','C','D'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// Verify this attempt belongs to the current student
$db = Database::getConnection();
$check = $db->prepare(
    "SELECT attempt_id FROM exam_attempts WHERE attempt_id = ? AND student_id = ? AND status = 'in_progress'"
);
$check->execute([$attemptId, currentUser()['id']]);
if (!$check->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$exam = new Exam();
$exam->saveAnswer($attemptId, $questionId, $option);

echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
