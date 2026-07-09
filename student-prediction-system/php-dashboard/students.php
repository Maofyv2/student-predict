<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

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

if (isset($_POST['assign_advisor'])) {
    $student_id = (int) $_POST['student_id'];
    $advisor_id = (int) $_POST['advisor_id'];
    $stmt = db()->prepare("UPDATE tbl_students SET advisor_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $advisor_id, $student_id);
    $stmt->execute();
    redirect_to('students.php');
}

$advisors = db()->query("SELECT id, full_name FROM users WHERE role = 'Advisor'")->fetch_all(MYSQLI_ASSOC);
$current_user = current_user();

page_header('Students');
?>
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
                </tr>
            </thead>
            <tbody>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="3" class="empty">No student records found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= h($student['student_no']) ?></td>
                        <td>
                            <strong><?= h($student['full_name']) ?></strong>
                        </td>
                        <td><?= h($student['year_level'] . ' / ' . $student['section']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(); ?>