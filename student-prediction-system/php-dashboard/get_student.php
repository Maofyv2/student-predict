<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json');

$student_no = trim($_GET['student_no'] ?? '');

if ($student_no === '') {
    echo json_encode(['success' => false, 'message' => 'Empty student number']);
    exit();
}

$conn = db();
$stmt = $conn->prepare("SELECT full_name, year_level, section, gender, scholarship_status, household_income, parental_education, working_student FROM tbl_students WHERE student_no = ? LIMIT 1");
$stmt->bind_param('s', $student_no);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result) {
    echo json_encode(['success' => true, 'data' => $result]);
} else {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
}
exit();