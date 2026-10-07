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

$allWeights = get_all_grading_weights();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $studentId     = (int)($_POST['student_id'] ?? 0);
    $gradingPeriod = trim($_POST['grading_period'] ?? '');
    $academicYear  = trim($_POST['academic_year'] ?? '');
    $semester      = trim($_POST['semester'] ?? '');

    $formAction = trim($_POST['form_action'] ?? '');
    $saveScores = true; // enter_scores only saves scores, no prediction

    $computedGrade = null;
    $missingList   = [];
    $components    = [];
    $recordedScores = [];

    if (!$studentId || !$gradingPeriod || !$academicYear || !$semester) {
        $errors[] = 'Student, grading period, academic year, and semester are required.';
    }

    if ($gradingPeriod && !in_array($gradingPeriod, $PERIODS, true)) {
        $errors[] = 'Invalid grading period selected.';
    }

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

    if (!$errors) {
        $weights = $allWeights[$gradingPeriod] ?? [];

        foreach ($weights as $compName => $cfg) {
            $cleanComp = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($compName)));
            $fieldKey  = $cleanComp;
            if ($compName === 'Activities') $fieldKey = 'activity';
            $postKey   = 'score_' . $fieldKey;

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

        if (isset($_POST['attendance_rate']) && $_POST['attendance_rate'] !== '') {
            $components['attendance_rate'] = (float)$_POST['attendance_rate'];
        }
        if (isset($_POST['lab_score']) && $_POST['lab_score'] !== '') {
            $components['lab_score'] = (float)$_POST['lab_score'];
        }

        // No socio-demographic data needed for enter_scores (scores only)
    }

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

            // Always save-only: compute GWA and return success
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

    // Prediction has been moved to predictions.php (Dashboard → Forecast)
}

$selStudentId = (int)($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
$selPeriod    = trim($_POST['grading_period'] ?? $_GET['grading_period'] ?? '');
$selAY        = trim($_POST['academic_year'] ?? '2025-2026');
$selSem       = trim($_POST['semester'] ?? '1st Semester');

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

$dbPeriodGrades = $selStudentId ? get_student_period_grades($selStudentId, $selAY, $selSem) : [];

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
    border-radius: 16px;
    padding: 26px;
    margin-bottom: 24px;
    background: var(--surface, #ffffff);
    border: 1px solid var(--line, #e2e8f0);
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06), 0 4px 6px -2px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
    transition: all 0.25s ease;
}
.prediction-result::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #2563eb, #1d4ed8);
}
.prediction-result.status-pass::before {
    background: linear-gradient(90deg, #10b981, #059669);
}
.prediction-result.status-risk::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}
.prediction-result.status-fail::before {
    background: linear-gradient(90deg, #ef4444, #dc2626);
}

.forecast-header-wrap {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--line, #e2e8f0);
}
.forecast-eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--muted, #64748b);
    margin-bottom: 6px;
    flex-wrap: wrap;
}
.forecast-period-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--primary-subtle, #eff6ff);
    color: var(--primary, #1e3a8a);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    border: 1px solid var(--primary-border, #bfdbfe);
}
.forecast-title {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text, #0f172a);
    display: flex;
    align-items: center;
    gap: 10px;
}
.forecast-ai-badge {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 3px 8px;
    border-radius: 6px;
    background: #e0e7ff;
    color: #4338ca;
}

.prediction-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.prediction-stat {
    background: var(--surface-subtle, #f8fafc);
    border: 1px solid var(--line, #e2e8f0);
    border-radius: 12px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.prediction-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    border-color: var(--line-strong, #cbd5e1);
}
.prediction-stat.hero-stat {
    background: linear-gradient(135deg, #f0fdf4 0%, #eff6ff 100%);
    border-color: #93c5fd;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
}
.prediction-stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.prediction-stat small {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted, #64748b);
    font-weight: 700;
}
.prediction-stat strong {
    display: block;
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--text, #0f172a);
    line-height: 1.15;
}
.prediction-stat .stat-subtitle {
    font-size: 0.76rem;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--muted, #64748b);
}
.stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}
.stat-pill-gain {
    background: #dcfce7;
    color: #15803d;
}
.stat-pill-loss {
    background: #fee2e2;
    color: #b91c1c;
}
.stat-pill-neutral {
    background: #f1f5f9;
    color: #475569;
}

.accuracy-bar-track {
    width: 100%;
    height: 6px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
    margin-top: 8px;
}
.accuracy-bar-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #3b82f6, #10b981);
    transition: width 0.6s ease;
}

.gwa-card {
    background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #2563eb 100%);
    color: #ffffff;
    border-radius: 14px;
    padding: 22px 24px;
    margin-top: 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    box-shadow: 0 10px 20px -5px rgba(30, 58, 138, 0.28);
    position: relative;
    overflow: hidden;
}
.gwa-card::after {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    pointer-events: none;
}
.gwa-info-col {
    flex: 0 0 auto;
    min-width: 240px;
}
.gwa-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
    color: #93c5fd;
    display: flex;
    align-items: center;
    gap: 6px;
}
.gwa-value {
    font-size: 2.75rem;
    font-weight: 800;
    line-height: 1.05;
    margin: 6px 0 4px;
    letter-spacing: -0.02em;
    color: #ffffff;
}
.gwa-desc {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.82);
}
.gwa-standing-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: #ffffff;
}

.gwa-roadmap-col {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    z-index: 1;
}
.roadmap-title {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
    color: #bfdbfe;
    align-self: flex-start;
}
.period-milestones-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    width: 100%;
}
.milestone-card {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    padding: 10px 12px;
    backdrop-filter: blur(6px);
    display: flex;
    flex-direction: column;
    gap: 2px;
    transition: transform 0.2s ease, background 0.2s ease;
}
.milestone-card:hover {
    background: rgba(255, 255, 255, 0.18);
    transform: translateY(-2px);
}
.milestone-card.milestone-actual {
    background: rgba(16, 185, 129, 0.22);
    border-color: rgba(16, 185, 129, 0.45);
}
.milestone-card.milestone-forecast {
    background: rgba(245, 158, 11, 0.25);
    border-color: rgba(245, 158, 11, 0.5);
    box-shadow: 0 0 12px rgba(245, 158, 11, 0.2);
}
.milestone-card.milestone-upcoming {
    opacity: 0.55;
    background: rgba(255, 255, 255, 0.06);
    border-style: dashed;
}
.milestone-name {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #e0e7ff;
}
.milestone-score {
    font-size: 1.05rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.2;
}
.milestone-tag {
    font-size: 0.65rem;
    font-weight: 600;
    opacity: 0.85;
}

.recomm-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #3b82f6;
    border-radius: 10px;
    padding: 16px 18px;
    margin-top: 18px;
}
.recomm-icon {
    flex: 0 0 36px;
    height: 36px;
    border-radius: 999px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 700;
    border: 1px solid #bfdbfe;
}
.recomm-body {
    flex: 1;
}
.recomm-title {
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #1e3a8a;
    margin-bottom: 4px;
}
.recomm-text {
    font-size: 0.92rem;
    color: var(--text, #0f172a);
    line-height: 1.5;
}

.risk-factors-wrap {
    margin-top: 16px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.risk-factors-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--muted, #64748b);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.risk-factor-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fffbeb;
    color: #92400e;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.82rem;
    font-weight: 600;
    transition: transform 0.15s ease, background 0.15s ease;
}
.risk-factor-badge:hover {
    background: #fef3c7;
    transform: translateY(-1px);
}
.no-risk-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.82rem;
    font-weight: 600;
}

@media (max-width: 900px) {
    .gwa-card {
        flex-direction: column;
        align-items: stretch;
    }
    .gwa-roadmap-col {
        align-items: stretch;
    }
    .period-milestones-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 520px) {
    .prediction-grid {
        grid-template-columns: 1fr;
    }
    .period-milestones-grid {
        grid-template-columns: 1fr;
    }
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

<?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
        <?php foreach ($errors as $e): ?>
            <div><strong>Notice:</strong> <?= h($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($success && isset($success['saved_only'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <strong>✓ Scores Saved:</strong> Scores recorded successfully. Computed grade: <strong><?= round((float)$success['computed_grade'], 2) ?>%</strong>
            <?php if (!empty($success['missing'])): ?>
                <span style="color:var(--risk-text);font-weight:600;"> — Missing: <?= h(implode(', ', $success['missing'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if (!empty($stu['student_no'])): ?>
        <a href="predictions.php?student_no=<?= urlencode($stu['student_no']) ?>&academic_year=<?= urlencode($academicYear ?? '2025-2026') ?>&semester=<?= urlencode($semester ?? '1st Semester') ?>" class="button button-sm button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
            <span>🔮</span> Generate Next-Period Forecast &rarr;
        </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" id="scores-form" autocomplete="off">
    <input type="hidden" name="form_action" id="form_action" value="">

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

    <div id="prev-grades-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
        <div class="panel-title"><h2>Previous Period Actual Grades</h2></div>
        <div id="prev-grades-body" class="form-grid"></div>
    </div>

    <div id="scores-panel" class="panel form-panel" style="display:none;margin-bottom:16px;">
        <div class="panel-title">
            <h2>Step 2 — Assessment Scores <span id="period-label" style="color:var(--blue);"></span></h2>
        </div>
        <p style="font-size:.85rem;color:var(--muted);margin:0 0 14px;">
            Enter the raw scores below. Click <strong>Save Scores</strong> to compute and record the actual grade.
            To generate a forecast for the next grading period, go to <strong>Dashboard → Predictions</strong>.
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

    <div id="action-panel" class="form-actions" style="display:none;gap:12px;align-items:center;flex-wrap:wrap;">
        <button type="submit" name="save_scores" value="1" class="button button-primary" id="btn-save"
                onclick="document.getElementById('form_action').value='save_scores'">
            Save Scores
        </button>
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
    document.getElementById('prev-grades-panel').style.display = 'none';
    document.getElementById('action-panel').style.display      = 'none';
}

function showPanels() {
    const prev = PERIOD_PREV[currentPeriod] || [];
    document.getElementById('prev-grades-panel').style.display = prev.length ? '' : 'none';
    document.getElementById('scores-panel').style.display      = '';
    document.getElementById('action-panel').style.display      = 'flex';
    document.getElementById('period-label').textContent        = '— ' + currentPeriod;
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

        const existing = DB_SCORES[dbColKey] ?? DB_SCORES[comp] ?? DB_SCORES[fk + '_score'] ?? 0;
        const initialScore = Number(existing);
        const safeExisting = Number.isFinite(initialScore)
            ? Math.min(ms, Math.max(0, initialScore))
            : 0;

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
                       value="${safeExisting}"
                       inputmode="decimal"
                       oninput="enforceScoreLimit(this)">
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function enforceScoreLimit(input) {
    const max = Number(input.max);
    const value = Number(input.value);
    if (input.value !== '' && Number.isFinite(value) && value > max) {
        input.value = String(max);
    } else if (input.value !== '' && Number.isFinite(value) && value < 0) {
        input.value = '0';
    }
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

if (currentPeriod) {
    buildScoreRows();
    buildPrevGrades();
    showPanels();
}
onStudentChange();

document.getElementById('scores-form').addEventListener('submit', function(e) {
    const submitBtn = e.submitter;
    if (submitBtn) {
        setTimeout(() => {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
        }, 10);
    }
});
</script>

<?php page_footer(); ?>
