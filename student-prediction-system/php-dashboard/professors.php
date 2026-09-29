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
    if (isset($_POST['add_professor'])) {
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
    } elseif (isset($_POST['delete_professor'])) {
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
$sql = "SELECT * FROM tbl_professors";

if ($q !== '') {
    $sql .= ' WHERE full_name LIKE ? OR department LIKE ? OR email LIKE ?';
    $stmt = db()->prepare($sql . ' ORDER BY full_name ASC');
    $like = '%' . $q . '%';
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $professors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $professors = db()->query($sql . ' ORDER BY full_name ASC')->fetch_all(MYSQLI_ASSOC);
}

page_header('Professors Management');
?>

<section class="page-heading">
    <div>
        <p class="eyebrow">Faculty Management</p>
        <h1>Professors List</h1>
    </div>
</section>

<?php if ($error): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 12px; margin-bottom: 20px; border-radius: 6px;">
        <?= h($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px; margin-bottom: 20px; border-radius: 6px;">
        <?= h($success) ?>
    </div>
<?php endif; ?>

<section class="layout-two" style="align-items: flex-start; gap: 2rem;">
    <article class="panel">
        <div class="panel-title">
            <h2>Add New Professor</h2>
        </div>
        <form method="POST" action="professors.php">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Full Name</label>
                <input type="text" name="full_name" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required placeholder="e.g. Juan Dela Cruz, Ph.D.">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Department</label>
                <input type="text" name="department" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required placeholder="Information Technology Department">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Email Address</label>
                <input type="email" name="email" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required placeholder="e.g. jdelacruz@arellano.edu.ph">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Login Username</label>
                <input type="text" name="username" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required placeholder="e.g. jdelacruz">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Temporary Password</label>
                <input type="password" name="password" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required placeholder="••••••••">
            </div>
            <button type="submit" name="add_professor" class="button" style="width: 100%; padding: 10px; background: #004080; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Add Professor & Create Account</button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2>Registered Professors</h2>
            <span>Total: <?= count($professors) ?></span>
        </div>

        <form method="get" style="display: flex; gap: 8px; margin-bottom: 1rem;">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search professors..." style="flex: 1; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 14px;">
            <button type="submit" class="button button-secondary" style="padding: 8px 14px; background: #e2e8f0; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">Search</button>
            <?php if ($q !== ''): ?>
                <a href="professors.php" class="button" style="padding: 8px 12px; background: #cbd5e1; color: #334155; text-decoration: none; border-radius: 4px; font-size: 14px; display: inline-flex; align-items: center;">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($professors)): ?>
                        <tr><td colspan="4" class="empty">No registered professors found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($professors as $prof): ?>
                        <tr>
                            <td><strong><?= h($prof['full_name']) ?></strong></td>
                            <td><?= h($prof['department']) ?></td>
                            <td><?= h($prof['email']) ?></td>
                            <td style="text-align: right;">
                                <form method="POST" action="professors.php" onsubmit="return confirm('Are you sure you want to delete this professor?');" style="margin: 0;">
                                    <input type="hidden" name="professor_id" value="<?= $prof['id'] ?>">
                                    <button type="submit" name="delete_professor" class="button button-secondary" style="padding: 6px 12px; font-size: 12px; background: #fee2e2; color: #991b1b; border: 1px solid #f87171; border-radius: 4px; cursor: pointer;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php page_footer(); ?>