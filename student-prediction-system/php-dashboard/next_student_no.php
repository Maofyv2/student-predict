<?php
/**
 * GET /php-dashboard/next_student_no.php
 * Returns the next auto-generated student number as JSON.
 * Used by the registration/add-student form to display the preview on page load.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$conn = db();
$next = peek_next_student_no($conn, '26');

echo json_encode(['student_no' => $next]);
