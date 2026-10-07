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
$missingList = [];

/* Period ordering */
$PERIODS = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];
$PERIOD_NEXT = [
    'Prelim'     => 'Midterm',
    'Midterm'    => 'Semi-Final',
    'Semi-Final' => 'Final',
];
$PERIOD_PREV = [
    'Prelim'     => [],
    'Midterm'    => ['Prelim'],
    'Semi-Final' => ['Prelim', 'Midterm'],
    'Final'      => ['Prelim', 'Midterm', 'Semi-Final'],
];
$PERIOD_GRADE_KEY = [
    'Prelim'     => 'prelim_grade',
    'Midterm'    => 'midterm_grade',
    'Semi-Final' => 'semi_final_grade',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* -------- Context fields -------- */
    $studentNo    = trim($_POST['student_no']    ?? '');
    $academicYear = trim($_POST['academic_year'] ?? '');
    $semester     = trim($_POST['semester']      ?? '');

    foreach ([
        'Student number' => $studentNo,
        'Academic year'  => $academicYear,
        'Semester'       => $semester,
    ] as $label => $val) {
        if ($val === '') $errors[] = "{$label} is required.";
    }

    /* -------- Look up student in DB -------- */
    $studentId = null;
    $studentRec = null;
    if (!$errors) {
        $stStmt = db()->prepare("SELECT * FROM tbl_students WHERE student_no = ? LIMIT 1");
        $stStmt->bind_param('s', $studentNo);
        $stStmt->execute();
        $studentRec = $stStmt->get_result()->fetch_assoc();
        if (!$studentRec) {
            $errors[] = "Student number '{$studentNo}' not found. Please enter scores first via Enter Scores.";
        } else {
            $studentId = (int)$studentRec['id'];
        }
    }

    /* -------- Auto-detect latest saved grading period -------- */
    $latestPeriod = null;    // The period we have actual data for
    $targetPeriod = null;    // The period we are forecasting
    $savedPeriodGrades = []; // All saved grades for this student/year/sem
    $savedComponents   = []; // Components from the latest period

    if (!$errors) {
        $periodOrder = array_flip($PERIODS); // Prelim=0, Midterm=1, Semi-Final=2, Final=3

        $gcStmt = db()->prepare(
            "SELECT period, computed_grade, attendance_rate, lab_score, exam_score, quiz_score,
                    activity_score, assignment_score, project_score
             FROM tbl_grade_components
             WHERE student_id = ? AND academic_year = ? AND semester = ?
             ORDER BY FIELD(period,'Prelim','Midterm','Semi-Final','Final')"
        );
        $gcStmt->bind_param('iss', $studentId, $academicYear, $semester);
        $gcStmt->execute();
        $gcRows = $gcStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($gcRows as $row) {
            $savedPeriodGrades[$row['period']] = (float)$row['computed_grade'];
            $savedComponents[$row['period']] = $row;
        }

        // Determine the latest period that has a saved grade
        foreach (array_reverse($PERIODS) as $p) {
            if (isset($savedPeriodGrades[$p])) {
                $latestPeriod = $p;
                break;
            }
        }

        if ($latestPeriod === null) {
            $errors[] = "No saved grades found for {$studentNo} in {$academicYear} {$semester}. Please enter scores first.";
        } elseif ($latestPeriod === 'Final') {
            $errors[] = "Final is the last grading period. No next-period forecast is available for {$studentNo}.";
        } else {
            $targetPeriod = $PERIOD_NEXT[$latestPeriod];
        }
    }

    /* -------- Load survey/socio-demographic data -------- */
    $internetAccess    = 1;
    $digitalLiteracy   = 3;
    $householdIncome   = 0.0;
    $parentalEducation = 3;
    $studyHours        = 5.0;
    $workingStudent    = 0;

    if ($studentRec) {
        if (isset($studentRec['household_income']))   $householdIncome   = (float)$studentRec['household_income'];
        if (isset($studentRec['parental_education'])) $parentalEducation = (int)$studentRec['parental_education'];
        if (isset($studentRec['working_student']))    $workingStudent    = (int)$studentRec['working_student'];

        $svQ = db()->prepare("SELECT internet_access, digital_literacy, study_hours FROM tbl_surveys WHERE student_id = ? ORDER BY id DESC LIMIT 1");
        if ($svQ) {
            $svQ->bind_param('i', $studentId);
            $svQ->execute();
            $svRow = $svQ->get_result()->fetch_assoc();
            if ($svRow) {
                if (isset($svRow['internet_access']))  $internetAccess  = (int)$svRow['internet_access'];
                if (isset($svRow['digital_literacy'])) $digitalLiteracy = (int)$svRow['digital_literacy'];
                if (isset($svRow['study_hours']))      $studyHours      = (float)$svRow['study_hours'];
            }
        }
    }

    // Allow form overrides for socio-demographic fields
    if (isset($_POST['internet_access']))    $internetAccess    = (int)$_POST['internet_access'];
    if (isset($_POST['digital_literacy']))   $digitalLiteracy   = (int)$_POST['digital_literacy'];
    if (isset($_POST['household_income']))   $householdIncome   = (float)$_POST['household_income'];
    if (isset($_POST['parental_education'])) $parentalEducation = (int)$_POST['parental_education'];
    if (isset($_POST['study_hours']))        $studyHours        = (float)$_POST['study_hours'];
    if (isset($_POST['working_student']))    $workingStudent    = (int)$_POST['working_student'];

    /* -------- Build API payload from saved DB data -------- */
    if (!$errors) {
        $latestComps = $savedComponents[$latestPeriod];
        $attendanceRate = (float)($latestComps['attendance_rate'] ?? 85.0);
        $labScore       = (float)($latestComps['lab_score']       ?? 0.0);
        $computedGrade  = $savedPeriodGrades[$latestPeriod];

        $apiPayload = [
            'current_period'     => $latestPeriod,
            'computed_grade'     => $computedGrade,
            'attendance_rate'    => $attendanceRate,
            'lab_score'          => $labScore,
            'internet_access'    => $internetAccess,
            'digital_literacy'   => (int)$digitalLiteracy,
            'household_income'   => (float)$householdIncome,
            'parental_education' => (int)$parentalEducation,
            'study_hours'        => (float)$studyHours,
            'working_student'    => $workingStudent,
        ];

        // Include all previous period grades as features
        foreach ($PERIOD_PREV[$latestPeriod] as $prevPeriod) {
            if (isset($savedPeriodGrades[$prevPeriod]) && isset($PERIOD_GRADE_KEY[$prevPeriod])) {
                $apiPayload[$PERIOD_GRADE_KEY[$prevPeriod]] = $savedPeriodGrades[$prevPeriod];
            }
        }
        // Include the latest period's own grade
        if (isset($PERIOD_GRADE_KEY[$latestPeriod])) {
            $apiPayload[$PERIOD_GRADE_KEY[$latestPeriod]] = $computedGrade;
        }

        /* -------- Call Flask API -------- */
        $api = api_request_local('POST', '/predict-next-period', $apiPayload);
        if (!$api['ok']) {
            $api = api_request('POST', '/predict-next-period', $apiPayload);
        }

        if (!$api['ok']) {
            // Fallback: try /predict-progressive endpoint
            $progPayload = $apiPayload;
            $progPayload['grading_period'] = $latestPeriod;
            $api = api_request_local('POST', '/predict-progressive', $progPayload);
            if (!$api['ok']) {
                $api = api_request('POST', '/predict-progressive', $progPayload);
            }
        }

        if (!$api['ok']) {
            $errors[] = $api['error'] ?? 'Prediction service is unavailable.';
        } else {
            /* -------- Save to database -------- */
            $conn = db();
            $metadata = model_metadata();
            $conn->begin_transaction();

            try {
                /* Get or create academic record */
                $prelimGrade  = $savedPeriodGrades['Prelim']     ?? 0;
                $midtermGrade = $savedPeriodGrades['Midterm']    ?? 0;
                $semiGrade    = $savedPeriodGrades['Semi-Final'] ?? 0;
                $finalGrade   = $savedPeriodGrades['Final']      ?? 0;

                $arCheck = $conn->prepare(
                    'SELECT id FROM tbl_academic_records WHERE student_id = ? AND academic_year = ? AND semester = ? ORDER BY id DESC LIMIT 1'
                );
                $arCheck->bind_param('iss', $studentId, $academicYear, $semester);
                $arCheck->execute();
                $existingAr = $arCheck->get_result()->fetch_assoc();

                if ($existingAr) {
                    $academicRecordId = (int)$existingAr['id'];
                    $arUpd = $conn->prepare(
                        'UPDATE tbl_academic_records
                         SET prelim_grade = ?, midterm_grade = ?, semi_final_grade = ?, final_grade = ?,
                             attendance_rate = ?, lab_score = ?
                         WHERE id = ?'
                    );
                    $arUpd->bind_param('ddddddi',
                        $prelimGrade, $midtermGrade, $semiGrade, $finalGrade,
                        $attendanceRate, $labScore, $academicRecordId
                    );
                    $arUpd->execute();
                } else {
                    $arIns = $conn->prepare(
                        'INSERT INTO tbl_academic_records
                            (student_id, academic_year, semester, prelim_grade, midterm_grade,
                             semi_final_grade, final_grade, attendance_rate, lab_score)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $arIns->bind_param('issdddddd',
                        $studentId, $academicYear, $semester,
                        $prelimGrade, $midtermGrade, $semiGrade, $finalGrade,
                        $attendanceRate, $labScore
                    );
                    $arIns->execute();
                    $academicRecordId = (int)$conn->insert_id;
                }

                /* Insert prediction — grading_period stored as the TARGET (forecast) period */
                $apiTargetPeriod = (string)($api['data']['target_period'] ?? $targetPeriod ?? '');
                if (!$apiTargetPeriod) $apiTargetPeriod = $targetPeriod;

                $prediction    = (string)($api['data']['prediction'] ?? 'Pass');
                $predictedGrade = (float)($api['data']['predicted_grade'] ?? $computedGrade);
                $confidence    = (float)($api['data']['confidence'] ?? $api['data']['within_5_points_rate'] ?? 0.85);
                if ($confidence <= 0) {
                    $confidence = (float)($api['data']['within_5_points_rate'] ?? 0.85);
                }
                $recomm        = (string)($api['data']['recommendation'] ?? '');
                $riskFactors   = json_encode($api['data']['risk_factors'] ?? []);
                $missingComp   = json_encode($missingList);
                $featurePayload= json_encode($apiPayload);
                $modelAccuracy = (float)($api['data']['model_accuracy'] ?? $api['data']['within_5_points_rate'] ?? $metadata['accuracy'] ?? 0.85);
                $f1Score       = (float)($api['data']['model_f1'] ?? $metadata['weighted_f1'] ?? 0);
                $algorithm     = 'XGBoost Next-Period Forecast (' . $latestPeriod . ' → ' . $apiTargetPeriod . ')';
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
                    $studentId, $academicRecordId, $apiTargetPeriod, $prediction,
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
                    $msg = "Student {$studentRec['full_name']} ({$studentNo}) — forecast for {$apiTargetPeriod}: {$prediction} (predicted {$predictedGrade}%).";
                    create_alert($studentId, $advisorToNotify, 'Risk', $severity, $msg);
                }

                $conn->commit();
                $result = $api['data'];
                $result['algorithm']      = $algorithm;
                $result['accuracy']       = $modelAccuracy;
                $result['confidence']     = $confidence;
                $result['computed_grade'] = $computedGrade;
                $result['missing']        = $missingList;
                $result['source_period']  = $latestPeriod;
                $result['target_period']  = $apiTargetPeriod;
                $result['grading_period'] = $latestPeriod; // backward compat
                $result['predicted_grade']= $predictedGrade;
                $result['prediction']     = $prediction;
                $result['student_id']     = $studentId;
                $result['student_no']     = $studentNo;
                $result['student_name']   = $studentRec['full_name'];
                $result['academic_year']  = $academicYear;
                $result['semester']       = $semester;
                $result['period_grades']  = $savedPeriodGrades;

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
    $sourcePeriod = $result['source_period'] ?? $result['grading_period'] ?? '';
    $targetPeriodR = $result['target_period'] ?? '';
    $confPct = round((float)($result['confidence'] ?? 0) * 100, 1);
    $gradePct = round((float)($result['computed_grade'] ?? 0), 1);
    $predGradeR = round((float)($result['predicted_grade'] ?? $result['computed_grade'] ?? 0), 2);
    $statusCls = status_class($result['prediction'] ?? 'Pass');

    $statusBadgeBg     = '#ecfdf5'; $statusBadgeColor = '#065f46'; $statusBadgeBorder = '#a7f3d0'; $statusDot = '#10b981';
    if (($result['prediction'] ?? '') === 'At-Risk') {
        $statusBadgeBg = '#fffbeb'; $statusBadgeColor = '#92400e'; $statusBadgeBorder = '#fde68a'; $statusDot = '#f59e0b';
    } elseif (($result['prediction'] ?? '') === 'Fail') {
        $statusBadgeBg = '#fef2f2'; $statusBadgeColor = '#991b1b'; $statusBadgeBorder = '#fecaca'; $statusDot = '#ef4444';
    }

    $allPeriodGrades = $result['period_grades'] ?? [];
    $gradeDeltaR = round($predGradeR - $gradePct, 2);
    $gwaValR = count($allPeriodGrades) > 0 ? array_sum($allPeriodGrades) / count($allPeriodGrades) : null;
    ?>

    <!-- ── Forecast Result Card ── -->
    <section class="result-progressive <?= h($statusCls) ?>" id="result-banner" style="border-radius:16px;padding:28px;margin-bottom:20px;border:1.5px solid;box-shadow:0 8px 24px rgba(15,23,42,.07);">
        <div class="rp-header">
            <div>
                <small style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--muted);margin-bottom:6px;">
                    <span style="font-weight:700;"><?= h($result['student_name'] ?? $result['student_no'] ?? '') ?></span>
                    <span>•</span>
                    <span style="background:var(--primary-subtle,#eff6ff);color:var(--primary,#1e3a8a);padding:2px 10px;border-radius:999px;font-size:.73rem;font-weight:700;border:1px solid #bfdbfe;">
                        <?= h($sourcePeriod) ?> Actual → <?= h($targetPeriodR ?: 'Next Period') ?> Forecast
                    </span>
                </small>
                <h2 style="margin:0 0 6px;font-size:1.55rem;font-weight:800;color:var(--text);">
                    Next-Period Forecast
                    <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:6px;background:#e0e7ff;color:#4338ca;margin-left:6px;">AI Engine</span>
                </h2>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;font-size:.85rem;font-weight:700;background:<?= $statusBadgeBg ?>;color:<?= $statusBadgeColor ?>;border:1px solid <?= $statusBadgeBorder ?>;">
                    <span style="width:8px;height:8px;border-radius:999px;background:<?= $statusDot ?>;box-shadow:0 0 6px <?= $statusDot ?>;"></span>
                    <?= h($result['prediction'] ?? '') ?> Status
                </span>
            </div>
        </div>

        <!-- Stats grid -->
        <div class="rp-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:16px 0;">
            <div style="background:var(--surface-subtle,#f8fafc);border:1px solid var(--line);border-radius:12px;padding:16px;">
                <small style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-bottom:6px;"><?= h($sourcePeriod) ?> Actual Grade</small>
                <strong style="font-size:1.6rem;font-weight:800;"><?= number_format($gradePct, 2) ?>%</strong>
                <div style="font-size:.76rem;margin-top:4px;color:var(--muted);">Source period</div>
            </div>
            <div style="background:linear-gradient(135deg,#f0fdf4,#eff6ff);border:1px solid #93c5fd;border-radius:12px;padding:16px;box-shadow:0 4px 12px rgba(37,99,235,.08);">
                <small style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#1e40af;font-weight:700;margin-bottom:6px;"><?= h($targetPeriodR ?: 'Next Period') ?> Forecast Grade</small>
                <strong style="font-size:1.6rem;font-weight:800;color:#1d4ed8;"><?= number_format($predGradeR, 2) ?>%</strong>
                <div style="font-size:.76rem;margin-top:4px;">
                    <?php if ($gradeDeltaR > 0): ?>
                        <span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:999px;font-weight:700;">▲ +<?= number_format($gradeDeltaR, 2) ?>% Projected</span>
                    <?php elseif ($gradeDeltaR < 0): ?>
                        <span style="background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:999px;font-weight:700;">▼ <?= number_format($gradeDeltaR, 2) ?>% Projected</span>
                    <?php else: ?>
                        <span style="background:#f1f5f9;color:#475569;padding:2px 7px;border-radius:999px;font-weight:700;">● Steady</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="background:var(--surface-subtle,#f8fafc);border:1px solid var(--line);border-radius:12px;padding:16px;">
                <small style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-bottom:6px;">Confidence</small>
                <strong style="font-size:1.6rem;font-weight:800;"><?= $confPct ?>%</strong>
                <div style="width:100%;height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:8px;">
                    <div style="height:100%;border-radius:999px;background:linear-gradient(90deg,#3b82f6,#10b981);width:<?= min(100,max(5,$confPct)) ?>%;"></div>
                </div>
            </div>
            <div style="background:var(--surface-subtle,#f8fafc);border:1px solid var(--line);border-radius:12px;padding:16px;">
                <small style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-bottom:6px;">Model Accuracy</small>
                <strong style="font-size:1.6rem;font-weight:800;"><?= round(($result['accuracy'] ?? 0) * 100, 1) ?>%</strong>
                <div style="font-size:.76rem;margin-top:4px;color:var(--muted);"><?= h($result['algorithm'] ?? '') ?></div>
            </div>
        </div>

        <!-- Period grades timeline -->
        <?php if (!empty($allPeriodGrades)): ?>
        <div style="margin-top:20px;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px;">Semester Period Progress</div>
            <div class="period-summary-grid">
                <?php foreach (['Prelim','Midterm','Semi-Final','Final'] as $pg_period): ?>
                <div class="psg-card" style="<?= $pg_period === $targetPeriodR ? 'background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.4);' : '' ?>">
                    <div class="period-name"><?= h($pg_period) ?></div>
                    <?php if (isset($allPeriodGrades[$pg_period])): ?>
                        <div class="actual-grade"><?= number_format($allPeriodGrades[$pg_period], 2) ?>%</div>
                        <?php if ($pg_period === $sourcePeriod): ?>
                            <div class="pred-grade" style="color:var(--blue);font-size:.73rem;font-weight:700;margin-top:3px;"> Latest Actual</div>
                        <?php endif; ?>
                    <?php elseif ($pg_period === $targetPeriodR): ?>
                        <div class="actual-grade" style="color:#d97706;"><?= number_format($predGradeR, 2) ?>%</div>
                        <div class="pred-grade" style="color:#d97706;font-size:.73rem;font-weight:700;margin-top:3px;"> Forecast</div>
                    <?php else: ?>
                        <div class="na-tag">Not yet entered</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recommendation -->
        <?php if (!empty($result['recommendation'])): ?>
        <div style="display:flex;align-items:flex-start;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #3b82f6;border-radius:10px;padding:16px 18px;margin-top:18px;">
            <div style="flex:0 0 36px;height:36px;border-radius:999px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:1.1rem;border:1px solid #bfdbfe;"></div>
            <div>
                <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#1e3a8a;margin-bottom:4px;">Advisory Recommendation</div>
                <div style="font-size:.92rem;color:var(--text);line-height:1.5;"><?= h($result['recommendation']) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Risk factors -->
        <?php if (!empty($result['risk_factors'])): ?>
        <div style="margin-top:16px;display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
            <span style="font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Risk Factors:</span>
            <?php foreach ($result['risk_factors'] as $rf): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#fffbeb;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;font-size:.82rem;font-weight:600;"> <?= h($rf) ?></span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="margin-top:14px;">
            <span style="display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px;padding:6px 12px;font-size:.82rem;font-weight:600;"> No critical risk factors identified</span>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<!-- ── Forecast Form ── -->
<div class="panel form-panel" style="margin-bottom:0;">
    <!-- Info banner (Minimalist) -->
    <div style="background:var(--surface-subtle,#f8fafc);border:1px solid var(--line,#e2e8f0);border-left:4px solid var(--primary,#1e3a8a);border-radius:6px;padding:12px 16px;margin-bottom:18px;">
        <div style="font-weight:700;font-size:0.92rem;color:var(--text,#0f172a);margin-bottom:3px;">
            Next-Period Grade Forecast
        </div>
        <p style="margin:0;font-size:0.85rem;color:var(--muted,#64748b);line-height:1.5;">
            Select a student and semester to automatically forecast their next grading period using currently saved scores.
        </p>
    </div>

    <!-- Period flow visual (Simple Sequence) -->
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin:0 0 24px;flex-wrap:wrap;padding:10px 16px;background:var(--surface-subtle,#f8fafc);border:1px solid var(--line,#e2e8f0);border-radius:8px;font-family:'Plus Jakarta Sans','Segoe UI',sans-serif;">
        <span style="font-size:0.8rem;font-weight:700;color:var(--muted,#64748b);text-transform:uppercase;letter-spacing:0.04em;">Grading Sequence:</span>
        <?php
        $flowPeriods = ['Prelim','Midterm','Semi-Final','Final'];
        foreach ($flowPeriods as $fi => $fp):
        ?>
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:0.84rem;font-weight:600;color:var(--text,#1e293b);">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#e2e8f0;color:#334155;font-size:0.74rem;font-weight:700;">
                <?= $fi + 1 ?>
            </span>
            <span><?= h($fp) ?></span>
        </span>
        <?php if ($fi < 3): ?>
        <span style="color:#94a3b8;font-size:0.85rem;">→</span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <form method="post" id="prediction-form" autocomplete="off">
        <div class="form-section" style="padding-top:0;">
            <h2 style="margin-bottom:16px;">Select Student &amp; Context</h2>

            <!-- Quick student dropdown -->
            <div style="margin-bottom:20px;padding:14px 18px;background:var(--surface-strong);border-radius:8px;border:1px solid var(--line);">
                <label for="student_quick_select" style="display:block;margin-bottom:6px;font-weight:700;font-size:.9rem;">Quick Select Student:</label>
                <select id="student_quick_select" style="width:100%;padding:10px 14px;border:1px solid var(--line);border-radius:6px;background:#fff;font-size:.95rem;color:var(--text);">
                    <option value="">— Select an enrolled student —</option>
                    <?php foreach ($registeredStudents as $st): ?>
                        <option value="<?= h($st['student_no']) ?>" <?= old_value('student_no') === $st['student_no'] ? 'selected' : '' ?>>
                            <?= h($st['student_no']) ?> — <?= h($st['full_name']) ?> (<?= h($st['year_level']) ?>, <?= h($st['section']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
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

    <!-- Section: Self-Assessment Profile -->
    <div class="form-section" id="self-assessment-section">
        <h2>Self-Assessment Profile</h2>
        <div class="form-grid">
            <label>
                <span>Assigned Advisor / Professor</span>
                <input type="text" id="assigned_advisor" value="<?= h($_cu['full_name'] ?? 'Advisor') ?>" readonly
                       style="background-color: var(--surface-strong, #f1f5f9); color: var(--muted, #475569); cursor: not-allowed;">
            </label>
            <label>
                <span>Internet Access</span>
                <select name="internet_access" id="internet_access">
                    <option value="1" <?= old_value('internet_access', '1') === '1' ? 'selected' : '' ?>>Yes (Available)</option>
                    <option value="0" <?= old_value('internet_access') === '0' ? 'selected' : '' ?>>No (Limited/None)</option>
                </select>
            </label>
            <label>
                <span>Digital Literacy (1-5)</span>
                <input type="number" min="1" max="5" name="digital_literacy" id="digital_literacy"
                       value="<?= h(old_value('digital_literacy', '1')) ?>" required>
                <small style="color: var(--muted, #666); font-size: 0.8rem; display: block; margin-top: 4px;">Rate 1 (Basic) to 5 (Advanced skills)</small>
            </label>
            <label>
                <span>Weekly Study Hours</span>
                <input type="number" step="0.01" min="0" max="80" name="study_hours" id="study_hours"
                       value="<?= h(old_value('study_hours', '6.00')) ?>" required>
            </label>
            <label>
                <span>Household Income (PHP)</span>
                <input type="number" step="0.01" min="0" max="500000" name="household_income" id="household_income"
                       value="<?= h(old_value('household_income', '2')) ?>" required>
            </label>
            <label>
                <span>Parental Education Level</span>
                <select name="parental_education" id="parental_education">
                    <?php 
                    $curParentEdu = (int)old_value('parental_education', '2');
                    foreach ([1 => 'Elementary', 2 => 'High School', 3 => 'College', 4 => 'Postgraduate'] as $val => $label): 
                    ?>
                        <option value="<?= $val ?>" <?= ($curParentEdu === $val) ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Working Student</span>
                <?php $curWorking = (int)old_value('working_student', '0'); ?>
                <select name="working_student" id="working_student">
                    <option value="0" <?= ($curWorking === 0) ? 'selected' : '' ?>>No (Full-time student)</option>
                    <option value="1" <?= ($curWorking === 1) ? 'selected' : '' ?>>Yes (Part-time / Working)</option>
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
                    <span>Internet Access</span>
                    <select name="internet_access" id="internet_access">
                        <option value="1" <?= old_value('internet_access','1')==='1' ? 'selected' : '' ?>>Yes (Available)</option>
                        <option value="0" <?= old_value('internet_access')==='0' ? 'selected' : '' ?>>No (Limited/None)</option>
                    </select>
                </label>
            </div>
        </details>

        <div class="form-actions" style="margin-top:8px;">
            <button class="button button-primary" type="submit" id="submit-btn" style="gap:8px;display:inline-flex;align-items:center;">
                <span></span> Generate Next-Period Forecast
            </button>
            <span id="forecast-hint" style="font-size:.83rem;color:var(--muted);"></span>
        </div>
    </form>
</div>
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

const DEFAULT_ADVISOR_NAME = <?= json_encode($_cu['full_name'] ?? 'Advisor') ?>;
const REGISTERED_STUDENTS = <?= json_encode($registeredStudentsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

/* --------------------------------------------------------------- */
/* Quick-select student dropdown                                    */
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

    // Assigned advisor
    const advEl = document.getElementById('assigned_advisor');
    if (advEl) {
        if (student.advisor_name) {
            advEl.value = student.advisor_name;
        } else if (DEFAULT_ADVISOR_NAME) {
            advEl.value = DEFAULT_ADVISOR_NAME;
        }
    }

    // Internet Access
    const netVal = (sv && sv.internet_access !== undefined && sv.internet_access !== null)
        ? sv.internet_access
        : (student.internet_access !== undefined && student.internet_access !== null ? student.internet_access : null);
    if (netVal !== null) {
        const el = document.getElementById('internet_access');
        if (el) el.value = String(netVal);
    }

    // Household Income
    if (student.household_income !== undefined && student.household_income !== null && student.household_income !== '') {
        const el = document.getElementById('household_income');
        if (el) el.value = student.household_income;
    }

    // Parental Education
    if (student.parental_education !== undefined && student.parental_education !== null) {
        const el = document.getElementById('parental_education');
        if (el) el.value = student.parental_education;
    }

    // Working Student
    if (student.working_student !== undefined && student.working_student !== null) {
        const el = document.getElementById('working_student');
        if (el) el.value = student.working_student;
    }

    // Digital Literacy
    const dlVal = (sv && sv.digital_literacy) ? sv.digital_literacy : (student.digital_literacy ?? null);
    if (dlVal !== null) {
        const el = document.getElementById('digital_literacy');
        if (el) el.value = dlVal;
    }

    // Weekly Study Hours
    const shVal = (sv && sv.study_hours) ? sv.study_hours : (student.study_hours ?? null);
    if (shVal !== null) {
        const el = document.getElementById('study_hours');
        if (el) el.value = shVal;
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

    const url = `get_student_grades.php?student_no=${encodeURIComponent(sno)}&academic_year=${encodeURIComponent(ay)}&semester=${encodeURIComponent(sem)}&period=`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!panel) return;

            // Autofill socio-demographic from DB data
            const sv = res.data?.survey || {};
            const st = res.data?.student || {};
            if (st) applyProfileFields({ ...st, ...sv });

            // Sync quick-select
            const qs = document.getElementById('student_quick_select');
            if (qs && sno) qs.value = sno;

            const pg = res.data?.period_grades || {};
            const allPeriods = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];

            // Find latest period with a saved grade
            let latestPeriod = null;
            for (let i = allPeriods.length - 1; i >= 0; i--) {
                if (pg[allPeriods[i]] !== undefined && pg[allPeriods[i]] !== null) {
                    latestPeriod = allPeriods[i];
                    break;
                }
            }

            panel.style.display = '';
            warnEl.style.display = 'none';

            if (!latestPeriod) {
                srcEl.textContent = 'None saved';
                tgtEl.textContent = '—';
                grEl.textContent  = '';
                warnEl.textContent = '⚠ No grades saved yet. Please enter scores first via Enter Scores.';
                warnEl.style.display = '';
                if (hint) hint.textContent = '⚠ No grades available to forecast.';
                return;
            }

            if (latestPeriod === 'Final') {
                srcEl.textContent = 'Final';
                tgtEl.textContent = '—';
                warnEl.textContent = 'ℹ Final is the last period. No next-period forecast available.';
                warnEl.style.display = '';
                if (hint) hint.textContent = 'Final period reached — no next forecast.';
                return;
            }

            const targetPeriod = PERIOD_NEXT[latestPeriod];
            srcEl.textContent = latestPeriod + ' (' + parseFloat(pg[latestPeriod]).toFixed(2) + '%)';
            tgtEl.textContent = targetPeriod;

            // Show all saved grades summary
            const gradeLines = allPeriods
                .filter(p => pg[p] !== undefined && pg[p] !== null)
                .map(p => `${p}: ${parseFloat(pg[p]).toFixed(2)}%`)
                .join('  |  ');
            grEl.textContent = gradeLines || '';
            if (hint) hint.textContent = `Will use ${latestPeriod} data to forecast ${targetPeriod}`;
        })
        .catch(() => {
            if (panel) panel.style.display = 'none';
        });
}

/* --------------------------------------------------------------- */
/* Submit handler                                                   */
/* --------------------------------------------------------------- */
document.getElementById('prediction-form').addEventListener('submit', function () {
    const btn = document.getElementById('submit-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Generating Forecast…';
    }
});

/* --------------------------------------------------------------- */
/* Init                                                             */
/* --------------------------------------------------------------- */
const initialSno = document.getElementById('student_no')?.value.trim();
if (initialSno) {
    loadStudentStatus();
}

<?php if ($result): ?>
    document.getElementById('result-banner')?.scrollIntoView({behavior:'smooth', block:'start'});
<?php endif; ?>
</script>

<?php page_footer(); ?>
