<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$user_id = (int) $user['id'];

db()->query("CREATE TABLE IF NOT EXISTS tbl_student_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    assigned_by INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    instructions TEXT NOT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    original_file_name VARCHAR(255) DEFAULT NULL,
    academic_year VARCHAR(20) DEFAULT NULL,
    semester VARCHAR(30) DEFAULT NULL,
    grading_period ENUM('Prelim','Midterm','Semi-Final','Final') DEFAULT NULL,
    criterion_component VARCHAR(60) DEFAULT NULL,
    criterion_max_score DECIMAL(6,2) DEFAULT NULL,
    raw_score DECIMAL(6,2) DEFAULT NULL,
    due_date DATETIME DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    submission_file VARCHAR(255) DEFAULT NULL,
    submission_file_path VARCHAR(255) DEFAULT NULL,
    submission_text TEXT DEFAULT NULL,
    grade VARCHAR(50) DEFAULT NULL,
    feedback TEXT DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$colsToCheck = [
    'original_file_name' => "VARCHAR(255) DEFAULT NULL",
    'academic_year' => "VARCHAR(20) DEFAULT NULL",
    'semester' => "VARCHAR(30) DEFAULT NULL",
    'grading_period' => "ENUM('Prelim','Midterm','Semi-Final','Final') DEFAULT NULL",
    'criterion_component' => "VARCHAR(60) DEFAULT NULL",
    'criterion_max_score' => "DECIMAL(6,2) DEFAULT NULL",
    'raw_score' => "DECIMAL(6,2) DEFAULT NULL",
    'submission_file' => "VARCHAR(255) DEFAULT NULL",
    'submission_file_path' => "VARCHAR(255) DEFAULT NULL",
    'submission_text' => "TEXT DEFAULT NULL",
    'grade' => "VARCHAR(50) DEFAULT NULL",
    'feedback' => "TEXT DEFAULT NULL",
    'submitted_at' => "DATETIME DEFAULT NULL"
];

foreach ($colsToCheck as $col => $def) {
    $chk = db()->query("SHOW COLUMNS FROM tbl_student_activities LIKE '{$col}'");
    if ($chk && $chk->num_rows === 0) {
        db()->query("ALTER TABLE tbl_student_activities ADD COLUMN {$col} {$def}");
    }
}

function activity_grade_column(string $component): ?string
{
    return [
        'Exam' => 'exam_score',
        'Quiz' => 'quiz_score',
        'Activities' => 'activity_score',
        'Assignment' => 'assignment_score',
        'Project' => 'project_score',
        'Attendance' => 'attendance_rate',
        'Lab' => 'lab_score',
    ][$component] ?? null;
}

function refresh_activity_component_grade(array $activity): void
{
    $component = (string)($activity['criterion_component'] ?? '');
    $period = (string)($activity['grading_period'] ?? '');
    $column = activity_grade_column($component);
    if (!$column || !$period || empty($activity['academic_year']) || empty($activity['semester'])) return;

    $weights = get_grading_weights($period);
    if (!isset($weights[$component])) return;
    $componentMax = (float)$weights[$component]['max_score'];

    $avgStmt = db()->prepare(
        "SELECT AVG(raw_score / NULLIF(criterion_max_score, 0)) AS average_ratio
         FROM tbl_student_activities
         WHERE student_id = ? AND academic_year = ? AND semester = ?
           AND grading_period = ? AND criterion_component = ?
           AND status = 'Graded' AND raw_score IS NOT NULL"
    );
    $activityStudentId = (int)$activity['student_id'];
    $activityAcademicYear = (string)$activity['academic_year'];
    $activitySemester = (string)$activity['semester'];
    $avgStmt->bind_param('issss', $activityStudentId, $activityAcademicYear, $activitySemester, $period, $component);
    $avgStmt->execute();
    $averageRatio = (float)($avgStmt->get_result()->fetch_assoc()['average_ratio'] ?? 0);
    $avgStmt->close();
    $componentRawScore = round($averageRatio * $componentMax, 2);

    $gradeStmt = db()->prepare(
        'SELECT exam_score, quiz_score, activity_score, assignment_score, project_score,
                attendance_rate, lab_score, scores_json
         FROM tbl_grade_components
         WHERE student_id = ? AND academic_year = ? AND semester = ? AND period = ? LIMIT 1'
    );
    $activityStudentId = (int)$activity['student_id'];
    $activityAcademicYear = (string)$activity['academic_year'];
    $activitySemester = (string)$activity['semester'];
    $gradeStmt->bind_param('isss', $activityStudentId, $activityAcademicYear, $activitySemester, $period);
    $gradeStmt->execute();
    $existing = $gradeStmt->get_result()->fetch_assoc() ?: [];
    $gradeStmt->close();

    $columnValues = [
        'Exam' => $existing['exam_score'] ?? null,
        'Quiz' => $existing['quiz_score'] ?? null,
        'Activities' => $existing['activity_score'] ?? null,
        'Assignment' => $existing['assignment_score'] ?? null,
        'Project' => $existing['project_score'] ?? null,
        'Attendance' => $existing['attendance_rate'] ?? null,
        'Lab' => $existing['lab_score'] ?? null,
    ];
    $columnValues[$component] = $componentRawScore;
    $gradeResult = compute_weighted_grade($columnValues, $weights, 1);
    if (!$gradeResult) return;

    $scores = json_decode($existing['scores_json'] ?? '', true);
    if (!is_array($scores)) $scores = [];
    $scores[$component] = [
        'raw' => $componentRawScore,
        'max_score' => $componentMax,
        'weight' => (float)$weights[$component]['weight'],
    ];
    $scoresJson = json_encode($scores);
    $missingJson = json_encode($gradeResult['missing']);
    $computedGrade = (float)$gradeResult['grade'];
    $studentId = (int)$activity['student_id'];
    $academicYear = (string)$activity['academic_year'];
    $semester = (string)$activity['semester'];

    $saveStmt = db()->prepare(
        "INSERT INTO tbl_grade_components
            (student_id, academic_year, semester, period, {$column}, computed_grade, missing_components, scores_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE {$column}=VALUES({$column}), computed_grade=VALUES(computed_grade),
            missing_components=VALUES(missing_components), scores_json=VALUES(scores_json), updated_at=CURRENT_TIMESTAMP"
    );
    $saveStmt->bind_param('isssddss', $studentId, $academicYear, $semester, $period, $componentRawScore, $computedGrade, $missingJson, $scoresJson);
    $saveStmt->execute();
    $saveStmt->close();

    $periodColumn = [
        'Prelim' => 'prelim_grade',
        'Midterm' => 'midterm_grade',
        'Semi-Final' => 'semi_final_grade',
        'Final' => 'final_grade',
    ][$period] ?? null;
    if ($periodColumn) {
        $recordStmt = db()->prepare("UPDATE tbl_academic_records SET {$periodColumn} = ? WHERE student_id = ? AND academic_year = ? AND semester = ?");
        $recordStmt->bind_param('diss', $computedGrade, $studentId, $academicYear, $semester);
        $recordStmt->execute();
        $recordStmt->close();
    }
}

if (isset($_GET['missing_components'])) {
    header('Content-Type: application/json; charset=utf-8');
    $targetStudentId = (int)($_GET['student_id'] ?? 0);
    $targetYear = trim($_GET['academic_year'] ?? '');
    $targetSemester = trim($_GET['semester'] ?? '');
    $targetPeriod = trim($_GET['grading_period'] ?? '');
    if (!$targetStudentId || !$targetYear || !$targetSemester || !in_array($targetPeriod, ['Prelim', 'Midterm', 'Semi-Final', 'Final'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose a student, academic year, semester, and grading period.']);
        exit;
    }

    if (($user['role'] ?? '') !== 'Admin') {
        $ownerCheck = db()->prepare('SELECT id FROM tbl_students WHERE id = ? AND (advisor_id = ? OR professor_id = ?) LIMIT 1');
        $ownerCheck->bind_param('iii', $targetStudentId, $user_id, $user_id);
        $ownerCheck->execute();
        $isAssignedStudent = $ownerCheck->get_result()->num_rows > 0;
        $ownerCheck->close();
        if (!$isAssignedStudent) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only check your assigned students.']);
            exit;
        }
    }

    $gradeStmt = db()->prepare(
        'SELECT exam_score, quiz_score, activity_score, assignment_score, project_score, attendance_rate, lab_score
         FROM tbl_grade_components WHERE student_id = ? AND academic_year = ? AND semester = ? AND period = ? LIMIT 1'
    );
    $gradeStmt->bind_param('isss', $targetStudentId, $targetYear, $targetSemester, $targetPeriod);
    $gradeStmt->execute();
    $storedScores = $gradeStmt->get_result()->fetch_assoc() ?: [];
    $gradeStmt->close();

    $scoreColumns = [
        'Exam' => 'exam_score', 'Quiz' => 'quiz_score', 'Activities' => 'activity_score',
        'Assignment' => 'assignment_score', 'Project' => 'project_score',
        'Attendance' => 'attendance_rate', 'Lab' => 'lab_score',
    ];
    $components = [];
    foreach (get_grading_weights($targetPeriod) as $name => $config) {
        if (!isset($scoreColumns[$name])) continue;
        $raw = $storedScores[$scoreColumns[$name]] ?? null;
        $components[] = [
            'name' => $name,
            'weight' => (float)$config['weight'],
            'max_score' => (float)$config['max_score'],
            'raw_score' => $raw === null ? null : (float)$raw,
            'missing' => $raw === null,
        ];
    }
    usort($components, static fn($a, $b) => ($b['missing'] <=> $a['missing']));
    echo json_encode(['components' => $components]);
    exit;
}

if (isset($_POST['grade_activity'])) {
    $activity_id = (int) $_POST['activity_id'];
    $raw_score = trim($_POST['raw_score'] ?? '');
    $feedback = trim($_POST['feedback'] ?? '');

    if ($activity_id > 0) {
        $activityStmt = db()->prepare('SELECT * FROM tbl_student_activities WHERE id = ? AND assigned_by = ? LIMIT 1');
        $activityStmt->bind_param('ii', $activity_id, $user_id);
        $activityStmt->execute();
        $activity = $activityStmt->get_result()->fetch_assoc();
        $activityStmt->close();

        $maxScore = (float)($activity['criterion_max_score'] ?? 0);
        $validationMax = $maxScore > 0 ? $maxScore : 100.0;
        if (!$activity || !is_numeric($raw_score) || (float)$raw_score < 0 || (float)$raw_score > $validationMax) {
            redirect_to('notifications.php?grade_error=score');
        }

        $rawScoreValue = round((float)$raw_score, 2);
        if ($maxScore <= 0) $maxScore = 100.0; // Legacy tasks were not linked to a grading component.
        $grade = number_format($rawScoreValue, 2, '.', '') . ' / ' . number_format($maxScore, 2, '.', '');
        $stmt = db()->prepare("UPDATE tbl_student_activities SET grade = ?, raw_score = ?, feedback = ?, status = 'Graded' WHERE id = ? AND assigned_by = ?");
        $stmt->bind_param('sdsii', $grade, $rawScoreValue, $feedback, $activity_id, $user_id);
        $saved = $stmt->execute();
        if ($saved) {
            $activity['status'] = 'Graded';
            $activity['raw_score'] = $rawScoreValue;
            refresh_activity_component_grade($activity);
        }
        $stmt->close();
        if (!$saved) redirect_to('notifications.php?grade_error=save');
    }
    redirect_to('notifications.php?grade_saved=1');
}

if (isset($_POST['send_activity'])) {
    $student_id = (int) $_POST['student_id'];
    $title = trim($_POST['activity_title'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $grading_period = trim($_POST['grading_period'] ?? '');
    $criterion_component = trim($_POST['criterion_component'] ?? '');
    $periodWeights = in_array($grading_period, ['Prelim', 'Midterm', 'Semi-Final', 'Final'], true)
        ? get_grading_weights($grading_period)
        : [];
    $criterionConfig = $periodWeights[$criterion_component] ?? null;
    if (!$student_id || !$title || !$instructions || !$academic_year || !$semester || !$criterionConfig || !activity_grade_column($criterion_component)) {
        redirect_to('notifications.php?activity_error=criteria');
    }
    $criterion_max_score = (float)$criterionConfig['max_score'];
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $file_path = null;
    $original_file_name = null;

    if (isset($_FILES['activity_file']) && $_FILES['activity_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/activities/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $original_file_name = basename(str_replace('\\', '/', $_FILES['activity_file']['name']));
        $original_file_name = substr(preg_replace('/[\\x00-\\x1F\\x7F]/', '', $original_file_name), 0, 255);
        $file_extension = pathinfo($original_file_name, PATHINFO_EXTENSION);
        $new_filename = 'activity_' . time() . '_' . uniqid() . '.' . $file_extension;
        $target_file = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['activity_file']['tmp_name'], $target_file)) {
            $file_path = 'uploads/activities/' . $new_filename;
        }
    }

    if ($student_id > 0 && !empty($title) && !empty($instructions)) {
        $stmt = db()->prepare("INSERT INTO tbl_student_activities (student_id, assigned_by, title, instructions, file_path, original_file_name, academic_year, semester, grading_period, criterion_component, criterion_max_score, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissssssssds', $student_id, $user_id, $title, $instructions, $file_path, $original_file_name, $academic_year, $semester, $grading_period, $criterion_component, $criterion_max_score, $due_date);
        $stmt->execute();
    }
    redirect_to('notifications.php');
}

if (isset($_GET['mark_read_id'])) {
    $alert_id = (int) $_GET['mark_read_id'];
    $stmtRead = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmtRead->bind_param('ii', $alert_id, $user_id);
    $stmtRead->execute();
    redirect_to('notifications.php');
}

$sql = "SELECT a.*, s.full_name, s.student_no, s.year_level, s.section,
               sur.internet_access, sur.digital_literacy, sur.study_hours, 
               s.household_income, s.parental_education, s.working_student, sur.created_at AS survey_date
        FROM tbl_alerts a 
        JOIN tbl_students s ON a.student_id = s.id 
        LEFT JOIN (
            SELECT t1.* FROM tbl_surveys t1
            INNER JOIN (SELECT student_id, MAX(id) AS max_id FROM tbl_surveys GROUP BY student_id) t2
            ON t1.id = t2.max_id
        ) sur ON sur.student_id = s.id
        WHERE a.user_id = ? 
          AND (a.alert_type = 'Student Update' OR a.message LIKE '%updated their self-assessment profile%')
        ORDER BY a.created_at DESC";

$stmt = db()->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sqlSubmissions = "SELECT sa.*, s.full_name, s.student_no, s.year_level, s.section 
                   FROM tbl_student_activities sa 
                   JOIN tbl_students s ON sa.student_id = s.id 
                   WHERE sa.assigned_by = ? AND sa.status IN ('Submitted', 'Graded') 
                   ORDER BY sa.submitted_at DESC";
$stmtSub = db()->prepare($sqlSubmissions);
$stmtSub->bind_param('i', $user_id);
$stmtSub->execute();
$submitted_activities = $stmtSub->get_result()->fetch_all(MYSQLI_ASSOC);

page_header('Student Assessment & Submissions');
?>

<div class="page-heading">
    <div>
        <h1>Student Assessment & Profile Updates</h1>
        <p class="eyebrow">Notifications regarding student self-assessments and submitted activity outputs.</p>
    </div>
</div>

<?php if (isset($_GET['grade_saved'])): ?>
    <div class="alert alert-success">Grade saved to the selected criterion. The period grade was recalculated from the graded activities in that criterion.</div>
<?php elseif (($_GET['grade_error'] ?? '') === 'score'): ?>
    <div class="alert alert-error">Enter a valid raw score within the maximum shown for this criterion.</div>
<?php elseif (($_GET['grade_error'] ?? '') === 'save'): ?>
    <div class="alert alert-error">Could not save the grade. Please try again.</div>
<?php elseif (($_GET['activity_error'] ?? '') === 'criteria'): ?>
    <div class="alert alert-error">Choose the academic year, semester, grading period, and criterion for this activity.</div>
<?php endif; ?>

<article class="panel" style="margin-bottom: 2rem;">
    <div class="panel-title">
        <h2>Submitted Student Activities</h2>
        <span>Outputs returned by your students</span>
    </div>

    <?php if (empty($submitted_activities)): ?>
        <p class="muted">No submitted activity outputs yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Submitted Date</th>
                        <th>Student Name</th>
                        <th>Activity Title</th>
                        <th>Status</th>
                        <th>Grade</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submitted_activities as $sub): ?>
                        <tr>
                            <td><?= !empty($sub['submitted_at']) ? date('M d, Y H:i', strtotime($sub['submitted_at'])) : 'N/A' ?></td>
                            <td><?= h($sub['full_name']) ?> <small class="muted">(<?= h($sub['student_no']) ?>)</small></td>
                            <td>
                                <?= h($sub['title']) ?>
                                <?php if (!empty($sub['criterion_component'])): ?>
                                    <small class="muted" style="display:block;"><?= h($sub['criterion_component']) ?> · <?= h($sub['grading_period']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub['status'] === 'Graded'): ?>
                                    <span style="background: #cfe2ff; color: #084298; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 0.8rem;">
                                        Graded
                                    </span>
                                <?php else: ?>
                                    <span style="background: #d1e7dd; color: #0f5132; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 0.8rem;">
                                        Submitted
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= !empty($sub['grade']) ? h($sub['grade']) : '—' ?></strong></td>
                            <td style="text-align: right;">
                                <button type="button" class="button button-primary" 
                                        style="padding: 6px 12px; font-size: 0.85rem;"
                                        onclick="openSubmissionModal(<?= htmlspecialchars(json_encode($sub), ENT_QUOTES, 'UTF-8') ?>)">
                                    <?= $sub['status'] === 'Graded' ? 'View / Edit Grade' : 'Review & Grade' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>

<article class="panel">
    <div class="panel-title">
        <h2>Self-Assessment Notifications</h2>
    </div>

    <?php if (empty($notifications)): ?>
        <p class="muted">No survey or assessment update notifications found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student Name</th>
                        <th>Student No.</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $notif): ?>
                        <tr style="<?= $notif['is_read'] ? '' : 'font-weight: bold; background-color: #f8f9fa;' ?>">
                            <td><?= date('M d, Y H:i', strtotime($notif['created_at'])) ?></td>
                            <td><?= h($notif['full_name']) ?></td>
                            <td><?= h($notif['student_no']) ?></td>
                            <td><?= h($notif['message']) ?></td>
                            <td>
                                <span class="status <?= $notif['is_read'] ? 'status-muted' : 'status-risk' ?>">
                                    <?= $notif['is_read'] ? 'Read' : 'Unread' ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" class="button button-primary" 
                                        style="padding: 6px 12px; font-size: 0.85rem;"
                                        onclick="openSurveyModal(<?= htmlspecialchars(json_encode($notif), ENT_QUOTES, 'UTF-8') ?>)">
                                    View Assessment
                                </button>
                                <button type="button" class="button button-secondary" 
                                        style="padding: 6px 12px; font-size: 0.85rem; margin-left: 4px;"
                                        onclick="openActivityModal(<?= $notif['student_id'] ?>, '<?= h(addslashes($notif['full_name'])) ?>', '<?= h(addslashes($notif['student_no'])) ?>')">
                                    Send Activity
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>

<div id="submissionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: #ffffff; padding: 2rem; border-radius: 8px; max-width: 580px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h2 id="subModalTitle" style="margin-top: 0; border-bottom: 2px solid #0d6efd; padding-bottom: 0.5rem; color: #0d6efd;">Review Activity Output</h2>
        <p style="color: #666; margin-bottom: 1rem;" id="subModalStudentInfo"></p>

        <div style="background: #f9f9f9; padding: 0.85rem; border-radius: 6px; margin-bottom: 0.85rem; border: 1px solid #e0e0e0;">
            <strong style="font-size: 0.85rem;">Instructions Given:</strong>
            <p id="subModalInstructions" style="margin: 0.2rem 0 0 0; font-size: 0.85rem; color: #444; white-space: pre-wrap;"></p>
        </div>

        <div style="background: #fff; padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem; border: 1px solid #ddd;">
            <strong style="color: #198754; font-size: 0.85rem;">Student Answer / Notes:</strong>
            <p id="subModalText" style="margin: 0.3rem 0 0 0; font-size: 0.9rem; color: #222; white-space: pre-wrap; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;"></p>
            
            <div id="subModalFileContainer" style="margin-top: 0.75rem; padding-top: 0.5rem; border-top: 1px dashed #ccc;">
                <strong style="font-size: 0.85rem;">Attached Output File:</strong><br>
                <a id="subModalFileBtn" href="#" target="_blank" download class="button button-primary" style="margin-top: 0.4rem; display: inline-block; font-size: 0.8rem; padding: 5px 12px;">
                    Download Submitted File
                </a>
                <span id="subModalNoFile" style="display:none; color: #888; font-size: 0.85rem;">No file uploaded by student.</span>
            </div>
        </div>

        <form method="POST" style="background: #f0f7ff; padding: 1rem; border-radius: 6px; border: 1px solid #b6d4fe; margin-bottom: 1rem;">
            <input type="hidden" name="activity_id" id="subModalActivityId">
            <h3 style="margin-top: 0; font-size: 1rem; color: #084298;">Evaluation & Grading</h3>

            <p id="subModalCriterion" style="font-size:.85rem;color:#475569;margin:.25rem 0 .85rem;"></p>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem;">Raw Score <span id="subModalMaxLabel"></span></label>
                <input type="number" name="raw_score" id="subModalGrade" min="0" step="0.01" required style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem;">
                <small style="display:block;margin-top:.25rem;color:#64748b;">This score is recorded under the activity's selected grading criterion.</small>
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem;">Feedback / Remarks (Optional)</label>
                <textarea name="feedback" id="subModalFeedback" rows="2" placeholder="Great job! Keep it up..." style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.85rem; font-family: inherit;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="button" onclick="closeSubmissionModal()" class="button button-ghost">Cancel</button>
                <button type="submit" name="grade_activity" class="button button-primary" style="background: #0d6efd;">Save Grade</button>
            </div>
        </form>
    </div>
</div>

<div id="surveyModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: #ffffff; padding: 2rem; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h2 id="modalStudentName" style="margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 0.5rem;">Assessment Details</h2>
        <p style="color: #666; margin-bottom: 1.5rem;" id="modalStudentMeta"></p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; background: #f9f9f9; padding: 1rem; border-radius: 6px;">
            <div><strong>Internet Access:</strong> <p id="modalInternet" style="margin: 0.25rem 0;"></p></div>
            <div><strong>Digital Literacy:</strong> <p id="modalLiteracy" style="margin: 0.25rem 0;"></p></div>
            <div><strong>Weekly Study Hours:</strong> <p id="modalHours" style="margin: 0.25rem 0;"></p></div>
            <div><strong>Working Student:</strong> <p id="modalWorking" style="margin: 0.25rem 0;"></p></div>
            <div><strong>Household Income:</strong> <p id="modalIncome" style="margin: 0.25rem 0;"></p></div>
            <div><strong>Parental Education:</strong> <p id="modalParentEdu" style="margin: 0.25rem 0;"></p></div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
            <a id="modalProfileLink" href="#" class="button button-ghost">Go to Full Profile</a>
            <div style="display: flex; gap: 0.5rem;">
                <a id="modalMarkReadBtn" href="#" class="button button-primary">Mark as Read</a>
                <button type="button" onclick="closeSurveyModal()" class="button button-ghost">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="activityModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: #ffffff; padding: 2rem; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h2 style="margin-top: 0; font-size: 1.3rem;">Send Individual Activity</h2>
        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.25rem;" id="actModalStudentInfo"></p>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="student_id" id="actModalStudentId">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem;">Activity Title</label>
                <input type="text" name="activity_title" placeholder="e.g. Remedial Quiz / Task 1" required style="width: 100%; padding: 0.6rem; border: 1px solid #ccc; border-radius: 6px;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem;">Instructions / Description</label>
                <textarea name="instructions" rows="4" placeholder="Enter instructions for the student..." required style="width: 100%; padding: 0.6rem; border: 1px solid #ccc; border-radius: 6px; font-family: inherit;"></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
                <label style="font-weight:600;font-size:.9rem;">Academic Year
                    <select name="academic_year" id="activityAcademicYear" required onchange="updateActivityCriteria()" style="display:block;width:100%;margin-top:.3rem;padding:.55rem;border:1px solid #ccc;border-radius:6px;">
                        <option value="">Select academic year</option>
                        <option value="2025-2026">2025-2026</option>
                        <option value="2026-2027">2026-2027</option>
                        <option value="2027-2028">2027-2028</option>
                    </select>
                </label>
                <label style="font-weight:600;font-size:.9rem;">Semester
                    <select name="semester" id="activitySemester" required onchange="updateActivityCriteria()" style="display:block;width:100%;margin-top:.3rem;padding:.55rem;border:1px solid #ccc;border-radius:6px;">
                        <option value="">Select semester</option>
                        <option value="1st Semester">1st Semester</option>
                        <option value="2nd Semester">2nd Semester</option>
                        <option value="Summer">Summer</option>
                    </select>
                </label>
                <label style="font-weight:600;font-size:.9rem;">Grading Period
                    <select name="grading_period" id="activityGradingPeriod" required onchange="updateActivityCriteria()" style="display:block;width:100%;margin-top:.3rem;padding:.55rem;border:1px solid #ccc;border-radius:6px;">
                        <option value="">Select period</option>
                        <option>Prelim</option><option>Midterm</option><option>Semi-Final</option><option>Final</option>
                    </select>
                </label>
                <label style="font-weight:600;font-size:.9rem;">Missing / Target Component
                    <select name="criterion_component" id="activityCriterion" required onchange="updateActivityCriterionMax()" style="display:block;width:100%;margin-top:.3rem;padding:.55rem;border:1px solid #ccc;border-radius:6px;">
                        <option value="">Select a period first</option>
                    </select>
                </label>
            </div>
            <small id="activityCriterionMax" style="display:block;margin:-.5rem 0 1rem;color:#475569;">Select the term and period to see which score components are missing.</small>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem;">Attach Activity File (Optional)</label>
                <input type="file" name="activity_file" style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 6px; background: #fff;">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem;">Due Date (Optional)</label>
                <input type="datetime-local" name="due_date" style="width: 100%; padding: 0.6rem; border: 1px solid #ccc; border-radius: 6px;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="button" onclick="closeActivityModal()" class="button button-ghost">Cancel</button>
                <button type="submit" name="send_activity" class="button button-primary">Send Activity</button>
            </div>
        </form>
    </div>
</div>

<script>
const ACTIVITY_GRADING_WEIGHTS = <?= json_encode(get_all_grading_weights(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let activityCriteriaRequest = 0;

async function updateActivityCriteria() {
    const period = document.getElementById('activityGradingPeriod').value;
    const year = document.getElementById('activityAcademicYear').value;
    const semester = document.getElementById('activitySemester').value;
    const componentSelect = document.getElementById('activityCriterion');
    componentSelect.innerHTML = '<option value="">Select criterion</option>';
    if (!period || !year || !semester) {
        document.getElementById('activityCriterionMax').textContent = 'Select the term and period to see which score components are missing.';
        return;
    }

    const requestId = ++activityCriteriaRequest;
    const params = new URLSearchParams({
        missing_components: '1',
        student_id: document.getElementById('actModalStudentId').value,
        academic_year: year,
        semester: semester,
        grading_period: period,
    });
    document.getElementById('activityCriterionMax').textContent = 'Checking this student\'s recorded scores…';
    try {
        const response = await fetch(`notifications.php?${params.toString()}`, { credentials: 'same-origin' });
        const result = await response.json();
        if (requestId !== activityCriteriaRequest) return;
        if (!response.ok) throw new Error(result.error || 'Could not load score components.');

        result.components.forEach(item => {
            const option = document.createElement('option');
            option.value = item.name;
            option.textContent = item.name;
            option.dataset.maxScore = item.max_score;
            componentSelect.appendChild(option);
        });
        const firstMissing = result.components.find(item => item.missing);
        if (firstMissing) componentSelect.value = firstMissing.name;
        updateActivityCriterionMax();
        if (!result.components.length) {
            document.getElementById('activityCriterionMax').textContent = 'No supported score components are configured for this period.';
        }
    } catch (error) {
        if (requestId !== activityCriteriaRequest) return;
        document.getElementById('activityCriterionMax').textContent = error.message;
    }
}

function updateActivityCriterionMax() {
    const period = document.getElementById('activityGradingPeriod').value;
    const component = document.getElementById('activityCriterion').value;
    const config = (ACTIVITY_GRADING_WEIGHTS[period] || {})[component];
    document.getElementById('activityCriterionMax').textContent = config
        ? `Maximum raw score: ${config.max_score}.`
        : 'The student\'s score will count toward the selected component.';
}

function openSubmissionModal(data) {
    document.getElementById('subModalActivityId').value = data.id;
    document.getElementById('subModalTitle').innerText = data.title;
    document.getElementById('subModalStudentInfo').innerText = 'Submitted by: ' + data.full_name + ' (' + data.student_no + ')';
    document.getElementById('subModalInstructions').innerText = data.instructions || 'N/A';
    document.getElementById('subModalText').innerText = data.submission_text || 'No additional text or notes provided.';
    
    const scoreInput = document.getElementById('subModalGrade');
    const criterionMax = Number(data.criterion_max_score) || 100;
    scoreInput.max = criterionMax;
    const savedRawScore = Number(data.raw_score ?? (data.grade ? parseFloat(data.grade) : NaN));
    scoreInput.value = Number.isFinite(savedRawScore) ? savedRawScore : '';
    document.getElementById('subModalMaxLabel').textContent = `out of ${criterionMax}`;
    document.getElementById('subModalCriterion').textContent = data.criterion_component
        ? `Counts toward: ${data.criterion_component} • ${data.grading_period} • ${data.academic_year} / ${data.semester}`
        : 'Legacy activity: no grading criterion was selected when it was assigned.';
    document.getElementById('subModalFeedback').value = data.feedback || '';

    const fileBtn = document.getElementById('subModalFileBtn');
    const noFileText = document.getElementById('subModalNoFile');

    const filePath = data.submission_file_path || data.submission_file;

    if (filePath && filePath.trim() !== '') {
        fileBtn.href = 'download.php?file=' + encodeURIComponent(filePath);
        fileBtn.removeAttribute('download');   // download.php sets the header
        fileBtn.style.display = 'inline-block';
        noFileText.style.display = 'none';
    } else {
        fileBtn.style.display = 'none';
        noFileText.style.display = 'inline';
    }

    document.getElementById('submissionModal').style.display = 'flex';
}

function closeSubmissionModal() {
    document.getElementById('submissionModal').style.display = 'none';
}

function openSurveyModal(data) {
    const parentEduMap = { 1: 'Elementary', 2: 'High School', 3: 'College', 4: 'Postgraduate' };

    document.getElementById('modalStudentName').innerText = data.full_name + ' (' + data.student_no + ')';
    document.getElementById('modalStudentMeta').innerText = 'Year/Section: ' + data.year_level + ' - ' + data.section;
    
    document.getElementById('modalInternet').innerText = data.internet_access == 1 ? 'Yes (Available)' : 'No (Limited/None)';
    document.getElementById('modalLiteracy').innerText = (data.digital_literacy || 'N/A') + ' / 5';
    document.getElementById('modalHours').innerText = (data.study_hours || '0') + ' Hours/Week';
    document.getElementById('modalWorking').innerText = data.working_student == 1 ? 'Yes (Part-time / Working)' : 'No (Full-time)';
    document.getElementById('modalIncome').innerText = '₱' + parseFloat(data.household_income || 0).toLocaleString();
    document.getElementById('modalParentEdu').innerText = parentEduMap[data.parental_education] || 'N/A';

    document.getElementById('modalProfileLink').href = 'students.php?id=' + data.student_id;
    document.getElementById('modalMarkReadBtn').href = 'notifications.php?mark_read_id=' + data.id;

    document.getElementById('surveyModal').style.display = 'flex';
}

function closeSurveyModal() {
    document.getElementById('surveyModal').style.display = 'none';
}

function openActivityModal(studentId, name, studentNo) {
    document.getElementById('actModalStudentId').value = studentId;
    document.getElementById('actModalStudentInfo').innerText = 'Assign activity to: ' + name + ' (' + studentNo + ')';
    document.getElementById('activityGradingPeriod').value = '';
    document.getElementById('activityAcademicYear').value = '';
    document.getElementById('activitySemester').value = '';
    updateActivityCriteria();
    document.getElementById('activityModal').style.display = 'flex';
}

function closeActivityModal() {
    document.getElementById('activityModal').style.display = 'none';
}
</script>

<?php page_footer(); ?>
