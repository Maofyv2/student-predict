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

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Arellano BSIT Prediction System</title>
    <link rel="stylesheet" href="assets.css">
    <link rel="stylesheet" href="css.css">
</head>
<body class="login-body">
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
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="button button-primary" type="submit">Login</button>
            </form>

            <div style="margin-top: 24px; text-align: center; border-top: 1px solid var(--line); padding-top: 16px;">
                <p style="margin-bottom: 8px; font-size: 0.9rem; color: var(--muted);">Are you a student?</p>
                <a href="student_login.php" class="button button-secondary" style="width: 100%;">Go to Student Portal</a>
            </div>
        </section>
    </main>
</body>
</html>
