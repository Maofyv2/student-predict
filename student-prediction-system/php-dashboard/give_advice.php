<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();

// RESTRICTION: Only the Advisor role can give advice.
if ($user['role'] !== 'Advisor') {
    die("Access Denied: Only Academic Advisors can provide advice.");
}

$student_id = (int) ($_GET['student_id'] ?? 0);

if (!$student_id) {
    redirect_to('students.php');
}

// Fetch student details
$stmt = db()->prepare("SELECT * FROM tbl_students WHERE id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    redirect_to('students.php');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $advice_text = trim($_POST['advice_text'] ?? '');
    
    if ($advice_text === '') {
        $error = "Advice text cannot be empty.";
    } else {
        $stmt = db()->prepare("INSERT INTO tbl_advice (student_id, advisor_id, advice_text) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $student_id, $user['id'], $advice_text);
        if ($stmt->execute()) {
            $success = "Advice has been successfully sent to the student.";
        } else {
            $error = "Failed to save advice. Please try again.";
        }
    }
}

$history = get_student_advice($student_id);

page_header('Give Advice');
?>

<section class="page-heading">
    <div>
        <p class="eyebrow">Advisor Action</p>
        <h1>Send Advice to <?= h($student['full_name']) ?></h1>
    </div>
    <a href="students.php" class="button button-secondary">Back to Students</a>
</section>

<?php if ($success): ?>
    <div class="alert alert-pass" style="background: var(--green-bg); color: var(--green); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <?= h($success) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= h($error) ?>
    </div>
<?php endif; ?>

<div class="layout-two">
    <div class="panel">
        <form method="post" class="form-stack">
            <label>
                <span>Advice / Guidance</span>
                <textarea name="advice_text" rows="8" style="width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 12px; font-family: inherit;" placeholder="Write your academic advice, tips, or guidance for this student here..."></textarea>
            </label>
            <div style="margin-top: 1rem;">
                <button type="submit" class="button button-primary">Send Advice</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-title">
            <h2>Advice History</h2>
        </div>
        <?php if (empty($history)): ?>
            <p class="muted">No previous advice sent to this student.</p>
        <?php else: ?>
            <div class="model-list">
                <?php foreach ($history as $row): ?>
                    <div style="flex-direction: column; align-items: flex-start; padding: 15px 0;">
                        <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 8px;">
                            <strong style="font-size: 0.85rem; color: var(--blue);"><?= h($row['advisor_name']) ?></strong>
                            <small class="muted"><?= h(date('M d, Y', strtotime($row['created_at']))) ?></small>
                        </div>
                        <p style="margin: 0; font-size: 0.95rem; line-height: 1.5;"><?= nl2br(h($row['advice_text'])) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php page_footer(); ?>
