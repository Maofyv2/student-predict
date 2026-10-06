<?php
/**
 * Enter Scores — Advisor Workflow
 * Step 1: Select student + period
 * Step 2: Enter raw scores based on dynamic criteria
 * Step 3: Compute weighted grade automatically
 * Step 4: Run Prediction → save result
 */
require_once __DIR__ . '/bootstrap.php';
require_role(['Advisor']);

$user   = current_user();
$errors = [];
$success = null;   // holds prediction result on success

$PERIODS     = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];
$PERIOD_PREV = [
    'Prelim'     => [],
    'Midterm'    => ['Prelim'],
    'Semi-Final' => ['Prelim', 'Midterm'],
    'Final'      => ['Prelim', 'Midterm', 'Semi-Final'],
];

/* ------------------------------------------------------------------ */
/* Load students for dropdown                                          */
/* ------------------------------------------------------------------ */
$_es_advisorId = (int)$user['id'];

/* Load only this advisor's students for dropdown */
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

$allWeights = get_all_grading_weights();  // ['Prelim'=>['Exam'=>['weight'=>30,'max_score'=>100],...],...]

/* ------------------------------------------------------------------ */
/* POST: Save scores + optionally run prediction                       */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId     = (int)($_POST['student_id']     ?? 0);
    $gradingPeriod = trim($_POST['grading_period']  ?? '');
    $academicYear  = trim($_POST['academic_year']   ?? '');
    $semester      = trim($_POST['semester']        ?? '');
    $runPredict    = isset($_POST['run_prediction']);

    if (!$studentId || !$gradingPeriod || !$academicYear || !$semester) {
        $errors[] = 'Student, grading period, academic year and semester are required.';
    }
    if ($gradingPeriod && !in_array($gradingPeriod, $PERIODS, true)) {
        $errors[] = 'Invalid grading period.';
    }

    if (!$errors) {
        $stu     = $studentMap[$studentId] ?? null;
        if (!$stu) {
            // Double-check: direct DB ownership verification
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

    if (!$errors) {
        $weights   = $allWeights[$gradingPeriod] ?? [];
        $components = [];

        // Collect raw scores from POST using dynamic component list
        $recordedScores = [];
        foreach ($weights as $compName => $cfg) {
            $cleanComp = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($compName)));
            $fieldKey  = $cleanComp;
            if ($compName === 'Activities') $fieldKey = 'activity';
            $postKey   = 'score_' . $fieldKey;
            $raw       = $_POST[$postKey] ?? '';

            if ($raw !== '' && $raw !== null) {
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
        }

        // Attendance and lab (always optional)
        if (($_POST['attendance_rate'] ?? '') !== '') {
            $components['attendance_rate'] = (float)$_POST['attendance_rate'];
        }
        if (($_POST['lab_score'] ?? '') !== '') {
            $components['lab_score'] = (float)$_POST['lab_score'];
        }

        // Socio-demographic
        $internetAccess    = (int)($_POST['internet_access']    ?? 1);
        $digitalLiteracy   = (int)($_POST['digital_literacy']   ?? 3);
        $householdIncome   = (float)($_POST['household_income'] ?? ($stu['household_income'] ?? 0));
        $parentalEducation = (int)($_POST['parental_education'] ?? ($stu['parental_education'] ?? 3));
        $studyHours        = (float)($_POST['study_hours']      ?? 4);
        $workingStudent    = (int)($_POST['working_student']    ?? ($stu['working_student'] ?? 0));
    }

    if (!$errors) {
        // Compute weighted grade
        $gradeResult = compute_weighted_grade($components, $weights, 1);

        if ($gradeResult === null) {
            $errors[] = 'Please enter at least one score to save.';
        } else {
            $computedGrade = $gradeResult['grade'];
            $missingList   = $gradeResult['missing'];

            // --- Save to tbl_grade_components ---
            $conn = db();
            $missingJson = json_encode($missingList);
            $scoresJson  = json_encode($recordedScores);

            // Map component names to DB columns
            $examScore       = $components['exam_score']       ?? null;
            $quizScore       = $components['quiz_score']       ?? null;
            $activityScore   = $components['activity_score']   ?? null;
            $assignmentScore = $components['assignment_score'] ?? null;
            $projectScore    = $components['project_score']    ?? null;
            $attendanceRate  = $components['attendance_rate']  ?? null;
            $labScore        = $components['lab_score']        ?? null;

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
            $stmt->bind_param('isssddddddddss',
                $studentId, $academicYear, $semester, $gradingPeriod,
                $examScore, $quizScore, $activityScore, $assignmentScore,
                $projectScore, $attendanceRate, $labScore, $computedGrade, $missingJson, $scoresJson
            );
            $stmt->execute();

            if (!$runPredict) {
                $success = ['saved_only' => true, 'computed_grade' => $computedGrade, 'missing' => $missingList];
            }
        }
    }

    /* ---- Run prediction ---- */
    if (!$errors && $runPredict && isset($computedGrade)) {
        // Previous period actual grades
        $prevGrades = [];
        foreach ($PERIOD_PREV[$gradingPeriod] as $pp) {
            $ppKey = strtolower(str_replace('-', '_', $pp)) . '_actual_grade';
            if (($_POST[$ppKey] ?? '') !== '') {
                $prevGrades[$pp] = (float)$_POST[$ppKey];
            } else {
                // Try to pull from DB
                $pgRows = get_student_period_grades($studentId, $academicYear, $semester);
                if (isset($pgRows[$pp])) {
                    $prevGrades[$pp] = $pgRows[$pp];
                } else {
                    $errors[] = "Please enter the actual {$pp} grade before running a Final prediction.";
                }
            }
        }

        if (!$errors) {
            $apiPayload = [
                'grading_period'   => $gradingPeriod,
                'computed_grade'   => $computedGrade,
                'attendance_rate'  => $components['attendance_rate'] ?? 85.0,
                'lab_score'        => $components['lab_score']       ?? 80.0,
                'internet_access'  => $internetAccess,
                'digital_literacy' => $digitalLiteracy,
                'household_income' => $householdIncome,
                'parental_education' => $parentalEducation,
                'study_hours'      => $studyHours,
                'working_student'  => $workingStudent,
            ];
            $periodGradeMap = ['Prelim'=>'prelim_grade','Midterm'=>'midterm_grade','Semi-Final'=>'semi_final_grade'];
            foreach ($PERIOD_PREV[$gradingPeriod] as $pp) {
                if (isset($prevGrades[$pp])) {
                    $apiPayload[$periodGradeMap[$pp]] = $prevGrades[$pp];
                }
            }

            $api = api_request_local('POST', '/predict-progressive', $apiPayload);
            if (!$api['ok']) {
                $api = api_request('POST', '/predict-progressive', $apiPayload);
            }

            if (!$api['ok']) {
                $errors[] = $api['error'] ?? 'Prediction service unavailable.';
            } else {
                $conn = db();
                $conn->begin_transaction();
                try {
                    // Upsert student
                    $stmt = $conn->prepare(
                        'INSERT INTO tbl_students
                            (student_no, full_name, year_level, section, gender,
                             household_income, parental_education, scholarship_status, working_student)
                         VALUES (?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            household_income=VALUES(household_income),
                            parental_education=VALUES(parental_education),
                            working_student=VALUES(working_student)'
                    );
                    $stmt->bind_param('sssssdisi',
                        $stu['student_no'], $stu['full_name'], $stu['year_level'], $stu['section'], $stu['gender'],
                        $householdIncome, $parentalEducation, $stu['scholarship_status'], $workingStudent
                    );
                    $stmt->execute();

                    // Survey
                    $dlInt    = $digitalLiteracy;
                    $devAvail = 'Unknown';
                    $stmt = $conn->prepare(
                        'INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, device_availability, study_hours)
                         VALUES (?,?,?,?,?)'
                    );
                    $stmt->bind_param('iiisd', $studentId, $internetAccess, $dlInt, $devAvail, $studyHours);
                    $stmt->execute();

                    // Academic record
                    $prelimGrade  = ($gradingPeriod === 'Prelim'     ? $computedGrade : ($prevGrades['Prelim']     ?? 0));
                    $midtermGrade = ($gradingPeriod === 'Midterm'    ? $computedGrade : ($prevGrades['Midterm']    ?? 0));
                    $semiGrade    = ($gradingPeriod === 'Semi-Final' ? $computedGrade : ($prevGrades['Semi-Final'] ?? 0));
                    $finalGrade   = ($gradingPeriod === 'Final'      ? $computedGrade : 0);
                    $attRate      = $components['attendance_rate'] ?? 0;
                    $lScore       = $components['lab_score']       ?? 0;

                    $stmt = $conn->prepare(
                        'INSERT INTO tbl_academic_records
                            (student_id, academic_year, semester,
                             prelim_grade, midterm_grade, semi_final_grade, final_grade,
                             attendance_rate, lab_score)
                         VALUES (?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->bind_param('issdddddd',
                        $studentId, $academicYear, $semester,
                        $prelimGrade, $midtermGrade, $semiGrade, $finalGrade, $attRate, $lScore
                    );
                    $stmt->execute();
                    $academicRecordId = (int)$conn->insert_id;

                    // Prediction
                    $prediction     = (string)$api['data']['prediction'];
                    $predictedGrade = (float)($api['data']['predicted_grade'] ?? $computedGrade);
                    $confidence     = (float)$api['data']['confidence'];
                    $recomm         = (string)$api['data']['recommendation'];
                    $riskFactors    = json_encode($api['data']['risk_factors'] ?? []);
                    $missingComp    = json_encode($missingList);
                    $featurePayload = json_encode($apiPayload);
                    $metadata       = model_metadata();
                    $modelAccuracy  = (float)($api['data']['model_accuracy'] ?? $metadata['accuracy'] ?? 0);
                    $f1Score        = (float)($api['data']['model_f1']       ?? $metadata['weighted_f1'] ?? 0);
                    $algorithm      = 'XGBoost Progressive (' . $gradingPeriod . ')';
                    $createdBy      = (int)$user['id'];

                    $stmt = $conn->prepare(
                        'INSERT INTO tbl_predictions
                            (student_id, academic_record_id, grading_period, predicted_status,
                             predicted_grade, confidence, recommendation, risk_factors,
                             missing_components, feature_payload, model_accuracy,
                             f1_score_log, algorithm, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->bind_param('iissddssssddsi',
                        $studentId, $academicRecordId, $gradingPeriod, $prediction,
                        $predictedGrade, $confidence, $recomm, $riskFactors,
                        $missingComp, $featurePayload, $modelAccuracy,
                        $f1Score, $algorithm, $createdBy
                    );
                    $stmt->execute();

                    // Alert for at-risk
                    if ($prediction === 'At-Risk' || $prediction === 'Fail') {
                        $severity = ($prediction === 'Fail') ? 'High' : 'Medium';
                        $msg = "Student {$stu['full_name']} ({$stu['student_no']}) — {$gradingPeriod} — flagged as '{$prediction}' with " . round($confidence * 100, 1) . "% confidence.";
                        create_alert($studentId, $createdBy, 'Risk', $severity, $msg);
                    }

                    $conn->commit();
                    $success = $api['data'];
                    $success['computed_grade']  = $computedGrade;
                    $success['missing']         = $missingList;
                    $success['grading_period']  = $gradingPeriod;
                    $success['student_name']    = $stu['full_name'];
                    $success['student_no']      = $stu['student_no'];
                    $success['algorithm']       = $algorithm;
                    $success['accuracy']        = $modelAccuracy;

                } catch (Throwable $ex) {
                    $conn->rollback();
                    $errors[] = 'Prediction generated but could not be saved: ' . $ex->getMessage();
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Restore selected values for form re-render                         */
/* ------------------------------------------------------------------ */
$selStudentId = (int)($_POST['student_id']    ?? $_GET['student_id']    ?? 0);
$selPeriod    = trim($_POST['grading_period'] ?? $_GET['grading_period'] ?? '');
$selAY        = trim($_POST['academic_year']  ?? '2025-2026');
$selSem       = trim($_POST['semester']       ?? '1st Semester');

// Load existing scores from DB if student+period selected
$dbScores = [];
if ($selStudentId && $selPeriod && $selAY && $selSem) {
    $stmt = db()->prepare(
        'SELECT exam_score, quiz_score, activity_score, assignment_score, project_score,
                attendance_rate, lab_score, scores_json
         FROM tbl_grade_components
         WHERE student_id=? AND academic_year=? AND semester=? AND period=?'
    );
    $stmt->bind_param('isss', $selStudentId, $selAY, $selSem, $selPeriod);
    $stmt->execute();
    $dbScores = $stmt->get_result()->fetch_assoc() ?? [];
    if (!empty($dbScores['scores_json'])) {
        $decoded = json_decode($dbScores['scores_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (is_array($v) && isset($v['raw'])) {
                    $dbScores[$k] = $v['raw'];
                } else {
                    $dbScores[$k] = $v;
                }
            }
        }
    }
}

$dbPeriodGrades = $selStudentId ? get_student_period_grades($selStudentId, $selAY, $selSem) : [];

page_header('Enter Scores');
?>

<style>
.score-table { width:100%; border-collapse:collapse; }
.score-table th { background:var(--surface-strong); padding:10px 14px; text-align:left; font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); border-bottom:2px solid var(--line); }
.score-table td { padding:10px 14px; border-bottom:1px solid var(--line); vertical-align:middle; }
.score-table tr:last-child td { border-bottom:none; }
.score-table .input-narrow { width:100px; text-align:center; }
.score-table .contrib-cell { font-size:.82rem; color:var(--muted); }

.computed-box {
    background: linear-gradient(135deg,#1d4ed8 0%,#3b82f6 100%);
    color:#fff;
    border-radius:10px;
    padding:18px 22px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin:16px 0;
}
.computed-box .cg-label { font-size:.85rem; opacity:.8; }
.computed-box .cg-value { font-size:2.2rem; font-weight:800; }

.result-banner {
    border-radius:10px;
    padding:20px 24px;
    border:2px solid;
    margin-bottom:20px;
}
.prev-grade-box { background:var(--surface-strong); border:1px solid var(--line); border-radius:8px; padding:14px 16px; display:flex; align-items:center; gap:16px; margin-bottom:16px; }
.prev-grade-box .pgb-val { font-size:1.6rem; font-weight:800; color:var(--blue); min-width:60px; text-align:center; }
.prev-grade-box .pgb-label { font-size:.78rem; color:var(--muted); font-weight:700; text-transform:uppercase; }
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow">Advisor Workflow</p>
        <h1>Enter Student Scores</h1>
    </div>
</section>

<?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
        <?php foreach ($errors as $e): ?><div><strong>Notice:</strong> <?= h($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($success && isset($success['saved_only'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <strong>Saved:</strong> Scores recorded successfully. Computed grade: <strong><?= round($success['computed_grade'], 2) ?>%</strong>
        <?php if ($success['missing']): ?>
            <span style="color:var(--risk-text);font-weight:600;"> — Missing: <?= h(implode(', ', $success['missing'])) ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($success && isset($success['prediction'])): ?>
    <?php
    $confPct   = round((float)$success['confidence'] * 100, 1);
    $gradePct  = round((float)$success['computed_grade'], 1);
    $statusCls = status_class($success['prediction']);
    ?>
    <section class="result-band <?= h($statusCls) ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
            <div>
                <small style="display:block;font-size:.78rem;color:inherit;opacity:.7;margin-bottom:4px;">
                    <?= h($success['grading_period']) ?> Prediction — <?= h($success['student_name']) ?> (<?= h($success['student_no']) ?>)
                </small>
                <div style="font-size:2.5rem;font-weight:800;"><?= $gradePct ?>%</div>
                <span class="status <?= h($statusCls) ?>" style="margin-top:6px;"><?= h($success['prediction']) ?></span>
            </div>
            <div style="min-width:160px;">
                <small>Confidence Level</small>
                <strong style="font-size:1.4rem;display:block;"><?= $confPct ?>%</strong>
                <div style="width:100%;height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:6px;">
                    <div style="height:100%;background:currentColor;width:<?= $confPct ?>%;opacity:.8;"></div>
                </div>
            </div>
            <p style="flex:1;margin:0;font-size:.9rem;"><?= h($success['recommendation'] ?? '') ?></p>
        </div>
        <?php if (!empty($success['risk_factors'])): ?>
        <div style="margin-top:8px;">
            <?php foreach ($success['risk_factors'] as $f): ?>
                <span class="chip" style="background:rgba(0,0,0,.06);margin:2px;">Risk Factor: <?= h($f) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<!-- ================================================================ -->
<!-- STEP 1 — Select Student & Context                                -->
<!-- ================================================================ -->
<form method="post" id="scores-form" autocomplete="off">

<div class="panel form-panel" style="margin-bottom:16px;">
    <div class="panel-title"><h2>Step 1 — Student &amp; Grading Period</h2></div>
    <div class="form-grid">
        <label>
            <span>Student</span>
            <select name="student_id" id="student_id" required onchange="onStudentChange()">
                <option value="">— Select Student —</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>"
                        <?= $selStudentId === $s['id'] ? 'selected' : '' ?>
                        data-info="<?= h($s['year_level'] . ' / ' . $s['section']) ?>">
                        <?= h($s['student_no']) ?> — <?= h($s['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Academic Year</span>
            <select name="academic_year" id="academic_year" onchange="loadContext()">
                <?php foreach (['2025-2026','2026-2027','2027-2028'] as $opt): ?>
                    <option <?= $selAY === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Semester</span>
            <select name="semester" id="semester" onchange="loadContext()">
                <?php foreach (['1st Semester','2nd Semester','Summer'] as $opt): ?>
                    <option <?= $selSem === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Grading Period</span>
            <select name="grading_period" id="grading_period" required onchange="onPeriodChange()">
                <option value="">— Select Period —</option>
                <?php foreach ($PERIODS as $p): ?>
                    <option value="<?= h($p) ?>" <?= $selPeriod === $p ? 'selected' : '' ?>><?= h($p) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div id="student-preview" style="margin-top:10px;display:none;" class="prev-grade-box">
        <span id="student-preview-text" style="font-size:.9rem;font-weight:600;color:var(--text);"></span>
    </div>
</div>

<!-- ================================================================ -->
<!-- STEP 2 — Previous Period Grades (shown for Midterm/Semi/Final)  -->
<!-- ================================================================ -->
<div id="prev-grades-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
    <div class="panel-title"><h2>Previous Period Actual Grades</h2></div>
    <div id="prev-grades-body" class="form-grid"></div>
</div>

<!-- ================================================================ -->
<!-- STEP 3 — Score Entry                                             -->
<!-- ================================================================ -->
<div id="scores-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
    <div class="panel-title">
        <h2>Step 2 — Assessment Scores <span id="period-label" style="color:var(--blue);"></span></h2>
        <span id="weight-total-badge" class="pill"></span>
    </div>
    <p style="font-size:.85rem;color:var(--muted);margin:0 0 14px;">
        Enter raw scores. Leave blank if not yet available — missing scores are excluded from the weighted average.
    </p>

    <div class="table-wrap">
    <table class="score-table" id="score-table">
        <thead>
            <tr>
                <th>Component</th>
                <th style="text-align:center;">Max Score</th>
                <th style="text-align:center;">Weight</th>
                <th style="text-align:center;">Raw Score</th>
                <th style="text-align:center;">Contribution</th>
            </tr>
        </thead>
        <tbody id="score-rows"></tbody>
    </table>
    </div>

    <!-- Attendance & Lab -->
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

    <!-- Computed grade preview -->
    <div id="computed-box" class="computed-box" style="display:none;">
        <div>
            <div class="cg-label">Computed Weighted Grade</div>
            <div class="cg-value" id="cg-value">—</div>
            <div style="font-size:.78rem;opacity:.75;" id="cg-note"></div>
        </div>
        <div id="cg-missing" style="font-size:.82rem;opacity:.8;text-align:right;"></div>
    </div>
</div>

<!-- ================================================================ -->
<!-- STEP 4 — Socio-Demographic                                       -->
<!-- ================================================================ -->
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
                <?php foreach ([1=>'Elementary',2=>'High School',3=>'College',4=>'Postgraduate'] as $v=>$l): ?>
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
                <option value="1" <?= ($_POST['internet_access'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= ($_POST['internet_access'] ?? '') === '0' ? 'selected' : '' ?>>No</option>
            </select>
        </label>
        <label>
            <span>Working Student</span>
            <select id="working_student" name="working_student">
                <option value="0" <?= (($_POST['working_student'] ?? $studentMap[$selStudentId]['working_student'] ?? 0) == 0) ? 'selected' : '' ?>>No</option>
                <option value="1" <?= (($_POST['working_student'] ?? $studentMap[$selStudentId]['working_student'] ?? 0) == 1) ? 'selected' : '' ?>>Yes</option>
            </select>
        </label>
    </div>
</div>

<!-- ================================================================ -->
<!-- Action buttons                                                   -->
<!-- ================================================================ -->
<div id="action-panel" class="form-actions" style="display:none;gap:10px;flex-wrap:wrap;">
    <button type="submit" name="save_scores" class="button button-secondary" id="btn-save">
        Save Scores
    </button>
    <button type="submit" name="run_prediction" class="button button-primary" id="btn-predict">
        Generate Prediction
    </button>
</div>

</form>

<script>
/* ---------------------------------------------------------------- */
/* Data from PHP                                                     */
/* ---------------------------------------------------------------- */
const ALL_WEIGHTS = <?= json_encode($allWeights, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PERIOD_PREV = {
    'Prelim':     [],
    'Midterm':    ['Prelim'],
    'Semi-Final': ['Prelim','Midterm'],
    'Final':      ['Prelim','Midterm','Semi-Final']
};
const STUDENT_MAP = <?= json_encode($studentMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const DB_SCORES   = <?= json_encode($dbScores,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const DB_PERIOD_GRADES = <?= json_encode($dbPeriodGrades, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let currentPeriod = '<?= h($selPeriod) ?>';

const COMP_FIELD_MAP = {
    'Exam':       'exam',
    'Quiz':       'quiz',
    'Activities': 'activity',
    'Assignment': 'assignment',
    'Project':    'project',
    'Attendance': 'attendance',
    'Lab':        'lab',
};

/* ---------------------------------------------------------------- */
function onStudentChange() {
    const sid = document.getElementById('student_id').value;
    const preview = document.getElementById('student-preview');
    const previewText = document.getElementById('student-preview-text');
    if (sid && STUDENT_MAP[sid]) {
        const s = STUDENT_MAP[sid];
        previewText.textContent = `${s.full_name} | ${s.year_level} | ${s.section}`;
        preview.style.display = 'flex';
        // Pre-fill socio fields
        if (s.household_income) document.getElementById('household_income').value = s.household_income;
        if (s.parental_education) document.getElementById('parental_education').value = s.parental_education;
        if (s.working_student !== undefined) document.getElementById('working_student').value = s.working_student;
    } else {
        preview.style.display = 'none';
    }
}

function onPeriodChange() {
    currentPeriod = document.getElementById('grading_period').value;
    if (!currentPeriod) {
        hideAll(); return;
    }
    buildScoreRows();
    buildPrevGrades();
    showPanels();
    recalc();
}

function loadContext() { /* scores re-fetched on full form submit */ }

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
    document.getElementById('action-panel').style.display      = '';
    document.getElementById('period-label').textContent         = '— ' + currentPeriod;
}

/* ---------------------------------------------------------------- */
/* Build score input rows dynamically from criteria                  */
/* ---------------------------------------------------------------- */
function buildScoreRows() {
    const weights = ALL_WEIGHTS[currentPeriod] || {};
    const tbody   = document.getElementById('score-rows');
    tbody.innerHTML = '';

    Object.entries(weights).forEach(([comp, cfg]) => {
        const wt  = cfg.weight    || cfg;
        const ms  = cfg.max_score || 100;
        const fk  = COMP_FIELD_MAP[comp] || comp.toLowerCase().replace(/[^a-z0-9]/g, '_');
        let dbColKey = fk + '_score';
        if (fk === 'activity') dbColKey = 'activity_score';
        if (fk === 'attendance') dbColKey = 'attendance_rate';
        if (fk === 'lab') dbColKey = 'lab_score';

        const existing = DB_SCORES[dbColKey] ?? DB_SCORES[comp] ?? DB_SCORES[fk + '_score'] ?? '';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="font-weight:600;">${comp}</td>
            <td style="text-align:center;">${ms}</td>
            <td style="text-align:center;">${wt}%</td>
            <td style="text-align:center;">
                <input type="number" step="0.01" min="0" max="${ms}"
                       class="input-narrow"
                       name="score_${fk}"
                       id="score_${fk}"
                       value="${existing}"
                       placeholder="/ ${ms}"
                       oninput="recalc()">
            </td>
            <td class="contrib-cell" id="contrib_${fk}">—</td>
        `;
        tbody.appendChild(tr);
    });

    recalc();
}

/* ---------------------------------------------------------------- */
/* Build previous period grade inputs                                */
/* ---------------------------------------------------------------- */
function buildPrevGrades() {
    const prev = PERIOD_PREV[currentPeriod] || [];
    const body = document.getElementById('prev-grades-body');
    body.innerHTML = '';

    prev.forEach(period => {
        const key  = period.toLowerCase().replace('-', '_') + '_actual_grade';
        const known = DB_PERIOD_GRADES[period];
        const label = document.createElement('label');
        label.innerHTML = `
            <span>${period} Actual Grade</span>
            <input type="number" step="0.01" min="0" max="100"
                   name="${key}" id="${key}"
                   value="${known !== undefined ? parseFloat(known).toFixed(2) : ''}"
                   placeholder="Enter actual grade" required>
        `;
        body.appendChild(label);
    });
}

/* ---------------------------------------------------------------- */
/* Live weighted grade computation                                   */
/* ---------------------------------------------------------------- */
function recalc() {
    const weights = ALL_WEIGHTS[currentPeriod] || {};
    if (!Object.keys(weights).length) {
        document.getElementById('computed-box').style.display = 'none';
        return;
    }

    let totalWeight = 0, weightedScore = 0, present = 0;
    const missing = [];

    Object.entries(weights).forEach(([comp, cfg]) => {
        const wt = cfg.weight    || cfg;
        const ms = cfg.max_score || 100;
        const fk = COMP_FIELD_MAP[comp] || comp.toLowerCase().replace(/[^a-z0-9]/g, '_');
        const el = document.getElementById('score_' + fk);
        const val = el ? el.value.trim() : '';

        const contribEl = document.getElementById('contrib_' + fk);

        if (val === '' || val === null) {
            missing.push(comp);
            if (contribEl) contribEl.textContent = '—';
        } else {
            const raw  = parseFloat(val);
            if (!isNaN(raw) && raw >= 0 && raw <= ms) {
                const normPct = (raw / ms) * 100;
                const contrib = normPct * (wt / 100);
                totalWeight   += wt;
                weightedScore += contrib;
                present++;
                if (contribEl) contribEl.textContent = `(${raw}/${ms}) × ${wt}% = ${contrib.toFixed(2)}%`;
            } else {
                missing.push(comp);
                if (contribEl) contribEl.textContent = 'Invalid Score';
            }
        }
    });

    const box = document.getElementById('computed-box');
    if (present === 0) {
        box.style.display = 'none';
        return;
    }

    const grade = totalWeight > 0 ? (weightedScore / totalWeight) * 100 : 0;
    box.style.display = '';
    document.getElementById('cg-value').textContent = grade.toFixed(2) + '%';
    document.getElementById('cg-note').textContent  = `Based on ${present} of ${Object.keys(weights).length} components`;
    document.getElementById('cg-missing').textContent =
        missing.length ? 'Missing components: ' + missing.join(', ') : 'All components evaluated';
}

/* ---------------------------------------------------------------- */
/* Init on page load                                                 */
/* ---------------------------------------------------------------- */
if (currentPeriod) {
    buildScoreRows();
    buildPrevGrades();
    showPanels();
    recalc();
}
onStudentChange();

document.getElementById('scores-form').addEventListener('submit', function(e) {
    const btn = e.submitter;
    if (btn) { btn.disabled = true; btn.textContent = 'Processing...'; }
});
</script>

<?php page_footer(); ?>
