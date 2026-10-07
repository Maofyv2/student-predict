<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$advisor_id = ($user['role'] === 'Advisor') ? $user['id'] : null;
$denied = isset($_GET['denied']);

$counts = prediction_counts($advisor_id);
$total  = total_students($advisor_id);
$recent = latest_predictions(8, $advisor_id);
$metadata = model_metadata();
$health   = api_request('GET', '/health');
$latestTotal = max(1, array_sum($counts));
$recent_alerts = get_alerts($user['id'], true);

page_header('Dashboard');
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">Academic Analytics</p>
        <h1>Student Performance Dashboard</h1>
        <p style="margin: 4px 0 0; font-size: 0.85rem; color: var(--muted);">Real-time semester academic progress and predictive performance metrics.</p>
    </div>
    <span class="role-pill role-<?= strtolower($user['role']) ?>" style="align-self: flex-start; margin-top: 6px;">
        Role: <?= h($user['role']) ?>
    </span>
</section>

<?php if ($denied): ?>
<div class="alert alert-warning">
    <strong>Restricted Access:</strong> The requested view is not available for your account role (<?= h($user['role']) ?>).
</div>
<?php endif; ?>

<section class="metrics-grid">
    <article class="metric">
        <span>Total Students</span>
        <strong><?= h((string) $total) ?></strong>
        <small>Active enrolled student records</small>
    </article>
    <article class="metric">
        <span>Pass Forecast</span>
        <strong class="text-pass"><?= h((string) $counts['Pass']) ?></strong>
        <small>Satisfactory academic performance</small>
    </article>
    <article class="metric">
        <span>At-Risk Alert</span>
        <strong class="text-risk"><?= h((string) $counts['At-Risk']) ?></strong>
        <small>Needs academic monitoring &amp; support</small>
    </article>
    <article class="metric">
        <span>Critical Fail Risk</span>
        <strong class="text-fail"><?= h((string) $counts['Fail']) ?></strong>
        <small>Urgent advisor intervention recommended</small>
    </article>
</section>

<section class="layout-two">
    <article class="panel">
        <div class="panel-title">
            <h2>Outcome Distribution</h2>
            <span><?= h((string) array_sum($counts)) ?> evaluated records</span>
        </div>
        <?php foreach ($counts as $status => $count): ?>
            <?php $width = (int) round(($count / $latestTotal) * 100); ?>
            <div class="bar-row">
                <div class="bar-label">
                    <span><?= h($status) ?></span>
                    <strong><?= h((string) $count) ?> (<?= $width ?>%)</strong>
                </div>
                <div class="bar-track">
                    <span class="bar-fill <?= h(status_class($status)) ?>" style="width: <?= $width ?>%"></span>
                </div>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel">
        <div class="panel-title">
            <h2>Model Engine Status</h2>
            <span class="pill <?= $health['ok'] ? 'pill-good' : 'pill-bad' ?>">
                <?= $health['ok'] ? 'Service Operational' : 'Service Offline' ?>
            </span>
        </div>
        <dl class="model-list">
            <div>
                <dt>Predictive Model</dt>
                <dd><?= h(str_replace(' (Progressive Stages)', '', $metadata['algorithm'] ?? 'XGBoost Classification')) ?></dd>
            </div>
            <div>
                <dt>Evaluation Accuracy</dt>
                <dd><?= isset($metadata['accuracy']) ? h((string) round((float) $metadata['accuracy'] * 100, 2)) . '%' : 'Pending Evaluation' ?></dd>
            </div>
            <div>
                <dt>Weighted F1 Metric</dt>
                <dd><?= isset($metadata['weighted_f1']) ? h((string) round((float) $metadata['weighted_f1'] * 100, 2)) . '%' : 'Pending Evaluation' ?></dd>
            </div>
            <div>
                <dt>Training Data Samples</dt>
                <dd><?= h((string) ($metadata['training_rows'] ?? 'Pending')) ?></dd>
            </div>
        </dl>
        <?php if (!$health['ok']): ?>
            <div class="alert alert-warning" style="margin-top: 14px; margin-bottom: 0;">
                <?= h($health['error'] ?? 'Prediction service is unavailable. Please verify API service.') ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <div class="panel-title">
        <div>
            <h2>Recent Predictions</h2>
            <span style="font-size: 0.8rem; color: var(--muted);">Latest evaluated student academic risk profiles</span>
        </div>
        <a href="reports.php" class="button button-outline" style="font-size: 0.825rem; padding: 6px 14px;">View Full Reports</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student Name &amp; ID</th>
                    <th>Year / Section</th>
                    <th>Predicted Outcome</th>
                    <th>Confidence</th>
                    <th style="text-align: right;">Evaluation Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="5" class="empty">No evaluation predictions recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h($row['full_name']) ?></strong>
                            <small><?= h($row['student_no']) ?></small>
                        </td>
                        <td><?= h($row['year_level'] . ' / ' . $row['section']) ?></td>
                        <td><span class="status <?= h(status_class($row['predicted_status'])) ?>"><?= h($row['predicted_status']) ?></span></td>
                        <td><?= (float)$row['confidence'] > 0 ? h((string) round((float) $row['confidence'] * 100, 1)) . '%' : 'N/A' ?></td>
                        <td style="text-align: right;"><?= h(date('M d, Y - h:i A', strtotime($row['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(); ?>
