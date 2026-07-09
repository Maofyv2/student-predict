<?php
require_once __DIR__ . '/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_no = trim($_POST['student_no'] ?? '');
    
    $stmt = db()->prepare("SELECT * FROM tbl_students WHERE student_no = ?");
    $stmt->bind_param('s', $student_no);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if ($student) {
        $_SESSION['student'] = $student;
        redirect_to('student_portal.php');
    } else {
        $error = 'Student number not found. Please contact your advisor.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Login | Arellano BSIT</title>
    <link rel="stylesheet" href="assets.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background-image: url('bg.jpg'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;}
        .login-card { width: 100%; max-width: 400px; padding: 2rem; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .login-logo { text-align: center; margin-bottom: 2rem; }
        .login-logo h1 { font-size: 1.5rem; margin: 0; color: #1a1a1a; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
        .form-group input { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; }
        .error { color: #e74c3c; background: #fdf2f2; padding: 0.75rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <h1>Student Portal</h1>
            <p>Access your academic performance</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="student_no">Student Number</label>
                <input type="text" name="student_no" id="student_no" placeholder="e.g. 2021-0001" required autofocus>
            </div>
            <button type="submit" class="button button-primary" style="width: 100%;">Sign In</button>
        </form>
        
        <div style="text-align: center; margin-top: 2rem;">
            <a href="index.php" style="color: var(--text-muted); font-size: 0.9rem;">Back to Advisor Login</a>
        </div>
    </div>
</body>
</html>
