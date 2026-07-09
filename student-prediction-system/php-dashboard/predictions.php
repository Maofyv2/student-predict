<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$errors = [];
$result = null;

function old_value(string $key, string $default = ''): string
{
    return (string) ($_POST[$key] ?? $default);
}

function numeric_field(string $key, float $min, float $max, array &$errors): float
{
    $value = $_POST[$key] ?? '';
    if ($value === '' || !is_numeric($value)) {
        $errors[] = str_replace('_', ' ', ucfirst($key)) . ' must be numeric.';
        return 0;
    }

    $number = (float) $value;
    if ($number < $min || $number > $max) {
        $errors[] = str_replace('_', ' ', ucfirst($key)) . " must be between {$min} and {$max}.";
    }
    return $number;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNo = trim($_POST['student_no'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $yearLevel = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $academicYear = trim($_POST['academic_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $scholarshipStatus = trim($_POST['scholarship_status'] ?? 'None');
    $deviceAvailability = trim($_POST['device_availability'] ?? 'Shared device');

    foreach ([
        'Student number' => $studentNo,
        'Full name' => $fullName,
        'Year level' => $yearLevel,
        'Section' => $section,
        'Academic year' => $academicYear,
        'Semester' => $semester,
    ] as $label => $value) {
        if ($value === '') {
            $errors[] = "{$label} is required.";
        }
    }

    $payload = [
        'prelim_grade' => numeric_field('prelim_grade', 0, 100, $errors),
        'midterm_grade' => numeric_field('midterm_grade', 0, 100, $errors),
        'semi_final_grade' => numeric_field('semi_final_grade', 0, 100, $errors),
        'final_grade' => numeric_field('final_grade', 0, 100, $errors),
        'attendance_rate' => numeric_field('attendance_rate', 0, 100, $errors),
        'lab_score' => numeric_field('lab_score', 0, 100, $errors),
        'internet_access' => (int) old_value('internet_access', '1'),
        'digital_literacy' => (int) numeric_field('digital_literacy', 1, 5, $errors),
        'household_income' => numeric_field('household_income', 0, 999999, $errors),
        'parental_education' => (int) numeric_field('parental_education', 1, 4, $errors),
        'study_hours' => numeric_field('study_hours', 0, 80, $errors),
        'working_student' => (int) old_value('working_student', '0'),
    ];

    if (!$errors) {
        $api = api_request('POST', '/predict', $payload);
        if (!$api['ok']) {
            $errors[] = $api['error'] ?? 'Prediction service is unavailable.';
        } else {
            $conn = db();
            $metadata = model_metadata();
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_students
                        (student_no, full_name, year_level, section, gender, household_income, parental_education, scholarship_status, working_student)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        full_name = VALUES(full_name),
                        year_level = VALUES(year_level),
                        section = VALUES(section),
                        gender = VALUES(gender),
                        household_income = VALUES(household_income),
                        parental_education = VALUES(parental_education),
                        scholarship_status = VALUES(scholarship_status),
                        working_student = VALUES(working_student)"
                );
                $stmt->bind_param(
                    'sssssdisi',
                    $studentNo,
                    $fullName,
                    $yearLevel,
                    $section,
                    $gender,
                    $payload['household_income'],
                    $payload['parental_education'],
                    $scholarshipStatus,
                    $payload['working_student']
                );
                $stmt->execute();

                $stmt = $conn->prepare('SELECT id FROM tbl_students WHERE student_no = ? LIMIT 1');
                $stmt->bind_param('s', $studentNo);
                $stmt->execute();
                $studentId = (int) $stmt->get_result()->fetch_assoc()['id'];

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, device_availability, study_hours)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'iiisd',
                    $studentId,
                    $payload['internet_access'],
                    $payload['digital_literacy'],
                    $deviceAvailability,
                    $payload['study_hours']
                );
                $stmt->execute();

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_academic_records
                        (student_id, academic_year, semester, prelim_grade, midterm_grade, semi_final_grade, final_grade, attendance_rate, lab_score)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'issdddddd',
                    $studentId,
                    $academicYear,
                    $semester,
                    $payload['prelim_grade'],
                    $payload['midterm_grade'],
                    $payload['semi_final_grade'],
                    $payload['final_grade'],
                    $payload['attendance_rate'],
                    $payload['lab_score']
                );
                $stmt->execute();
                $academicRecordId = (int) $conn->insert_id;

                $prediction = (string) $api['data']['prediction'];
                $confidence = (float) $api['data']['confidence'];
                $recommendation = (string) $api['data']['recommendation'];
                $riskFactors = json_encode($api['data']['risk_factors'] ?? []);
                $featurePayload = json_encode($payload);
                $modelAccuracy = (float) ($metadata['accuracy'] ?? 0);
                $f1Score = (float) ($metadata['weighted_f1'] ?? 0);
                $algorithm = (string) ($metadata['algorithm'] ?? 'XGBoost Classification');
                $createdBy = (int) current_user()['id'];

                $stmt = $conn->prepare(
                    'INSERT INTO tbl_predictions
                        (student_id, academic_record_id, predicted_status, confidence, recommendation, risk_factors, feature_payload, model_accuracy, f1_score_log, algorithm, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'iisdsssddsi',
                    $studentId,
                    $academicRecordId,
                    $prediction,
                    $confidence,
                    $recommendation,
                    $riskFactors,
                    $featurePayload,
                    $modelAccuracy,
                    $f1Score,
                    $algorithm,
                    $createdBy
                );
                $stmt->execute();

                if ($prediction === 'At-Risk' || $prediction === 'Fail') {
                    $stmt = $conn->prepare("SELECT advisor_id FROM tbl_students WHERE id = ?");
                    $stmt->bind_param('i', $studentId);
                    $stmt->execute();
                    $adv = $stmt->get_result()->fetch_assoc();
                    $advisorToNotify = $adv['advisor_id'] ?? $createdBy;

                    $severity = ($prediction === 'Fail') ? 'High' : 'Medium';
                    $msg = "Student {$fullName} ({$studentNo}) has been flagged as '{$prediction}' with " . round($confidence * 100, 1) . "% confidence.";

                    create_alert($studentId, $advisorToNotify, 'Risk', $severity, $msg);
                }

                $conn->commit();
                $result = $api['data'];
            } catch (Throwable $exception) {
                $conn->rollback();
                $errors[] = 'Prediction was generated but could not be saved: ' . $exception->getMessage();
            }
        }
    }
}

page_header('Prediction');
?>
<section class="page-heading">
    <div>
        <h1>Student Prediction</h1>
    </div>
</section>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($result): ?>
    <section class="result-band <?= h(status_class($result['prediction'])) ?>">
        <div>
            <span>Predicted Status</span>
            <strong><?= h($result['prediction']) ?></strong>
        </div>
        <div>
            <span>Confidence</span>
            <strong><?= h((string) round((float) $result['confidence'] * 100, 1)) ?>%</strong>
        </div>
        <p><?= h($result['recommendation']) ?></p>
    </section>
<?php endif; ?>

<form method="post" class="panel form-panel">
    <div class="form-section">
        <h2>Student Profile</h2>
        <div class="form-grid">
            <label>
                <span>Student No.</span>
                <input id="student_no" name="student_no" value="<?= h(old_value('student_no')) ?>" required
                    autocomplete="off">
            </label>
            <label>
                <span>Full Name</span>
                <input id="full_name" name="full_name" value="<?= h(old_value('full_name')) ?>" required>
            </label>
            <label>
                <span>Year Level</span>
                <select id="year_level" name="year_level" required>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $option): ?>
                        <option <?= old_value('year_level', '3rd Year') === $option ? 'selected' : '' ?>><?= h($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Section</span>
                <input id="section" name="section" value="<?= h(old_value('section', 'BSIT-1')) ?>" required>
            </label>
            <label>
                <span>Gender</span>
                <select id="gender" name="gender">
                    <?php foreach (['', 'Female', 'Male', 'Prefer not to say'] as $option): ?>
                        <option value="<?= h($option) ?>" <?= old_value('gender') === $option ? 'selected' : '' ?>>
                            <?= h($option ?: 'Not specified') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Scholarship</span>
                <input id="scholarship_status" name="scholarship_status"
                    value="<?= h(old_value('scholarship_status', 'None')) ?>">
            </label>
        </div>
    </div>

    <div class="form-section">
        <h2>Academic Data</h2>
        <div class="form-grid">
            <label>
                <span>Academic Year</span>
                <input name="academic_year" value="<?= h(old_value('academic_year', '2025-2026')) ?>" required>
            </label>
            <label>
                <span>Semester</span>
                <select name="semester" required>
                    <?php foreach (['1st Semester', '2nd Semester', 'Summer'] as $option): ?>
                        <option <?= old_value('semester', '2nd Semester') === $option ? 'selected' : '' ?>><?= h($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Prelim Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="prelim_grade"
                    value="<?= h(old_value('prelim_grade')) ?>" required>
            </label>
            <label>
                <span>Midterm Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="midterm_grade"
                    value="<?= h(old_value('midterm_grade')) ?>" required>
            </label>
            <label>
                <span>Semi-Final Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="semi_final_grade"
                    value="<?= h(old_value('semi_final_grade', '0')) ?>" required>
            </label>
            <label>
                <span>Final Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="final_grade"
                    value="<?= h(old_value('final_grade', '0')) ?>" required>
            </label>
            <label>
                <span>Attendance Rate</span>
                <input type="number" step="0.01" min="0" max="100" name="attendance_rate"
                    value="<?= h(old_value('attendance_rate')) ?>" required>
            </label>
            <label>
                <span>Lab Score</span>
                <input type="number" step="0.01" min="0" max="100" name="lab_score"
                    value="<?= h(old_value('lab_score')) ?>" required>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="button button-primary" type="submit">Generate Prediction</button>
    </div>
</form>

<?php if ($result && !empty($result['risk_factors'])): ?>
    <section class="panel">
        <div class="panel-title">
            <h2>Risk Factors</h2>
        </div>
        <div class="chip-list">
            <?php foreach ($result['risk_factors'] as $factor): ?>
                <span class="chip"><?= h($factor) ?></span>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<script>
    document.getElementById('student_no').addEventListener('blur', function () {
        const studentNo = this.value.trim();
        if (studentNo === '') return;

        fetch(`get_student.php?student_no=${encodeURIComponent(studentNo)}`)
            .then(response => response.json())
            .then(res => {
                if (res.success) {
                    const s = res.data;
                    document.getElementById('full_name').value = s.full_name || '';
                    document.getElementById('year_level').value = s.year_level || '3rd Year';
                    document.getElementById('section').value = s.section || '';
                    document.getElementById('gender').value = s.gender || '';
                    document.getElementById('scholarship_status').value = s.scholarship_status || 'None';
                    document.getElementById('household_income').value = s.household_income || '';
                    document.getElementById('parental_education').value = s.parental_education || '3';
                    document.getElementById('working_student').value = s.working_student !== undefined ? s.working_student : '0';
                }
            })
            .catch(err => console.error('Error fetching student data:', err));
    });
</script>

<?php page_footer(); ?>