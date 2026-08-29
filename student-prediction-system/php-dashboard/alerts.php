<?php
require_once 'bootstrap.php';
require_login();

$user = current_user();
$user_id = (int) $user['id'];

$sql = "SELECT a.*, s.full_name, s.student_no 
        FROM tbl_alerts a 
        JOIN tbl_students s ON a.student_id = s.id 
        WHERE a.user_id = ? 
          AND a.alert_type != 'Student Update' 
          AND a.message NOT LIKE '%updated their self-assessment profile%'
        ORDER BY a.created_at DESC";

$stmt = db()->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['mark_read'])) {
    $alert_id = (int) $_GET['mark_read'];
    $stmtMark = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmtMark->bind_param('ii', $alert_id, $user_id);
    $stmtMark->execute();
    redirect_to('alerts.php');
}

page_header('Early Warning Alerts');
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Early Warning System (At-Risk Alerts)</h2>
        <p class="card-subtitle">Automated risk detection for academic performance & attendance issues.</p>
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
                            No early warning risk alerts found. Everything looks good!
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