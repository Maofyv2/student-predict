<?php
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['student'])) {
    redirect_to('student_login.php');
}

$student = $_SESSION['student'];
$student_id = (int) $student['id'];

// Fetch latest prediction
$stmt = db()->prepare("SELECT * FROM tbl_predictions WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$prediction = $stmt->get_result()->fetch_assoc();

// Fetch latest survey
$stmt = db()->prepare("SELECT * FROM tbl_surveys WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();

// Handle Survey Submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_survey'])) {
    $internet = (int) ($_POST['internet_access'] ?? 0);
    $literacy = (int) ($_POST['digital_literacy'] ?? 1);
    $device = $_POST['device_availability'] ?? 'None';
    $hours = (float) ($_POST['study_hours'] ?? 0);
    
    $stmt = db()->prepare("INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, device_availability, study_hours) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iiisd', $student_id, $internet, $literacy, $device, $hours);
    
    if ($stmt->execute()) {
        $message = 'Self-assessment submitted successfully! Your advisor will review it.';
        // Refresh survey data
        $stmt = db()->prepare("SELECT * FROM tbl_surveys WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param('i', $student_id);
        $stmt->execute();
        $survey = $stmt->get_result()->fetch_assoc();
    }
}

page_header('Student Portal');
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Welcome, <?= h($student['full_name']) ?></p>
        <h1>Your Performance Overview</h1>
    </div>
    <a href="logout.php" class="button button-ghost">Logout</a>
</div>

<section class="layout-two">
    <article class="panel">
        <div class="panel-title">
            <h2>Current Status</h2>
        </div>
        <?php if ($prediction): ?>
            <div style="text-align: center; padding: 2rem;">
                <div class="status <?= h(status_class($prediction['predicted_status'])) ?>" style="font-size: 2rem; padding: 1rem 2rem;">
                    <?= h($prediction['predicted_status']) ?>
                </div>
                <p style="margin-top: 1rem; color: var(--text-muted);">
                    Predicted on <?= date('M d, Y', strtotime($prediction['created_at'])) ?>
                </p>
                <div style="margin-top: 2rem; text-align: left;">
                    <strong>Recommendation:</strong>
                    <p style="margin-top: 0.5rem; line-height: 1.6;"><?= h($prediction['recommendation']) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="empty">No prediction results yet. Please wait for your advisor's assessment.</div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="panel-title">
            <h2>Self-Assessment</h2>
            <span>Update your details</span>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success" style="margin-bottom: 1rem; background: #eef9f1; border-color: #d1e7dd; color: #0f5132;"><?= h($message) ?></div>
        <?php endif; ?>

        <form method="post" style="display: flex; flex-direction: column; gap: 1rem;">
            <div>
                <label style="display: block; margin-bottom: 0.25rem;">Internet Access</label>
                <select name="internet_access" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="1" <?= ($survey && $survey['internet_access']) ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= ($survey && !$survey['internet_access']) ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem;">Digital Literacy (1-5)</label>
                <input type="number" name="digital_literacy" min="1" max="5" value="<?= $survey['digital_literacy'] ?? 3 ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem;">Primary Device</label>
                <input type="text" name="device_availability" value="<?= h($survey['device_availability'] ?? '') ?>" placeholder="e.g. Laptop, Smartphone" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem;">Weekly Study Hours</label>
                <input type="number" step="0.1" name="study_hours" value="<?= h($survey['study_hours'] ?? '0') ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <button type="submit" name="submit_survey" class="button button-primary" style="margin-top: 1rem;">Update Assessment</button>
        </form>
    </article>
</section>

<?php
$records = db()->query("SELECT * FROM tbl_academic_records WHERE student_id = $student_id ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
?>
<section class="panel" style="margin-top: 2rem;">
    <div class="panel-title">
        <h2>Academic Progress</h2>
        <span>Recent term grades</span>
    </div>
    <?php if (empty($records)): ?>
        <p class="muted">No academic records found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Year/Semester</th>
                        <th>Prelim</th>
                        <th>Midterm</th>
                        <th>Semi-Final</th>
                        <th>Final</th>
                        <th>Lab</th>
                        <th>Attendance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><?= h($r['academic_year']) ?> / <?= h($r['semester']) ?></td>
                            <td><?= round($r['prelim_grade'], 1) ?>%</td>
                            <td><?= round($r['midterm_grade'], 1) ?>%</td>
                            <td><?= round($r['semi_final_grade'] ?? 0, 1) ?>%</td>
                            <td><?= round($r['final_grade'] ?? 0, 1) ?>%</td>
                            <td><?= round($r['lab_score'], 1) ?>%</td>
                            <td><?= round($r['attendance_rate'], 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php $advice_history = get_student_advice($student_id); ?>
<section class="panel" style="margin-top: 2rem;">
    <div class="panel-title">
        <h2>Advisor's Guidance</h2>
        <span>Personalized tips from your instructor</span>
    </div>
    
    <?php if (empty($advice_history)): ?>
        <p class="muted">No direct guidance has been posted by your advisor yet.</p>
    <?php else: ?>
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
</section>

<?php page_footer(); ?>
