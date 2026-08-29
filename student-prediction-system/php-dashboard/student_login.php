<?php
require_once __DIR__ . '/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_no = trim($_POST['student_no'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($student_no) || empty($password)) {
        $error = 'Please enter both student number and password.';
    } else {
        $stmt = db()->prepare("SELECT * FROM tbl_students WHERE student_no = ?");
        $stmt->bind_param('s', $student_no);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();

        if ($student) {
            $is_password_valid = false;

            if (!empty($student['password_hash'])) {
                $is_password_valid = password_verify($password, $student['password_hash']);
            } elseif ($password === 'student123') {
                $is_password_valid = true;
                
                $new_hash = password_hash('student123', PASSWORD_DEFAULT);
                $updateStmt = db()->prepare("UPDATE tbl_students SET password_hash = ? WHERE id = ?");
                $updateStmt->bind_param('si', $new_hash, $student['id']);
                $updateStmt->execute();
            }

            if ($is_password_valid) {
                $_SESSION['student'] = $student;
                redirect_to('student_portal.php');
            } else {
                $error = 'Invalid password. Please try again.';
            }
        } else {
            $error = 'Student number not found. Please contact your advisor.';
        }
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
        .form-group input { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .error { color: #e74c3c; background: #fdf2f2; padding: 0.75rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.9rem; }
        
        /* Password Eye Toggle Styling */
        .password-wrapper { position: relative; display: flex; align-items: center; }
        .password-wrapper input { padding-right: 2.5rem; }
        .toggle-password { position: absolute; right: 0.75rem; background: none; border: none; cursor: pointer; color: #666; display: flex; align-items: center; justify-content: center; padding: 0; }
        .toggle-password:hover { color: #1a1a1a; }
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
                <input type="text" name="student_no" id="student_no" placeholder="e.g. 26-0002" required autofocus value="<?= h($_POST['student_no'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" placeholder="Enter password" required>
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                        <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="button button-primary" style="width: 100%;">Sign In</button>
        </form>
        
        <div style="text-align: center; margin-top: 2rem;">
            <a href="index.php" style="color: var(--text-muted, #666); font-size: 0.9rem;">Back to Advisor Login</a>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                `;
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                `;
            }
        }
    </script>
</body>
</html>