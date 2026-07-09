<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$advisor_id = ($user['role'] === 'Advisor') ? $user['id'] : null;

$counts = prediction_counts($advisor_id);
$total = total_students($advisor_id);
$recent = latest_predictions(8, $advisor_id);
$metadata = model_metadata();
$health = api_request('GET', '/health');
$latestTotal = max(1, array_sum($counts));
$recent_alerts = get_alerts($user['id'], true); 

page_header('Dashboard');
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">Academic monitoring</p>
        <h1>Student Performance Dashboard</h1>
    </div>
</section>

<section class="metrics-grid">
    <article class="metric">
        <span>Total Students</span>
        <strong><?= h((string) $total) ?></strong>
    </article>
    <article class="metric">
        <span>Pass</span>
        <strong class="text-pass"><?= h((string) $counts['Pass']) ?></strong>
    </article>
    <article class="metric">
        <span>At-Risk</span>
        <strong class="text-risk"><?= h((string) $counts['At-Risk']) ?></strong>
    </article>
    <article class="metric">
        <span>Fail</span>
        <strong class="text-fail"><?= h((string) $counts['Fail']) ?></strong>
    </article>
</section>

<?php if (!empty($recent_alerts)): ?>
<section class="panel" style="border-left: 4px solid var(--accent-risk);">
    <div class="panel-title">
        <h2 style="color: var(--accent-risk);">Recent Early Warnings</h2>
        <a href="alerts.php">View all</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($recent_alerts, 0, 5) as $alert): ?>
                    <tr>
                        <td><strong><?= h($alert['full_name']) ?></strong></td>
                        <td><?= h($alert['alert_type']) ?></td>
                        <td><span class="status-badge <?= severity_class($alert['severity']) ?>"><?= h($alert['severity']) ?></span></td>
                        <td><?= h($alert['message']) ?></td>
                        <td><?= h(date('H:i', strtotime($alert['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="layout-two">
    <article class="panel">
        <div class="panel-title">
            <h2>Outcome Distribution</h2>
            <span><?= h((string) array_sum($counts)) ?> latest results</span>
        </div>
        <?php foreach ($counts as $status => $count): ?>
            <?php $width = (int) round(($count / $latestTotal) * 100); ?>
            <div class="bar-row">
                <div class="bar-label">
                    <span><?= h($status) ?></span>
                    <strong><?= h((string) $count) ?></strong>
                </div>
                <div class="bar-track">
                    <span class="bar-fill <?= h(status_class($status)) ?>" style="width: <?= $width ?>%"></span>
                </div>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel">
        <div class="panel-title">
            <h2>Model Status</h2>
            <span class="<?= $health['ok'] ? 'pill pill-good' : 'pill pill-bad' ?>">
                <?= $health['ok'] ? 'Online' : 'Offline' ?>
            </span>
        </div>
        <dl class="model-list">
            <div>
                <dt>Algorithm</dt>
                <dd><?= h($metadata['algorithm'] ?? 'XGBoost Classification') ?></dd>
            </div>
            <div>
                <dt>Accuracy</dt>
                <dd><?= isset($metadata['accuracy']) ? h((string) round((float) $metadata['accuracy'] * 100, 2)) . '%' : 'Pending' ?></dd>
            </div>
            <div>
                <dt>Weighted F1</dt>
                <dd><?= isset($metadata['weighted_f1']) ? h((string) round((float) $metadata['weighted_f1'] * 100, 2)) . '%' : 'Pending' ?></dd>
            </div>
            <div>
                <dt>Training Rows</dt>
                <dd><?= h((string) ($metadata['training_rows'] ?? 'Pending')) ?></dd>
            </div>
        </dl>
        <?php if (!$health['ok']): ?>
            <div class="alert alert-warning"><?= h($health['error'] ?? 'Start the Flask API to generate live predictions.') ?></div>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <div class="panel-title">
        <h2>Recent Predictions</h2>
        <a href="reports.php">View reports</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Year / Section</th>
                    <th>Status</th>
                    <th>Confidence</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="5" class="empty">No predictions recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h($row['full_name']) ?></strong>
                            <small><?= h($row['student_no']) ?></small>
                        </td>
                        <td><?= h($row['year_level'] . ' / ' . $row['section']) ?></td>
                        <td><span class="status <?= h(status_class($row['predicted_status'])) ?>"><?= h($row['predicted_status']) ?></span></td>
                        <td><?= h((string) round((float) $row['confidence'] * 100, 1)) ?>%</td>
                        <td><?= h(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(); ?>
