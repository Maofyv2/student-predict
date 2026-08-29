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
        'household_income' => numeric_field('household_income', 0, 500000, $errors),
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
                    'INSERT INTO tbl_surveys 
                        (student_id, internet_access, digital_literacy, household_income, parental_education, working_student, study_hours)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'iiididd',
                    $studentId,
                    $payload['internet_access'],
                    $payload['digital_literacy'],
                    $payload['household_income'],
                    $payload['parental_education'],
                    $payload['working_student'],
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
                $result['algorithm'] = $algorithm;
                $result['accuracy'] = $modelAccuracy;
            } catch (Throwable $exception) {
                $conn->rollback();
                $errors[] = 'Prediction was generated but could not be saved: ' . $exception->getMessage();
            }
        }
    }
}

page_header('Prediction');
?>

<style>
    .progress-bar-container {
        width: 100%;
        background-color: #e0e0e0;
        border-radius: 8px;
        overflow: hidden;
        margin-top: 5px;
        height: 18px;
    }
    .progress-bar-fill {
        height: 100%;
        background-color: #2563eb;
        transition: width 0.4s ease;
    }
    .meta-details {
        margin-top: 15px;
        font-size: 0.85rem;
        color: #666;
        display: flex;
        gap: 15px;
    }
    .auto-dismiss {
        transition: opacity 0.5s ease-in-out;
    }
</style>

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
    <?php $confPercent = round((float) $result['confidence'] * 100, 1); ?>
    <section id="result-banner" class="result-band auto-dismiss <?= h(status_class($result['prediction'])) ?>">
        <div>
            <span>Predicted Status</span>
            <strong><?= h($result['prediction']) ?></strong>
        </div>
        <div style="min-width: 200px;">
            <span>Confidence</span>
            <strong><?= h((string) $confPercent) ?>%</strong>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?= $confPercent ?>%;"></div>
            </div>
        </div>
        <p><?= h($result['recommendation']) ?></p>
        <div class="meta-details">
            <span><strong>Date:</strong> <?= date('Y-m-d H:i') ?></span>
            <span><strong>Algorithm:</strong> <?= h($result['algorithm'] ?? 'XGBoost Classification') ?></span>
            <span><strong>Model Accuracy:</strong> <?= h((string) round(($result['accuracy'] ?? 0) * 100, 1)) ?>%</span>
        </div>
    </section>
<?php endif; ?>

<form method="post" class="panel form-panel" id="prediction-form">
    <div class="form-section">
        <h2>Student Profile</h2>
        <div class="form-grid">
            <label>
                <span>Student No.</span>
                <input id="student_no" name="student_no" value="<?= h(old_value('student_no')) ?>" required autocomplete="off">
            </label>
            <label>
                <span>Full Name</span>
                <input id="full_name" name="full_name" value="<?= h(old_value('full_name')) ?>" required>
            </label>
            <label>
                <span>Year Level</span>
                <select id="year_level" name="year_level" required>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $option): ?>
                        <option <?= old_value('year_level', '3rd Year') === $option ? 'selected' : '' ?>><?= h($option) ?></option>
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
                            <?= h($option ?: 'Select') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Scholarship</span>
                <select id="scholarship_status" name="scholarship_status">
                    <?php foreach (['None', 'CHED', 'TES', 'Academic', 'Athletic', 'Others'] as $option): ?>
                        <option value="<?= h($option) ?>" <?= old_value('scholarship_status', 'None') === $option ? 'selected' : '' ?>>
                            <?= h($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="form-section">
        <h2>Academic Data</h2>
        <div class="form-grid">
            <label>
                <span>Academic Year</span>
                <select name="academic_year" required>
                    <?php foreach (['2025-2026', '2026-2027', '2027-2028'] as $option): ?>
                        <option <?= old_value('academic_year', '2025-2026') === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Semester</span>
                <select name="semester" required>
                    <?php foreach (['1st Semester', '2nd Semester', 'Summer'] as $option): ?>
                        <option <?= old_value('semester', '2nd Semester') === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Prelim Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="prelim_grade" value="<?= h(old_value('prelim_grade')) ?>" required>
            </label>
            <label>
                <span>Midterm Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="midterm_grade" value="<?= h(old_value('midterm_grade')) ?>" required>
            </label>
            <label>
                <span>Semi-Final Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="semi_final_grade" value="<?= h(old_value('semi_final_grade', '0')) ?>" required>
            </label>
            <label>
                <span>Final Grade</span>
                <input type="number" step="0.01" min="0" max="100" name="final_grade" value="<?= h(old_value('final_grade', '0')) ?>" required>
            </label>
            <label>
                <span>Attendance Rate (%)</span>
                <input type="number" step="0.01" min="0" max="100" name="attendance_rate" value="<?= h(old_value('attendance_rate')) ?>" required>
            </label>
            <label>
                <span>Lab Score</span>
                <input type="number" step="0.01" min="0" max="100" name="lab_score" value="<?= h(old_value('lab_score')) ?>" required>
            </label>
        </div>
    </div>

    <div class="form-section">
        <h2>Household &amp; Digital Profile</h2>
        <div class="form-grid">
            <label>
                <span>Household Income (PHP)</span>
                <input type="number" step="0.01" min="0" max="500000" id="household_income" name="household_income" value="<?= h(old_value('household_income')) ?>" required>
            </label>
            <label>
                <span>Parental Education</span>
                <select id="parental_education" name="parental_education" required>
                    <?php foreach ([1 => 'Elementary', 2 => 'High School', 3 => 'College', 4 => 'Postgraduate'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= old_value('parental_education', '3') == $val ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Digital Literacy (1-5)</span>
                <input type="number" step="1" min="1" max="5" id="digital_literacy" name="digital_literacy" value="<?= h(old_value('digital_literacy', '3')) ?>" required>
            </label>
            <label>
                <span>Study Hours (per week)</span>
                <input type="number" step="0.1" min="0" max="80" id="study_hours" name="study_hours" value="<?= h(old_value('study_hours')) ?>" required>
            </label>
            <label>
                <span>Internet Access</span>
                <select id="internet_access" name="internet_access">
                    <option value="1" <?= old_value('internet_access', '1') === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= old_value('internet_access') === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </label>
            <label>
                <span>Working Student</span>
                <select id="working_student" name="working_student">
                    <option value="0" <?= old_value('working_student', '0') === '0' ? 'selected' : '' ?>>No</option>
                    <option value="1" <?= old_value('working_student') === '1' ? 'selected' : '' ?>>Yes</option>
                </select>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="button button-primary" type="submit" id="submit-btn">Generate Prediction</button>
    </div>
</form>

<?php if ($result && !empty($result['risk_factors'])): ?>
    <section id="risk-factors-panel" class="panel auto-dismiss">
        <div class="panel-title">
            <h2>Risk Factors Identified</h2>
        </div>
        <div class="chip-list">
            <?php foreach ($result['risk_factors'] as $factor): ?>
                <span class="chip" style="background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171;">
                    ⚠️ <?= h($factor) ?>
                </span>
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

    const predForm = document.getElementById('prediction-form');
    predForm.addEventListener('submit', function () {
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerText = 'Analyzing Data & Predicting...';
    });

    const autoDismissElements = document.querySelectorAll('.auto-dismiss');
    if (autoDismissElements.length > 0) {
        setTimeout(() => {
            autoDismissElements.forEach(el => {
                el.style.opacity = '0';
                setTimeout(() => {
                    el.style.display = 'none';
                }, 500);
            });

            if (predForm) {

                predForm.querySelectorAll('input').forEach(input => {
                    input.value = '';
                });

                predForm.querySelectorAll('select').forEach(select => {
                    select.selectedIndex = 0;
                });
            }
        }, 5000); 
    }
</script>

<?php page_footer(); ?>