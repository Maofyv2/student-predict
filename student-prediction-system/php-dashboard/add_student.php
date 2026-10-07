<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if (!$user || !in_array($user['role'], ['Admin', 'Advisor'])) {
    redirect_to('students.php');
}

$conn = db();
$error_message = '';

// Load available professors from users table
$professors = db()->query("SELECT id, full_name, username FROM users WHERE role = 'Advisor' AND is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $school_no = trim($_POST['school_no'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $password = $_POST['password'] ?? '';

    // Assign professor: Admin selects, Advisor assigns self
    if ($user['role'] === 'Admin') {
        $advisor_id = (int)($_POST['advisor_id'] ?? 0);
    } else {
        $advisor_id = (int)$user['id'];
    }

    if (empty($fullname) || empty($school_no) || empty($password) || empty($year_level) || empty($section)) {
        $error_message = 'Please fill in all required fields.';
    } elseif ($user['role'] === 'Admin' && $advisor_id <= 0) {
        $error_message = 'Please select an assigned professor for this student.';
    } else {
        // Check if student_no already exists
        $chk = $conn->prepare("SELECT id FROM tbl_students WHERE student_no = ? LIMIT 1");
        $chk->bind_param('s', $school_no);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $error_message = "Student number '{$school_no}' is already registered.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO tbl_students (student_no, full_name, year_level, section, gender, password_hash, advisor_id, professor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssii", $school_no, $fullname, $year_level, $section, $gender, $password_hash, $advisor_id, $advisor_id);

            if ($stmt->execute()) {
                redirect_to("students.php?msg=added");
            } else {
                $error_message = 'Error adding student: ' . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Student - Prediction System</title>
    <link rel="icon" type="image/png" href="au.png">
    <link rel="shortcut icon" type="image/png" href="au.png">
    <link rel="apple-touch-icon" href="au.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-image: url('bg.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .form-container {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            padding: 30px 35px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 480px;
        }

        .form-header {
            margin-bottom: 25px;
            text-align: center;
        }

        .form-header h2 {
            color: #1e293b;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-header p {
            color: #64748b;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: 14px;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            background-color: #fff;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .password-toggle-eye {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            border-radius: 4px;
            transition: color 0.15s ease;
        }

        .password-toggle-eye:hover {
            color: #1e293b;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .btn-container {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background-color: #1e3a8a;
            color: white;
        }

        .btn-primary:hover {
            background-color: #172554;
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }
    </style>
</head>

<body>

    <div class="form-container">
        <div class="form-header">
            <h2>Add New Student</h2>
            <p>Fill in the required information below.</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div style="background-color: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px;">
                <?= h($error_message) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="form-group">
                <label for="fullname">Student Name</label>
                <input type="text" id="fullname" name="fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required value="<?= h($_POST['fullname'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="school_no">Student ID / Student Number</label>
                <input type="text" id="school_no" name="school_no" class="form-control" placeholder="e.g. 2024-00123" required value="<?= h($_POST['school_no'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Student Password</label>
                <div class="password-wrapper">
                    <input type="password" id="studentPassword" name="password" class="form-control" placeholder="Assign initial password" required>
                    <button type="button" class="password-toggle-eye" id="togglePwdBtn" onclick="togglePasswordVisibility()" aria-label="Show password" title="Show password">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="year_level">Year Level</label>
                <select id="year_level" name="year_level" class="form-control" required>
                    <option value="" disabled <?= empty($_POST['year_level']) ? 'selected' : '' ?>>Select Year Level</option>
                    <option value="1st Year" <?= (($_POST['year_level'] ?? '') === '1st Year') ? 'selected' : '' ?>>1st Year</option>
                    <option value="2nd Year" <?= (($_POST['year_level'] ?? '') === '2nd Year') ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3rd Year" <?= (($_POST['year_level'] ?? '') === '3rd Year') ? 'selected' : '' ?>>3rd Year</option>
                    <option value="4th Year" <?= (($_POST['year_level'] ?? '') === '4th Year') ? 'selected' : '' ?>>4th Year</option>
                </select>
            </div>

            <!-- SECTION LIMIT: ONLY 5 SECTIONS -->
            <div class="form-group">
                <label for="section">Section (5 Sections Available)</label>
                <select id="section" name="section" class="form-control" required>
                    <option value="" disabled <?= empty($_POST['section']) ? 'selected' : '' ?>>Select Section</option>
                    <option value="BSIT 1" <?= (($_POST['section'] ?? '') === 'BSIT 1') ? 'selected' : '' ?>>BSIT 1</option>
                    <option value="BSIT 2" <?= (($_POST['section'] ?? '') === 'BSIT 2') ? 'selected' : '' ?>>BSIT 2</option>
                    <option value="BSIT 3" <?= (($_POST['section'] ?? '') === 'BSIT 3') ? 'selected' : '' ?>>BSIT 3</option>
                    <option value="BSIT 4" <?= (($_POST['section'] ?? '') === 'BSIT 4') ? 'selected' : '' ?>>BSIT 4</option>
                    <option value="BSIT 5" <?= (($_POST['section'] ?? '') === 'BSIT 5') ? 'selected' : '' ?>>BSIT 5</option>
                </select>
            </div>

            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender" name="gender" class="form-control" required>
                    <option value="" disabled <?= empty($_POST['gender']) ? 'selected' : '' ?>>Select Gender</option>
                    <option value="Male" <?= (($_POST['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                </select>
            </div>

            <!-- PROFESSOR ASSIGNMENT -->
            <div class="form-group">
                <label for="advisor_id">Assigned Professor <span style="color: #ef4444;">*</span></label>
                <?php if ($user['role'] === 'Admin'): ?>
                    <select id="advisor_id" name="advisor_id" class="form-control" required>
                        <option value="" disabled <?= empty($_POST['advisor_id']) ? 'selected' : '' ?>>-- Select Professor --</option>
                        <?php foreach ($professors as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= ((int)($_POST['advisor_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                                <?= h($p['full_name']) ?> (<?= h($p['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="advisor_id" value="<?= (int)$user['id'] ?>">
                    <input type="text" class="form-control" value="<?= h($user['full_name']) ?> (You)" readonly style="background: #f1f5f9; cursor: not-allowed;">
                <?php endif; ?>
            </div>

            <div class="btn-container">
                <a href="students.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Student</button>
            </div>
        </form>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('studentPassword');
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