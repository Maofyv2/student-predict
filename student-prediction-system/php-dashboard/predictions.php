<?php
/**
 * Progressive Student Prediction Page
 * Supports: Prelim → Midterm → Semi-Final → Final
 * Each stage uses only features available at that point.
 */
require_once __DIR__ . '/bootstrap.php';
require_role(['Advisor']);

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function old_value(string $key, string $default = ''): string
{
    return (string) ($_POST[$key] ?? $default);
}

/**
 * Parse a nullable numeric field (empty string → null, never auto-zero).
 */
function nullable_numeric(string $key, float $min, float $max, array &$errors): ?float
{
    $value = $_POST[$key] ?? '';
    if ($value === '' || $value === null) {
        return null; // explicitly missing — NOT treated as zero
    }
    if (!is_numeric($value)) {
        $errors[] = ucwords(str_replace('_', ' ', $key)) . ' must be a number.';
        return null;
    }
    $number = (float)$value;
    if ($number < $min || $number > $max) {
        $errors[] = ucwords(str_replace('_', ' ', $key)) . " must be between {$min} and {$max}.";
    }
    return $number;
}

function required_numeric(string $key, float $min, float $max, array &$errors): ?float
{
    $value = $_POST[$key] ?? '';
    if ($value === '') {
        $errors[] = ucwords(str_replace('_', ' ', $key)) . ' is required.';
        return null;
    }
    return nullable_numeric($key, $min, $max, $errors);
}

/* ------------------------------------------------------------------ */
/* POST handler                                                        */
/* ------------------------------------------------------------------ */

$errors = [];
$result = null;
$computedGradeDebug = null;
$missingList = [];

$PERIODS = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];
$PERIOD_PREV = [
    'Prelim'     => [],
    'Midterm'    => ['Prelim'],
    'Semi-Final' => ['Prelim', 'Midterm'],
    'Final'      => ['Prelim', 'Midterm', 'Semi-Final'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* -------- Student & context fields -------- */
    $studentNo    = trim($_POST['student_no']    ?? '');
    $fullName     = trim($_POST['full_name']     ?? '');
    $yearLevel    = trim($_POST['year_level']    ?? '');
    $section      = trim($_POST['section']       ?? '');
    $gender       = trim($_POST['gender']        ?? '');
    $academicYear = trim($_POST['academic_year'] ?? '');
    $semester     = trim($_POST['semester']      ?? '');
    $scholarshipStatus = trim($_POST['scholarship_status'] ?? 'None');
    $gradingPeriod = trim($_POST['grading_period'] ?? '');

    foreach ([
        'Student number' => $studentNo,
        'Full name'      => $fullName,
        'Year level'     => $yearLevel,
        'Section'        => $section,
        'Academic year'  => $academicYear,
        'Semester'       => $semester,
        'Grading period' => $gradingPeriod,
    ] as $label => $val) {
        if ($val === '') $errors[] = "{$label} is required.";
    }

    if ($gradingPeriod && !in_array($gradingPeriod, $PERIODS, true)) {
        $errors[] = 'Invalid grading period selected.';
    }

    /* -------- Previous period grades -------- */
    $prevGrades = [];
    if (!$errors && $gradingPeriod) {
        foreach ($PERIOD_PREV[$gradingPeriod] as $prevPeriod) {
            $key = strtolower(str_replace('-', '_', $prevPeriod)) . '_actual_grade';
            $val = required_numeric($key, 0, 100, $errors);
            if ($val !== null) $prevGrades[$prevPeriod] = $val;
        }
    }

    /* -------- Current period components -------- */
    $components = [];
    $components['exam_score']       = nullable_numeric('exam_score',       0, 100, $errors);
    $components['quiz_score']       = nullable_numeric('quiz_score',       0, 100, $errors);
    $components['activity_score']   = nullable_numeric('activity_score',   0, 100, $errors);
    $components['assignment_score'] = nullable_numeric('assignment_score', 0, 100, $errors);
    $components['project_score']    = nullable_numeric('project_score',    0, 100, $errors);
    $components['attendance_rate']  = nullable_numeric('attendance_rate',  0, 100, $errors);
    $components['lab_score']        = nullable_numeric('lab_score',        0, 100, $errors);

    /* -------- Socio-demographic fields (loaded from student record/surveys or defaults) -------- */
    $internetAccess    = 1;
    $digitalLiteracy   = 3;
    $householdIncome   = 0.0;
    $parentalEducation = 3;
    $studyHours        = 5.0;
    $workingStudent    = 0;

    if (!empty($studentNo)) {
        $stInfo = db()->prepare("
            SELECT s.household_income, s.parental_education, s.working_student,
                   sv.internet_access, sv.digital_literacy, sv.study_hours
            FROM tbl_students s
            LEFT JOIN tbl_surveys sv ON sv.student_id = s.id
            WHERE s.student_no = ?
            ORDER BY sv.id DESC LIMIT 1
        ");
        if ($stInfo) {
            $stInfo->bind_param('s', $studentNo);
            $stInfo->execute();
            $stRow = $stInfo->get_result()->fetch_assoc();
            if ($stRow) {
                if (isset($stRow['household_income']))   $householdIncome   = (float)$stRow['household_income'];
                if (isset($stRow['parental_education'])) $parentalEducation = (int)$stRow['parental_education'];
                if (isset($stRow['working_student']))    $workingStudent    = (int)$stRow['working_student'];
                if (isset($stRow['internet_access']))    $internetAccess    = (int)$stRow['internet_access'];
                if (isset($stRow['digital_literacy']))   $digitalLiteracy   = (int)$stRow['digital_literacy'];
                if (isset($stRow['study_hours']))        $studyHours        = (float)$stRow['study_hours'];
            }
        }
    }

    if (isset($_POST['internet_access']))    $internetAccess    = (int)$_POST['internet_access'];
    if (isset($_POST['digital_literacy']))   $digitalLiteracy   = (int)$_POST['digital_literacy'];
    if (isset($_POST['household_income']))   $householdIncome   = (float)$_POST['household_income'];
    if (isset($_POST['parental_education'])) $parentalEducation = (int)$_POST['parental_education'];
    if (isset($_POST['study_hours']))        $studyHours        = (float)$_POST['study_hours'];
    if (isset($_POST['working_student']))    $workingStudent    = (int)$_POST['working_student'];

    /* -------- Compute weighted grade -------- */
    if (!$errors && $gradingPeriod) {
        $weights = get_grading_weights($gradingPeriod);
        $gradeResult = compute_weighted_grade($components, $weights, 2);

        if ($gradeResult === null) {
            $errors[] = 'Insufficient data for prediction. Please enter at least 2 assessment component scores.';
        } else {
            $computedGrade      = $gradeResult['grade'];
            $computedGradeDebug = $gradeResult;
            $missingList        = $gradeResult['missing'];
        }
    }

    /* -------- Call Flask API -------- */
    if (!$errors) {
        // Build API payload
        $apiPayload = [
            'grading_period'  => $gradingPeriod,
            'computed_grade'  => $computedGrade,
            'attendance_rate' => $components['attendance_rate'] ?? 85.0,
            'lab_score'       => $components['lab_score']       ?? 80.0,
            'internet_access' => $internetAccess,
            'digital_literacy'=> (int)$digitalLiteracy,
            'household_income'=> (float)$householdIncome,
            'parental_education'=> (int)$parentalEducation,
            'study_hours'     => (float)$studyHours,
            'working_student' => $workingStudent,
        ];

        // Add previous period grades
        $periodGradeMap = [
            'Prelim'     => 'prelim_grade',
            'Midterm'    => 'midterm_grade',
            'Semi-Final' => 'semi_final_grade',
        ];
        foreach ($PERIOD_PREV[$gradingPeriod] as $prevPeriod) {
            $apiKey = $periodGradeMap[$prevPeriod];
            $apiPayload[$apiKey] = $prevGrades[$prevPeriod];
        }

        // Use local API (Render.com is fallback)
        $api = api_request_local('POST', '/predict-progressive', $apiPayload);
        if (!$api['ok']) {
            // Fallback to remote API if local fails
            $api = api_request('POST', '/predict-progressive', $apiPayload);
        }

        if (!$api['ok']) {
            $errors[] = $api['error'] ?? 'Prediction service is unavailable.';
        } else {
            /* -------- Save to database -------- */
            $conn = db();
            $metadata = model_metadata();
            $conn->begin_transaction();

            try {
                /* Upsert student — preserve advisor_id/professor_id ownership */
                $stmt = $conn->prepare(
                    'INSERT INTO tbl_students
                        (student_no, full_name, year_level, section, gender, household_income,
                         parental_education, scholarship_status, working_student,
                         advisor_id, professor_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        full_name=VALUES(full_name), year_level=VALUES(year_level),
                        section=VALUES(section), gender=VALUES(gender),
                        household_income=VALUES(household_income),
                        parental_education=VALUES(parental_education),
                        scholarship_status=VALUES(scholarship_status),
                        working_student=VALUES(working_student),
                        advisor_id=COALESCE(advisor_id, VALUES(advisor_id)),
                        professor_id=COALESCE(professor_id, VALUES(professor_id))'
                );
                $currentUser = current_user();
                $ownerId = (int) ($currentUser['id'] ?? 0) ?: null;
                $stmt->bind_param('sssssdiisii',
                    $studentNo, $fullName, $yearLevel, $section, $gender,
                    $householdIncome, $parentalEducation, $scholarshipStatus, $workingStudent,
                    $ownerId, $ownerId
                );
                $stmt->execute();

                $stmt = $conn->prepare('SELECT id FROM tbl_students WHERE student_no = ? LIMIT 1');
                $stmt->bind_param('s', $studentNo);
                $stmt->execute();
                $studentId = (int)$stmt->get_result()->fetch_assoc()['id'];

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, device_availability, study_hours)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $dlInt = (int)$digitalLiteracy;
                $devAvail = 'Unknown';
                $stmt->bind_param('iiisd', $studentId, $internetAccess, $dlInt, $devAvail, $studyHours);
                $stmt->execute();

                /* Save grade components */
                $examScore       = $components['exam_score'];
                $quizScore       = $components['quiz_score'];
                $activityScore   = $components['activity_score'];
                $assignmentScore = $components['assignment_score'];
                $projectScore    = $components['project_score'];
                $attendanceRate  = $components['attendance_rate'];
                $labScore        = $components['lab_score'];
                $missingJson     = json_encode($missingList);

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_grade_components
                        (student_id, academic_year, semester, period, exam_score, quiz_score,
                         activity_score, assignment_score, project_score, attendance_rate,
                         lab_score, computed_grade, missing_components)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        exam_score=VALUES(exam_score), quiz_score=VALUES(quiz_score),
                        activity_score=VALUES(activity_score), assignment_score=VALUES(assignment_score),
                        project_score=VALUES(project_score), attendance_rate=VALUES(attendance_rate),
                        lab_score=VALUES(lab_score), computed_grade=VALUES(computed_grade),
                        missing_components=VALUES(missing_components), updated_at=CURRENT_TIMESTAMP'
                );
                $stmt->bind_param('isssdddddddds',
                    $studentId, $academicYear, $semester, $gradingPeriod,
                    $examScore, $quizScore, $activityScore, $assignmentScore,
                    $projectScore, $attendanceRate, $labScore, $computedGrade, $missingJson
                );
                $stmt->execute();

                /* Insert academic record stub */
                $prelimGrade = ($gradingPeriod === 'Prelim' ? $computedGrade : ($prevGrades['Prelim'] ?? 0));
                $midtermGrade = ($gradingPeriod === 'Midterm' ? $computedGrade : ($prevGrades['Midterm'] ?? 0));
                $semiGrade  = ($gradingPeriod === 'Semi-Final' ? $computedGrade : ($prevGrades['Semi-Final'] ?? 0));
                $finalGrade = ($gradingPeriod === 'Final' ? $computedGrade : 0);
                $attRate    = $components['attendance_rate'] ?? 0;
                $lScore     = $components['lab_score'] ?? 0;

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_academic_records
                        (student_id, academic_year, semester, prelim_grade, midterm_grade,
                         semi_final_grade, final_grade, attendance_rate, lab_score)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('issdddddd',
                    $studentId, $academicYear, $semester,
                    $prelimGrade, $midtermGrade, $semiGrade, $finalGrade,
                    $attRate, $lScore
                );
                $stmt->execute();
                $academicRecordId = (int)$conn->insert_id;

                /* Insert prediction */
                $prediction    = (string)$api['data']['prediction'];
                $predictedGrade = (float)($api['data']['predicted_grade'] ?? $computedGrade);
                $confidence    = (float)$api['data']['confidence'];
                $recomm        = (string)$api['data']['recommendation'];
                $riskFactors   = json_encode($api['data']['risk_factors'] ?? []);
                $missingComp   = json_encode($missingList);
                $featurePayload= json_encode($apiPayload);
                $modelAccuracy = (float)($api['data']['model_accuracy'] ?? $metadata['accuracy'] ?? 0);
                $f1Score       = (float)($api['data']['model_f1'] ?? $metadata['weighted_f1'] ?? 0);
                $algorithm     = 'XGBoost Progressive (' . $gradingPeriod . ')';
                $createdBy     = (int)current_user()['id'];

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_predictions
                        (student_id, academic_record_id, grading_period, predicted_status,
                         predicted_grade, confidence, recommendation, risk_factors,
                         missing_components, feature_payload, model_accuracy,
                         f1_score_log, algorithm, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('iissddssssddsi',
                    $studentId, $academicRecordId, $gradingPeriod, $prediction,
                    $predictedGrade, $confidence, $recomm, $riskFactors,
                    $missingComp, $featurePayload, $modelAccuracy,
                    $f1Score, $algorithm, $createdBy
                );
                $stmt->execute();

                /* Create alert if at-risk or fail */
                if ($prediction === 'At-Risk' || $prediction === 'Fail') {
                    $sAdv = $conn->prepare('SELECT advisor_id FROM tbl_students WHERE id = ?');
                    $sAdv->bind_param('i', $studentId);
                    $sAdv->execute();
                    $advRow = $sAdv->get_result()->fetch_assoc();
                    $advisorToNotify = $advRow['advisor_id'] ?? $createdBy;
                    $severity = ($prediction === 'Fail') ? 'High' : 'Medium';
                    $msg = "Student {$fullName} ({$studentNo}) — {$gradingPeriod} period — flagged as '{$prediction}' with " . round($confidence * 100, 1) . "% confidence.";
                    create_alert($studentId, $advisorToNotify, 'Risk', $severity, $msg);
                }

                $conn->commit();
                $result = $api['data'];
                $result['algorithm']    = $algorithm;
                $result['accuracy']     = $modelAccuracy;
                $result['computed_grade'] = $computedGrade;
                $result['missing']      = $missingList;
                $result['grading_period'] = $gradingPeriod;
                $result['student_id']   = $studentId;
                $result['student_no']  = $studentNo;
                $result['academic_year']= $academicYear;
                $result['semester']     = $semester;

            } catch (Throwable $ex) {
                $conn->rollback();
                $errors[] = 'Prediction generated but could not be saved: ' . $ex->getMessage();
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Load grading weights for JS                                         */
/* ------------------------------------------------------------------ */
$allWeights = get_all_grading_weights();

/* ------------------------------------------------------------------ */
/* Load enrolled students list for autofill / dropdown                 */
/* ------------------------------------------------------------------ */
// Enforce ownership: professors only see their own students
$_cu = current_user();
$_isAdvisor = ($_cu['role'] === 'Advisor');
$_advisorId  = (int)$_cu['id'];

if ($_isAdvisor) {
    $registeredStudentsStmt = db()->prepare(
        "SELECT id, student_no, full_name, year_level, section, gender, scholarship_status,
                household_income, parental_education, working_student
         FROM tbl_students
         WHERE (advisor_id = ? OR professor_id = ?)
         ORDER BY full_name ASC"
    );
    $registeredStudentsStmt->bind_param('ii', $_advisorId, $_advisorId);
    $registeredStudentsStmt->execute();
    $registeredStudents = $registeredStudentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $registeredStudents = db()->query(
        "SELECT id, student_no, full_name, year_level, section, gender, scholarship_status,
                household_income, parental_education, working_student
         FROM tbl_students
         ORDER BY full_name ASC"
    )->fetch_all(MYSQLI_ASSOC);
}

$registeredStudentsMap = [];
foreach ($registeredStudents as $st) {
    $registeredStudentsMap[$st['student_no']] = $st;
}

page_header('Progressive Prediction');
?>

<style>
/* ---- Progressive Prediction Styles ---- */
.period-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--line);
    margin-bottom: 24px;
}
.period-tab {
    padding: 11px 22px;
    cursor: pointer;
    border: none;
    background: transparent;
    font: inherit;
    font-weight: 600;
    color: var(--muted);
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    display: flex;
    align-items: center;
    gap: 7px;
}
.period-tab.active {
    color: var(--blue);
    border-bottom-color: var(--blue);
}
.period-tab .tab-badge {
    font-size: .72rem;
    padding: 2px 7px;
    border-radius: 999px;
    font-weight: 700;
}
.tab-badge-done   { background: var(--green-bg);  color: var(--green); }
.tab-badge-active { background: #e0eaff; color: var(--blue); }
.tab-badge-future { background: #f3f4f6; color: var(--muted); }

.previous-grades-banner {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.prev-grade-card {
    background: var(--surface-strong);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 12px 14px;
    text-align: center;
}
.prev-grade-card .pgc-label { font-size: .78rem; color: var(--muted); font-weight: 700; text-transform: uppercase; }
.prev-grade-card .pgc-value { font-size: 1.5rem; font-weight: 800; color: var(--blue); margin-top: 2px; }
.prev-grade-card .pgc-tag   { font-size: .7rem; background: var(--green-bg); color: var(--green); padding: 2px 7px; border-radius: 999px; font-weight: 700; }

.weight-badge {
    display: inline-block;
    font-size: .7rem;
    background: #e0eaff;
    color: var(--blue);
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 700;
    margin-left: 4px;
}
.field-missing-tag {
    font-size: .72rem;
    color: var(--amber);
    font-weight: 700;
    margin-left: 4px;
}
.computed-grade-preview {
    background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);
    color: #fff;
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}
.cgp-label { font-size: .85rem; opacity: .85; }
.cgp-value { font-size: 2rem; font-weight: 800; }
.cgp-note  { font-size: .75rem; opacity: .75; }

.insufficient-warning {
    background: var(--amber-bg);
    border: 1px solid #ffd98a;
    border-radius: 8px;
    padding: 12px 16px;
    color: #7a4100;
    font-weight: 600;
    margin-bottom: 14px;
    display: none;
}

.result-progressive {
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid currentColor;
}
.result-progressive .rp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 14px;
}
.rp-grade-big { font-size: 2.5rem; font-weight: 800; }
.rp-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-top: 12px; }
.rp-meta-item label { font-size: .75rem; color: var(--muted); font-weight: 700; display: block; }
.rp-meta-item strong { font-size: 1rem; }
.progress-bar-container { width: 100%; background: #e0e0e0; border-radius: 8px; overflow: hidden; margin-top: 5px; height: 16px; }
.progress-bar-fill { height: 100%; background: var(--blue); transition: width .4s ease; }

.period-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.psg-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 14px;
}
.psg-card .period-name { font-size: .75rem; font-weight: 800; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.psg-card .actual-grade { font-size: 1.3rem; font-weight: 800; color: var(--text); }
.psg-card .pred-grade { font-size: .88rem; color: var(--muted); }
.psg-card .na-tag { font-size: .85rem; color: var(--muted); font-style: italic; }

@media (max-width: 760px) {
    .period-summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 460px) {
    .period-summary-grid { grid-template-columns: 1fr; }
    .period-tabs { flex-wrap: wrap; }
}
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow">Progressive Assessment</p>
        <h1>Student Grade Prediction</h1>
    </div>
</section>

<?php if ($errors): ?>
    <div class="alert alert-error" id="error-box">
        <?php foreach ($errors as $err): ?>
            <div><?= h($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($result): ?>
    <?php
    $confPct = round((float)$result['confidence'] * 100, 1);
    $gradePct = round((float)$result['computed_grade'], 1);
    $statusCls = status_class($result['prediction']);
    ?>
    <section class="result-progressive <?= h($statusCls) ?>" id="result-banner">
        <div class="rp-header">
            <div>
                <small style="display:block;font-size:.8rem;color:#475467;margin-bottom:4px;">
                    <?= h($result['grading_period']) ?> Prediction
                </small>
                <div class="rp-grade-big"><?= h((string)$gradePct) ?>%</div>
                <span class="status <?= h($statusCls) ?>" style="margin-top:6px;"><?= h($result['prediction']) ?></span>
            </div>
            <div style="min-width:180px;">
                <small>Confidence</small>
                <strong style="font-size:1.4rem;display:block;"><?= $confPct ?>%</strong>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width:<?= $confPct ?>%;"></div>
                </div>
            </div>
            <p style="flex:1;margin:0;font-size:.9rem;"><?= h($result['recommendation'] ?? '') ?></p>
        </div>
        <div class="rp-meta-grid">
            <div class="rp-meta-item"><label>Date</label><strong><?= date('M d, Y H:i') ?></strong></div>
            <div class="rp-meta-item"><label>Algorithm</label><strong><?= h($result['algorithm'] ?? '') ?></strong></div>
            <div class="rp-meta-item"><label>Model Accuracy</label><strong><?= round(($result['accuracy'] ?? 0) * 100, 1) ?>%</strong></div>
            <?php if (!empty($result['missing'])): ?>
            <div class="rp-meta-item">
                <label>Missing Components</label>
                <strong style="color:var(--amber)"><?= h(implode(', ', $result['missing'])) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // --- Period Grades Summary ---
        $pgStudentId = null;
        if (!empty($result['student_no'])) {
            $pgStmt = db()->prepare("SELECT id FROM tbl_students WHERE student_no = ? LIMIT 1");
            $pgStmt->bind_param('s', $result['student_no']);
            $pgStmt->execute();
            $pgRow = $pgStmt->get_result()->fetch_assoc();
            $pgStudentId = $pgRow['id'] ?? null;
        }
        $pgAY  = $result['academic_year'] ?? old_value('academic_year');
        $pgSem = $result['semester']      ?? old_value('semester');
        $pgGrades = [];
        if ($pgStudentId && $pgAY && $pgSem) {
            $pgQ = db()->prepare(
                "SELECT period, computed_grade FROM tbl_grade_components
                  WHERE student_id = ? AND academic_year = ? AND semester = ?
                  ORDER BY FIELD(period,'Prelim','Midterm','Semi-Final','Final')"
            );
            $pgQ->bind_param('iss', $pgStudentId, $pgAY, $pgSem);
            $pgQ->execute();
            foreach ($pgQ->get_result()->fetch_all(MYSQLI_ASSOC) as $pgr) {
                $pgGrades[$pgr['period']] = (float)$pgr['computed_grade'];
            }
        }
        // Include current predicted period
        if (!empty($result['grading_period']) && !isset($pgGrades[$result['grading_period']])) {
            $pgGrades[$result['grading_period']] = (float)($result['computed_grade'] ?? 0);
        }
        $gwaVal = count($pgGrades) > 0 ? array_sum($pgGrades) / count($pgGrades) : null;
        ?>

        <?php if (!empty($pgGrades)): ?>
        <div style="margin-top:20px;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px;">Period Grades</div>
            <div class="period-summary-grid">
                <?php foreach (['Prelim','Midterm','Semi-Final','Final'] as $pg_period): ?>
                <div class="psg-card">
                    <div class="period-name"><?= h($pg_period) ?></div>
                    <?php if (isset($pgGrades[$pg_period])): ?>
                        <div class="actual-grade"><?= number_format($pgGrades[$pg_period], 2) ?>%</div>
                        <?php if ($pg_period === ($result['grading_period'] ?? '')): ?>
                            <div class="pred-grade" style="color:var(--blue);font-size:.75rem;font-weight:700;margin-top:3px;">Current Period</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="na-tag">Not yet entered</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php endif; ?>
    </section>
    <?php if (!empty($result['risk_factors'])): ?>
        <section class="panel" style="margin-bottom:16px;">
            <div class="panel-title"><h2>Risk Factors</h2></div>
            <div class="chip-list">
                <?php foreach ($result['risk_factors'] as $f): ?>
                    <span class="chip" style="background:var(--amber-bg);color:#7a4100;border:1px solid #ffd98a;"><?= h($f) ?></span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<!-- Prediction form -->
<form method="post" class="panel form-panel" id="prediction-form" autocomplete="off">

    <!-- Section 1: Student & Context -->
    <div class="form-section">
        <h2>Student & Grading Context</h2>

        <!-- Quick Select Student Dropdown -->
        <div style="margin-bottom: 20px; padding: 14px 18px; background: var(--surface-strong); border-radius: 8px; border: 1px solid var(--line);">
            <label for="student_quick_select" style="display: block; margin-bottom: 6px; font-weight: 700; font-size: 0.9rem; color: var(--text);">
                Select Student:
            </label>
            <select id="student_quick_select" style="width: 100%; padding: 10px 14px; border: 1px solid var(--line); border-radius: 6px; background: #fff; font-size: 0.95rem; color: var(--text);">
                <option value="">— Select an enrolled student to autofill details —</option>
                <?php foreach ($registeredStudents as $st): ?>
                    <option value="<?= h($st['student_no']) ?>" <?= old_value('student_no') === $st['student_no'] ? 'selected' : '' ?>>
                        <?= h($st['student_no']) ?> — <?= h($st['full_name']) ?> (<?= h($st['year_level']) ?>, <?= h($st['section']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="student_autofill_indicator" style="display: none; margin-top: 8px; font-size: 0.85rem; color: var(--green); font-weight: 600;">
                Student details autofilled.
            </div>
        </div>

        <div class="form-grid">
            <label>
                <span>Student No.</span>
                <input id="student_no" name="student_no" list="student_datalist"
                       value="<?= h(old_value('student_no')) ?>" required
                       placeholder="e.g. 2026-0001" autocomplete="off">
                <datalist id="student_datalist">
                    <?php foreach ($registeredStudents as $st): ?>
                        <option value="<?= h($st['student_no']) ?>"><?= h($st['full_name']) ?> — <?= h($st['year_level']) ?> (<?= h($st['section']) ?>)</option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label>
                <span>Full Name</span>
                <input id="full_name" name="full_name"
                       value="<?= h(old_value('full_name')) ?>" required>
            </label>
            <label>
                <span>Year Level</span>
                <select id="year_level" name="year_level" required>
                    <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $opt): ?>
                        <option <?= old_value('year_level','3rd Year') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Section</span>
                <input id="section" name="section"
                       value="<?= h(old_value('section','BSIT-1')) ?>" required>
            </label>
            <label>
                <span>Gender</span>
                <select id="gender" name="gender">
                    <?php foreach (['' => 'Select', 'Female' => 'Female', 'Male' => 'Male', 'Prefer not to say' => 'Prefer not to say'] as $v => $l): ?>
                        <option value="<?= h($v) ?>" <?= old_value('gender') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Scholarship</span>
                <select id="scholarship_status" name="scholarship_status">
                    <?php foreach (['None','CHED','TES','Academic','Athletic','Others'] as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= old_value('scholarship_status','None') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Academic Year</span>
                <select name="academic_year" id="academic_year" required onchange="onContextChange()">
                    <?php foreach (['2025-2026','2026-2027','2027-2028'] as $opt): ?>
                        <option <?= old_value('academic_year','2025-2026') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Semester</span>
                <select name="semester" id="semester" required onchange="onContextChange()">
                    <?php foreach (['1st Semester','2nd Semester','Summer'] as $opt): ?>
                        <option <?= old_value('semester','1st Semester') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Grading Period</span>
                <select name="grading_period" id="grading_period" required onchange="onPeriodChange()">
                    <option value="">— Select —</option>
                    <?php foreach (['Prelim','Midterm','Semi-Final','Final'] as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= old_value('grading_period') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <!-- Section 2: Previous Period Grades (shown dynamically) -->
    <div class="form-section" id="prev-grades-section" style="display:none;">
        <h2>Previous Period Grades <small style="font-weight:400;color:var(--muted);">(Actual)</small></h2>
        <div class="previous-grades-banner" id="prev-grades-banner"></div>
        <div class="form-grid" id="prev-grades-inputs"></div>
    </div>

    <!-- Section 3: Current Period Components -->
    <div class="form-section" id="components-section" style="display:none;">
        <h2>Current Period Assessment Components <span id="period-label-h" style="color:var(--blue);"></span></h2>
        <p style="font-size:.85rem;color:var(--muted);margin:0 0 12px;">
            Leave a component blank if the score has not been recorded yet.
            A blank value means <em>Missing / Not Yet Submitted</em> — it is <strong>not</strong> treated as zero.
        </p>
        <div class="insufficient-warning" id="insuf-warning">
            Notice: Not enough components filled in. Please enter at least 2 assessment scores to generate a prediction.
        </div>
        <div class="form-grid" id="components-grid">
            <?php
            $compFields = [
                'exam_score'       => ['label' => 'Exam Score',       'key' => 'Exam'],
                'quiz_score'       => ['label' => 'Quiz Score',       'key' => 'Quiz'],
                'activity_score'   => ['label' => 'Activities Score', 'key' => 'Activities'],
                'assignment_score' => ['label' => 'Assignment Score', 'key' => 'Assignment'],
                'project_score'    => ['label' => 'Project Score',    'key' => 'Project'],
                'attendance_rate'  => ['label' => 'Attendance Rate (%)', 'key' => null],
                'lab_score'        => ['label' => 'Lab Score',        'key' => null],
            ];
            foreach ($compFields as $name => $meta):
                $posted = old_value($name);
            ?>
                <label>
                    <span>
                        <?= h($meta['label']) ?>
                        <?php if ($meta['key']): ?>
                            <span class="weight-badge" data-comp="<?= h($meta['key']) ?>">?%</span>
                        <?php endif; ?>
                    </span>
                    <input type="number" step="0.01" min="0" max="100"
                           name="<?= h($name) ?>" id="field_<?= h($name) ?>"
                           value="<?= h($posted) ?>"
                           placeholder="Leave blank if missing"
                           oninput="checkMinInputs()">
                </label>
            <?php endforeach; ?>
        </div>


    </div>



    <div class="form-actions" id="submit-actions" style="display:none;">
        <button class="button button-primary" type="submit" id="submit-btn">
            Generate Prediction
        </button>
    </div>
</form>

<script>
/* --------------------------------------------------------------- */
/* Grading weights from PHP                                        */
/* --------------------------------------------------------------- */
const ALL_WEIGHTS = <?= json_encode($allWeights) ?>;
const PERIOD_PREV = {
    'Prelim':     [],
    'Midterm':    ['Prelim'],
    'Semi-Final': ['Prelim','Midterm'],
    'Final':      ['Prelim','Midterm','Semi-Final']
};

const GRADE_FIELD_KEY = {
    'exam_score':       'Exam',
    'quiz_score':       'Quiz',
    'activity_score':   'Activities',
    'assignment_score': 'Assignment',
    'project_score':    'Project',
};

let knownPeriodGrades = {};   // {Prelim: 85.0, Midterm: 87.0, ...}
let currentPeriod     = '';

const REGISTERED_STUDENTS = <?= json_encode($registeredStudentsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

/* --------------------------------------------------------------- */
/* Student autofill helper                                         */
/* --------------------------------------------------------------- */
function applyStudentProfile(student, sv = {}) {
    if (!student) return;

    if (student.student_no) {
        const snoEl = document.getElementById('student_no');
        if (snoEl && snoEl.value !== student.student_no) snoEl.value = student.student_no;
        const qsEl = document.getElementById('student_quick_select');
        if (qsEl && qsEl.value !== student.student_no) qsEl.value = student.student_no;
    }

    if (student.full_name) document.getElementById('full_name').value = student.full_name;
    if (student.year_level) document.getElementById('year_level').value = student.year_level;
    if (student.section) document.getElementById('section').value = student.section;
    if (student.gender) document.getElementById('gender').value = student.gender;
    if (student.scholarship_status) document.getElementById('scholarship_status').value = student.scholarship_status;

    if (student.household_income !== undefined && student.household_income !== null && student.household_income !== '') {
        const el = document.getElementById('household_income');
        if (el) el.value = student.household_income;
    }
    if (student.parental_education !== undefined && student.parental_education !== null) {
        const el = document.getElementById('parental_education');
        if (el) el.value = student.parental_education;
    }
    if (student.working_student !== undefined && student.working_student !== null) {
        const el = document.getElementById('working_student');
        if (el) el.value = student.working_student;
    }

    if (sv && sv.digital_literacy) {
        const el = document.getElementById('digital_literacy');
        if (el) el.value = sv.digital_literacy;
    }
    if (sv && sv.study_hours) {
        const el = document.getElementById('study_hours');
        if (el) el.value = sv.study_hours;
    }

    const ind = document.getElementById('student_autofill_indicator');
    if (ind) {
        ind.style.display = 'block';
        const yr = student.year_level ? ' • ' + student.year_level : '';
        const sec = student.section ? ' (' + student.section + ')' : '';
        ind.textContent = `Autofilled: ${student.full_name || student.student_no}${yr}${sec}`;
    }
}

function onStudentNoChange(sno) {
    sno = (sno || '').trim();
    if (!sno) {
        const ind = document.getElementById('student_autofill_indicator');
        if (ind) ind.style.display = 'none';
        return;
    }

    // 1. Instant client-side autofill from known registered students
    if (REGISTERED_STUDENTS[sno]) {
        applyStudentProfile(REGISTERED_STUDENTS[sno]);
    }

    // 2. Fetch full academic & period grades
    fetchStudentData(sno);
}

// Quick select dropdown listener
const quickSelectEl = document.getElementById('student_quick_select');
if (quickSelectEl) {
    quickSelectEl.addEventListener('change', function() {
        if (this.value) {
            document.getElementById('student_no').value = this.value;
            onStudentNoChange(this.value);
        }
    });
}

// Student No input listeners (input, change, blur)
const snoInput = document.getElementById('student_no');
if (snoInput) {
    snoInput.addEventListener('input', function() {
        const val = this.value.trim();
        if (REGISTERED_STUDENTS[val]) {
            onStudentNoChange(val);
        }
    });
    snoInput.addEventListener('change', function() {
        onStudentNoChange(this.value);
    });
    snoInput.addEventListener('blur', function() {
        onStudentNoChange(this.value);
    });
}

function fetchStudentData(sno) {
    sno = (sno || '').trim();
    if (!sno) return;
    const ay  = document.getElementById('academic_year').value;
    const sem = document.getElementById('semester').value;
    const per = document.getElementById('grading_period').value;
    const url = `get_student_grades.php?student_no=${encodeURIComponent(sno)}&academic_year=${encodeURIComponent(ay)}&semester=${encodeURIComponent(sem)}&period=${encodeURIComponent(per)}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.data) return;
            const s = res.data.student || {};
            const sv = res.data.survey || {};
            const pg = res.data.period_grades || {};
            const pc = res.data.period_components;

            applyStudentProfile(s, sv);

            // Store known period grades
            knownPeriodGrades = pg;
            updatePrevGradesBanner();

            // Pre-fill current period components if available
            if (pc) {
                if (pc.exam_score !== null)       document.getElementById('field_exam_score').value       = pc.exam_score;
                if (pc.quiz_score !== null)        document.getElementById('field_quiz_score').value       = pc.quiz_score;
                if (pc.activity_score !== null)    document.getElementById('field_activity_score').value   = pc.activity_score;
                if (pc.assignment_score !== null)  document.getElementById('field_assignment_score').value = pc.assignment_score;
                if (pc.project_score !== null)     document.getElementById('field_project_score').value    = pc.project_score;
                if (pc.attendance_rate !== null)   document.getElementById('field_attendance_rate').value  = pc.attendance_rate;
                if (pc.lab_score !== null)         document.getElementById('field_lab_score').value        = pc.lab_score;
                checkMinInputs();
            }
        })
        .catch(err => console.error('Error fetching student:', err));
}

/* --------------------------------------------------------------- */
/* Period selection change                                          */
/* --------------------------------------------------------------- */
function onPeriodChange() {
    currentPeriod = document.getElementById('grading_period').value;
    if (!currentPeriod) {
        hideAllSections(); return;
    }

    updateWeightBadges();
    updatePrevGradesBanner();
    showSections();
    checkMinInputs();

    // Reload period-specific components from DB
    const sno = document.getElementById('student_no').value.trim();
    if (sno) fetchStudentData(sno);
}

function onContextChange() {
    const sno = document.getElementById('student_no').value.trim();
    if (sno) fetchStudentData(sno);
}

function hideAllSections() {
    document.getElementById('prev-grades-section').style.display = 'none';
    document.getElementById('components-section').style.display  = 'none';
    document.getElementById('submit-actions').style.display      = 'none';
}

function showSections() {
    const prev = PERIOD_PREV[currentPeriod] || [];
    document.getElementById('prev-grades-section').style.display = prev.length ? '' : 'none';
    document.getElementById('components-section').style.display  = '';
    document.getElementById('submit-actions').style.display      = '';
    document.getElementById('period-label-h').textContent        = '— ' + currentPeriod;
}

/* --------------------------------------------------------------- */
/* Previous grades banner                                          */
/* --------------------------------------------------------------- */
function updatePrevGradesBanner() {
    const banner = document.getElementById('prev-grades-banner');
    const inputs = document.getElementById('prev-grades-inputs');
    banner.innerHTML = '';
    inputs.innerHTML = '';

    const prev = PERIOD_PREV[currentPeriod] || [];
    prev.forEach(period => {
        const grade = knownPeriodGrades[period];
        const fieldKey = period.toLowerCase().replace('-','_') + '_actual_grade';
        const hasGrade = grade !== undefined && grade !== null;

        // Visual card
        const card = document.createElement('div');
        card.className = 'prev-grade-card';
        card.innerHTML = `
            <div class="pgc-label">${period}</div>
            <div class="pgc-value">${hasGrade ? parseFloat(grade).toFixed(1) + '%' : '—'}</div>
            <span class="pgc-tag">Actual Grade</span>`;
        banner.appendChild(card);

        // Hidden or visible input
        const label = document.createElement('label');
        label.innerHTML = `
            <span>${period} Actual Grade</span>
            <input type="number" step="0.01" min="0" max="100"
                   name="${fieldKey}" id="field_${fieldKey}"
                   value="${hasGrade ? parseFloat(grade).toFixed(2) : ''}"
                   required placeholder="Enter actual grade">`;
        inputs.appendChild(label);
    });
}

/* --------------------------------------------------------------- */
/* Weight badges                                                    */
/* --------------------------------------------------------------- */
function updateWeightBadges() {
    const weights = (ALL_WEIGHTS[currentPeriod] || {});
    document.querySelectorAll('.weight-badge[data-comp]').forEach(badge => {
        const comp = badge.dataset.comp;
        const cfg  = weights[comp];
        if (cfg !== undefined) {
            const wt = typeof cfg === 'object' ? cfg.weight : cfg;
            badge.textContent = wt + '%';
        } else {
            badge.textContent = '?%';
        }
    });
}

/* --------------------------------------------------------------- */
/* Minimum-input guard (replaces live grade compute)               */
/* --------------------------------------------------------------- */
function checkMinInputs() {
    const fieldIds = [
        'field_exam_score', 'field_quiz_score', 'field_activity_score',
        'field_assignment_score', 'field_project_score',
        'field_attendance_rate', 'field_lab_score'
    ];
    const filled = fieldIds.filter(id => {
        const el = document.getElementById(id);
        return el && el.value.trim() !== '';
    }).length;

    const insufEl = document.getElementById('insuf-warning');
    const btn     = document.getElementById('submit-btn');

    if (filled < 2) {
        insufEl.style.display = '';
        btn.disabled = true;
        btn.textContent = 'Need more data…';
    } else {
        insufEl.style.display = 'none';
        btn.disabled = false;
        btn.textContent = 'Generate Prediction';
    }
}

/* --------------------------------------------------------------- */
/* Submit handler                                                   */
/* --------------------------------------------------------------- */
document.getElementById('prediction-form').addEventListener('submit', function() {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Analyzing & Predicting…';
});

/* --------------------------------------------------------------- */
/* Init on page load (restore POST state)                          */
/* --------------------------------------------------------------- */
const initialPeriod = document.getElementById('grading_period').value;
if (initialPeriod) {
    currentPeriod = initialPeriod;
    updateWeightBadges();
    updatePrevGradesBanner();
    showSections();
    checkMinInputs();
}

const initialSno = document.getElementById('student_no').value.trim();
if (initialSno) {
    onStudentNoChange(initialSno);
}

// Auto-scroll to result
<?php if ($result): ?>
    document.getElementById('result-banner')?.scrollIntoView({behavior:'smooth', block:'center'});
<?php endif; ?>
</script>

<?php page_footer(); ?>
