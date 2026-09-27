<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

if (isset($_POST['reset_password'])) {
    $student_id = (int) $_POST['student_id'];
    $new_password = $_POST['new_password'] ?? '';

    if ($student_id > 0 && !empty($new_password)) {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = db()->prepare("UPDATE tbl_students SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $password_hash, $student_id);
        $stmt->execute();
    }
    redirect_to('students.php');
}

<<<<<<< HEAD
// BACKEND: Handle Assign Advisor Submission
=======
>>>>>>> d29f5ea (Update student prediction system)
if (isset($_POST['assign_advisor'])) {
    $student_id = (int) $_POST['student_id'];
    $advisor_id = (int) $_POST['advisor_id'];
    $stmt = db()->prepare("UPDATE tbl_students SET advisor_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $advisor_id, $student_id);
    $stmt->execute();
    redirect_to('students.php');
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT s.*,
            p.predicted_status,
            p.confidence,
            p.created_at AS predicted_at,
            u.full_name AS advisor_name
        FROM tbl_students s
        LEFT JOIN users u ON u.id = s.advisor_id
        LEFT JOIN tbl_predictions p ON p.id = (
            SELECT MAX(p2.id) FROM tbl_predictions p2 WHERE p2.student_id = s.id
        )";

if ($q !== '') {
    $sql .= ' WHERE s.student_no LIKE ? OR s.full_name LIKE ? OR s.section LIKE ?';
    $stmt = db()->prepare($sql . ' ORDER BY s.full_name ASC');
    $like = '%' . $q . '%';
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $students = db()->query($sql . ' ORDER BY s.full_name ASC')->fetch_all(MYSQLI_ASSOC);
}

$advisors = db()->query("SELECT id, full_name FROM users WHERE role = 'Advisor'")->fetch_all(MYSQLI_ASSOC);
$current_user = current_user();

page_header('Students');
?>

<style>
<<<<<<< HEAD
    /* CSS para sa Reset Modal at Password Input */
=======
>>>>>>> d29f5ea (Update student prediction system)
    .reset-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        z-index: 999;
        align-items: center;
        justify-content: center;
    }

    .reset-modal-card {
        background: #ffffff;
        padding: 28px;
        border-radius: 12px;
        max-width: 420px;
        width: 90%;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
    }

    .pwd-input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
        margin-top: 6px;
    }

    .pwd-input-wrapper input {
        width: 100%;
        padding: 10px 42px 10px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 14px;
    }

    .pwd-toggle-btn {
        position: absolute;
        right: 10px;
        background: none;
        border: none;
        cursor: pointer;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .pwd-toggle-btn:hover {
        color: #2563eb;
    }

    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 24px;
    }
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow">Records</p>
        <h1>Student List</h1>
    </div>
    <a class="button button-primary" href="add_student.php">Add Student</a>
</section>

<form class="toolbar" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search students">
    <button class="button button-secondary" type="submit">Search</button>
</form>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>School No.</th>
                    <th>Student</th>
                    <th>Year / Section</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="4" class="empty">No student records found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= h($student['student_no']) ?></td>
                        <td>
                            <strong><?= h($student['full_name']) ?></strong>
                        </td>
                        <td><?= h($student['year_level'] . ' / ' . $student['section']) ?></td>
                        <td style="text-align: right;">
                            <button type="button" class="button button-secondary" style="padding: 6px 12px; font-size: 12px;"
                                onclick="openResetModal(<?= $student['id'] ?>, '<?= h(addslashes($student['full_name'])) ?>', '<?= h(addslashes($student['student_no'])) ?>')">
                                Reset Password
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<<<<<<< HEAD
<!-- RESET PASSWORD MODAL -->
=======
>>>>>>> d29f5ea (Update student prediction system)
<div id="resetModal" class="reset-modal">
    <div class="reset-modal-card">
        <h3 style="margin: 0 0 6px 0; color: #1e293b; font-size: 20px;">Reset Password</h3>
        <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;" id="modalStudentInfo"></p>

        <form method="POST">
            <input type="hidden" name="student_id" id="modalStudentId">

            <div>
                <label style="font-weight: 600; color: #334155; font-size: 14px;">New Password</label>
                <div class="pwd-input-wrapper">
                    <input type="password" id="modalPasswordInput" name="new_password" placeholder="Enter new password" required>
                    <button type="button" class="pwd-toggle-btn" onclick="toggleModalPassword()">
                        <svg id="modalEyeIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="button button-secondary" onclick="closeResetModal()">Cancel</button>
                <button type="submit" name="reset_password" class="button button-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(id, name, studentNo) {
    document.getElementById('modalStudentId').value = id;
    document.getElementById('modalStudentInfo').innerText = 'Set new password for ' + name + ' (' + studentNo + ')';
    document.getElementById('modalPasswordInput').value = '';
    document.getElementById('resetModal').style.display = 'flex';
}

function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}

function toggleModalPassword() {
    const pwdInput = document.getElementById('modalPasswordInput');
    const eyeIcon = document.getElementById('modalEyeIcon');

    if (pwdInput.type === 'password') {
        pwdInput.type = 'text';
        eyeIcon.innerHTML = `
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
            <line x1="1" y1="1" x2="23" y2="23"></line>
        `;
    } else {
        pwdInput.type = 'password';
        eyeIcon.innerHTML = `
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
        `;
    }
}
</script>

<?php page_footer(); ?>