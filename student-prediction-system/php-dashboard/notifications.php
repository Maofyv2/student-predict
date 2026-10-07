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

if (isset($_POST['grade_activity'])) {
    $activity_id = (int) $_POST['activity_id'];
    $grade = trim($_POST['grade'] ?? '');
    $feedback = trim($_POST['feedback'] ?? '');

    if ($activity_id > 0) {
        $stmt = db()->prepare("UPDATE tbl_student_activities SET grade = ?, feedback = ?, status = 'Graded' WHERE id = ? AND assigned_by = ?");
        $stmt->bind_param('ssii', $grade, $feedback, $activity_id, $user_id);
        $stmt->execute();
    }
    redirect_to('notifications.php');
}

if (isset($_POST['send_activity'])) {
    $student_id = (int) $_POST['student_id'];
    $title = trim($_POST['activity_title'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $file_path = null;

    if (isset($_FILES['activity_file']) && $_FILES['activity_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/activities/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = pathinfo($_FILES['activity_file']['name'], PATHINFO_EXTENSION);
        $new_filename = 'activity_' . time() . '_' . uniqid() . '.' . $file_extension;
        $target_file = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['activity_file']['tmp_name'], $target_file)) {
            $file_path = 'uploads/activities/' . $new_filename;
        }
    }

    if ($student_id > 0 && !empty($title) && !empty($instructions)) {
        $stmt = db()->prepare("INSERT INTO tbl_student_activities (student_id, assigned_by, title, instructions, file_path, due_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissss', $student_id, $user_id, $title, $instructions, $file_path, $due_date);
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
                            <td><?= h($sub['title']) ?></td>
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

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem;">Grade / Score</label>
                <input type="text" name="grade" id="subModalGrade" placeholder="e.g. 95/100, 1.25, or Passed" required style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem;">
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
function openSubmissionModal(data) {
    document.getElementById('subModalActivityId').value = data.id;
    document.getElementById('subModalTitle').innerText = data.title;
    document.getElementById('subModalStudentInfo').innerText = 'Submitted by: ' + data.full_name + ' (' + data.student_no + ')';
    document.getElementById('subModalInstructions').innerText = data.instructions || 'N/A';
    document.getElementById('subModalText').innerText = data.submission_text || 'No additional text or notes provided.';
    
    document.getElementById('subModalGrade').value = data.grade || '';
    document.getElementById('subModalFeedback').value = data.feedback || '';

    const fileBtn = document.getElementById('subModalFileBtn');
    const noFileText = document.getElementById('subModalNoFile');

    const filePath = data.submission_file_path || data.submission_file;

    if (filePath && filePath.trim() !== '') {
        fileBtn.href = filePath.startsWith('http') || filePath.startsWith('/') ? filePath : '../' + filePath;
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
    document.getElementById('activityModal').style.display = 'flex';
}

function closeActivityModal() {
    document.getElementById('activityModal').style.display = 'none';
}
</script>

<?php page_footer(); ?>