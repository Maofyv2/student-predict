<?php
require_once 'bootstrap.php';
require_login();

$user = current_user();
$user_id = (int) $user['id'];

// Handle Mark as Read for single alert
if (isset($_GET['mark_read'])) {
    $alert_id = (int) $_GET['mark_read'];
    $stmtMark = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmtMark->bind_param('ii', $alert_id, $user_id);
    $stmtMark->execute();
    $stmtMark->close();
    
    $filterParam = !empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '';
    redirect_to('alerts.php' . $filterParam);
}

// Handle Mark All as Read
if (isset($_GET['mark_all_read'])) {
    $stmtAll = db()->prepare("UPDATE tbl_alerts SET is_read = 1 WHERE user_id = ? AND alert_type != 'Student Update'");
    $stmtAll->bind_param('i', $user_id);
    $stmtAll->execute();
    $stmtAll->close();
    redirect_to('alerts.php');
}

$filter = $_GET['filter'] ?? 'all';

// Fetch all early warning risk alerts
$sql = "SELECT a.*, s.full_name, s.student_no, s.year_level, s.section 
        FROM tbl_alerts a 
        JOIN tbl_students s ON a.student_id = s.id 
        WHERE a.user_id = ? 
          AND a.alert_type != 'Student Update' 
          AND a.message NOT LIKE '%updated their self-assessment profile%'
        ORDER BY a.created_at DESC";

$stmt = db()->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$all_alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unread_count = 0;
$fail_count = 0;
$at_risk_count = 0;
$recent_unread_students = [];

foreach ($all_alerts as $a) {
    $isUnread = !(bool)$a['is_read'];
    $isFail = ($a['severity'] === 'High' || stripos($a['message'], "'Fail'") !== false || stripos($a['message'], 'Fail') !== false);
    
    if ($isUnread) {
        $unread_count++;
        if (count($recent_unread_students) < 5) {
            $recent_unread_students[] = [
                'student_id' => $a['student_id'],
                'full_name'  => $a['full_name'],
                'student_no' => $a['student_no'],
                'severity'   => $a['severity'],
                'message'    => $a['message']
            ];
        }
    }
    
    if ($isFail) {
        $fail_count++;
    } else {
        $at_risk_count++;
    }
}

// Filter alerts for current view
$alerts = array_filter($all_alerts, function ($a) use ($filter) {
    if ($filter === 'unread') {
        return !(bool)$a['is_read'];
    }
    if ($filter === 'fail') {
        return ($a['severity'] === 'High' || stripos($a['message'], 'Fail') !== false);
    }
    if ($filter === 'at-risk') {
        return ($a['severity'] === 'Medium' || stripos($a['message'], 'At-Risk') !== false);
    }
    return true;
});

page_header('Early Warning Alerts');
?>

<div class="page-heading">
    <div>
        <h1>Risk Alerts</h1>
    </div>
    <?php if ($unread_count > 0): ?>
        <div>
            <a href="alerts.php?mark_all_read=1" class="button button-secondary" style="font-size: 0.82rem;">
                Mark All as Read (<?= $unread_count ?>)
            </a>
        </div>
    <?php endif; ?>
</div>

<?php if ($unread_count > 0): ?>
    <div style="border: 1px solid #d1d5db; border-left: 3px solid #374151; border-radius: 4px; padding: 12px 16px; margin-bottom: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <p style="margin: 0; font-size: 0.875rem; color: var(--text);">
                <?= $unread_count ?> unread alert<?= $unread_count > 1 ? 's' : '' ?> students flagged for review.
                <?php if (!empty($recent_unread_students)): ?>
                    <?php foreach ($recent_unread_students as $rus): ?>
                        <a href="notifications.php" style="font-size: 0.8rem; color: var(--text); text-decoration: underline; margin-left: 6px;">
                            <?= h($rus['full_name']) ?> (<?= h($rus['student_no']) ?>)
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
            <?php if ($filter !== 'unread'): ?>
                <a href="alerts.php?filter=unread" class="button button-secondary" style="font-size: 0.8rem; padding: 4px 12px;">View Unread</a>
            <?php else: ?>
                <a href="alerts.php?filter=all" class="button button-secondary" style="font-size: 0.8rem; padding: 4px 12px;">View All</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Summary Metric Cards -->
<section class="metrics-grid" style="margin-bottom: 1.5rem;">
    <article class="metric">
        <span>Total Alerts</span>
        <strong><?= count($all_alerts) ?></strong>
    </article>
    <article class="metric">
        <span>Unread</span>
        <strong><?= $unread_count ?></strong>
    </article>
    <article class="metric">
        <span>Fail Risk</span>
        <strong><?= $fail_count ?></strong>
    </article>
    <article class="metric">
        <span>At-Risk</span>
        <strong><?= $at_risk_count ?></strong>
    </article>
</section>

<!-- Filter Tabs Navigation -->
<div style="display: flex; align-items: center; gap: 6px; margin-bottom: 14px; flex-wrap: wrap;">
    <a href="alerts.php?filter=all" class="button <?= $filter === 'all' ? 'button-primary' : 'button-secondary' ?>" style="font-size: 0.8rem; padding: 4px 12px; min-height: 28px;">All (<?= count($all_alerts) ?>)</a>
    <a href="alerts.php?filter=unread" class="button <?= $filter === 'unread' ? 'button-primary' : 'button-secondary' ?>" style="font-size: 0.8rem; padding: 4px 12px; min-height: 28px;">Unread (<?= $unread_count ?>)</a>
    <a href="alerts.php?filter=fail" class="button <?= $filter === 'fail' ? 'button-primary' : 'button-secondary' ?>" style="font-size: 0.8rem; padding: 4px 12px; min-height: 28px;">Fail Risk (<?= $fail_count ?>)</a>
    <a href="alerts.php?filter=at-risk" class="button <?= $filter === 'at-risk' ? 'button-primary' : 'button-secondary' ?>" style="font-size: 0.8rem; padding: 4px 12px; min-height: 28px;">At-Risk (<?= $at_risk_count ?>)</a>
</div>

<!-- Main Alerts Table Panel -->
<div class="card">
    <div class="card-header">
        <div class="card-header-content">
            <h2 class="card-title">
                <?php 
                if ($filter === 'unread') echo 'Unread (' . count($alerts) . ')';
                elseif ($filter === 'fail') echo 'Fail Risk (' . count($alerts) . ')';
                elseif ($filter === 'at-risk') echo 'At-Risk (' . count($alerts) . ')';
                else echo 'All Alerts (' . count($alerts) . ')';
                ?>
            </h2>
        </div>
    </div>
    
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 140px;">Date &amp; Time</th>
                    <th style="min-width: 200px;">Student Information</th>
                    <th style="width: 110px;">Risk Level</th>
                    <th style="min-width: 320px;">Alert Reason / Message</th>
                    <th style="width: 100px; text-align: center;">Status</th>
                    <th style="width: 200px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alerts)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 3rem 1.5rem; color: var(--muted);">
                            <div style="font-weight: 600; font-size: 0.95rem; color: var(--text);">
                                <?= $filter === 'unread' ? 'No unread alerts.' : 'No alerts found in this category.' ?>
                            </div>
                            <div style="font-size: 0.82rem; margin-top: 4px; color: var(--muted);">
                                <?= $filter === 'unread' ? 'All early warning alerts have been reviewed.' : 'Students in this filter currently have no flagged records.' ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): 
                        $isUnread = !(bool)$alert['is_read'];
                        $isHigh = ($alert['severity'] === 'High' || stripos($alert['message'], 'Fail') !== false);
                    ?>
                        <tr style="<?= $isUnread ? 'border-left: 3px solid #9ca3af;' : '' ?>">
                            <td style="white-space: nowrap; font-size: 0.82rem; color: var(--muted);">
                                <?= date('M d, Y', strtotime($alert['created_at'])) ?><br>
                                <span style="font-size: 0.78rem;"><?= date('h:i A', strtotime($alert['created_at'])) ?></span>
                            </td>
                            <td>
                                <strong style="font-size: 0.9rem;"><?= h($alert['full_name']) ?></strong>
                                <div style="font-size: 0.78rem; color: var(--muted); margin-top: 2px;">
                                    <?= h($alert['student_no']) ?>
                                    <?php if (!empty($alert['year_level']) && !empty($alert['section'])): ?>
                                        &middot; <?= h($alert['year_level']) ?>-<?= h($alert['section']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="font-size: 0.82rem; color: var(--muted);">
                                <?= $isHigh ? 'Fail Risk' : 'At-Risk' ?>
                            </td>
                            <td style="font-size: 0.85rem; color: var(--text-secondary); max-width: 420px; line-height: 1.5;">
                                <?= h(str_replace('—', ' ', $alert['message'])) ?>
                            </td>
                            <td style="text-align: center; font-size: 0.8rem; color: var(--muted);">
                                <?= $alert['is_read'] ? 'Read' : 'Unread' ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; align-items: center; gap: 6px;">
                                    <a href="notifications.php" class="button button-secondary" style="font-size: 0.75rem; padding: 4px 10px; min-height: 26px;">Assign Task</a>
                                    <?php if ($isUnread): ?>
                                        <a href="alerts.php?mark_read=<?= $alert['id'] ?>&filter=<?= urlencode($filter) ?>" class="button button-secondary" style="font-size: 0.75rem; padding: 4px 8px; min-height: 26px;">Mark Read</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php page_footer(); ?>