<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if (!in_array($user['role'], ['Admin', 'Advisor'])) {
    redirect_to('dashboard.php');
}

$advisor_id = ($user['role'] === 'Advisor') ? $user['id'] : null;

$sql = "SELECT 
            s.id, 
            s.student_no, 
            s.full_name, 
            s.year_level, 
            s.section,
            ar.prelim_grade, 
            ar.midterm_grade, 
            ar.semi_final_grade, 
            ar.final_grade,
            p.predicted_status, 
            p.confidence
        FROM tbl_students s
        LEFT JOIN (
            SELECT student_id, MAX(id) as latest_record_id
            FROM tbl_academic_records
            GROUP BY student_id
        ) latest_ar ON s.id = latest_ar.student_id
        LEFT JOIN tbl_academic_records ar ON latest_ar.latest_record_id = ar.id
        LEFT JOIN (
            SELECT student_id, MAX(id) as latest_pred_id
            FROM tbl_predictions
            GROUP BY student_id
        ) latest_p ON s.id = latest_p.student_id
        LEFT JOIN tbl_predictions p ON latest_p.latest_pred_id = p.id";

if ($advisor_id !== null) {
    $sql .= " WHERE s.advisor_id = ?";
    $stmt = db()->prepare($sql . " ORDER BY s.full_name ASC");
    $stmt->bind_param('i', $advisor_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $students = db()->query($sql . " ORDER BY s.full_name ASC")->fetch_all(MYSQLI_ASSOC);
}

function calculate_gpa($student) {
    $grades = [
        $student['prelim_grade'],
        $student['midterm_grade'],
        $student['semi_final_grade'],
        $student['final_grade']
    ];
    $valid_grades = array_filter($grades, function($g) { return $g > 0; });
    if (empty($valid_grades)) return 0;
    return array_sum($valid_grades) / count($valid_grades);
}

function get_scholarship_status($student) {
    $gpa = calculate_gpa($student);
    $prediction = $student['predicted_status'];
    $is_first_year = ($student['year_level'] === '1st Year');

    if ($prediction !== 'Pass') {
        return [
            'eligible' => false,
            'reason' => 'Predicted status must be "Pass" (Currently: ' . ($prediction ?: 'Pending') . ')',
            'award' => 'None',
            'discount' => '0%'
        ];
    }

    if ($is_first_year && $gpa >= 98) {
        return [
            'eligible' => true,
            'reason' => 'Entrance Scholarship: With Highest Honors',
            'award' => "Entrance Scholarship (Full)",
            'discount' => '100% Tuition'
        ];
    }

    if ($gpa >= 95) {
        return [
            'eligible' => true,
            'reason' => 'Excellent Academic Performance (GPA ' . number_format($gpa, 2) . ')',
            'award' => "President's Scholarship",
            'discount' => '100% Tuition'
        ];
    } elseif ($gpa >= 92) {
        return [
            'eligible' => true,
            'reason' => 'Superior Academic Performance (GPA ' . number_format($gpa, 2) . ')',
            'award' => "Dean's Scholarship",
            'discount' => '50% Tuition'
        ];
    } elseif ($gpa >= 90) {
        return [
            'eligible' => true,
            'reason' => 'High Academic Performance (GPA ' . number_format($gpa, 2) . ')',
            'award' => 'Academic Achievement',
            'discount' => '25% Tuition'
        ];
    }

    return [
        'eligible' => false,
        'reason' => 'GPA (' . number_format($gpa, 2) . ') below 90 threshold',
        'award' => 'None',
        'discount' => '0%'
    ];
}

page_header('Scholarship Eligibility');
?>

<section class="page-heading">
    <div>
        <p class="eyebrow">Academic Advisor Portal</p>
        <h1>Scholarship Eligibility Insights</h1>
    </div>
</section>

<section class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-bottom: 2rem;">
    <article class="metric" style="border-top: 4px solid var(--blue);">
        <span style="font-weight: 800; color: var(--blue);">President's List</span>
        <strong>100%</strong>
        <p style="margin: 8px 0 0; font-size: 0.85rem;">GPA 95.0 - 100.0</p>
    </article>
    <article class="metric" style="border-top: 4px solid var(--green);">
        <span style="font-weight: 800; color: var(--green);">Dean's List</span>
        <strong>50%</strong>
        <p style="margin: 8px 0 0; font-size: 0.85rem;">GPA 92.0 - 94.9</p>
    </article>
    <article class="metric" style="border-top: 4px solid var(--amber);">
        <span style="font-weight: 800; color: var(--amber);">Academic Award</span>
        <strong>25%</strong>
        <p style="margin: 8px 0 0; font-size: 0.85rem;">GPA 90.0 - 91.9</p>
    </article>
    <article class="metric" style="border-top: 4px solid #7c3aed;">
        <span style="font-weight: 800; color: #7c3aed;">Entrance Merit</span>
        <strong>Up to 100%</strong>
        <p style="margin: 8px 0 0; font-size: 0.85rem;">For 1st Year Honors</p>
    </article>
</section>

<section class="panel">
    <div class="panel-title">
        <h2>Advisor Review & Eligibility Checklist</h2>
        <span class="pill status-muted"><?= count($students) ?> Students Listed</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student Details</th>
                    <th>Academic Metrics</th>
                    <th>AI Prediction</th>
                    <th>Eligibility Status</th>
                    <th>Scholarship / Grant</th>
                    <th>Tuition Benefit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="6" class="empty">No students assigned to your department.</td></tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <?php 
                        $gpa = calculate_gpa($student);
                        $status = get_scholarship_status($student);
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; flex-direction: column;">
                                <strong><?= h($student['full_name']) ?></strong>
                                <small style="margin-top: 2px;"><?= h($student['student_no']) ?> • <?= h($student['year_level']) ?></small>
                                <small><?= h($student['section']) ?></small>
                            </div>
                        </td>
                        <td>
                            <div style="padding: 8px; background: var(--surface-strong); border-radius: 6px; display: inline-block;">
                                <span style="font-size: 0.75rem; color: var(--muted); display: block;">Computed GPA</span>
                                <?php if ($gpa > 0): ?>
                                    <strong style="font-size: 1.25rem; color: var(--blue);"><?= number_format($gpa, 2) ?></strong>
                                <?php else: ?>
                                    <strong style="font-size: 0.9rem; color: var(--muted);">No Records</strong>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <?php if ($student['predicted_status']): ?>
                                    <span class="status <?= h(status_class($student['predicted_status'])) ?>">
                                        <?= h($student['predicted_status']) ?>
                                    </span>
                                    <?php if (isset($student['confidence'])): ?>
                                        <small style="font-size: 0.7rem;"><?= round($student['confidence'] * 100) ?>% Confidence</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status status-muted">Pending AI</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($status['eligible']): ?>
                                <span class="pill pill-good" style="padding: 8px 16px; font-size: 0.9rem;">✓ QUALIFIED</span>
                            <?php else: ?>
                                <span class="pill pill-bad" style="padding: 8px 16px; font-size: 0.9rem;">✕ INELIGIBLE</span>
                                <div style="font-size: 0.75rem; color: var(--red); margin-top: 6px; font-weight: 600;"><?= h($status['reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color: var(--text);"><?= h($status['award']) ?></strong>
                        </td>
                        <td>
                            <div style="font-size: 1.1rem; font-weight: 800; color: <?= $status['eligible'] ? 'var(--green)' : 'var(--muted)' ?>;">
                                <?= h($status['discount']) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="alert alert-warning" style="background: rgba(255, 244, 216, 0.5); border-color: var(--amber);">
    <h3 style="margin: 0 0 8px; font-size: 1rem;">Scholarship Policy Disclaimer</h3>
    <p style="margin: 0; font-size: 0.85rem;">The results shown above are automated predictions based on currently encoded academic records and machine learning outputs. Final scholarship approval is subject to manual verification by the Office of Student Affairs and compliance with the minimum load requirement of 18 units per semester.</p>
</div>

<?php page_footer(); ?>
