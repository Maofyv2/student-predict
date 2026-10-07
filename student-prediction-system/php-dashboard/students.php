<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$current_user = current_user();
$user = $current_user;
$isAdvisor = ($user['role'] === 'Advisor');
$advisorId = (int)$user['id'];

// --- Reset Password Handler ---
if (isset($_POST['reset_password'])) {
    if ($user['role'] !== 'Admin' && $user['role'] !== 'Advisor') {
        redirect_to('students.php');
    }

    $student_id = (int)($_POST['student_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if ($student_id > 0 && !empty($new_password)) {
        // Enforce ownership if Advisor
        if ($isAdvisor) {
            $chk = db()->prepare("SELECT id FROM tbl_students WHERE id = ? AND (advisor_id = ? OR professor_id = ?)");
            $chk->bind_param('iii', $student_id, $advisorId, $advisorId);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                redirect_to('students.php?error=unauthorized');
            }
        }

        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = db()->prepare("UPDATE tbl_students SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $password_hash, $student_id);
        $stmt->execute();
    }
    redirect_to('students.php?msg=pwd_updated');
}

// --- Assign Advisor Handler (Admin Only) ---
if (isset($_POST['assign_advisor']) && $user['role'] === 'Admin') {
    $student_id = (int)$_POST['student_id'];
    $assigned_advisor_id = (int)$_POST['advisor_id'];
    $stmt = db()->prepare("UPDATE tbl_students SET advisor_id = ?, professor_id = ? WHERE id = ?");
    $stmt->bind_param('iii', $assigned_advisor_id, $assigned_advisor_id, $student_id);
    $stmt->execute();
    redirect_to('students.php?msg=assigned');
}

// --- Delete Student Handler (Admin Only) ---
if (isset($_POST['delete_student']) && $user['role'] === 'Admin') {
    $student_id = (int)$_POST['student_id'];
    if ($student_id > 0) {
        $stmt = db()->prepare("DELETE FROM tbl_students WHERE id = ?");
        $stmt->bind_param('i', $student_id);
        $stmt->execute();
    }
    redirect_to('students.php?msg=deleted');
}

// --- Fetch Students with Search and Permission Scoping ---
$q          = trim($_GET['q'] ?? '');
$filterYear = trim($_GET['year_level'] ?? '');
$filterSec  = trim($_GET['section'] ?? '');

// Fetch distinct year levels and sections for filter dropdowns
$allYearLevels = db()->query("SELECT DISTINCT year_level FROM tbl_students ORDER BY year_level ASC")->fetch_all(MYSQLI_ASSOC);
$allSections   = db()->query("SELECT DISTINCT section FROM tbl_students ORDER BY section ASC")->fetch_all(MYSQLI_ASSOC);

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

$conditions = [];
$params = [];
$types = '';

// Ownership: Advisors can only view/search their own students
if ($isAdvisor) {
    $conditions[] = "(s.advisor_id = ? OR s.professor_id = ?)";
    $params[] = $advisorId;
    $params[] = $advisorId;
    $types .= 'ii';
}

// Search by Student Name OR Student ID (partial match)
if ($q !== '') {
    $conditions[] = "(s.full_name LIKE ? OR s.student_no LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

// Filter by Year Level
if ($filterYear !== '') {
    $conditions[] = "s.year_level = ?";
    $params[] = $filterYear;
    $types .= 's';
}

// Filter by Section
if ($filterSec !== '') {
    $conditions[] = "s.section = ?";
    $params[] = $filterSec;
    $types .= 's';
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY s.year_level ASC, s.section ASC, s.full_name ASC";

if (!empty($params)) {
    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $students = db()->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$advisors = db()->query("SELECT id, full_name FROM users WHERE role = 'Advisor' AND is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

page_header('Students');
?>

<style>
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

    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 24px;
    }
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $isAdvisor ? 'Assigned Students' : 'Records Management' ?></p>
        <h1><?= $isAdvisor ? 'My Assigned Students' : 'All Students List' ?></h1>
    </div>
    <?php if ($user['role'] === 'Admin'): ?>
        <a class="button button-primary" href="add_student.php">Add Student</a>
    <?php endif; ?>
</section>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'added'): ?>
        <div class="alert alert-success">Student successfully registered and assigned.</div>
    <?php elseif ($_GET['msg'] === 'pwd_updated'): ?>
        <div class="alert alert-success">Student password successfully updated.</div>
    <?php elseif ($_GET['msg'] === 'deleted'): ?>
        <div class="alert alert-success">Student record deleted.</div>
    <?php elseif ($_GET['msg'] === 'assigned'): ?>
        <div class="alert alert-success">Professor assignment updated.</div>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
    <div class="alert alert-error">Access denied: You can only manage students assigned to you.</div>
<?php endif; ?>

<form class="toolbar" method="get" action="students.php" id="filter-form">
    <div style="display:flex;gap:8px;width:100%;flex-wrap:wrap;align-items:center;">
        <input type="search" name="q" value="<?= h($q) ?>"
               placeholder="Search by name or student ID…"
               style="flex:1;min-width:180px;">

        <select name="year_level" id="filter_year"
                style="padding:8px 10px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-size:.9rem;min-width:140px;"
                onchange="this.form.submit()">
            <option value="">All Year Levels</option>
            <?php foreach ($allYearLevels as $yl): ?>
                <option value="<?= h($yl['year_level']) ?>"
                    <?= ($filterYear === $yl['year_level']) ? 'selected' : '' ?>>
                    <?= h($yl['year_level']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="section" id="filter_section"
                style="padding:8px 10px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-size:.9rem;min-width:130px;"
                onchange="this.form.submit()">
            <option value="">All Sections</option>
            <?php foreach ($allSections as $sec): ?>
                <option value="<?= h($sec['section']) ?>"
                    <?= ($filterSec === $sec['section']) ? 'selected' : '' ?>>
                    <?= h($sec['section']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="button button-primary" type="submit">Search</button>

        <?php if ($q !== '' || $filterYear !== '' || $filterSec !== ''): ?>
            <a href="students.php" class="button button-secondary">Clear Filters</a>
        <?php endif; ?>
    </div>

    <?php if ($filterYear !== '' || $filterSec !== ''): ?>
        <div style="margin-top:8px;font-size:.83rem;color:var(--muted);">
            Showing:
            <?php if ($filterYear !== ''): ?>
                <strong><?= h($filterYear) ?></strong>
            <?php endif; ?>
            <?php if ($filterSec !== ''): ?>
                — Section <strong><?= h($filterSec) ?></strong>
            <?php endif; ?>
            &nbsp;·&nbsp; <?= count($students) ?> student<?= count($students) !== 1 ? 's' : '' ?> found
        </div>
    <?php endif; ?>
</form>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width: 130px;">Student ID</th>
                    <th>Student Name</th>
                    <th>Year / Section</th>
                    <?php if (!$isAdvisor): ?>
                        <th>Assigned Professor</th>
                    <?php endif; ?>
                    <th>Latest Prediction</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="<?= $isAdvisor ? '5' : '6' ?>" class="empty">
                            <?php if ($q !== ''): ?>
                                No students found matching "<?= h($q) ?>" <?= $isAdvisor ? 'in your assigned list' : '' ?>.
                            <?php else: ?>
                                <?= $isAdvisor ? 'You have no assigned students yet.' : 'No student records found.' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td>
                            <span class="pill status-muted"><?= h($student['student_no']) ?></span>
                        </td>
                        <td>
                            <strong><?= h($student['full_name']) ?></strong>
                        </td>
                        <td><?= h($student['year_level'] . ' • ' . $student['section']) ?></td>
                        <?php if (!$isAdvisor): ?>
                            <td>
                                <?php if (!empty($student['advisor_name'])): ?>
                                    <span style="font-weight: 500; color: var(--text);"><?= h($student['advisor_name']) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 0.8rem; font-style: italic;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <?php if (!empty($student['predicted_status'])): ?>
                                <span class="status-badge <?= severity_class($student['predicted_status'] === 'Pass' ? 'Medium' : ($student['predicted_status'] === 'At-Risk' ? 'High' : 'Critical')) ?>">
                                    <?= h($student['predicted_status']) ?>
                                </span>
                                <?php if (!empty($student['confidence'])): ?>
                                    <small style="color: var(--muted); margin-top: 2px;">
                                        <?= round((float)$student['confidence'] * 100, 1) ?>% conf
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--muted); font-size: 0.8rem;">Not predicted</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                <?php if ($isAdvisor): ?>
                                    <a href="enter_scores.php?student_id=<?= $student['id'] ?>" class="button button-primary" style="padding: 5px 12px; font-size: 12px; text-decoration: none;">
                                        Enter Scores &amp; Predict
                                    </a>
                                <?php endif; ?>

                                <button type="button" class="button button-secondary" style="padding: 5px 10px; font-size: 12px;"
                                    onclick="openResetModal(<?= $student['id'] ?>, '<?= h(addslashes($student['full_name'])) ?>', '<?= h(addslashes($student['student_no'])) ?>')">
                                    Reset Password
                                </button>

                                <?php if ($user['role'] === 'Admin'): ?>
                                    <form method="POST" action="students.php" onsubmit="return confirm('Are you sure you want to delete this student record?');" style="margin: 0; display: inline;">
                                        <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                        <button type="submit" name="delete_student" class="button button-secondary" style="padding: 5px 10px; font-size: 12px; background: #fee2e2; color: #991b1b; border: 1px solid #f87171; border-radius: 4px; cursor: pointer;">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Reset Password Modal with Eye Icon -->
<div id="resetModal" class="reset-modal">
    <div class="reset-modal-card">
        <h3 style="margin: 0 0 6px 0; color: #1e293b; font-size: 20px;">Reset Student Password</h3>
        <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;" id="modalStudentInfo"></p>

        <form method="POST">
            <input type="hidden" name="student_id" id="modalStudentId">

            <div>
                <label style="font-weight: 600; color: #334155; font-size: 14px; display: block; margin-bottom: 4px;">New Password</label>
                <div class="password-input-wrapper">
                    <input type="password" id="modalPasswordInput" name="new_password" placeholder="Enter new password" required>
                    <button type="button" class="password-toggle-eye" id="modalPwdToggleBtn" onclick="toggleModalPassword()" aria-label="Show password" title="Show password">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="button button-outline" onclick="closeResetModal()">Cancel</button>
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
    document.getElementById('modalPasswordInput').type = 'password';
    document.getElementById('resetModal').style.display = 'flex';
}

function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}

function toggleModalPassword() {
    const pwdInput = document.getElementById('modalPasswordInput');
    const btn = document.getElementById('modalPwdToggleBtn');
    if (!pwdInput || !btn) return;

    const isPassword = pwdInput.type === 'password';
    pwdInput.type = isPassword ? 'text' : 'password';

    const eyeOpen = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
    const eyeSlash = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

    btn.innerHTML = isPassword ? eyeSlash : eyeOpen;
    btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    btn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
}
</script>

<?php page_footer(); ?>