<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user      = current_user();
$isAdvisor = ($user['role'] === 'Advisor');
$errors    = [];
$success   = false;
$periods   = ['Prelim', 'Midterm', 'Semi-Final', 'Final'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_weights'])) {
    if (!$isAdvisor) {
        $errors[] = 'Only Academic Advisors can modify grading criteria.';
    } else {
        $conn = db();
        $submittedCriteria = $_POST['criteria'] ?? [];

        $parsedPerPeriod = [];
        foreach ($periods as $period) {
            $rows = $submittedCriteria[$period] ?? [];
            if (empty($rows) || !is_array($rows)) {
                $errors[] = "At least one criterion must be defined for {$period}.";
                continue;
            }

            $totalWeight = 0;
            $seenComponents = [];
            $periodItems = [];

            foreach ($rows as $row) {
                $compName = trim((string)($row['component'] ?? ''));
                $weight   = (float)($row['weight'] ?? 0);
                $maxScore = (float)($row['max_score'] ?? 100);

                if ($compName === '') {
                    $errors[] = "A component name cannot be blank in {$period}.";
                    break;
                }
                $compLower = strtolower($compName);
                if (isset($seenComponents[$compLower])) {
                    $errors[] = "Duplicate component '{$compName}' in {$period}. Component names must be unique within a period.";
                    break;
                }
                $seenComponents[$compLower] = true;

                if ($weight < 0 || $weight > 100) {
                    $errors[] = "Weight for '{$compName}' ({$period}) must be between 0% and 100%.";
                    break;
                }
                if ($maxScore <= 0) {
                    $errors[] = "Maximum score for '{$compName}' ({$period}) must be greater than 0.";
                    break;
                }

                $totalWeight += $weight;
                $periodItems[] = [
                    'component' => $compName,
                    'weight'    => $weight,
                    'max_score' => $maxScore,
                ];
            }

            if (abs($totalWeight - 100.0) > 0.05) {
                $errors[] = "Weights for {$period} must total exactly 100%. Current total: " . round($totalWeight, 2) . "%.";
            }

            $parsedPerPeriod[$period] = $periodItems;
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $createdBy = (int)$user['id'];
                foreach ($parsedPerPeriod as $period => $items) {
                    $delStmt = $conn->prepare('DELETE FROM tbl_grading_weights WHERE period = ?');
                    $delStmt->bind_param('s', $period);
                    $delStmt->execute();

                    $insStmt = $conn->prepare(
                        'INSERT INTO tbl_grading_weights (period, component, weight, max_score, created_by)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($items as $item) {
                        $insStmt->bind_param('ssddi', $period, $item['component'], $item['weight'], $item['max_score'], $createdBy);
                        $insStmt->execute();
                    }
                }
                $conn->commit();
                $success = true;
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = 'Database error saving grading criteria: ' . $e->getMessage();
            }
        }
    }
}

$allWeights = get_all_grading_weights();

page_header('Grading Criteria');
?>

<section class="page-heading">
    <div>
        <p class="eyebrow">System Configuration</p>
        <h1>Dynamic Grading Criteria</h1>
    </div>
    <?php if (!$isAdvisor): ?>
        <span class="pill pill-bad" style="align-self:center;">View Only (Admin)</span>
    <?php else: ?>
        <span class="pill pill-good" style="align-self:center;">Advisor Active</span>
    <?php endif; ?>
</section>

<?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Success:</strong> Dynamic grading criteria updated and verified for all grading periods.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?><div><strong>Notice:</strong> <?= h($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$isAdvisor): ?>
<div class="alert alert-info">
    <strong>Administrator Notice:</strong> You are viewing this page as an Administrator. Grading criteria and evaluation formulas can only be modified by an Academic Advisor.
</div>
<?php endif; ?>


<form method="post" id="weights-form">
    <input type="hidden" name="save_weights" value="1">

    <div style="display:grid;gap:20px;">
        <?php foreach ($periods as $period):
            $ws = $allWeights[$period] ?? [];
            $periodKey = strtolower(str_replace(['-', ' '], '_', $period));
            $periodTotal = 0;
            foreach ($ws as $cfg) {
                $periodTotal += (float)($cfg['weight'] ?? 0);
            }
        ?>
        <div class="panel" id="period-card-<?= $periodKey ?>">
            <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
                <h2><?= h($period) ?> Criteria</h2>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span id="total-badge-<?= $periodKey ?>" class="pill <?= abs($periodTotal - 100) < 0.05 ? 'pill-good' : 'pill-bad' ?>">
                        Total: <span id="total-val-<?= $periodKey ?>"><?= round($periodTotal, 1) ?></span>%
                    </span>
                    <?php if ($isAdvisor): ?>
                        <button type="button" class="button button-outline" onclick="addCriterionRow('<?= $period ?>')" style="font-size: 0.8rem; padding: 4px 10px;">
                            Add Criterion
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div style="overflow-x:auto;">
            <table class="table" style="width:100%;border-collapse:collapse;font-size:.9rem;" id="table-<?= $periodKey ?>">
                <thead>
                    <tr style="background:var(--surface-strong);">
                        <th style="padding:10px 12px;text-align:left;border-bottom:1px solid var(--line);">Criterion Component</th>
                        <th style="padding:10px 12px;text-align:center;border-bottom:1px solid var(--line);width:130px;">Weight (%)</th>
                        <th style="padding:10px 12px;text-align:center;border-bottom:1px solid var(--line);width:130px;">Max Score</th>
                        <th style="padding:10px 12px;text-align:left;border-bottom:1px solid var(--line);">Sample Formula</th>
                        <?php if ($isAdvisor): ?>
                            <th style="padding:10px 12px;text-align:center;border-bottom:1px solid var(--line);width:70px;">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tbody-<?= $periodKey ?>">
                <?php 
                $rowIdx = 0;
                foreach ($ws as $comp => $cfg): 
                    $wt = (float)($cfg['weight'] ?? 0);
                    $ms = (float)($cfg['max_score'] ?? 100);
                    $sampleRaw = round($ms * 0.8, 0);
                    $sampleContrib = round(0.8 * $wt, 2);
                ?>
                    <tr data-period="<?= $periodKey ?>">
                        <td style="padding:8px 12px;">
                            <input type="text"
                                   name="criteria[<?= $period ?>][<?= $rowIdx ?>][component]"
                                   value="<?= h($comp) ?>"
                                   class="comp-input"
                                   placeholder="e.g. Exam, Quiz, Attendance"
                                   <?= !$isAdvisor ? 'readonly' : 'required' ?>
                                   style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;">
                        </td>
                        <td style="padding:8px 12px;text-align:center;">
                            <input type="number" step="0.01" min="0" max="100"
                                   name="criteria[<?= $period ?>][<?= $rowIdx ?>][weight]"
                                   value="<?= round($wt, 2) ?>"
                                   class="weight-input"
                                   data-period="<?= $periodKey ?>"
                                   oninput="recalcPeriodTotal('<?= $periodKey ?>')"
                                   <?= !$isAdvisor ? 'readonly' : 'required' ?>
                                   style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
                        </td>
                        <td style="padding:8px 12px;text-align:center;">
                            <input type="number" step="0.01" min="1" max="9999"
                                   name="criteria[<?= $period ?>][<?= $rowIdx ?>][max_score]"
                                   value="<?= round($ms, 2) ?>"
                                   class="max-input"
                                   data-period="<?= $periodKey ?>"
                                   oninput="recalcPeriodTotal('<?= $periodKey ?>')"
                                   <?= !$isAdvisor ? 'readonly' : 'required' ?>
                                   style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
                        </td>
                        <td style="padding:8px 12px;color:var(--muted);font-size:.82rem;" class="sample-formula">
                            (<?= $sampleRaw ?> / <?= $ms ?>) * <?= $wt ?>% = <?= $sampleContrib ?>%
                        </td>
                        <?php if ($isAdvisor): ?>
                        <td style="padding:8px 12px;text-align:center;">
                            <button type="button" onclick="removeCriterionRow(this, '<?= $periodKey ?>')"
                                    class="button button-ghost"
                                    title="Remove criterion"
                                    style="padding:4px 8px;font-size:0.775rem;color:var(--fail-text);">
                                Remove
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php 
                    $rowIdx++;
                endforeach; 
                ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($isAdvisor): ?>
    <div class="form-actions" style="margin-top:24px;display:flex;justify-content:flex-end;">
        <button type="submit" class="button button-primary" style="padding:10px 22px;">
            Save Grading Criteria
        </button>
    </div>
    <?php endif; ?>
</form>

<script>
const PERIODS = ['prelim', 'midterm', 'semi_final', 'final'];
const PERIOD_NAMES = {
    'prelim': 'Prelim',
    'midterm': 'Midterm',
    'semi_final': 'Semi-Final',
    'final': 'Final'
};

function recalcPeriodTotal(periodKey) {
    const tbody = document.getElementById('tbody-' + periodKey);
    if (!tbody) return;

    let total = 0;
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(tr => {
        const wtInput = tr.querySelector('.weight-input');
        const msInput = tr.querySelector('.max-input');
        const sampleEl = tr.querySelector('.sample-formula');

        const wt = parseFloat(wtInput ? wtInput.value : 0) || 0;
        const ms = parseFloat(msInput ? msInput.value : 100) || 100;
        total += wt;

        if (sampleEl) {
            const raw = Math.round(ms * 0.8);
            const contrib = (0.8 * wt).toFixed(2);
            sampleEl.textContent = `(${raw} / ${ms}) × ${wt}% = ${contrib}%`;
        }
    });

    const badge = document.getElementById('total-badge-' + periodKey);
    const valEl = document.getElementById('total-val-' + periodKey);
    if (badge && valEl) {
        valEl.textContent = total.toFixed(1);
        const ok = Math.abs(total - 100.0) < 0.05;
        badge.className = 'pill ' + (ok ? 'pill-good' : 'pill-bad');
    }
}

function addCriterionRow(periodName) {
    const periodKey = periodName.toLowerCase().replace(/[- ]/g, '_');
    const tbody = document.getElementById('tbody-' + periodKey);
    if (!tbody) return;

    const rowIdx = tbody.querySelectorAll('tr').length;
    const tr = document.createElement('tr');
    tr.dataset.period = periodKey;
    tr.innerHTML = `
        <td style="padding:8px 12px;">
            <input type="text"
                   name="criteria[${periodName}][${rowIdx}][component]"
                   value=""
                   class="comp-input"
                   placeholder="e.g. Attendance, Quiz, Project"
                   required
                   style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;">
        </td>
        <td style="padding:8px 12px;text-align:center;">
            <input type="number" step="0.01" min="0" max="100"
                   name="criteria[${periodName}][${rowIdx}][weight]"
                   value="10"
                   class="weight-input"
                   data-period="${periodKey}"
                   oninput="recalcPeriodTotal('${periodKey}')"
                   required
                   style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
        </td>
        <td style="padding:8px 12px;text-align:center;">
            <input type="number" step="0.01" min="1" max="9999"
                   name="criteria[${periodName}][${rowIdx}][max_score]"
                   value="100"
                   class="max-input"
                   data-period="${periodKey}"
                   oninput="recalcPeriodTotal('${periodKey}')"
                   required
                   style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
        </td>
        <td style="padding:8px 12px;color:var(--muted);font-size:.82rem;" class="sample-formula">
            (80 / 100) × 10% = 8.00%
        </td>
        <td style="padding:8px 12px;text-align:center;">
            <button type="button" onclick="removeCriterionRow(this, '${periodKey}')"
                    class="button button-ghost"
                    title="Remove criterion"
                    style="padding:4px 8px;font-size:0.775rem;color:var(--fail-text);">
                Remove
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    recalcPeriodTotal(periodKey);
}

function removeCriterionRow(btn, periodKey) {
    const tr = btn.closest('tr');
    if (tr) {
        tr.remove();
        recalcPeriodTotal(periodKey);
    }
}

function applyPresetStandard() {
    if (!confirm('Apply the 5-component standard preset (Exam 40%, Quiz 20%, Assignment 15%, Project 15%, Attendance 10%) to all grading periods?')) {
        return;
    }

    const preset = [
        { component: 'Exam',       weight: 40, max_score: 100 },
        { component: 'Quiz',       weight: 20, max_score: 20 },
        { component: 'Assignment', weight: 15, max_score: 50 },
        { component: 'Project',    weight: 15, max_score: 100 },
        { component: 'Attendance', weight: 10, max_score: 100 }
    ];

    Object.entries(PERIOD_NAMES).forEach(([periodKey, periodName]) => {
        const tbody = document.getElementById('tbody-' + periodKey);
        if (!tbody) return;
        tbody.innerHTML = '';

        preset.forEach((item, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.period = periodKey;
            tr.innerHTML = `
                <td style="padding:8px 12px;">
                    <input type="text"
                           name="criteria[${periodName}][${idx}][component]"
                           value="${item.component}"
                           class="comp-input"
                           required
                           style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;">
                </td>
                <td style="padding:8px 12px;text-align:center;">
                    <input type="number" step="0.01" min="0" max="100"
                           name="criteria[${periodName}][${idx}][weight]"
                           value="${item.weight}"
                           class="weight-input"
                           data-period="${periodKey}"
                           oninput="recalcPeriodTotal('${periodKey}')"
                           required
                           style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
                </td>
                <td style="padding:8px 12px;text-align:center;">
                    <input type="number" step="0.01" min="1" max="9999"
                           name="criteria[${periodName}][${idx}][max_score]"
                           value="${item.max_score}"
                           class="max-input"
                           data-period="${periodKey}"
                           oninput="recalcPeriodTotal('${periodKey}')"
                           required
                           style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
                </td>
                <td style="padding:8px 12px;color:var(--muted);font-size:.82rem;" class="sample-formula">
                    (${Math.round(item.max_score * 0.8)} / ${item.max_score}) * ${item.weight}% = ${(0.8 * item.weight).toFixed(2)}%
                </td>
                <td style="padding:8px 12px;text-align:center;">
                    <button type="button" onclick="removeCriterionRow(this, '${periodKey}')"
                            class="button button-ghost"
                            title="Remove criterion"
                            style="padding:4px 8px;font-size:0.775rem;color:var(--fail-text);">
                        Remove
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        recalcPeriodTotal(periodKey);
    });
}

function copyPrelimToAll() {
    const prelimTbody = document.getElementById('tbody-prelim');
    if (!prelimTbody) return;

    const rows = prelimTbody.querySelectorAll('tr');
    if (!rows.length) return;

    if (!confirm('Copy Prelim criteria to Midterm, Semi-Final, and Final?')) return;

    const data = [];
    rows.forEach(tr => {
        const comp = tr.querySelector('.comp-input').value.trim();
        const wt = parseFloat(tr.querySelector('.weight-input').value || 0);
        const ms = parseFloat(tr.querySelector('.max-input').value || 100);
        if (comp) {
            data.push({ component: comp, weight: wt, max_score: ms });
        }
    });

    ['midterm', 'semi_final', 'final'].forEach(periodKey => {
        const periodName = PERIOD_NAMES[periodKey];
        const tbody = document.getElementById('tbody-' + periodKey);
        if (!tbody) return;
        tbody.innerHTML = '';

        data.forEach((item, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.period = periodKey;
            tr.innerHTML = `
                <td style="padding:8px 12px;">
                    <input type="text"
                           name="criteria[${periodName}][${idx}][component]"
                           value="${item.component}"
                           class="comp-input"
                           required
                           style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-weight:600;">
                </td>
                <td style="padding:8px 12px;text-align:center;">
                    <input type="number" step="0.01" min="0" max="100"
                           name="criteria[${periodName}][${idx}][weight]"
                           value="${item.weight}"
                           class="weight-input"
                           data-period="${periodKey}"
                           oninput="recalcPeriodTotal('${periodKey}')"
                           required
                           style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
                </td>
                <td style="padding:8px 12px;text-align:center;">
                    <input type="number" step="0.01" min="1" max="9999"
                           name="criteria[${periodName}][${idx}][max_score]"
                           value="${item.max_score}"
                           class="max-input"
                           data-period="${periodKey}"
                           oninput="recalcPeriodTotal('${periodKey}')"
                           required
                           style="width:90px;padding:6px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;">
                </td>
                <td style="padding:8px 12px;color:var(--muted);font-size:.82rem;" class="sample-formula">
                    (${Math.round(item.max_score * 0.8)} / ${item.max_score}) * ${item.weight}% = ${(0.8 * item.weight).toFixed(2)}%
                </td>
                <td style="padding:8px 12px;text-align:center;">
                    <button type="button" onclick="removeCriterionRow(this, '${periodKey}')"
                            class="button button-ghost"
                            title="Remove criterion"
                            style="padding:4px 8px;font-size:0.775rem;color:var(--fail-text);">
                        Remove
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        recalcPeriodTotal(periodKey);
    });
}
</script>

<?php page_footer(); ?>
