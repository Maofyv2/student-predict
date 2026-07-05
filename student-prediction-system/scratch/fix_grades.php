<?php
require_once __DIR__ . '/../php-dashboard/bootstrap.php';

$conn = db();
$records = $conn->query("SELECT id, prelim_grade, midterm_grade FROM tbl_academic_records")->fetch_all(MYSQLI_ASSOC);

foreach ($records as $r) {
    $prelim = (float)$r['prelim_grade'];
    $midterm = (float)$r['midterm_grade'];
    
    // Generate SF and Final based on Midterm trend
    $sf = round($midterm + rand(-5, 5), 2);
    $final = round($sf + rand(-5, 5), 2);
    
    // Clamp to 0-100
    $sf = max(0, min(100, $sf));
    $final = max(0, min(100, $final));
    
    $stmt = $conn->prepare("UPDATE tbl_academic_records SET semi_final_grade = ?, final_grade = ? WHERE id = ?");
    $stmt->bind_param('ddi', $sf, $final, $r['id']);
    $stmt->execute();
}

echo "Updated " . count($records) . " records with random SF and Final grades.";
