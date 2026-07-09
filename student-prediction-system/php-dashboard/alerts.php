<?php
require_once 'bootstrap.php';
require_login();

$user = current_user();
$alerts = get_alerts($user['id']);

if (isset($_GET['mark_read'])) {
    $alert_id = (int) $_GET['mark_read'];
    $stmt = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $alert_id, $user['id']);
    $stmt->execute();
    redirect_to('alerts.php');
}

page_header('Early Warning Alerts');
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Early Warning Notifications</h2>
        <p class="card-subtitle">Automated alerts for students at risk of academic failure.</p>
    </div>
    
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alerts)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            No alerts found. Everything looks good!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): ?>
                        <tr class="<?= $alert['is_read'] ? '' : 'unread-alert' ?>" style="<?= $alert['is_read'] ? '' : 'background: rgba(var(--primary-rgb), 0.05);' ?>">
                            <td><?= date('M d, Y H:i', strtotime($alert['created_at'])) ?></td>
                            <td>
                                <strong><?= h($alert['full_name']) ?></strong><br>
                                <small><?= h($alert['student_no']) ?></small>
                            </td>
                            <td><?= h($alert['alert_type']) ?></td>
                            <td>
                                <span class="status-badge <?= severity_class($alert['severity']) ?>">
                                    <?= h($alert['severity']) ?>
                                </span>
                            </td>
                            <td><?= h($alert['message']) ?></td>
                            <td>
                                <?= $alert['is_read'] ? 'Read' : '<strong>Unread</strong>' ?>
                            </td>
                            <td>
                                <?php if (!$alert['is_read']): ?>
                                    <a href="alerts.php?mark_read=<?= $alert['id'] ?>" class="button button-ghost" style="font-size: 0.8rem;">Mark as Read</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php page_footer(); ?>
