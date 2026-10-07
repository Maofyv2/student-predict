<?php
require_once __DIR__ . '/bootstrap.php';
require_role(['Advisor']);

$user   = current_user();
$errors = [];
$success = null;

$PERIODS = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];

$PERIOD_PREV = [
    'Prelim'     => [],
    'Midterm'    => ['Prelim'],
    'Semi-Final' => ['Prelim', 'Midterm'],
    'Final'      => ['Prelim', 'Midterm', 'Semi-Final'],
];

$_es_advisorId = (int)$user['id'];

/* ------------------------------------------------------------------ */
/* Helper: Calculate GWA from available grading periods               */
/* ------------------------------------------------------------------ */
function calculate_gwa(array $periodGrades): ?float {
    $validGrades = [];
    foreach (['Prelim', 'Midterm', 'Semi-Final', 'Final'] as $period) {
        if (isset($periodGrades[$period]) && $periodGrades[$period] !== null && $periodGrades[$period] !== '') {
            $grade = (float)$periodGrades[$period];
            if ($grade >= 0 && $grade <= 100) {
                $validGrades[$period] = $grade;
            }
        }
    }
    if (empty($validGrades)) {
        return null;
    }
    return array_sum($validGrades) / count($validGrades);
}

/* ------------------------------------------------------------------ */
/* Load students assigned to this advisor                             */
/* ------------------------------------------------------------------ */
$stuStmt = db()->prepare(
    "SELECT id, student_no, full_name, year_level, section, gender,
            household_income, parental_education, scholarship_status, working_student
     FROM tbl_students
     WHERE (advisor_id = ? OR professor_id = ?)
     ORDER BY full_name ASC"
);
$stuStmt->bind_param('ii', $_es_advisorId, $_es_advisorId);
$stuStmt->execute();
$students = $stuStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$studentMap = [];
foreach ($students as $s) {
    $studentMap[$s['id']] = $s;
}

/* ------------------------------------------------------------------ */
/* Load grading criteria weights                                      */
/* ------------------------------------------------------------------ */
$allWeights = get_all_grading_weights();

/* ------------------------------------------------------------------ */
/* POST Handling                                                      */
/* ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $studentId     = (int)($_POST['student_id'] ?? 0);
    $gradingPeriod = trim($_POST['grading_period'] ?? '');
    $academicYear  = trim($_POST['academic_year'] ?? '');
    $semester      = trim($_POST['semester'] ?? '');

    $formAction = trim($_POST['form_action'] ?? '');
    $saveScores = ($formAction === 'save_scores' || isset($_POST['save_scores']));
    $runPredict = ($formAction === 'run_prediction' || isset($_POST['run_prediction']));

    $computedGrade = null;
    $missingList   = [];
    $components    = [];
    $recordedScores = [];

    // Basic validation
    if (!$studentId || !$gradingPeriod || !$academicYear || !$semester) {
        $errors[] = 'Student, grading period, academic year, and semester are required.';
    }

    if ($gradingPeriod && !in_array($gradingPeriod, $PERIODS, true)) {
        $errors[] = 'Invalid grading period selected.';
    }

    if (!$saveScores && !$runPredict) {
        $errors[] = 'Please select Save Scores or Generate Prediction.';
    }

    // Verify student ownership
    if (!$errors) {
        $stu = $studentMap[$studentId] ?? null;
        if (!$stu) {
            $ownerChk = db()->prepare("SELECT id FROM tbl_students WHERE id = ? AND (advisor_id = ? OR professor_id = ?)");
            $ownerChk->bind_param('iii', $studentId, $_es_advisorId, $_es_advisorId);
            $ownerChk->execute();
            if ($ownerChk->get_result()->num_rows === 0) {
                $errors[] = 'Access denied: You can only enter scores for your assigned students.';
            } else {
                $errors[] = 'Student not found in your assigned list.';
            }
        }
    }

    // Collect raw scores (default 0)
    if (!$errors) {
        $weights = $allWeights[$gradingPeriod] ?? [];

        foreach ($weights as $compName => $cfg) {
            $cleanComp = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($compName)));
            $fieldKey  = $cleanComp;
            if ($compName === 'Activities') $fieldKey = 'activity';
            $postKey   = 'score_' . $fieldKey;

            // Default raw scores to 0
            $raw = $_POST[$postKey] ?? 0;
            if ($raw === '' || $raw === null) {
                $raw = 0;
            }

            $maxScore = (float)($cfg['max_score'] ?: 100);
            $rawVal   = (float)$raw;

            if ($rawVal < 0 || $rawVal > $maxScore) {
                $errors[] = "{$compName} score must be between 0 and {$maxScore}.";
            } else {
                $components[$compName] = $rawVal;
                $components[$fieldKey . '_score'] = $rawVal;
                $recordedScores[$compName] = [
                    'raw'       => $rawVal,
                    'max_score' => $maxScore,
                    'weight'    => (float)$cfg['weight'],
                ];
                if ($compName === 'Activities') {
                    $components['activity_score'] = $rawVal;
                }
                if ($compName === 'Attendance') {
                    $components['attendance_rate'] = ($maxScore > 0) ? ($rawVal / $maxScore) * 100.0 : $rawVal;
                }
                if ($compName === 'Lab') {
                    $components['lab_score'] = ($maxScore > 0) ? ($rawVal / $maxScore) * 100.0 : $rawVal;
                }
            }
        }

        // Attendance & Lab inputs
        if (isset($_POST['attendance_rate']) && $_POST['attendance_rate'] !== '') {
            $components['attendance_rate'] = (float)$_POST['attendance_rate'];
        }
        if (isset($_POST['lab_score']) && $_POST['lab_score'] !== '') {
            $components['lab_score'] = (float)$_POST['lab_score'];
        }

        // Socio-demographic inputs
        $internetAccess    = (int)($_POST['internet_access'] ?? 1);
        $digitalLiteracy   = (int)($_POST['digital_literacy'] ?? 3);
        $householdIncome   = (float)($_POST['household_income'] ?? ($stu['household_income'] ?? 0));
        $parentalEducation = (int)($_POST['parental_education'] ?? ($stu['parental_education'] ?? 3));
        $studyHours        = (float)($_POST['study_hours'] ?? 4);
        $workingStudent    = (int)($_POST['working_student'] ?? ($stu['working_student'] ?? 0));
    }

    // Process Save Scores
    if (!$errors) {
        $weights = $allWeights[$gradingPeriod] ?? [];
        $gradeResult = compute_weighted_grade($components, $weights, 1);

        if ($gradeResult === null) {
            $errors[] = 'Please enter valid scores to compute the grade.';
        } else {
            $computedGrade = (float)$gradeResult['grade'];
            $missingList   = $gradeResult['missing'];

            $conn = db();
            $missingJson = json_encode($missingList);
            $scoresJson  = json_encode($recordedScores);

            $examScore       = $components['exam_score'] ?? null;
            $quizScore       = $components['quiz_score'] ?? null;
            $activityScore   = $components['activity_score'] ?? null;
            $assignmentScore = $components['assignment_score'] ?? null;
            $projectScore    = $components['project_score'] ?? null;
            $attendanceRate  = $components['attendance_rate'] ?? null;
            $labScore        = $components['lab_score'] ?? null;

            $stmt = $conn->prepare(
                'INSERT INTO tbl_grade_components
                    (student_id, academic_year, semester, period,
                     exam_score, quiz_score, activity_score, assignment_score,
                     project_score, attendance_rate, lab_score,
                     computed_grade, missing_components, scores_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    exam_score=VALUES(exam_score), quiz_score=VALUES(quiz_score),
                    activity_score=VALUES(activity_score), assignment_score=VALUES(assignment_score),
                    project_score=VALUES(project_score), attendance_rate=VALUES(attendance_rate),
                    lab_score=VALUES(lab_score), computed_grade=VALUES(computed_grade),
                    missing_components=VALUES(missing_components), scores_json=VALUES(scores_json),
                    updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->bind_param(
                'isssddddddddss',
                $studentId, $academicYear, $semester, $gradingPeriod,
                $examScore, $quizScore, $activityScore, $assignmentScore,
                $projectScore, $attendanceRate, $labScore, $computedGrade, $missingJson, $scoresJson
            );
            $stmt->execute();

            // If user clicked Save Scores only
            if ($saveScores && !$runPredict) {
                // Calculate GWA including this newly saved grade
                $periodGradesForGwa = get_student_period_grades($studentId, $academicYear, $semester);
                $periodGradesForGwa[$gradingPeriod] = $computedGrade;
                $gwa = calculate_gwa($periodGradesForGwa);

                $success = [
                    'saved_only'     => true,
                    'computed_grade' => $computedGrade,
                    'missing'        => $missingList,
                    'gwa'            => $gwa,
                    'period_grades'  => $periodGradesForGwa,
                ];
            }
        }
    }

    // Process Generate Prediction (only if scores are saved)
    if (!$errors && $runPredict && $computedGrade !== null) {
        // Double-check verification: scores must be recorded in tbl_grade_components
        $savedCheck = db()->prepare(
            'SELECT computed_grade FROM tbl_grade_components
             WHERE student_id = ? AND academic_year = ? AND semester = ? AND period = ? LIMIT 1'
        );
        $savedCheck->bind_param('isss', $studentId, $academicYear, $semester, $gradingPeriod);
        $savedCheck->execute();
        $savedRow = $savedCheck->get_result()->fetch_assoc();

        if (!$savedRow) {
            $errors[] = 'Please save the scores first before generating a prediction.';
        } else {
            // Check previous period actual grades if applicable
            $prevGrades = [];
            foreach ($PERIOD_PREV[$gradingPeriod] as $pp) {
                $ppKey = strtolower(str_replace('-', '_', $pp)) . '_actual_grade';
                if (isset($_POST[$ppKey]) && $_POST[$ppKey] !== '') {
                    $prevGrades[$pp] = (float)$_POST[$ppKey];
                } else {
                    $pgRows = get_student_period_grades($studentId, $academicYear, $semester);
                    if (isset($pgRows[$pp])) {
                        $prevGrades[$pp] = (float)$pgRows[$pp];
                    } else {
                        $errors[] = "Please enter the actual {$pp} grade before running the prediction.";
                    }
                }
            }

            if (!$errors) {
                $apiPayload = [
                    'grading_period'     => $gradingPeriod,
                    'computed_grade'     => $computedGrade,
                    'attendance_rate'    => $components['attendance_rate'] ?? 85.0,
                    'lab_score'          => $components['lab_score'] ?? 80.0,
                    'internet_access'    => $internetAccess,
                    'digital_literacy'   => $digitalLiteracy,
                    'household_income'   => $householdIncome,
                    'parental_education' => $parentalEducation,
                    'study_hours'        => $studyHours,
                    'working_student'    => $workingStudent,
                ];

                $periodGradeMap = [
                    'Prelim'     => 'prelim_grade',
                    'Midterm'    => 'midterm_grade',
                    'Semi-Final' => 'semi_final_grade'
                ];
                foreach ($PERIOD_PREV[$gradingPeriod] as $pp) {
                    if (isset($prevGrades[$pp]) && isset($periodGradeMap[$pp])) {
                        $apiPayload[$periodGradeMap[$pp]] = $prevGrades[$pp];
                    }
                }

                // Call prediction API (local or remote)
                $api = api_request_local('POST', '/predict-progressive', $apiPayload);
                if (!$api['ok']) {
                    $api = api_request('POST', '/predict-progressive', $apiPayload);
                }

                if (!$api['ok']) {
                    $errors[] = $api['error'] ?? 'Prediction service is currently unavailable.';
                } else {
                    $conn = db();
                    $conn->begin_transaction();
                    try {
                        // Upsert student info
                        $stmt = $conn->prepare(
                            'INSERT INTO tbl_students
                                (student_no, full_name, year_level, section, gender,
                                 household_income, parental_education, scholarship_status, working_student)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                                household_income=VALUES(household_income),
                                parental_education=VALUES(parental_education),
                                working_student=VALUES(working_student)'
                        );
                        $stmt->bind_param(
                            'sssssdisi',
                            $stu['student_no'], $stu['full_name'], $stu['year_level'], $stu['section'], $stu['gender'],
                            $householdIncome, $parentalEducation, $stu['scholarship_status'], $workingStudent
                        );
                        $stmt->execute();

                        // Survey insert
                        $stmt = $conn->prepare(
                            'INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, device_availability, study_hours)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        $devAvail = 'Unknown';
                        $stmt->bind_param('iiisd', $studentId, $internetAccess, $digitalLiteracy, $devAvail, $studyHours);
                        $stmt->execute();

                        // Academic record
                        $prelimGrade  = ($gradingPeriod === 'Prelim'     ? $computedGrade : ($prevGrades['Prelim']     ?? 0));
                        $midtermGrade = ($gradingPeriod === 'Midterm'    ? $computedGrade : ($prevGrades['Midterm']    ?? 0));
                        $semiGrade    = ($gradingPeriod === 'Semi-Final' ? $computedGrade : ($prevGrades['Semi-Final'] ?? 0));
                        $finalGrade   = ($gradingPeriod === 'Final'      ? $computedGrade : 0);
                        $attRate      = $components['attendance_rate'] ?? 0;
                        $lScore       = $components['lab_score'] ?? 0;

                        $stmt = $conn->prepare(
                            'INSERT INTO tbl_academic_records
                                (student_id, academic_year, semester,
                                 prelim_grade, midterm_grade, semi_final_grade, final_grade,
                                 attendance_rate, lab_score)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param(
                            'issdddddd',
                            $studentId, $academicYear, $semester,
                            $prelimGrade, $midtermGrade, $semiGrade, $finalGrade, $attRate, $lScore
                        );
                        $stmt->execute();
                        $academicRecordId = (int)$conn->insert_id;

                        // Prediction data
                        $prediction     = (string)($api['data']['prediction'] ?? 'Unknown');
                        $predictedGrade = (float)($api['data']['predicted_grade'] ?? $computedGrade);
                        $confidence     = (float)($api['data']['confidence'] ?? 0);
                        $recomm         = (string)($api['data']['recommendation'] ?? '');
                        $riskFactors    = json_encode($api['data']['risk_factors'] ?? []);
                        $missingComp    = json_encode($missingList);
                        $featurePayload = json_encode($apiPayload);
                        $metadata       = model_metadata();
                        $modelAccuracy  = (float)($api['data']['model_accuracy'] ?? $metadata['accuracy'] ?? 0);
                        $f1Score        = (float)($api['data']['model_f1'] ?? $metadata['weighted_f1'] ?? 0);
                        $algorithm      = 'XGBoost Progressive (' . $gradingPeriod . ')';
                        $createdBy      = (int)$user['id'];

                        $stmt = $conn->prepare(
                            'INSERT INTO tbl_predictions
                                (student_id, academic_record_id, grading_period, predicted_status,
                                 predicted_grade, confidence, recommendation, risk_factors,
                                 missing_components, feature_payload, model_accuracy,
                                 f1_score_log, algorithm, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param(
                            'iissddssssddsi',
                            $studentId, $academicRecordId, $gradingPeriod, $prediction,
                            $predictedGrade, $confidence, $recomm, $riskFactors,
                            $missingComp, $featurePayload, $modelAccuracy,
                            $f1Score, $algorithm, $createdBy
                        );
                        $stmt->execute();

                        // Risk Alert
                        if ($prediction === 'At-Risk' || $prediction === 'Fail') {
                            $severity = ($prediction === 'Fail') ? 'High' : 'Medium';
                            $msg = "Student {$stu['full_name']} ({$stu['student_no']}) — {$gradingPeriod} — flagged as '{$prediction}' with " . round($confidence * 100, 1) . "% confidence.";
                            create_alert($studentId, $createdBy, 'Risk', $severity, $msg);
                        }

                        $conn->commit();

                        // Compute GWA across all available grading periods
                        $periodGradesForGwa = get_student_period_grades($studentId, $academicYear, $semester);
                        $periodGradesForGwa[$gradingPeriod] = $computedGrade;
                        $gwa = calculate_gwa($periodGradesForGwa);

                        // Success payload
                        $success = $api['data'];
                        $success['computed_grade']  = $computedGrade;
                        $success['predicted_grade'] = $predictedGrade;
                        $success['missing']         = $missingList;
                        $success['grading_period']  = $gradingPeriod;
                        $success['student_name']    = $stu['full_name'];
                        $success['student_no']      = $stu['student_no'];
                        $success['algorithm']       = $algorithm;
                        $success['accuracy']        = $modelAccuracy;
                        $success['f1_score']        = $f1Score;
                        $success['gwa']             = $gwa;
                        $success['period_grades']   = $periodGradesForGwa;

                    } catch (Throwable $ex) {
                        $conn->rollback();
                        $errors[] = 'Prediction generated but could not be saved: ' . $ex->getMessage();
                    }
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Restore selected context values                                    */
/* ------------------------------------------------------------------ */
$selStudentId = (int)($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
$selPeriod    = trim($_POST['grading_period'] ?? $_GET['grading_period'] ?? '');
$selAY        = trim($_POST['academic_year'] ?? '2025-2026');
$selSem       = trim($_POST['semester'] ?? '1st Semester');

// Load existing saved scores from DB for current selection
$dbScores = [];
if ($selStudentId && $selPeriod && $selAY && $selSem) {
    $stmt = db()->prepare(
        'SELECT exam_score, quiz_score, activity_score, assignment_score,
                project_score, attendance_rate, lab_score, computed_grade, scores_json
         FROM tbl_grade_components
         WHERE student_id = ? AND academic_year = ? AND semester = ? AND period = ? LIMIT 1'
    );
    $stmt->bind_param('isss', $selStudentId, $selAY, $selSem, $selPeriod);
    $stmt->execute();
    $dbScores = $stmt->get_result()->fetch_assoc() ?? [];

    if (!empty($dbScores['scores_json'])) {
        $decoded = json_decode($dbScores['scores_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $dbScores[$k] = (is_array($v) && isset($v['raw'])) ? $v['raw'] : $v;
            }
        }
    }
}

// Load previous period grades for the selected student
$dbPeriodGrades = $selStudentId ? get_student_period_grades($selStudentId, $selAY, $selSem) : [];

// Check if scores are saved in DB or just saved successfully
$scoresSaved = !empty($dbScores) || ($success && isset($success['saved_only']));

page_header('Enter Scores');
?>

<style>
.score-table {
    width: 100%;
    border-collapse: collapse;
}
.score-table th {
    background: var(--surface-strong);
    padding: 10px 14px;
    text-align: left;
    font-size: .82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--muted);
    border-bottom: 2px solid var(--line);
}
.score-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
.score-table .input-narrow {
    width: 100px;
    text-align: center;
    padding: 6px 8px;
    border-radius: 6px;
    border: 1px solid var(--line);
    font-weight: 600;
}
.prediction-result {
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 20px;
    border: 2px solid var(--line);
}
.prediction-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}
.prediction-stat {
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .1);
    border-radius: 10px;
    padding: 12px 14px;
}
.prediction-stat small {
    display: block;
    font-size: .74rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    opacity: .75;
    margin-bottom: 4px;
}
.prediction-stat strong {
    display: block;
    font-size: 1.45rem;
    font-weight: 800;
}
.gwa-card {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: #fff;
    border-radius: 12px;
    padding: 18px 20px;
    margin-top: 16px;
}
.gwa-label {
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    opacity: .85;
}
.gwa-value {
    font-size: 2.3rem;
    font-weight: 800;
    line-height: 1.1;
    margin-top: 4px;
}
.period-grade-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.period-grade-item {
    background: rgba(255, 255, 255, .16);
    padding: 6px 10px;
    border-radius: 6px;
    font-size: .82rem;
}
.prev-grade-box {
    background: var(--surface-strong);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 12px;
}
.period-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 12px;
    margin-top: 14px;
}
.psg-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 14px;
}
.psg-card .period-name  { font-size:.75rem; font-weight:800; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.psg-card .actual-grade { font-size:1.3rem; font-weight:800; color:var(--text); }
.psg-card .active-period { font-size:.7rem; color:var(--blue); font-weight:700; margin-top:3px; }
.psg-card .na-tag       { font-size:.85rem; color:var(--muted); font-style:italic; }
.gwa-strip {
    margin-top:10px;
    background: linear-gradient(135deg,#1e3a8a,#3b82f6);
    color:#fff;
    border-radius:8px;
    padding:12px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:6px;
}
.gwa-strip .gwa-val { font-size:1.6rem; font-weight:800; }
.gwa-strip .gwa-lbl { font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; opacity:.85; }
@media(max-width:680px){ .period-summary-grid { grid-template-columns: repeat(2,1fr); } }
button:disabled {
    opacity: .5;
    cursor: not-allowed !important;
}
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow">Advisor Workflow</p>
        <h1>Enter Student Scores</h1>
    </div>
</section>

<!-- Error Notices -->
<?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
        <?php foreach ($errors as $e): ?>
            <div><strong>Notice:</strong> <?= h($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Save Only Success Notification -->
<?php if ($success && isset($success['saved_only'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <div>
            <strong>✓ Scores Saved:</strong> Scores recorded successfully. Computed grade: <strong><?= round((float)$success['computed_grade'], 2) ?>%</strong>
            <?php if (!empty($success['missing'])): ?>
                <span style="color:var(--risk-text);font-weight:600;"> — Missing: <?= h(implode(', ', $success['missing'])) ?></span>
            <?php endif; ?>
        </div>
        <div style="margin-top:6px;font-size:.9rem;">
            👉 You may now click <strong>Generate Prediction</strong> below to classify student performance.
        </div>
    </div>
<?php endif; ?>

<!-- ================================================================ -->
<!-- Prediction Result Display                                        -->
<!-- ================================================================ -->
<?php if ($success && isset($success['prediction'])): ?>
    <?php
    $confRaw     = (float)($success['confidence'] ?? 0);
    $confPct     = ($confRaw <= 1) ? round($confRaw * 100, 1) : round($confRaw, 1);
    $accuracyRaw = (float)($success['accuracy'] ?? 0);
    $accuracyPct = ($accuracyRaw <= 1) ? round($accuracyRaw * 100, 2) : round($accuracyRaw, 2);
    $f1Raw       = (float)($success['f1_score'] ?? 0);
    $f1Pct       = ($f1Raw <= 1) ? round($f1Raw * 100, 2) : round($f1Raw, 2);
    $compGrade   = round((float)$success['computed_grade'], 2);
    $predGrade   = round((float)($success['predicted_grade'] ?? $success['computed_grade']), 2);
    $gwa         = $success['gwa'] ?? null;
    $statusCls   = status_class($success['prediction']);
    ?>

    <section class="prediction-result <?= h($statusCls) ?>">
        <div style="margin-bottom:16px;">
            <small style="display:block;font-size:.78rem;opacity:.75;margin-bottom:4px;">
                <?= h($success['grading_period']) ?> Prediction — <?= h($success['student_name']) ?> (<?= h($success['student_no']) ?>)
            </small>
            <h2 style="margin:0;font-size:1.5rem;">Prediction Result</h2>
        </div>

        <div class="prediction-grid">
            <!-- Computed Grade -->
            <div class="prediction-stat">
                <small>Computed Grade</small>
                <strong><?= $compGrade ?>%</strong>
            </div>

            <!-- Predicted Grade -->
            <div class="prediction-stat">
                <small>Predicted Grade</small>
                <strong><?= $predGrade ?>%</strong>
            </div>

            <!-- Prediction / Status -->
            <div class="prediction-stat">
                <small>Prediction</small>
                <strong><?= h($success['prediction']) ?></strong>
            </div>

            <!-- Confidence -->
            <div class="prediction-stat">
                <small>Confidence</small>
                <strong><?= $confPct ?>%</strong>
            </div>

            <!-- Model Accuracy -->
            <div class="prediction-stat">
                <small>Model Accuracy</small>
                <strong><?= $accuracyPct ?>%</strong>
            </div>

            <!-- F1 Score (kung available) -->
            <?php if ($f1Pct > 0): ?>
            <div class="prediction-stat">
                <small>Weighted F1</small>
                <strong><?= $f1Pct ?>%</strong>
            </div>
            <?php endif; ?>
        </div>

        <!-- General Weighted Average (GWA) -->
        <?php if ($gwa !== null): ?>
            <div class="gwa-card">
                <div class="gwa-label">General Weighted Average (GWA)</div>
                <div class="gwa-value"><?= number_format((float)$gwa, 2) ?></div>
                <div style="font-size:.78rem;opacity:.8;margin-top:4px;">
                    Average of available grading-period grades
                </div>
                <?php $dispGrades = $success['period_grades'] ?? []; ?>
                <?php if ($dispGrades): ?>
                    <div class="period-grade-list">
                        <?php foreach ($dispGrades as $pName => $pG): ?>
                            <?php if ($pG !== null && $pG !== ''): ?>
                                <span class="period-grade-item">
                                    <?= h($pName) ?>: <strong><?= number_format((float)$pG, 2) ?>%</strong>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Recommendation -->
        <?php if (!empty($success['recommendation'])): ?>
            <div style="padding:14px 16px;border-radius:8px;background:rgba(255,255,255,.10);margin-top:16px;">
                <strong>Recommendation:</strong>
                <div style="margin-top:4px;"><?= h($success['recommendation']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Risk Factors -->
        <?php if (!empty($success['risk_factors'])): ?>
            <div style="margin-top:12px;">
                <?php foreach ($success['risk_factors'] as $rf): ?>
                    <span class="chip" style="background:rgba(0,0,0,.08);margin:2px;">Risk Factor: <?= h($rf) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<!-- ================================================================ -->
<!-- Main Form                                                        -->
<!-- ================================================================ -->
<form method="post" id="scores-form" autocomplete="off">
    <!-- Hidden input to guarantee button action is always recognized -->
    <input type="hidden" name="form_action" id="form_action" value="">

    <!-- Step 1: Select Student & Context -->
    <div class="panel form-panel" style="margin-bottom:16px;">
        <div class="panel-title"><h2>Step 1 — Student &amp; Grading Period</h2></div>
        <div class="form-grid">
            <label>
                <span>Student</span>
                <select name="student_id" id="student_id" required onchange="onStudentChange()">
                    <option value="">— Select Student —</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= $s['id'] ?>"
                            <?= ($selStudentId === (int)$s['id']) ? 'selected' : '' ?>>
                            <?= h($s['student_no']) ?> — <?= h($s['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Academic Year</span>
                <select name="academic_year" id="academic_year">
                    <?php foreach (['2025-2026', '2026-2027', '2027-2028'] as $opt): ?>
                        <option <?= ($selAY === $opt) ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Semester</span>
                <select name="semester" id="semester">
                    <?php foreach (['1st Semester', '2nd Semester', 'Summer'] as $opt): ?>
                        <option <?= ($selSem === $opt) ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Grading Period</span>
                <select name="grading_period" id="grading_period" required onchange="onPeriodChange()">
                    <option value="">— Select Period —</option>
                    <?php foreach ($PERIODS as $p): ?>
                        <option value="<?= h($p) ?>" <?= ($selPeriod === $p) ? 'selected' : '' ?>><?= h($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div id="student-preview" style="display:none;" class="prev-grade-box">
            <span id="student-preview-text" style="font-size:.9rem;font-weight:600;color:var(--text);"></span>
        </div>

        <?php
        // --- Grades this semester ---
        $es_allPeriodGrades = [];
        if ($selStudentId && $selAY && $selSem) {
            $gStmt = db()->prepare(
                "SELECT period, computed_grade FROM tbl_grade_components
                  WHERE student_id = ? AND academic_year = ? AND semester = ?
                  ORDER BY FIELD(period,'Prelim','Midterm','Semi-Final','Final')"
            );
            $gStmt->bind_param('iss', $selStudentId, $selAY, $selSem);
            $gStmt->execute();
            foreach ($gStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gr) {
                $es_allPeriodGrades[$gr['period']] = (float)$gr['computed_grade'];
            }
        }
        $es_gwa = count($es_allPeriodGrades) > 0
            ? array_sum($es_allPeriodGrades) / count($es_allPeriodGrades)
            : null;
        ?>
        <?php if ($selStudentId && $selAY && $selSem): ?>
        <div style="margin-top:16px;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:8px;">Grades This Semester</div>
            <div class="period-summary-grid">
                <?php foreach (['Prelim','Midterm','Semi-Final','Final'] as $es_p): ?>
                <div class="psg-card">
                    <div class="period-name"><?= h($es_p) ?></div>
                    <?php if (isset($es_allPeriodGrades[$es_p])): ?>
                        <div class="actual-grade"><?= number_format($es_allPeriodGrades[$es_p], 2) ?>%</div>
                        <?php if ($es_p === $selPeriod): ?>
                            <div class="active-period">Current Period</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="na-tag"><?= ($es_p === $selPeriod) ? '<span style="color:var(--blue);font-style:normal;font-weight:700;">← Enter Below</span>' : 'Not yet entered' ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($es_gwa !== null): ?>
            <div class="gwa-strip">
                <div>
                    <div class="gwa-lbl">General Weighted Average (GWA)</div>
                    <div class="gwa-val"><?= number_format($es_gwa, 2) ?>%</div>
                </div>
                <div style="font-size:.8rem;opacity:.8;">Based on <?= count($es_allPeriodGrades) ?> period<?= count($es_allPeriodGrades) !== 1 ? 's' : '' ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Previous Period Actual Grades (shown for Midterm/Semi/Final) -->
    <div id="prev-grades-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
        <div class="panel-title"><h2>Previous Period Actual Grades</h2></div>
        <div id="prev-grades-body" class="form-grid"></div>
    </div>

    <!-- Step 2: Assessment Scores (Defaults to 0, no live calculation while typing) -->
    <div id="scores-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
        <div class="panel-title">
            <h2>Step 2 — Assessment Scores <span id="period-label" style="color:var(--blue);"></span></h2>
        </div>
        <p style="font-size:.85rem;color:var(--muted);margin:0 0 14px;">
            Enter the raw scores. All score fields default to <strong>0</strong>. Click <strong>Save Scores</strong> to calculate the grade.
        </p>

        <div class="table-wrap">
            <table class="score-table" id="score-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th style="text-align:center;">Max Score</th>
                        <th style="text-align:center;">Weight</th>
                        <th style="text-align:center;">Raw Score</th>
                    </tr>
                </thead>
                <tbody id="score-rows"></tbody>
            </table>
        </div>

        <!-- Optional Attendance & Lab -->
        <div class="form-grid" style="margin-top:16px;">
            <label>
                <span>Attendance Rate (%)</span>
                <input type="number" step="0.1" min="0" max="100"
                       name="attendance_rate" id="field_attendance_rate"
                       value="<?= h($_POST['attendance_rate'] ?? $dbScores['attendance_rate'] ?? '') ?>"
                       placeholder="Optional">
            </label>
            <label>
                <span>Lab Score</span>
                <input type="number" step="0.01" min="0" max="100"
                       name="lab_score" id="field_lab_score"
                       value="<?= h($_POST['lab_score'] ?? $dbScores['lab_score'] ?? '') ?>"
                       placeholder="Optional">
            </label>
        </div>
    </div>

    <!-- Step 3: Socio-demographic Profile -->
    <div id="socio-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
        <div class="panel-title"><h2>Step 3 — Household &amp; Digital Profile</h2></div>
        <div class="form-grid">
            <label>
                <span>Household Income (PHP)</span>
                <input type="number" step="0.01" min="0" max="500000"
                       id="household_income" name="household_income"
                       value="<?= h($_POST['household_income'] ?? ($studentMap[$selStudentId]['household_income'] ?? '')) ?>">
            </label>
            <label>
                <span>Parental Education</span>
                <select id="parental_education" name="parental_education">
                    <?php foreach ([1=>'Elementary', 2=>'High School', 3=>'College', 4=>'Postgraduate'] as $v => $l): ?>
                        <option value="<?= $v ?>"
                            <?= (($_POST['parental_education'] ?? ($studentMap[$selStudentId]['parental_education'] ?? 3)) == $v) ? 'selected' : '' ?>>
                            <?= h($l) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Digital Literacy (1–5)</span>
                <input type="number" step="1" min="1" max="5"
                       id="digital_literacy" name="digital_literacy"
                       value="<?= h($_POST['digital_literacy'] ?? 3) ?>">
            </label>
            <label>
                <span>Study Hours / Week</span>
                <input type="number" step="0.1" min="0" max="80"
                       id="study_hours" name="study_hours"
                       value="<?= h($_POST['study_hours'] ?? '') ?>">
            </label>
            <label>
                <span>Internet Access</span>
                <select id="internet_access" name="internet_access">
                    <option value="1" <?= (($_POST['internet_access'] ?? '1') === '1') ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (($_POST['internet_access'] ?? '') === '0') ? 'selected' : '' ?>>No</option>
                </select>
            </label>
            <label>
                <span>Working Student</span>
                <select id="working_student" name="working_student">
                    <option value="0" <?= ((($_POST['working_student'] ?? $studentMap[$selStudentId]['working_student'] ?? 0) == 0)) ? 'selected' : '' ?>>No</option>
                    <option value="1" <?= ((($_POST['working_student'] ?? $studentMap[$selStudentId]['working_student'] ?? 0) == 1)) ? 'selected' : '' ?>>Yes</option>
                </select>
            </label>
        </div>
    </div>

    <!-- Action Buttons -->
    <div id="action-panel" class="form-actions" style="display:none;gap:12px;align-items:center;flex-wrap:wrap;">
        <button type="submit" name="save_scores" value="1" class="button button-secondary" id="btn-save"
                onclick="document.getElementById('form_action').value='save_scores'">
            Save Scores
        </button>

        <button type="submit" name="run_prediction" value="1" class="button button-primary" id="btn-predict"
                onclick="document.getElementById('form_action').value='run_prediction'"
                <?= $scoresSaved ? '' : 'disabled' ?>>
            Generate Prediction
        </button>

        <span id="predict-hint" style="font-size:.85rem;color:var(--muted);<?= $scoresSaved ? 'display:none;' : '' ?>">
            ⚠️ Save scores first before generating a prediction.
        </span>
    </div>
</form>

<script>
const ALL_WEIGHTS      = <?= json_encode($allWeights, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PERIOD_PREV      = {
    'Prelim':     [],
    'Midterm':    ['Prelim'],
    'Semi-Final': ['Prelim', 'Midterm'],
    'Final':      ['Prelim', 'Midterm', 'Semi-Final']
};
const STUDENT_MAP      = <?= json_encode($studentMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const DB_SCORES        = <?= json_encode($dbScores, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const DB_PERIOD_GRADES = <?= json_encode($dbPeriodGrades, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const SCORES_SAVED     = <?= $scoresSaved ? 'true' : 'false' ?>;

let currentPeriod = <?= json_encode($selPeriod) ?>;

const COMP_FIELD_MAP = {
    'Exam':       'exam',
    'Quiz':       'quiz',
    'Activities': 'activity',
    'Assignment': 'assignment',
    'Project':    'project',
    'Attendance': 'attendance',
    'Lab':        'lab'
};

function onStudentChange() {
    const sid = document.getElementById('student_id').value;
    const preview = document.getElementById('student-preview');
    const previewText = document.getElementById('student-preview-text');
    if (sid && STUDENT_MAP[sid]) {
        const s = STUDENT_MAP[sid];
        previewText.textContent = `${s.full_name} | ${s.year_level} | Section ${s.section}`;
        preview.style.display = 'flex';
        if (s.household_income !== null && s.household_income !== undefined) {
            document.getElementById('household_income').value = s.household_income;
        }
        if (s.parental_education !== null && s.parental_education !== undefined) {
            document.getElementById('parental_education').value = s.parental_education;
        }
        if (s.working_student !== undefined) {
            document.getElementById('working_student').value = s.working_student;
        }
    } else {
        preview.style.display = 'none';
    }
}

function onPeriodChange() {
    currentPeriod = document.getElementById('grading_period').value;
    if (!currentPeriod) {
        hideAll();
        return;
    }
    buildScoreRows();
    buildPrevGrades();
    showPanels();
}

function hideAll() {
    document.getElementById('scores-panel').style.display      = 'none';
    document.getElementById('socio-panel').style.display       = 'none';
    document.getElementById('prev-grades-panel').style.display = 'none';
    document.getElementById('action-panel').style.display      = 'none';
}

function showPanels() {
    const prev = PERIOD_PREV[currentPeriod] || [];
    document.getElementById('prev-grades-panel').style.display = prev.length ? '' : 'none';
    document.getElementById('scores-panel').style.display      = '';
    document.getElementById('socio-panel').style.display       = '';
    document.getElementById('action-panel').style.display      = 'flex';
    document.getElementById('period-label').textContent        = '— ' + currentPeriod;

    // Determine prediction button state
    const predictBtn = document.getElementById('btn-predict');
    const hint = document.getElementById('predict-hint');
    if (SCORES_SAVED && currentPeriod === <?= json_encode($selPeriod) ?>) {
        predictBtn.disabled = false;
        hint.style.display = 'none';
    } else {
        predictBtn.disabled = true;
        hint.style.display = '';
    }
}

function buildScoreRows() {
    const weights = ALL_WEIGHTS[currentPeriod] || {};
    const tbody   = document.getElementById('score-rows');
    tbody.innerHTML = '';

    Object.entries(weights).forEach(([comp, cfg]) => {
        const wt = cfg.weight || cfg;
        const ms = cfg.max_score || 100;
        const fk = COMP_FIELD_MAP[comp] || comp.toLowerCase().replace(/[^a-z0-9]/g, '_');

        let dbColKey = fk + '_score';
        if (fk === 'activity')   dbColKey = 'activity_score';
        if (fk === 'attendance') dbColKey = 'attendance_rate';
        if (fk === 'lab')        dbColKey = 'lab_score';

        // Default raw score is 0 if not previously recorded
        const existing = DB_SCORES[dbColKey] ?? DB_SCORES[comp] ?? DB_SCORES[fk + '_score'] ?? 0;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="font-weight:600;">${escapeHtml(comp)}</td>
            <td style="text-align:center;">${ms}</td>
            <td style="text-align:center;">${wt}%</td>
            <td style="text-align:center;">
                <input type="number" step="0.01" min="0" max="${ms}"
                       class="input-narrow"
                       name="score_${fk}"
                       id="score_${fk}"
                       value="${existing}">
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function buildPrevGrades() {
    const prev = PERIOD_PREV[currentPeriod] || [];
    const body = document.getElementById('prev-grades-body');
    body.innerHTML = '';

    prev.forEach(period => {
        const key   = period.toLowerCase().replace('-', '_') + '_actual_grade';
        const known = DB_PERIOD_GRADES[period];
        const label = document.createElement('label');
        label.innerHTML = `
            <span>${escapeHtml(period)} Actual Grade</span>
            <input type="number" step="0.01" min="0" max="100"
                   name="${key}" id="${key}"
                   value="${known !== undefined ? parseFloat(known).toFixed(2) : ''}"
                   placeholder="Enter actual grade" required>
        `;
        body.appendChild(label);
    });
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Initial setup
if (currentPeriod) {
    buildScoreRows();
    buildPrevGrades();
    showPanels();
}
onStudentChange();

// Handle form submission and prevent accidental double clicks without blocking button values
document.getElementById('scores-form').addEventListener('submit', function(e) {
    const submitBtn = e.submitter;
    if (submitBtn) {
        if (submitBtn.id === 'btn-predict' && submitBtn.disabled) {
            e.preventDefault();
            alert('Please save the scores first before generating a prediction.');
            return;
        }
        // Defer disabling so browser has packaged name & value in the POST request
        setTimeout(() => {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
        }, 10);
    }
});
</script>

<?php page_footer(); ?>