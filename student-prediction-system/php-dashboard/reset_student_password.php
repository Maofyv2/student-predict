<?php
include('db.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if ($student_id > 0 && !empty($new_password)) {
        // Secure Hashing sa Bagong Password
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "UPDATE tbl_students SET password_hash = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $new_password_hash, $student_id);

        if (mysqli_stmt_execute($stmt)) {
            header("Location: students.php?msg=password_updated");
            exit();
        } else {
            header("Location: students.php?error=update_failed");
            exit();
        }
    }
}

header("Location: students.php");
exit();