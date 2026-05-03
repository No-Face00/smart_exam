<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json');

$deptId = (int)($_GET['dept_id'] ?? 0);
if (!$deptId) { echo '[]'; exit; }

$db   = Database::getConnection();
$stmt = $db->prepare("SELECT section_id, section_name FROM sections WHERE department_id = ? ORDER BY section_name");
$stmt->execute([$deptId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
