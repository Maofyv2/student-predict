<?php
require_once 'bootstrap.php';
require_login();

$user = current_user();
$user_id = (int) $user['id'];

if (isset($_GET['mark_read'])) {
    $alert_id = (int) $_GET['mark_read'];
    $stmtMark = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmtMark->bind_param('ii', $alert_id, $user_id);
    $stmtMark->execute();
    redirect_to('alerts.php');
}

if (isset($_GET['mark_all_read'])) {
    $stmtAll = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE user_id = ? AND alert_type != 'Student Update'");
    $stmtAll->bind_param('i', $user_id);
    $stmtAll->execute();
    redirect_to('alerts.php');
}

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

$unread_count = 0;
foreach ($alerts as $a) {
    if (!$a['is_read']) {
        $unread_count++;
    }
}

page_header('Early Warning Alerts');
?>

<div class="card">
    <div class="card-header">
        <div class="card-header-content">
            <h2 class="card-title">Early Warning System (At-Risk Alerts)</h2>
            <p class="card-subtitle">Automated risk detection for academic performance &amp; attendance issues.</p>
        </div>
        <?php if ($unread_count > 0): ?>
            <div>
                <a href="alerts.php?mark_all_read=1" class="button button-secondary" style="font-size: 0.825rem; padding: 6px 14px;">
                    Mark All as Read (<?= $unread_count ?>)
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 140px;">Date</th>
                    <th style="min-width: 180px;">Student</th>
                    <th style="width: 90px;">Type</th>
                    <th style="width: 100px;">Severity</th>
                    <th style="min-width: 320px;">Message</th>
                    <th style="width: 95px; text-align: center;">Status</th>
                    <th style="width: 110px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alerts)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 3rem 1.5rem; color: var(--muted);">
                            <div style="font-weight: 500; font-size: 0.95rem;">No early warning risk alerts found</div>
                            <div style="font-size: 0.8rem; margin-top: 4px; color: var(--muted-light);">All enrolled students are currently in good standing.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): ?>
                        <tr class="<?= $alert['is_read'] ? '' : 'unread-alert' ?>">
                            <td style="white-space: nowrap; font-size: 0.825rem; color: var(--muted);">
                                <?= date('M d, Y H:i', strtotime($alert['created_at'])) ?>
                            </td>
                            <td>
                                <strong style="display: block; font-size: 0.875rem; color: var(--text);"><?= h($alert['full_name']) ?></strong>
                                <span class="pill status-muted" style="font-size: 0.725rem; padding: 1px 6px; margin-top: 3px;"><?= h($alert['student_no']) ?></span>
                            </td>
                            <td>
                                <span class="pill status-muted" style="font-size: 0.75rem;"><?= h($alert['alert_type']) ?></span>
                            </td>
                            <td>
                                <span class="status-badge <?= severity_class($alert['severity']) ?>">
                                    <?= h($alert['severity']) ?>
                                </span>
                            </td>
                            <td style="line-height: 1.5; color: var(--text-secondary); max-width: 480px;">
                                <?= h($alert['message']) ?>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <?php if ($alert['is_read']): ?>
                                    <span class="badge-read">Read</span>
                                <?php else: ?>
                                    <span class="badge-unread">● Unread</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if (!$alert['is_read']): ?>
                                    <a href="alerts.php?mark_read=<?= $alert['id'] ?>" class="button button-secondary" style="font-size: 0.775rem; padding: 4px 10px; min-height: 28px;">Mark as Read</a>
                                <?php else: ?>
                                    <span style="color: var(--muted-light); font-size: 0.85rem;">—</span>
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