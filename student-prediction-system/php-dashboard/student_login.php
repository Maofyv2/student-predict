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
    <title>Student Portal Login | Arellano BSIT</title>
    <link rel="icon" type="image/png" href="au.png">
    <link rel="shortcut icon" type="image/png" href="au.png">
    <link rel="apple-touch-icon" href="au.png">
    <link rel="stylesheet" href="assets.css?v=<?= filemtime(__DIR__ . '/assets.css') ?>">
</head>
<body class="login-body" style="background: url('bg.jpg') no-repeat center center fixed; background-size: cover;">
    <main class="login-shell">
        <section class="login-panel">
            <div class="login-brand">
                <img src="au.png" alt="Arellano University Logo" class="login-logo">
                <div>
                    <h1>Student Portal</h1>
                    <p>Access your individual academic evaluations &amp; scores</p>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <form method="post" class="form-stack">
                <label>
                    <span>Student Number</span>
                    <input type="text" name="student_no" id="student_no" placeholder="e.g. 26-0002" required autofocus value="<?= h($_POST['student_no'] ?? '') ?>">
                </label>

                <label>
                    <span>Password</span>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" placeholder="Enter password" required>
                        <button type="button" class="password-toggle-eye" id="togglePwdBtn" onclick="toggleStudentPassword()" aria-label="Show password" title="Show password">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </label>

                <button type="submit" class="button button-primary" style="width: 100%;">Sign In to Portal</button>
            </form>
            
            <div style="text-align: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--line);">
                <p style="margin: 0 0 8px; font-size: 0.825rem; color: var(--muted);">Are you an Academic Advisor or Administrator?</p>
                <a href="index.php" class="button button-secondary" style="width: 100%;">Staff / Advisor Login</a>
            </div>
        </section>
    </main>

    <script>
        function toggleStudentPassword() {
            const passwordInput = document.getElementById('password');
            const btn = document.getElementById('togglePwdBtn');
            if (!passwordInput || !btn) return;

            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';

            const eyeOpen = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
            const eyeSlash = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

            btn.innerHTML = isPassword ? eyeSlash : eyeOpen;
            btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            btn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
        }
    </script>
</body>
</html>