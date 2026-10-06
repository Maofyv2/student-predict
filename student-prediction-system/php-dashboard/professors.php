<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();

if ($user['role'] !== 'Admin' && $user['role'] !== 'Advisor') {
    redirect_to('dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_professor']) && $user['role'] === 'Admin') {
        $full_name = trim($_POST['full_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($full_name === '' || $department === '' || $email === '' || $username === '' || $password === '') {
            $error = 'All fields must be filled.';
        } else {
            $added = add_professor($full_name, $department, $email, $username, $password);
            if ($added) {
                $success = 'Professor added successfully!';
            } else {
                $error = 'Error saving professor or email/username already exists.';
            }
        }
    } elseif (isset($_POST['edit_professor'])) {
        $professor_id = (int)($_POST['professor_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $new_password = trim($_POST['new_password'] ?? '');

        if ($user['role'] === 'Advisor') {
            $stmtProfCheck = db()->prepare("SELECT id FROM tbl_professors WHERE id = ? AND (user_id = ? OR full_name = ?)");
            $stmtProfCheck->bind_param('iis', $professor_id, $user['id'], $user['full_name']);
            $stmtProfCheck->execute();
            if ($stmtProfCheck->get_result()->num_rows === 0) {
                $error = 'Unauthorized to edit this professor profile.';
            }
        }

        if (empty($error)) {
            if ($full_name === '' || $department === '' || $email === '' || $username === '') {
                $error = 'Name, department, email, and username cannot be blank.';
            } else {
                $updated = update_professor($professor_id, $full_name, $department, $email, $username, $new_password);
                if ($updated) {
                    $success = 'Professor profile and password updated successfully!';
                } else {
                    $error = 'Failed to update professor. Username or email may already be in use.';
                }
            }
        }
    } elseif (isset($_POST['delete_professor']) && $user['role'] === 'Admin') {
        $professor_id = (int) $_POST['professor_id'];
        if ($professor_id > 0) {
            $stmt = db()->prepare("DELETE FROM tbl_professors WHERE id = ?");
            $stmt->bind_param('i', $professor_id);
            if ($stmt->execute()) {
                $success = 'Professor deleted successfully.';
            } else {
                $error = 'Error deleting professor.';
            }
        }
    }
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT p.*, u.username 
        FROM tbl_professors p 
        LEFT JOIN users u ON u.id = p.user_id";

if ($user['role'] === 'Advisor') {
    $sql .= " WHERE p.user_id = " . (int)$user['id'] . " OR p.full_name = '" . db()->real_escape_string($user['full_name']) . "'";
} elseif ($q !== '') {
    $sql .= ' WHERE p.full_name LIKE ? OR p.department LIKE ? OR p.email LIKE ? OR u.username LIKE ?';
    $stmt = db()->prepare($sql . ' ORDER BY p.full_name ASC');
    $like = '%' . $q . '%';
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $professors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if (!isset($professors)) {
    $professors = db()->query($sql . ' ORDER BY p.full_name ASC')->fetch_all(MYSQLI_ASSOC);
}

page_header('Faculty Management');
?>

<style>
    .edit-modal {
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

    .edit-modal-card {
        background: #ffffff;
        padding: 28px;
        border-radius: 12px;
        max-width: 480px;
        width: 90%;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
    }
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $user['role'] === 'Admin' ? 'Faculty & Advisors' : 'My Faculty Profile' ?></p>
        <h1><?= $user['role'] === 'Admin' ? 'Professors List' : 'Faculty Account Settings' ?></h1>
    </div>
</section>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= h($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= h($success) ?>
    </div>
<?php endif; ?>

<section class="<?= $user['role'] === 'Admin' ? 'layout-two' : '' ?>" style="align-items: flex-start; gap: 2rem;">
    <?php if ($user['role'] === 'Admin'): ?>
        <article class="panel">
            <div class="panel-title">
                <h2>Add New Professor</h2>
            </div>
            <form method="POST" action="professors.php">
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Full Name</label>
                    <input type="text" name="full_name" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;" required placeholder="e.g. Dr. Juan Dela Cruz">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Department</label>
                    <input type="text" name="department" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;" required placeholder="Information Technology">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Email Address</label>
                    <input type="email" name="email" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;" required placeholder="jdelacruz@arellano.edu.ph">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Login Username</label>
                    <input type="text" name="username" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;" required placeholder="jdelacruz">
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Initial Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="addProfPassword" class="form-control" style="width: 100%; padding: 8px 42px 8px 8px; border: 1px solid #cbd5e1; border-radius: 4px;" required placeholder="••••••••">
                        <button type="button" class="password-toggle-eye" onclick="togglePasswordVisibility('addProfPassword', this)" aria-label="Show password" title="Show password">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="submit" name="add_professor" class="button button-primary" style="width: 100%;">Add Professor &amp; Create Account</button>
            </form>
        </article>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2><?= $user['role'] === 'Admin' ? 'Registered Faculty' : 'My Professor Profile' ?></h2>
            <span>Total: <?= count($professors) ?></span>
        </div>

        <?php if ($user['role'] === 'Admin'): ?>
            <form method="get" style="display: flex; gap: 8px; margin-bottom: 1rem;">
                <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search professors by name, email or department..." style="flex: 1; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 14px;">
                <button type="submit" class="button button-primary" style="font-size: 14px;">Search</button>
                <?php if ($q !== ''): ?>
                    <a href="professors.php" class="button button-secondary" style="font-size: 14px;">Clear</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Username</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($professors)): ?>
                        <tr><td colspan="5" class="empty">No registered professors found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($professors as $prof): ?>
                        <tr>
                            <td><strong><?= h($prof['full_name']) ?></strong></td>
                            <td><?= h($prof['department']) ?></td>
                            <td><?= h($prof['email']) ?></td>
                            <td><span class="pill status-muted"><?= h($prof['username'] ?? 'advisor') ?></span></td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                    <button type="button" class="button button-secondary" style="padding: 5px 10px; font-size: 12px;"
                                        onclick="openEditProfModal(<?= (int)$prof['id'] ?>, '<?= h(addslashes($prof['full_name'])) ?>', '<?= h(addslashes($prof['department'])) ?>', '<?= h(addslashes($prof['email'])) ?>', '<?= h(addslashes($prof['username'] ?? '')) ?>')">
                                        Edit / Password
                                    </button>
                                    <?php if ($user['role'] === 'Admin'): ?>
                                        <form method="POST" action="professors.php" onsubmit="return confirm('Are you sure you want to delete this professor?');" style="margin: 0; display: inline;">
                                            <input type="hidden" name="professor_id" value="<?= $prof['id'] ?>">
                                            <button type="submit" name="delete_professor" class="button button-secondary" style="padding: 5px 10px; font-size: 12px; background: #fee2e2; color: #991b1b; border: 1px solid #f87171; border-radius: 4px; cursor: pointer;">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<!-- Edit Professor & Change Password Modal -->
<div id="editProfModal" class="edit-modal">
    <div class="edit-modal-card">
        <h3 style="margin: 0 0 6px 0; color: #1e293b; font-size: 20px;">Edit Professor &amp; Credentials</h3>
        <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;" id="editProfInfo">Update account information or reset password.</p>

        <form method="POST" action="professors.php">
            <input type="hidden" name="professor_id" id="editProfId">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px;">Full Name</label>
                <input type="text" name="full_name" id="editProfName" required style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px;">Department</label>
                <input type="text" name="department" id="editProfDept" required style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px;">Email Address</label>
                <input type="email" name="email" id="editProfEmail" required style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px;">Username</label>
                <input type="text" name="username" id="editProfUsername" required style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px;">New Password <span style="font-weight: normal; color: #64748b;">(leave blank to keep current)</span></label>
                <div class="password-input-wrapper">
                    <input type="password" name="new_password" id="editProfPassword" placeholder="Enter new password (optional)" style="width: 100%; padding: 8px 42px 8px 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    <button type="button" class="password-toggle-eye" onclick="togglePasswordVisibility('editProfPassword', this)" aria-label="Show password" title="Show password">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="button button-outline" onclick="closeEditProfModal()">Cancel</button>
                <button type="submit" name="edit_professor" class="button button-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditProfModal(id, name, dept, email, username) {
    document.getElementById('editProfId').value = id;
    document.getElementById('editProfName').value = name;
    document.getElementById('editProfDept').value = dept;
    document.getElementById('editProfEmail').value = email;
    document.getElementById('editProfUsername').value = username;
    document.getElementById('editProfPassword').value = '';
    document.getElementById('editProfPassword').type = 'password';
    document.getElementById('editProfModal').style.display = 'flex';
}

function closeEditProfModal() {
    document.getElementById('editProfModal').style.display = 'none';
}

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

<?php page_footer(); ?>