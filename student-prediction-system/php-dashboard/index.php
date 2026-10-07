<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect_to('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
        redirect_to('dashboard.php');
    }

    $stmtStu = db()->prepare('SELECT * FROM tbl_students WHERE student_no = ? LIMIT 1');
    $stmtStu->bind_param('s', $username);
    $stmtStu->execute();
    $student = $stmtStu->get_result()->fetch_assoc();

    if ($student) {
        $validStu = false;
        if (!empty($student['password_hash'])) {
            $validStu = password_verify($password, $student['password_hash']);
        } elseif ($password === 'student123') {
            $validStu = true;
            $newHash = password_hash('student123', PASSWORD_DEFAULT);
            $uStmt = db()->prepare('UPDATE tbl_students SET password_hash = ? WHERE id = ?');
            $uStmt->bind_param('si', $newHash, $student['id']);
            $uStmt->execute();
        }

        if ($validStu) {
            $_SESSION['student'] = $student;
            redirect_to('student_portal.php');
        }
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Arellano BSIT Prediction System</title>
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
                    <h1>BSIT PORTAL</h1>
                    <p>Arellano University College of Computer Studies</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack">
                <label>
                    <span>Username</span>
                    <input type="text" name="username" value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username" required>
                </label>
                <label>
                    <span>Password</span>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="loginPassword" autocomplete="current-password" required>
                        <button type="button" class="password-toggle-eye" onclick="togglePasswordVisibility('loginPassword', this)" aria-label="Show password" title="Show password">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="7" r="0"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </label>
                <button class="button button-primary" type="submit">Login</button>
            </form>

            <div style="margin-top: 24px; text-align: center; border-top: 1px solid var(--line); padding-top: 16px;">
                <p style="margin-bottom: 8px; font-size: 0.9rem; color: var(--muted);">Are you a student?</p>
                <a href="student_login.php" class="button button-secondary" style="width: 100%;">Go to Student Portal</a>
            </div>
        </section>
    </main>

    <script>
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        const eyeOpen = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
        const eyeSlash = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

        btn.innerHTML = isPassword ? eyeSlash : eyeOpen;
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        btn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
    }
    </script>
</body>
</html>
