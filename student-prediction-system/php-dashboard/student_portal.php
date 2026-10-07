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
$current_advisor_id = (int)($assignedAdvisor['id'] ?? 0);
$current_advisor_name = (string)($assignedAdvisor['full_name'] ?? '');

function student_portal_grade_status(float $grade): string
{
    if ($grade >= 75) return 'Pass';
    if ($grade >= 70) return 'At-Risk';
    return 'Fail';
}


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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_survey'])) {
    $internet           = (int) ($_POST['internet_access'] ?? 0);
    $literacy           = (int) ($_POST['digital_literacy'] ?? 1);
    $hours              = (float) ($_POST['study_hours'] ?? 0);
    $income             = (float) ($_POST['household_income'] ?? 0);
    $parentEdu          = (int) ($_POST['parental_education'] ?? 1);
    $working            = (int) ($_POST['working_student'] ?? 0);

    $stmt = db()->prepare("SELECT id FROM tbl_surveys WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $existing_survey = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing_survey) {
        $stmt = db()->prepare(
            "UPDATE tbl_surveys 
             SET internet_access = ?, digital_literacy = ?, study_hours = ?, household_income = ?, parental_education = ?, working_student = ? 
             WHERE id = ?"
        );
        $stmt->bind_param('iiddiii', $internet, $literacy, $hours, $income, $parentEdu, $working, $existing_survey['id']);
    } else {
        $stmt = db()->prepare(
            "INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, study_hours, household_income, parental_education, working_student) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iiiddii', $student_id, $internet, $literacy, $hours, $income, $parentEdu, $working);
    }

    if ($stmt->execute()) {
        $profName = 'your assigned professor';
        $stmt->close();

        // Also update tbl_students profile with these socio-demographic features and advisor
        $stmtStuUp = db()->prepare(
            "UPDATE tbl_students 
             SET household_income = ?, parental_education = ?, working_student = ? 
             WHERE id = ?"
        );
        $stmtStuUp->bind_param('diii', $income, $parentEdu, $working, $student_id);
        $stmtStuUp->execute();
        $stmtStuUp->close();

        // Refresh student session data
        $refStmt = db()->prepare("SELECT * FROM tbl_students WHERE id = ?");
        $refStmt->bind_param('i', $student_id);
        $refStmt->execute();
        $refreshed = $refStmt->get_result()->fetch_assoc();
        if ($refreshed) {
            $_SESSION['student'] = $refreshed;
            $student = $refreshed;
        }
        $refStmt->close();

        if ($current_advisor_id > 0) {
            $profName = $current_advisor_name;
            $studentName = $student['full_name'];
            $alertMsg = "Student {$studentName} updated their self-assessment profile (Study Hours: {$hours}h/wk, Digital Literacy: {$literacy}/5). Please review.";
            $alert_type = 'Student Update';
            $severity   = 'Low';
            $is_read    = 0;

            $stmtAlert = db()->prepare(
                "INSERT INTO tbl_alerts (student_id, user_id, alert_type, severity, message, is_read, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmtAlert->bind_param('iisssi', $student_id, $current_advisor_id, $alert_type, $severity, $alertMsg, $is_read);
            $stmtAlert->execute();
            $stmtAlert->close();
        }

        $success_message = "This assessment has been successfully updated and sent to " . $profName . "!";
    } else {
        $stmt->close();
    }
}

$stmt = db()->prepare("SELECT * FROM tbl_predictions WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$prediction = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = db()->prepare("SELECT * FROM tbl_predictions WHERE student_id = ? ORDER BY id ASC");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$all_predictions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$period_predictions = [];
foreach ($all_predictions as $p) {
    if (!empty($p['grading_period'])) {
        $period_predictions[$p['grading_period']] = $p;
    }
}

$stmt = db()->prepare("SELECT * FROM tbl_surveys WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

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

$stmtRec = db()->prepare("SELECT * FROM tbl_academic_records WHERE student_id = ? ORDER BY created_at DESC LIMIT 5");
$stmtRec->bind_param('i', $student_id);
$stmtRec->execute();
$records = $stmtRec->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtRec->close();

// Fetch detailed period scores and computed grades
$stmtGC = db()->prepare(
    "SELECT * FROM tbl_grade_components 
     WHERE student_id = ? 
     ORDER BY FIELD(period, 'Prelim', 'Midterm', 'Semi-Final', 'Final')"
);
$stmtGC->bind_param('i', $student_id);
$stmtGC->execute();
$all_components = $stmtGC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtGC->close();

$period_components = [];
foreach ($all_components as $gc) {
    $period_components[$gc['period']] = $gc;
}
$all_weights = get_all_grading_weights();

$advice_history = get_student_advice($student_id);

page_header('Student Portal');
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Welcome back, <?= h($student['full_name']) ?></p>
        <h1>Your Performance Overview</h1>
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

<section class="layout-two">
    <article class="panel">
        <div class="panel-title">
            <h2>Current Grade Standing</h2>
            <?php if ($prediction && !empty($prediction['grading_period'])): ?>
                <span class="pill pill-period-<?= strtolower(str_replace('-', '', $prediction['grading_period'])) ?>"><?= h($prediction['grading_period']) ?> <?= !empty($prediction['algorithm']) && strpos($prediction['algorithm'], 'Next-Period Regression') !== false ? 'Forecast' : 'Prediction' ?></span>
            <?php endif; ?>
        </div>
        <?php if ($prediction): ?>
            <div style="text-align: center; padding: 2rem;">
                <?php $portalGrade = (float)($prediction['predicted_grade'] ?? 0); $isForecast = strpos((string)($prediction['algorithm'] ?? ''), 'Next-Period Regression') !== false; ?>
                <?php if ($portalGrade > 0): ?>
                    <?php $gradeStanding = student_portal_grade_status($portalGrade); ?>
                    <small style="display:block;color:var(--muted);margin-bottom:6px;"><?= $isForecast ? 'Forecast Standing' : 'Grade Standing' ?></small>
                    <div class="status <?= h(status_class($gradeStanding)) ?>" style="font-size: 2rem; padding: 1rem 2rem;">
                        <?= h($gradeStanding) ?>
                    </div>
                    <p style="margin-top: 1rem; font-size: 1.25rem; font-weight: 700; color: var(--text);">
                        <?= $isForecast ? 'Forecast Grade' : 'Computed Grade' ?>: <?= round($portalGrade, 1) ?>%
                    </p>
                    <p style="margin-top:.5rem;color:var(--muted);font-size:.9rem;">
                        Model estimate: <strong><?= h($prediction['predicted_status']) ?></strong>
                    </p>
                <?php else: ?>
                    <div class="status <?= h(status_class($prediction['predicted_status'])) ?>" style="font-size: 2rem; padding: 1rem 2rem;">
                        <?= h($prediction['predicted_status']) ?>
                    </div>
                <?php endif; ?>
                <p style="margin-top: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">
                    Evaluated on <?= date('M d, Y', strtotime($prediction['created_at'])) ?>
                    • <?php if (!$isForecast): ?>Model confidence: <?= round($prediction['confidence'] * 100, 1) ?>%<?php else: ?>Forecast, not an actual grade<?php endif; ?>
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
            <h2>Self-Assessment Profile</h2>
        </div>

        <form method="post" id="survey-form" style="display: flex; flex-direction: column; gap: 1rem;">
            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Assigned Advisor / Professor</label>
                <input type="text" value="<?= h($current_advisor_name !== '' ? $current_advisor_name : 'No advisor assigned — please contact the administrator') ?>" disabled
                       style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background-color: #f1f5f9; color: #475569;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Internet Access</label>
                <select name="internet_access" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="1" <?= ($survey && (string)$survey['internet_access'] === '1') ? 'selected' : '' ?>>Yes (Available)</option>
                    <option value="0" <?= ($survey && (string)$survey['internet_access'] === '0') ? 'selected' : '' ?>>No (Limited/None)</option>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Digital Literacy (1-5)</label>
                <input type="number" name="digital_literacy" min="1" max="5" value="<?= h($survey['digital_literacy'] ?? 3) ?>" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                <small style="color: #666; font-size: 0.8rem;">Rate 1 (Basic) to 5 (Advanced skills)</small>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Weekly Study Hours</label>
                <input type="number" step="0.1" min="0" max="80" name="study_hours" value="<?= h($survey['study_hours'] ?? '0') ?>" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Household Income (PHP)</label>
                <input type="number" step="0.01" min="0" max="500000" name="household_income" value="<?= h($survey['household_income'] ?? $student['household_income'] ?? '0') ?>" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Parental Education Level</label>
                <select name="parental_education" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                    <?php 
                    $curParentEdu = (int)($survey['parental_education'] ?? $student['parental_education'] ?? 1);
                    foreach ([1 => 'Elementary', 2 => 'High School', 3 => 'College', 4 => 'Postgraduate'] as $val => $label): 
                    ?>
                        <option value="<?= $val ?>" <?= ($curParentEdu === $val) ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Working Student</label>
                <?php $curWorking = (int)($survey['working_student'] ?? $student['working_student'] ?? 0); ?>
                <select name="working_student" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="0" <?= ($curWorking === 0) ? 'selected' : '' ?>>No (Full-time student)</option>
                    <option value="1" <?= ($curWorking === 1) ? 'selected' : '' ?>>Yes (Part-time / Working)</option>
                </select>
            </div>

            <button type="submit" name="submit_survey" id="survey-btn" class="button button-primary" style="margin-top: 1rem;">
                Update Assessment
            </button>
        </form>
    </article>
</section>

<section class="panel" style="margin-top: 2rem;">
    <div class="panel-title">
        <h2>Semester Prediction Journey</h2>
        <span>Grade standing from score; ML estimate shown separately</span>
    </div>
    <div class="period-timeline">
        <?php foreach (['Prelim', 'Midterm', 'Semi-Final', 'Final'] as $per): 
            $pData = $period_predictions[$per] ?? null;
        ?>
            <div class="period-card <?= $pData ? 'active' : 'pending' ?>">
                <h4>
                    <span><?= $per ?></span>
                    <span class="pill pill-period-<?= strtolower(str_replace('-', '', $per)) ?>"><?= $per ?></span>
                </h4>
                <?php if ($pData): ?>
                    <div style="margin-top: 10px;">
                        <?php $isForecast = strpos((string)($pData['algorithm'] ?? ''), 'Next-Period Regression') !== false; $hasActualGrade = isset($period_components[$per]['computed_grade']); $periodGrade = (float)($period_components[$per]['computed_grade'] ?? $pData['predicted_grade'] ?? 0); ?>
                        <?php if ($periodGrade > 0): ?>
                            <?php $periodStanding = student_portal_grade_status($periodGrade); ?>
                            <span class="status <?= h(status_class($periodStanding)) ?>" style="display: inline-block; padding: 4px 10px; font-size: 0.85rem;">
                                <?= h($periodStanding) ?>
                            </span>
                            <div style="margin-top: 8px; font-size: 1.15rem; font-weight: 700;">
                                <?= round($periodGrade, 1) ?>%
                            </div>
                            <small style="display:block;margin-top:4px;color:var(--muted);"><?= $hasActualGrade ? 'Actual grade standing' : 'Forecast standing' ?></small>
                            <small style="display:block;margin-top:6px;color:var(--muted);">Model estimate: <?= h($pData['predicted_status']) ?></small>
                        <?php else: ?>
                            <span class="status <?= h(status_class($pData['predicted_status'])) ?>" style="display: inline-block; padding: 4px 10px; font-size: 0.85rem;">
                                <?= h($pData['predicted_status']) ?>
                            </span>
                        <?php endif; ?>
                        <small style="display: block; margin-top: 6px; color: var(--muted); font-size: 0.75rem;">
                            <?= $isForecast ? 'Forecast' : round($pData['confidence'] * 100, 1) . '% confidence' ?><br>
                            <?= date('M d, Y', strtotime($pData['created_at'])) ?>
                        </small>
                    </div>
                <?php else: ?>
                    <p style="margin-top: 14px; font-size: 0.85rem; color: var(--muted);">
                        Pending evaluation.<br>Awaiting component submissions.
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel" style="margin-top: 2rem;">
    <div class="panel-title">
        <h2>My Scores &amp; Computed Grades</h2>
        <span>Detailed assessment breakdown, criteria weights, and progressive period grades</span>
    </div>

    <div style="display: grid; gap: 20px; margin-top: 10px;">
        <?php foreach (['Prelim', 'Midterm', 'Semi-Final', 'Final'] as $per): 
            $gc = $period_components[$per] ?? null;
            $pData = $period_predictions[$per] ?? null;
            $weights = $all_weights[$per] ?? [];
            $scoresMap = [];

            if ($gc) {
                // Populate scores map
                if (!empty($gc['scores_json'])) {
                    $decoded = json_decode($gc['scores_json'], true);
                    if (is_array($decoded)) {
                        $scoresMap = $decoded;
                    }
                }
                if (empty($scoresMap)) {
                    if ($gc['exam_score'] !== null)       $scoresMap['Exam'] = ['raw' => (float)$gc['exam_score']];
                    if ($gc['quiz_score'] !== null)       $scoresMap['Quiz'] = ['raw' => (float)$gc['quiz_score']];
                    if ($gc['activity_score'] !== null)   $scoresMap['Activities'] = ['raw' => (float)$gc['activity_score']];
                    if ($gc['assignment_score'] !== null) $scoresMap['Assignment'] = ['raw' => (float)$gc['assignment_score']];
                    if ($gc['project_score'] !== null)    $scoresMap['Project'] = ['raw' => (float)$gc['project_score']];
                    if ($gc['attendance_rate'] !== null)  $scoresMap['Attendance'] = ['raw' => (float)$gc['attendance_rate']];
                    if ($gc['lab_score'] !== null)        $scoresMap['Lab'] = ['raw' => (float)$gc['lab_score']];
                }
            }
        ?>
            <div style="border: 1px solid var(--line); border-radius: 8px; padding: 18px; background: var(--surface);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; border-bottom: 1px solid var(--line); padding-bottom: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h3 style="margin: 0; font-size: 1.15rem;"><?= $per ?></h3>
                        <span class="pill pill-period-<?= strtolower(str_replace('-', '', $per)) ?>"><?= $per ?></span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if ($gc && $gc['computed_grade'] !== null): ?>
                            <div>
                                <small style="color: var(--muted); display: block; font-size: 0.75rem;">COMPUTED GRADE</small>
                                <strong style="font-size: 1.25rem; color: #2563eb;"><?= round((float)$gc['computed_grade'], 2) ?>%</strong>
                            </div>
                        <?php else: ?>
                            <span class="pill pill-bad" style="font-size: 0.8rem;">No Grades Yet</span>
                        <?php endif; ?>

                        <?php if ($pData): ?>
                            <div>
                                <small style="color: var(--muted); display: block; font-size: 0.75rem;">MODEL ESTIMATE</small>
                                <span class="status <?= h(status_class($pData['predicted_status'])) ?>" style="font-size: 0.8rem; padding: 2px 8px;">
                                    <?= h($pData['predicted_status']) ?><?= strpos((string)($pData['algorithm'] ?? ''), 'Next-Period Regression') !== false ? '' : ' (' . round((float)$pData['confidence'] * 100, 1) . '%)' ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($gc && (!empty($scoresMap) || $gc['computed_grade'] !== null)): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                            <thead>
                                <tr style="background: var(--surface-strong); color: var(--muted); font-size: 0.78rem; text-transform: uppercase;">
                                    <th style="padding: 8px 10px; text-align: left;">Criterion</th>
                                    <th style="padding: 8px 10px; text-align: center;">Raw Score</th>
                                    <th style="padding: 8px 10px; text-align: center;">Max Score</th>
                                    <th style="padding: 8px 10px; text-align: center;">Weight</th>
                                    <th style="padding: 8px 10px; text-align: right;">Weighted Contribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($weights as $compName => $cfg): 
                                    $wt = (float)($cfg['weight'] ?? 0);
                                    $ms = (float)($cfg['max_score'] ?? 100);
                                    $raw = null;

                                    if (isset($scoresMap[$compName])) {
                                        $raw = is_array($scoresMap[$compName]) ? ($scoresMap[$compName]['raw'] ?? null) : $scoresMap[$compName];
                                    } elseif ($compName === 'Activities' && isset($scoresMap['activity'])) {
                                        $raw = $scoresMap['activity'];
                                    } elseif ($compName === 'Attendance' && isset($scoresMap['attendance'])) {
                                        $raw = $scoresMap['attendance'];
                                    }

                                    $contrib = ($raw !== null && $ms > 0) ? round(($raw / $ms) * $wt, 2) : null;
                                ?>
                                    <tr style="border-bottom: 1px solid var(--line);">
                                        <td style="padding: 8px 10px; font-weight: 600;"><?= h($compName) ?></td>
                                        <td style="padding: 8px 10px; text-align: center;">
                                            <?= $raw !== null ? '<strong>' . round((float)$raw, 2) . '</strong>' : '<span style="color:var(--muted);">—</span>' ?>
                                        </td>
                                        <td style="padding: 8px 10px; text-align: center; color: var(--muted);"><?= $ms ?></td>
                                        <td style="padding: 8px 10px; text-align: center;"><?= $wt ?>%</td>
                                        <td style="padding: 8px 10px; text-align: right;">
                                            <?= $contrib !== null ? '<strong>' . $contrib . '%</strong>' : '<span style="color:var(--muted);">Pending</span>' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($gc['missing_components'])): 
                        $missing = json_decode($gc['missing_components'], true);
                        if (!empty($missing)):
                    ?>
                        <div style="margin-top: 10px; font-size: 0.8rem; color: #b45309; background: #fef3c7; padding: 6px 12px; border-radius: 4px;">
                            <em>Missing components:</em> <?= h(implode(', ', $missing)) ?>. Current grade is calculated proportionally from completed components.
                        </div>
                    <?php endif; endif; ?>

                <?php else: ?>
                    <p style="margin: 6px 0; color: var(--muted); font-size: 0.85rem;">
                        No assessment scores entered yet for <?= $per ?>. Scores will be posted once evaluated by your Advisor.
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

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

<section class="panel" style="margin-top: 2rem;">
    <div class="panel-title">
        <h2>Advisor's Guidance & Activities</h2>
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
