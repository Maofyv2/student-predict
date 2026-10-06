<?php
/**
 * AJAX endpoint: Returns a student's existing period grades and profile data.
 * Used by the predictions form to auto-populate previous period grades.
 */
require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json');

$studentNo    = trim($_GET['student_no']    ?? '');
$academicYear = trim($_GET['academic_year'] ?? '');
$semester     = trim($_GET['semester']      ?? '');
$period       = trim($_GET['period']        ?? '');

if ($studentNo === '') {
    echo json_encode(['success' => false, 'message' => 'Empty student number']);
    exit();
}

$conn = db();

// Fetch student profile
$stmt = $conn->prepare(
    'SELECT id, full_name, year_level, section, gender, scholarship_status,
            household_income, parental_education, working_student, advisor_id
     FROM tbl_students WHERE student_no = ? LIMIT 1'
);
$stmt->bind_param('s', $studentNo);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit();
}

$studentId = (int)$student['id'];

// Fetch survey data (latest)
$stmt2 = $conn->prepare(
    'SELECT internet_access, digital_literacy, study_hours
     FROM tbl_surveys WHERE student_id = ? ORDER BY created_at DESC LIMIT 1'
);
$stmt2->bind_param('i', $studentId);
$stmt2->execute();
$survey = $stmt2->get_result()->fetch_assoc() ?: [];

// Fetch existing grade components for this academic year/semester
$periodGrades = [];
if ($academicYear && $semester) {
    $periodGrades = get_student_period_grades($studentId, $academicYear, $semester);
}

// Fetch existing grade components for the requested period (to pre-fill the form)
$periodComponents = null;
if ($academicYear && $semester && $period) {
    $stmt3 = $conn->prepare(
        'SELECT exam_score, quiz_score, activity_score, assignment_score,
                project_score, attendance_rate, lab_score, computed_grade, missing_components
         FROM tbl_grade_components
         WHERE student_id = ? AND academic_year = ? AND semester = ? AND period = ?
         LIMIT 1'
    );
    $stmt3->bind_param('isss', $studentId, $academicYear, $semester, $period);
    $stmt3->execute();
    $periodComponents = $stmt3->get_result()->fetch_assoc();
}

echo json_encode([
    'success'          => true,
    'data'             => [
        'student'          => $student,
        'survey'           => $survey,
        'period_grades'    => $periodGrades,
        'period_components'=> $periodComponents,
    ]
]);
exit();
