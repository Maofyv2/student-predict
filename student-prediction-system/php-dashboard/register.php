<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect_to('dashboard.php');
}

$error = '';
$success = '';

$max_capacity = 40;
$available_sections = ['BSIT 1', 'BSIT 2', 'BSIT 3', 'BSIT 4', 'BSIT 5'];

function get_reg_section_counts($conn, $available_sections) {
    $counts = [];
    foreach ($available_sections as $sec) {
        $counts[$sec] = 0;
    }
    $secQuery = $conn->query("SELECT section, COUNT(*) as count FROM tbl_students GROUP BY section");
    if ($secQuery) {
        while ($row = $secQuery->fetch_assoc()) {
            $sName = trim($row['section']);
            if (isset($counts[$sName])) {
                $counts[$sName] = (int)$row['count'];
            }
        }
    }
    return $counts;
}

$section_counts = get_reg_section_counts(db(), $available_sections);

// Preview the next student number for the form (non-locking)
$next_student_no = peek_next_student_no(db(), '26');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // student_no is auto-generated server-side; ignore any form value
    $full_name = trim($_POST['full_name'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (empty($full_name) || empty($year_level) || empty($section) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        $conn = db();
        $secCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_students WHERE section = ?");
        $secCheck->bind_param('s', $section);
        $secCheck->execute();
        $current_sec_count = (int)($secCheck->get_result()->fetch_assoc()['cnt'] ?? 0);

        if ($current_sec_count >= $max_capacity) {
            $error = "Section '{$section}' is currently full ({$current_sec_count}/{$max_capacity}). Please select another section.";
            $section_counts = get_reg_section_counts($conn, $available_sections);
        } else {
            // Generate & insert inside a transaction (locked) to prevent race conditions
            $conn->begin_transaction();
            try {
                $student_no = generate_next_student_no($conn, '26');
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO tbl_students (student_no, full_name, year_level, section, gender, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $student_no, $full_name, $year_level, $section, $gender, $password_hash);
                $stmt->execute();
                $conn->commit();
                $success = 'Account created successfully! Your student number is <strong>' . h($student_no) . '</strong>. You can now log in to the student portal.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error registering: ' . $e->getMessage();
            }
            // Refresh preview number after any error
            $next_student_no = peek_next_student_no(db(), '26');
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Registration | Arellano BSIT</title>
    <link rel="icon" type="image/png" href="au.png">
    <link rel="shortcut icon" type="image/png" href="au.png">
    <link rel="apple-touch-icon" href="au.png">
    <link rel="stylesheet" href="assets.css">
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-panel" style="width: min(520px, 100%);">
            <div class="login-brand">
                <img src="au.png" alt="Arellano University Logo" class="login-logo">
                <div>
                    <h1>Student Registration</h1>
                    <p>Arellano University College of Computer Studies</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
                <div style="margin-top: 16px;">
                    <a href="student_login.php" class="button button-primary" style="width: 100%; text-align: center; display: block;">Go to Student Login</a>
                </div>
            <?php else: ?>
                <form method="post" class="form-stack">
                    <label>
                        <span>Student Number <span style="font-size: 11px; font-weight: 400; color: var(--muted);"> (Auto-assigned)</span></span>
                        <input type="text" name="student_no" readonly
                            value="<?= h($next_student_no) ?>"
                            style="background: rgba(241,245,249,0.85); color: var(--muted); cursor: not-allowed; font-weight: 600; letter-spacing: 0.04em;"
                            title="Your student number is automatically assigned by the system">
                    </label>

                    <label>
                        <span>Full Name <strong style="color: var(--danger, #dc2626); font-weight: bold;">*</strong></span>
                        <input type="text" name="full_name" value="<?= h($_POST['full_name'] ?? '') ?>" placeholder="e.g. Juan Dela Cruz" required>
                    </label>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <label>
                            <span>Year Level <strong style="color: var(--danger, #dc2626); font-weight: bold;">*</strong></span>
                            <select name="year_level" required>
                                <option value="">Select Year</option>
                                <option value="1st Year" <?= ($_POST['year_level'] ?? '') === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                                <option value="2nd Year" <?= ($_POST['year_level'] ?? '') === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                                <option value="3rd Year" <?= ($_POST['year_level'] ?? '') === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                                <option value="4th Year" <?= ($_POST['year_level'] ?? '') === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                            </select>
                        </label>

                        <label>
                            <span>Section <strong style="color: var(--danger, #dc2626); font-weight: bold;">*</strong></span>
                            <select name="section" required>
                                <option value="" disabled <?= empty($_POST['section']) ? 'selected' : '' ?>>Select Section</option>
                                <?php foreach ($available_sections as $sec): 
                                    $cnt = $section_counts[$sec] ?? 0;
                                    $isFull = ($cnt >= $max_capacity);
                                    $label = $sec . ' (' . $cnt . '/' . $max_capacity . ($isFull ? ' - FULL' : '') . ')';
                                ?>
                                    <option value="<?= h($sec) ?>" <?= $isFull ? 'disabled' : '' ?> <?= ((($_POST['section'] ?? '') === $sec) && !$isFull) ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <label>
                        <span>Gender</span>
                        <select name="gender">
                            <option value="">Select Gender</option>
                            <option value="Male" <?= ($_POST['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </label>

                    <label>
                        <span>Password <strong style="color: var(--danger, #dc2626); font-weight: bold;">*</strong></span>
                        <div class="password-input-wrapper">
                            <input type="password" name="password" id="regPassword" minlength="6" required>
                            <button type="button" class="password-toggle-eye" onclick="togglePassword('regPassword', this)" aria-label="Show password" title="Show password">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="7" r="0"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </label>

                    <label>
                        <span>Confirm Password <strong style="color: var(--danger, #dc2626); font-weight: bold;">*</strong></span>
                        <div class="password-input-wrapper">
                            <input type="password" name="confirm_password" id="regConfirmPassword" minlength="6" required>
                            <button type="button" class="password-toggle-eye" onclick="togglePassword('regConfirmPassword', this)" aria-label="Show password" title="Show password">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="7" r="0"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </label>

                    <button class="button button-primary" type="submit" style="width: 100%; margin-top: 8px;">Create Account</button>
                </form>
            <?php endif; ?>

            <div style="margin-top: 24px; text-align: center; border-top: 1px solid var(--line); padding-top: 16px;">
                <p style="margin-bottom: 8px; font-size: 0.9rem; color: var(--muted);">Already have an account?</p>
                <div style="display: flex; gap: 8px;">
                    <a href="student_login.php" class="button button-secondary" style="flex: 1; text-align: center;">Student Login</a>
                    <a href="index.php" class="button button-secondary" style="flex: 1; text-align: center;">Staff Login</a>
                </div>
            </div>
        </section>
    </main>

    <script>
    function togglePassword(inputId, btn) {
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
