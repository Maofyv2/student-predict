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

// Handle Advisor Assignment
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
    <a class="button button-primary" href="predictions.php">Add Prediction</a>
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
                    <th>Student</th>
                    <th>Year / Section</th>
                    <th>Advisor</th>
                    <th>Latest Status</th>
                    <th>Updated</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$students): ?>
                    <tr><td colspan="6" class="empty">No student records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td>
                            <strong><?= h($student['full_name']) ?></strong>
                            <small><?= h($student['student_no']) ?></small>
                        </td>
                        <td><?= h($student['year_level'] . ' / ' . $student['section']) ?></td>
                        <td>
                            <?php if ($student['advisor_id']): ?>
                                <strong><?= h($student['advisor_name']) ?></strong>
                            <?php else: ?>
                                <span class="text-muted">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($student['predicted_status']): ?>
                                <span class="status <?= h(status_class($student['predicted_status'])) ?>"><?= h($student['predicted_status']) ?></span>
                                <small><?= h((string) round((float) $student['confidence'] * 100, 1)) ?>%</small>
                            <?php else: ?>
                                <span class="status status-muted">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $student['predicted_at'] ? h(date('M d, Y', strtotime($student['predicted_at']))) : 'No prediction' ?></td>
                        <td>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <?php if ($current_user['role'] === 'Advisor'): ?>
                                    <a href="give_advice.php?student_id=<?= $student['id'] ?>" class="button button-secondary" style="font-size: 0.75rem; padding: 5px 10px;">Give Advice</a>
                                <?php endif; ?>
                                
                                <?php if ($current_user['role'] === 'Admin'): ?>
                                    <form method="post" style="display:flex; gap: 0.5rem;">
                                        <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                        <select name="advisor_id" style="padding: 2px; font-size: 0.8rem;">
                                            <option value="">Assign Advisor...</option>
                                            <?php foreach ($advisors as $adv): ?>
                                                <option value="<?= $adv['id'] ?>" <?= (int)$student['advisor_id'] === (int)$adv['id'] ? 'selected' : '' ?>><?= h($adv['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="assign_advisor" class="button button-ghost" style="font-size: 0.7rem; padding: 2px 5px;">Save</button>
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
<?php page_footer(); ?>
