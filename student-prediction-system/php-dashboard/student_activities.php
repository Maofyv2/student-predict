<?php
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['student'])) {
    redirect_to('student_login.php');
}

$student = $_SESSION['student'];
$student_id = (int) $student['id'];
$success_message = "";
$error_message = "";

$stmtAssignedAdvisor = db()->prepare(
    "SELECT u.id, u.full_name
     FROM tbl_students s
     LEFT JOIN users u ON u.id = s.advisor_id AND u.role = 'Advisor' AND u.is_active = 1
     WHERE s.id = ? LIMIT 1"
);
$stmtAssignedAdvisor->bind_param('i', $student_id);
$stmtAssignedAdvisor->execute();
$assignedAdvisor = $stmtAssignedAdvisor->get_result()->fetch_assoc() ?: [];
$stmtAssignedAdvisor->close();
$current_advisor_name = (string)($assignedAdvisor['full_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_answer'])) {
    $activity_id = (int) ($_POST['activity_id'] ?? 0);
    $submission_text = trim($_POST['submission_text'] ?? '');

    $db_file_path = null;
    $has_file = isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK;

    if ($has_file) {
        $allowed_exts = ['pdf', 'docx', 'doc', 'zip', 'rar', 'png', 'jpg', 'jpeg', 'txt', 'xlsx', 'pptx'];
        $file_ext = strtolower(pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_exts)) {
            $error_message = "Invalid file type. Allowed: " . implode(', ', $allowed_exts);
        } else {
            $upload_dir = __DIR__ . '/uploads/submissions/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = time() . '_' . bin2hex(random_bytes(5)) . '.' . $file_ext;
            $target_file = $upload_dir . $file_name;
            $db_file_path = 'uploads/submissions/' . $file_name;

            if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $target_file)) {
                $error_message = "Failed to upload file to target directory.";
            }
        }
    }

    if (empty($error_message)) {
        if ($db_file_path) {
            $stmt = db()->prepare("UPDATE tbl_student_activities SET status = 'Submitted', submission_file_path = ?, submission_file = ?, submission_text = ?, submitted_at = NOW() WHERE id = ? AND student_id = ?");
            $stmt->bind_param('sssii', $db_file_path, $db_file_path, $submission_text, $activity_id, $student_id);
        } else {
            $stmt = db()->prepare("UPDATE tbl_student_activities SET status = 'Submitted', submission_text = ?, submitted_at = NOW() WHERE id = ? AND student_id = ?");
            $stmt->bind_param('sii', $submission_text, $activity_id, $student_id);
        }

        if ($stmt->execute()) {
            $success_message = "Your activity response has been successfully submitted!";
        } else {
            $error_message = "Failed to update record in database.";
        }
        $stmt->close();
    }
}

$activityColumns = [
    'original_file_name' => 'VARCHAR(255) DEFAULT NULL AFTER file_path',
    'academic_year' => 'VARCHAR(20) DEFAULT NULL',
    'semester' => 'VARCHAR(30) DEFAULT NULL',
    'grading_period' => "ENUM('Prelim','Midterm','Semi-Final','Final') DEFAULT NULL",
    'criterion_component' => 'VARCHAR(60) DEFAULT NULL',
    'criterion_max_score' => 'DECIMAL(6,2) DEFAULT NULL',
    'raw_score' => 'DECIMAL(6,2) DEFAULT NULL',
];
foreach ($activityColumns as $column => $definition) {
    $columnCheck = db()->query("SHOW COLUMNS FROM tbl_student_activities LIKE '{$column}'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        db()->query("ALTER TABLE tbl_student_activities ADD COLUMN {$column} {$definition}");
    }
}

$stmtAct = db()->prepare("SELECT a.*, u.full_name AS advisor_name 
                          FROM tbl_student_activities a 
                          LEFT JOIN users u ON a.assigned_by = u.id 
                          WHERE a.student_id = ? 
                          ORDER BY a.created_at DESC");
$stmtAct->bind_param('i', $student_id);
$stmtAct->execute();
$assigned_activities = $stmtAct->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtAct->close();

$advice_history = get_student_advice($student_id);

page_header("Advisor's Guidance & Activities");
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Student Portal</p>
        <h1>Advisor's Guidance &amp; Activities</h1>
        <?php if ($current_advisor_name !== ''): ?>
            <p class="muted" style="margin:.35rem 0 0;">Assigned advisor: <?= h($current_advisor_name) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div id="alert-message" class="alert alert-success" style="margin-bottom: 20px;">
        <strong>Success:</strong> <?= h($success_message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
    <div id="alert-message" class="alert alert-error" style="margin-bottom: 20px;">
        <strong>Error:</strong> <?= h($error_message) ?>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-title">
        <h2>Advisor's Guidance &amp; Activities</h2>
        <span>Personalized tips and assigned tasks from your instructor</span>
    </div>
    
    <?php if (!empty($assigned_activities)): ?>
        <div style="margin-bottom: 2rem;">
            <h3 style="font-size: 1.1rem; border-bottom: 2px solid #0d6efd; padding-bottom: 0.5rem; margin-bottom: 1rem; color: #0d6efd;">
                Assigned Activities / Tasks
            </h3>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php foreach ($assigned_activities as $act): ?>
                    <div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-left: 5px solid #0d6efd; border-radius: 6px; padding: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.5rem;">
                            <div>
                                <h4 style="margin: 0; font-size: 1.1rem; color: #222;"><?= h($act['title']) ?></h4>
                                <small class="muted">Assigned by: <strong><?= h($act['advisor_name'] ?? 'Instructor') ?></strong> • Posted: <?= date('M d, Y g:i A', strtotime($act['created_at'])) ?></small>
                            </div>
                            <span class="status status-primary" style="padding: 4px 10px; font-size: 0.8rem; border-radius: 12px; background: #e7f1ff; color: #0d6efd; font-weight: bold;">
                                <?= h($act['status']) ?>
                            </span>
                        </div>

                        <div style="margin-top: 0.75rem; color: #444; line-height: 1.5; font-size: 0.95rem;">
                            <?= nl2br(h($act['instructions'])) ?>
                        </div>

                        <?php if (!empty($act['criterion_component'])): ?>
                            <div style="margin-top:.65rem;font-size:.85rem;color:#475569;">
                                Counts toward <strong><?= h($act['criterion_component']) ?></strong>
                                (<?= h($act['grading_period']) ?>, <?= h($act['academic_year']) ?> · <?= h($act['semester']) ?>)
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($act['due_date'])): ?>
                            <div style="margin-top: 0.75rem; font-size: 0.85rem; color: #d9534f; font-weight: 600;">
                                Due Date: <?= date('M d, Y - g:i A', strtotime($act['due_date'])) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($act['grade']) && $act['grade'] !== null && $act['grade'] !== ''): ?>
                            <div style="margin-top: 1rem; padding: 0.85rem; background-color: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 6px;">
                                <div style="font-weight: bold; color: #2e7d32; font-size: 1rem;">
                                     Grade: <?= h($act['grade']) ?>
                                </div>
                                <?php if (!empty($act['feedback'])): ?>
                                    <div style="margin-top: 0.4rem; font-size: 0.85rem; color: #1b5e20;">
                                        <strong>Instructor Feedback:</strong> <?= nl2br(h($act['feedback'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed #ccc;">
                            <?php if (!empty($act['file_path'])): ?>
                                <div style="margin-bottom: 0.75rem;">
                                    <a href="download.php?file=<?= urlencode($act['file_path']) ?>" class="button button-primary" style="font-size: 0.85rem; padding: 6px 14px; text-decoration: none; display: inline-block;">
                                        ⬇ Download <?= h($act["original_file_name"] ?: basename($act["file_path"])) ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 0.75rem; background: #fff; padding: 1rem; border-radius: 6px; border: 1px solid #ddd;">
                                <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                                
                                <div>
                                    <label style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem; color: #333;">
                                         Student Answer / Notes (Optional):
                                    </label>
                                    <textarea name="submission_text" rows="3" placeholder="Type your answer, explanations, or notes here..." style="width: 100%; padding: 0.5rem; font-size: 0.85rem; border: 1px solid #ccc; border-radius: 4px; font-family: inherit;"><?= h($act['submission_text'] ?? '') ?></textarea>
                                </div>

                                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;">
                                    <div>
                                        <label style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem; color: #333;">
                                            Attach Output File:
                                        </label>
                                        <input type="file" name="submission_file" style="font-size: 0.8rem; padding: 4px;">
                                        <?php if (!empty($act['submission_file_path'])): ?>
                                            <a href="download.php?file=<?= urlencode($act['submission_file_path']) ?>" style="font-size: 0.8rem; display: inline-block; margin-left: 8px; color: #198754; font-weight: bold; text-decoration: underline;">
                                                ⬇ Re-download My File
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <button type="submit" name="upload_answer" style="background-color: #198754; color: white; border: none; padding: 8px 16px; font-size: 0.85rem; border-radius: 4px; cursor: pointer; font-weight: bold; align-self: flex-end;">
                                         <?= !empty($act['submission_file_path']) || !empty($act['submission_text']) ? 'Resubmit Answer' : 'Submit Answer' ?>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <?php if (!empty($act['submitted_at'])): ?>
                            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #198754; text-align: right;">
                                Submitted on: <?= date('M d, Y - g:i A', strtotime($act['submitted_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($advice_history) && empty($assigned_activities)): ?>
        <p class="muted">No direct guidance or activity has been posted by your advisor yet.</p>
    <?php else: ?>
        <?php if (!empty($advice_history)): ?>
            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: #555;">General Notes & Feedback</h3>
            <div class="advice-list" style="display: flex; flex-direction: column; gap: 1.5rem;">
                <?php foreach ($advice_history as $row): ?>
                    <div style="border-left: 4px solid var(--blue); padding-left: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <strong>Advisor: <?= h($row['advisor_name']) ?></strong>
                            <small class="muted"><?= date('M d, Y', strtotime($row['created_at'])) ?></small>
                        </div>
                        <p style="margin: 0; line-height: 1.6; color: var(--text);"><?= nl2br(h($row['advice_text'])) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const alertBox = document.getElementById("alert-message");
    if (alertBox) {
        setTimeout(function () {
            alertBox.style.opacity = "0";
            setTimeout(function () {
                alertBox.remove();
            }, 500);
        }, 5000);
    }
});
</script>

<?php page_footer(); ?>
