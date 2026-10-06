<?php
require_once __DIR__ . '/bootstrap.php';

// 1. Authentication Check
if (!isset($_SESSION['student'])) {
    redirect_to('student_login.php');
}

$student = $_SESSION['student'];
$student_id = (int) $student['id'];
$success_message = "";
$error_message = "";

// 2. Data Handlers & Processing

// --- SUBMISSION FILE HANDLER ---
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

// --- SURVEY HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_survey'])) {
    $selected_advisor_id = (int) ($_POST['advisor_id'] ?? 0);
    $internet           = (int) ($_POST['internet_access'] ?? 0);
    $literacy           = (int) ($_POST['digital_literacy'] ?? 1);
    $hours              = (float) ($_POST['study_hours'] ?? 0);
    $income             = (float) ($_POST['household_income'] ?? 0);
    $parentEdu          = (int) ($_POST['parental_education'] ?? 1);
    $working            = (int) ($_POST['working_student'] ?? 0);

    // Fetch current survey record
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
             SET household_income = ?, parental_education = ?, working_student = ?, advisor_id = ?, professor_id = ? 
             WHERE id = ?"
        );
        $advVal = $selected_advisor_id > 0 ? $selected_advisor_id : null;
        $stmtStuUp->bind_param('diiiii', $income, $parentEdu, $working, $advVal, $advVal, $student_id);
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

        if ($selected_advisor_id > 0) {
            $stmtAdvName = db()->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $stmtAdvName->bind_param('i', $selected_advisor_id);
            $stmtAdvName->execute();
            $advRes = $stmtAdvName->get_result()->fetch_assoc();
            $stmtAdvName->close();

            if ($advRes) {
                $profName = $advRes['full_name'];
            }

            $studentName = $student['full_name'];
            $alertMsg = "Student {$studentName} updated their self-assessment profile (Study Hours: {$hours}h/wk, Digital Literacy: {$literacy}/5). Please review.";
            $alert_type = 'Student Update';
            $severity   = 'Low';
            $is_read    = 0;

            $stmtAlert = db()->prepare(
                "INSERT INTO tbl_alerts (student_id, user_id, alert_type, severity, message, is_read, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmtAlert->bind_param('iisssi', $student_id, $selected_advisor_id, $alert_type, $severity, $alertMsg, $is_read);
            $stmtAlert->execute();
            $stmtAlert->close();
        }

        $success_message = "This assessment has been successfully updated and sent to " . $profName . "!";
    } else {
        $stmt->close();
    }
}

// 3. Fetch Data for Display
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

$advisors_list = db()->query("SELECT id, full_name FROM users WHERE role = 'Advisor' AND is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

$stmtAdv = db()->prepare("SELECT advisor_id FROM tbl_students WHERE id = ? LIMIT 1");
$stmtAdv->bind_param('i', $student_id);
$stmtAdv->execute();
$studentCurrent = $stmtAdv->get_result()->fetch_assoc();
$current_advisor_id = $studentCurrent['advisor_id'] ?? 0;
$stmtAdv->close();

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
    </div>
    <a href="logout.php" class="button button-ghost">Logout</a>
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
            <h2>Current Status</h2>
            <?php if ($prediction && !empty($prediction['grading_period'])): ?>
                <span class="pill pill-period-<?= strtolower(str_replace('-', '', $prediction['grading_period'])) ?>"><?= h($prediction['grading_period']) ?> Prediction</span>
            <?php endif; ?>
        </div>
        <?php if ($prediction): ?>
            <div style="text-align: center; padding: 2rem;">
                <div class="status <?= h(status_class($prediction['predicted_status'])) ?>" style="font-size: 2rem; padding: 1rem 2rem;">
                    <?= h($prediction['predicted_status']) ?>
                </div>
                <?php if ($prediction['predicted_grade']): ?>
                    <p style="margin-top: 1rem; font-size: 1.25rem; font-weight: 700; color: var(--text);">
                        Predicted Grade: <?= round($prediction['predicted_grade'], 1) ?>%
                    </p>
                <?php endif; ?>
                <p style="margin-top: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">
                    Evaluated on <?= date('M d, Y', strtotime($prediction['created_at'])) ?>
                    • <?= round($prediction['confidence'] * 100, 1) ?>% Confidence
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
                <select name="advisor_id" id="advisor_select" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9;">
                    <option value="" disabled <?= empty($current_advisor_id) ? 'selected' : '' ?>>-- Select Your Advisor --</option>
                    <?php foreach ($advisors_list as $adv): ?>
                        <option value="<?= $adv['id'] ?>" <?= ($current_advisor_id == $adv['id']) ? 'selected' : '' ?>>
                            <?= h($adv['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
        <span>Progressive grade forecasts across grading periods</span>
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
                        <span class="status <?= h(status_class($pData['predicted_status'])) ?>" style="display: inline-block; padding: 4px 10px; font-size: 0.85rem;">
                            <?= h($pData['predicted_status']) ?>
                        </span>
                        <?php if ($pData['predicted_grade']): ?>
                            <div style="margin-top: 8px; font-size: 1.15rem; font-weight: 700;">
                                <?= round($pData['predicted_grade'], 1) ?>%
                            </div>
                        <?php endif; ?>
                        <small style="display: block; margin-top: 6px; color: var(--muted); font-size: 0.75rem;">
                            <?= round($pData['confidence'] * 100, 1) ?>% confidence<br>
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
                                <small style="color: var(--muted); display: block; font-size: 0.75rem;">FORECAST</small>
                                <span class="status <?= h(status_class($pData['predicted_status'])) ?>" style="font-size: 0.8rem; padding: 2px 8px;">
                                    <?= h($pData['predicted_status']) ?> (<?= round((float)$pData['confidence'] * 100, 1) ?>%)
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
    
    <!-- ASSIGNED ACTIVITIES SECTION -->
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

                        <?php if (!empty($act['due_date'])): ?>
                            <div style="margin-top: 0.75rem; font-size: 0.85rem; color: #d9534f; font-weight: 600;">
                                Due Date: <?= date('M d, Y - g:i A', strtotime($act['due_date'])) ?>
                            </div>
                        <?php endif; ?>

                        <!-- DISPLAY GRADE & ADVISOR FEEDBACK IF AVAILABLE -->
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

                        <!-- FILE ACTION AND SUBMISSION AREA -->
                        <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed #ccc;">
                            <?php if (!empty($act['file_path'])): ?>
                                <div style="margin-bottom: 0.75rem;">
                                    <a href="<?= h($act['file_path']) ?>" target="_blank" download class="button button-primary" style="font-size: 0.85rem; padding: 6px 14px; text-decoration: none; display: inline-block;">
                                        Download Task File
                                    </a>
                                </div>
                            <?php endif; ?>

                            <!-- SUBMIT FORM WITH TEXT & FILE INPUT -->
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
                                            <a href="<?= h($act['submission_file_path']) ?>" target="_blank" download style="font-size: 0.8rem; display: inline-block; margin-left: 8px; color: #198754; font-weight: bold; text-decoration: underline;">
                                                View Current File
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

    <!-- GENERAL ADVICE HISTORY -->
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