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

/* Forecast result: restrained, information-first layout */
.forecast-card { padding: 0 !important; overflow: hidden; border-radius: 12px !important; box-shadow: var(--shadow-sm); }
.forecast-card__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; padding: 22px 24px 18px; border-bottom: 1px solid var(--line); }
.forecast-card__kicker, .forecast-section-label { margin: 0; font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.forecast-card__title { margin: 5px 0 0; font-size: 1.35rem; line-height: 1.2; letter-spacing: -.02em; color: var(--text); }
.forecast-status { display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0; padding: 7px 10px; border: 1px solid var(--status-border); border-radius: var(--radius-full); color: var(--status-text); background: var(--status-bg); font-size: .78rem; font-weight: 700; }
.forecast-status::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--status-dot); }
.forecast-card__body { padding: 20px 24px 24px; }
.forecast-periods { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
.forecast-period { position: relative; min-height: 120px; padding: 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
.forecast-period.is-forecast { border-color: var(--primary-border); background: #f8fbff; }
.forecast-period.is-empty { background: var(--surface-subtle); }
.forecast-period__name { font-size: .77rem; font-weight: 700; color: var(--text-secondary); }
.forecast-period__value { margin-top: 12px; font-size: 1.5rem; line-height: 1; font-weight: 750; letter-spacing: -.035em; color: var(--text); }
.forecast-period.is-empty .forecast-period__value { color: var(--muted-light); font-weight: 500; }
.forecast-period__note { margin-top: 9px; font-size: .74rem; color: var(--muted); }
.forecast-period__note.is-delta { font-weight: 700; color: var(--delta-color); }
.forecast-details { display: grid; grid-template-columns: .75fr .75fr .75fr 1.75fr; gap: 0; margin-top: 20px; padding: 15px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.forecast-detail { min-width: 0; padding: 0 15px; border-left: 1px solid var(--line); }
.forecast-detail:first-child { padding-left: 0; border-left: 0; }
.forecast-detail__label { display: block; font-size: .7rem; font-weight: 700; color: var(--muted); letter-spacing: .05em; text-transform: uppercase; }
.forecast-detail__value { display: block; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-secondary); font-size: .82rem; font-weight: 600; }
.forecast-support { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(220px, .9fr); gap: 14px; margin-top: 18px; }
.forecast-advice { padding: 15px 16px; border-left: 3px solid var(--primary); background: var(--primary-subtle); }
.forecast-advice p { margin: 7px 0 0; color: var(--text-secondary); font-size: .87rem; line-height: 1.55; }
.forecast-risks { padding: 15px 16px; border: 1px solid var(--line); background: var(--surface-subtle); }
.forecast-advice + .forecast-risks { margin-top: 14px; }
.forecast-risk-list { display: flex; flex-direction: column; gap: 8px; margin-top: 9px; }
.forecast-risk { position: relative; padding-left: 14px; color: var(--text-secondary); font-size: .8rem; line-height: 1.35; }
.forecast-risk::before { content: ''; position: absolute; left: 0; top: .5em; width: 5px; height: 5px; border-radius: 50%; background: #d97706; }

/* Forecast setup: concise guidance instead of decorative system panels */
.forecast-setup { margin-bottom: 22px; padding-bottom: 18px; border-bottom: 1px solid var(--line); }
.forecast-setup__title { margin: 0; color: var(--text); font-size: 1.05rem; line-height: 1.3; letter-spacing: -.01em; }
.forecast-setup__copy { margin: 5px 0 0; max-width: 62ch; color: var(--muted); font-size: .87rem; }
.forecast-setup__steps { display: flex; gap: 18px; margin: 15px 0 0; color: var(--text-secondary); font-size: .8rem; }
.forecast-setup__steps span { display: inline-flex; align-items: center; gap: 7px; }
.forecast-setup__steps b { display: inline-grid; place-items: center; width: 19px; height: 19px; border-radius: 50%; background: var(--surface-subtle); color: var(--muted); font-size: .69rem; }
.grading-sequence { justify-content: flex-start !important; padding: 0 0 18px !important; background: transparent !important; border: 0 !important; border-bottom: 1px solid var(--line) !important; border-radius: 0 !important; }
.student-picker { position: relative; }
.student-picker__input { width: 100%; padding: 10px 42px 10px 14px; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--text); font-size: .95rem; }
.student-picker__input:focus { outline: 0; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .10); }
.student-picker::after { content: ''; position: absolute; right: 16px; top: 15px; width: 7px; height: 7px; border-right: 2px solid var(--muted); border-bottom: 2px solid var(--muted); transform: rotate(45deg); pointer-events: none; }
.student-picker__list { display: none; position: absolute; z-index: 30; top: calc(100% + 5px); left: 0; right: 0; max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid var(--line-strong); border-radius: 6px; box-shadow: var(--shadow-lg); }
.student-picker.is-open .student-picker__list { display: block; }
.student-picker__option { width: 100%; padding: 10px 14px; border: 0; border-bottom: 1px solid var(--line); background: #fff; color: var(--text-secondary); text-align: left; cursor: pointer; font: inherit; font-size: .86rem; }
.student-picker__option:last-child { border-bottom: 0; }
.student-picker__option:hover, .student-picker__option:focus { outline: 0; background: var(--surface-subtle); }
.student-picker__option strong { color: var(--text); font-weight: 700; }
.student-picker__empty { padding: 12px 14px; color: var(--muted); font-size: .85rem; }

@media (max-width: 760px) {
    .forecast-periods { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .forecast-details { grid-template-columns: repeat(2, 1fr); gap: 14px 0; }
    .forecast-detail:nth-child(3) { padding-left: 0; border-left: 0; }
    .forecast-support { grid-template-columns: 1fr; }
}
@media (max-width: 460px) {
    .forecast-card__header, .forecast-card__body { padding-left: 16px; padding-right: 16px; }
    .forecast-card__header { flex-direction: column; gap: 12px; }
    .forecast-periods, .forecast-details { grid-template-columns: 1fr; }
    .forecast-detail { padding: 0; border-left: 0; }
    .forecast-setup__steps { flex-direction: column; gap: 8px; }
}

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

    <!-- ── Forecast Result Card (Minimalist & Non-Duplicated) ── -->
    <section class="panel forecast-card" id="result-banner" style="margin-bottom:20px;--status-bg:<?= $statusBadgeBg ?>;--status-text:<?= $statusBadgeColor ?>;--status-border:<?= $statusBadgeBorder ?>;--status-dot:<?= $statusDot ?>;">
        <div class="forecast-card__header">
            <div>
                <p class="forecast-card__kicker">
                    <?= h($result['student_name'] ?? $result['student_no'] ?? '') ?> &bull; <?= h($sourcePeriod) ?> &rarr; <?= h($targetPeriodR ?: 'Next Period') ?> Forecast
                </p>
                <h2 class="forecast-card__title">
                    Performance Forecast
                </h2>
            </div>
            <span class="forecast-status"><?= h($result['prediction'] ?? '') ?> status</span>
        </div>

        <!-- Semester Grading Progress (Unified 4 Periods) -->
        <div class="forecast-card__body">
            <p class="forecast-section-label">Semester grading progress</p>
            <div class="forecast-periods">
                <?php foreach (['Prelim','Midterm','Semi-Final','Final'] as $pg_period): ?>
                    <?php
                    $hasActual = isset($allPeriodGrades[$pg_period]);
                    $isForecast = ($pg_period === $targetPeriodR);
                    ?>
                    <div class="forecast-period<?= $hasActual ? ' is-actual' : ($isForecast ? ' is-forecast' : ' is-empty') ?>">
                        <div class="forecast-period__name"><?= h($pg_period) ?></div>
                        <div class="forecast-period__value">
                            <?php if ($hasActual): ?>
                                <?= number_format($allPeriodGrades[$pg_period], 2) ?>%
                            <?php elseif ($isForecast): ?>
                                <?= number_format($predGradeR, 2) ?>%
                            <?php else: ?>
                                <span style="color:var(--muted);font-weight:400;font-size:1.1rem;">—</span>
                            <?php endif; ?>
                        </div>
                        <div class="forecast-period__note<?= $isForecast ? ' is-delta' : '' ?>"<?= $isForecast ? ' style="--delta-color:' . ($gradeDeltaR >= 0 ? '#15803d' : '#b91c1c') . ';"' : '' ?>>
                            <?php if ($hasActual): ?>
                                <span style="color:var(--muted);">Recorded Actual</span>
                            <?php elseif ($isForecast): ?>
                                <span style="color:<?= $gradeDeltaR >= 0 ? '#16a34a' : '#dc2626' ?>;font-weight:600;">
                                    Forecast (<?= $gradeDeltaR >= 0 ? '+' : '' ?><?= number_format($gradeDeltaR, 2) ?>%)
                                </span>
                            <?php else: ?>
                                <span style="color:var(--muted);">Not yet entered</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Model Evaluation Metadata Bar -->
        <div class="forecast-details">
            <div class="forecast-detail">
                <span class="forecast-detail__label">Target period</span><span class="forecast-detail__value"><?= h($targetPeriodR ?: 'Next Period') ?></span>
            </div>
            <div class="forecast-detail">
                <span class="forecast-detail__label">Confidence</span><span class="forecast-detail__value"><?= $confPct ?>%</span>
            </div>
            <div class="forecast-detail">
                <span class="forecast-detail__label">Accuracy</span><span class="forecast-detail__value"><?= round(($result['accuracy'] ?? 0) * 100, 1) ?>%</span>
            </div>
            <div class="forecast-detail">
                <span class="forecast-detail__label">Model</span><span class="forecast-detail__value"><?= h($result['algorithm'] ?? 'XGBoost') ?></span>
            </div>
        </div>

        <!-- Advisory Recommendation -->
        <?php if (!empty($result['recommendation'])): ?>
        <div class="forecast-advice">
            <p class="forecast-section-label">Advisor note</p>
            <p><?= h($result['recommendation']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Risk Factors -->
        <?php if (!empty($result['risk_factors'])): ?>
        <div class="forecast-risks">
            <p class="forecast-section-label">Items to watch</p>
            <div class="forecast-risk-list">
                <?php foreach ($result['risk_factors'] as $rf): ?>
                    <span class="forecast-risk">
                        <?= h($rf) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="forecast-risks">
            <p class="forecast-section-label">Items to watch</p>
            <div class="forecast-risk-list"><span class="forecast-risk">No critical risk factors identified.</span></div>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<!-- ── Forecast Form ── -->
<div class="panel form-panel" style="margin-bottom:0;">
    <div class="forecast-setup">
        <h2 class="forecast-setup__title">Create a grade forecast</h2>
        <p class="forecast-setup__copy">Choose a student and term. The forecast uses the latest recorded grading period and saved assessment scores.</p>
        <div class="forecast-setup__steps" aria-label="Forecast steps">
            <span><b>1</b> Choose student</span><span><b>2</b> Confirm term</span><span><b>3</b> Review forecast</span>
        </div>
    </div>

    <!-- Period flow visual (Simple Sequence) -->
    <div class="grading-sequence" style="display:flex;align-items:center;justify-content:center;gap:12px;margin:0 0 24px;flex-wrap:wrap;padding:10px 16px;background:var(--surface-subtle,#f8fafc);border:1px solid var(--line,#e2e8f0);border-radius:8px;font-family:'Plus Jakarta Sans','Segoe UI',sans-serif;">
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
                <div class="student-picker" id="student_picker">
                    <input class="student-picker__input" id="student_quick_select" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="student_picker_list" placeholder="Type a name or student number" value="<?= h(old_value('student_no')) ?>" autocomplete="off">
                    <div class="student-picker__list" id="student_picker_list" role="listbox"></div>
                </div>
                <select id="student_quick_select_legacy" aria-hidden="true" tabindex="-1" style="display:none;">
                    <option value=""> Select an enrolled student </option>
                    <?php foreach ($registeredStudents as $st): ?>
                        <option value="<?= h($st['student_no']) ?>" <?= old_value('student_no') === $st['student_no'] ? 'selected' : '' ?>>
                            <?= h($st['student_no']) ?> — <?= h($st['full_name']) ?> (<?= h($st['year_level']) ?>, <?= h($st['section']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="student_autofill_indicator" style="display:none;margin-top:8px;font-size:0.85rem;color:var(--green,#16a34a);font-weight:600;"></div>
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
                           value="<?= h(old_value('full_name')) ?>" readonly
                           style="background-color:var(--surface-strong,#f1f5f9);">
                </label>
                <label>
                    <span>Year Level</span>
                    <input id="year_level" name="year_level"
                           value="<?= h(old_value('year_level')) ?>" readonly
                           style="background-color:var(--surface-strong,#f1f5f9);">
                </label>
                <label>
                    <span>Section</span>
                    <input id="section" name="section"
                           value="<?= h(old_value('section')) ?>" readonly
                           style="background-color:var(--surface-strong,#f1f5f9);">
                </label>
                <label>
                    <span>Academic Year</span>
                    <select name="academic_year" id="academic_year" required onchange="loadStudentStatus()">
                        <?php foreach (['2025-2026','2026-2027','2027-2028'] as $opt): ?>
                            <option <?= old_value('academic_year','2025-2026') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Semester</span>
                    <select name="semester" id="semester" required onchange="loadStudentStatus()">
                        <?php foreach (['1st Semester','2nd Semester','Summer'] as $opt): ?>
                            <option <?= old_value('semester','1st Semester') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <!-- Auto-detect status display -->
            <div id="auto-detect-panel" style="display:none;margin-top:16px;padding:16px 20px;border-radius:10px;border:1px solid var(--line);background:var(--surface-subtle,#f8fafc);">
                <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px;">Auto-Detected Forecast Pipeline</div>
                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:.72rem;color:var(--muted);font-weight:600;">Latest Recorded Period</div>
                        <div id="detect-source" style="font-size:1rem;font-weight:800;color:var(--text);">—</div>
                    </div>
                    <div style="font-size:1.4rem;color:var(--blue,#2563eb);">→</div>
                    <div>
                        <div style="font-size:.72rem;color:var(--muted);font-weight:600;">Target Forecast Period</div>
                        <div id="detect-target" style="font-size:1rem;font-weight:800;color:#2563eb;">—</div>
                    </div>
                    <div style="margin-left:auto;">
                        <div id="detect-grades" style="font-size:.82rem;color:var(--muted);"></div>
                    </div>
                </div>
                <div id="detect-warn" style="display:none;margin-top:10px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;color:#92400e;font-size:.84rem;font-weight:600;"></div>
            </div>
        </div>

        <!-- Socio-Demographic & Profile Settings (Collapsible) -->
        <details class="form-section" style="margin-top:0;" open>
            <summary style="cursor:pointer;font-weight:700;font-size:.95rem;padding:6px 0;list-style:none;display:flex;align-items:center;gap:8px;">
                Socio-Demographic &amp; Survey Profile
                <small style="font-weight:400;color:var(--muted);font-size:.8rem;">(auto-loaded from student record)</small>
            </summary>
            <div class="form-grid" style="margin-top:16px;">
                <label>
                    <span>Assigned Advisor / Professor</span>
                    <input type="text" id="assigned_advisor" value="<?= h($_cu['full_name'] ?? 'Advisor') ?>" readonly
                           style="background-color:var(--surface-strong,#f1f5f9);color:var(--muted,#475569);cursor:not-allowed;">
                </label>
                <label>
                    <span>Internet Access</span>
                    <select name="internet_access" id="internet_access">
                        <option value="1" <?= old_value('internet_access','1')==='1' ? 'selected' : '' ?>>Yes (Available)</option>
                        <option value="0" <?= old_value('internet_access')==='0' ? 'selected' : '' ?>>No (Limited/None)</option>
                    </select>
                </label>
                <label>
                    <span>Digital Literacy (1-5)</span>
                    <input type="number" min="1" max="5" name="digital_literacy" id="digital_literacy"
                           value="<?= h(old_value('digital_literacy','3')) ?>" required>
                    <small style="color:var(--muted);font-size:.78rem;margin-top:4px;display:block;">1=Basic → 5=Advanced</small>
                </label>
                <label>
                    <span>Weekly Study Hours</span>
                    <input type="number" step="0.1" min="0" max="80" name="study_hours" id="study_hours"
                           value="<?= h(old_value('study_hours','6.0')) ?>" required>
                </label>
                <label>
                    <span>Household Income (PHP)</span>
                    <input type="number" step="1" min="0" max="500000" name="household_income" id="household_income"
                           value="<?= h(old_value('household_income','0')) ?>" required>
                </label>
                <label>
                    <span>Parental Education</span>
                    <select name="parental_education" id="parental_education">
                        <?php 
                        $curParentEdu = (int)old_value('parental_education', '3');
                        foreach ([1 => 'Elementary', 2 => 'High School', 3 => 'College', 4 => 'Postgraduate'] as $val => $label): 
                        ?>
                            <option value="<?= $val ?>" <?= ($curParentEdu === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
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
        </details>

        <div class="form-actions" style="margin-top:8px;">
            <button class="button button-primary" type="submit" id="submit-btn" style="gap:8px;display:inline-flex;align-items:center;">
                Generate Next-Period Forecast
            </button>
            <span id="forecast-hint" style="font-size:.83rem;color:var(--muted);"></span>
        </div>
    </form>
</div>

<script>
const REGISTERED_STUDENTS = <?= json_encode($registeredStudentsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PERIOD_NEXT = { 'Prelim': 'Midterm', 'Midterm': 'Semi-Final', 'Semi-Final': 'Final' };

/* --------------------------------------------------------------- */
/* Apply student profile data to form fields                       */
/* --------------------------------------------------------------- */
function applyStudentProfile(student, sv = {}) {
    if (!student) return;

    const set = (id, val) => {
        const el = document.getElementById(id);
        if (el && val !== null && val !== undefined) el.value = val;
    };

    set('full_name', student.full_name);
    set('year_level', student.year_level);
    set('section', student.section);

    if (student.advisor_name) {
        set('assigned_advisor', student.advisor_name);
    }

    const netVal = (sv && sv.internet_access !== undefined && sv.internet_access !== null)
        ? sv.internet_access
        : (student.internet_access !== undefined ? student.internet_access : null);
    if (netVal !== null) set('internet_access', String(netVal));

    if (student.household_income !== undefined && student.household_income !== null) {
        set('household_income', student.household_income);
    }
    if (student.parental_education !== undefined && student.parental_education !== null) {
        set('parental_education', student.parental_education);
    }
    if (student.working_student !== undefined && student.working_student !== null) {
        set('working_student', student.working_student);
    }

    const dlVal = (sv && sv.digital_literacy) ? sv.digital_literacy : (student.digital_literacy ?? null);
    if (dlVal !== null) set('digital_literacy', dlVal);

    const shVal = (sv && sv.study_hours) ? sv.study_hours : (student.study_hours ?? null);
    if (shVal !== null) set('study_hours', shVal);

    const ind = document.getElementById('student_autofill_indicator');
    if (ind) {
        ind.style.display = 'block';
        const yr = student.year_level ? ' • ' + student.year_level : '';
        const sec = student.section ? ' (' + student.section + ')' : '';
        ind.textContent = `Autofilled: ${student.full_name || student.student_no}${yr}${sec}`;
    }
}

/* --------------------------------------------------------------- */
/* Quick-select student dropdown                                    */
/* --------------------------------------------------------------- */
const quickSelectEl = document.getElementById('student_quick_select');
const studentPickerEl = document.getElementById('student_picker');
const studentPickerListEl = document.getElementById('student_picker_list');

function studentPickerLabel(student) {
    return `${student.student_no} — ${student.full_name || ''}${student.year_level ? ` (${student.year_level}${student.section ? ', ' + student.section : ''})` : ''}`;
}

function renderStudentPicker(query = '') {
    if (!studentPickerListEl) return;
    const term = query.trim().toLowerCase();
    const students = Object.values(REGISTERED_STUDENTS).filter(student => {
        const searchable = `${student.student_no || ''} ${student.full_name || ''} ${student.year_level || ''} ${student.section || ''}`.toLowerCase();
        return !term || searchable.includes(term);
    });
    studentPickerListEl.innerHTML = '';
    if (!students.length) {
        studentPickerListEl.innerHTML = '<div class="student-picker__empty">No matching student found.</div>';
        return;
    }
    students.forEach(student => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'student-picker__option';
        option.setAttribute('role', 'option');
        const studentNo = document.createElement('strong');
        studentNo.textContent = student.student_no || '';
        option.appendChild(studentNo);
        option.append(` — ${student.full_name || ''}${student.year_level ? ` (${student.year_level}${student.section ? ', ' + student.section : ''})` : ''}`);
        option.addEventListener('mousedown', event => {
            event.preventDefault();
            quickSelectEl.value = studentPickerLabel(student);
            document.getElementById('student_no').value = student.student_no;
            studentPickerEl.classList.remove('is-open');
            quickSelectEl.setAttribute('aria-expanded', 'false');
            applyStudentProfile(student);
            loadStudentStatus();
        });
        studentPickerListEl.appendChild(option);
    });
}

if (quickSelectEl && studentPickerEl) {
    const initialStudentNo = quickSelectEl.value.trim();
    if (REGISTERED_STUDENTS[initialStudentNo]) {
        quickSelectEl.value = studentPickerLabel(REGISTERED_STUDENTS[initialStudentNo]);
    }
    quickSelectEl.addEventListener('focus', function () {
        renderStudentPicker(this.value);
        studentPickerEl.classList.add('is-open');
        this.setAttribute('aria-expanded', 'true');
    });
    quickSelectEl.addEventListener('input', function () {
        renderStudentPicker(this.value);
        studentPickerEl.classList.add('is-open');
    });
    quickSelectEl.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            studentPickerEl.classList.remove('is-open');
            this.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('click', function (event) {
        if (!studentPickerEl.contains(event.target)) {
            studentPickerEl.classList.remove('is-open');
            quickSelectEl.setAttribute('aria-expanded', 'false');
        }
    });
}

const snoInput = document.getElementById('student_no');
if (snoInput) {
    snoInput.addEventListener('change', function () { loadStudentStatus(); });
    snoInput.addEventListener('blur',   function () { loadStudentStatus(); });
    snoInput.addEventListener('input',  function () {
        const qs = document.getElementById('student_quick_select');
        const val = this.value.trim();
        if (qs && REGISTERED_STUDENTS[val]) {
            qs.value = studentPickerLabel(REGISTERED_STUDENTS[val]);
            applyStudentProfile(REGISTERED_STUDENTS[val]);
        }
    });
}

/* --------------------------------------------------------------- */
/* Load student status & grades via AJAX                           */
/* --------------------------------------------------------------- */
function loadStudentStatus() {
    const sno = (document.getElementById('student_no')?.value || '').trim();
    const ay  = document.getElementById('academic_year')?.value || '';
    const sem = document.getElementById('semester')?.value || '';

    const panel = document.getElementById('auto-detect-panel');
    const srcEl = document.getElementById('detect-source');
    const tgtEl = document.getElementById('detect-target');
    const grEl  = document.getElementById('detect-grades');
    const warnEl= document.getElementById('detect-warn');
    const hint  = document.getElementById('forecast-hint');

    if (!sno || !ay || !sem) {
        if (panel) panel.style.display = 'none';
        return;
    }

    const url = `get_student_grades.php?student_no=${encodeURIComponent(sno)}&academic_year=${encodeURIComponent(ay)}&semester=${encodeURIComponent(sem)}&period=`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!panel) return;

            const sv = res.data?.survey || {};
            const st = res.data?.student || {};
            if (st) applyStudentProfile(st, sv);

            const qs = document.getElementById('student_quick_select');
            if (qs && REGISTERED_STUDENTS[sno]) qs.value = studentPickerLabel(REGISTERED_STUDENTS[sno]);

            const pg = res.data?.period_grades || {};
            const allPeriods = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];

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
                warnEl.textContent = 'No grades saved yet. Please enter scores first via Enter Scores.';
                warnEl.style.display = '';
                if (hint) hint.textContent = 'No grades available to forecast.';
                return;
            }

            if (latestPeriod === 'Final') {
                srcEl.textContent = 'Final';
                tgtEl.textContent = '—';
                warnEl.textContent = 'Final is the last period. No next-period forecast available.';
                warnEl.style.display = '';
                if (hint) hint.textContent = 'Final period reached — no next forecast.';
                return;
            }

            const targetPeriod = PERIOD_NEXT[latestPeriod];
            srcEl.textContent = latestPeriod + ' (' + parseFloat(pg[latestPeriod]).toFixed(2) + '%)';
            tgtEl.textContent = targetPeriod;

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
        btn.innerHTML = 'Generating Forecast…';
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
