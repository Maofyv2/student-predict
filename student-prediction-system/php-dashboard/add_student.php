<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if (!$user || !in_array($user['role'], ['Admin', 'Advisor'])) {
    redirect_to('students.php');
}

$conn = db();
$error_message = '';

// Preview the next student number for the form (non-locking)
$next_student_no = peek_next_student_no($conn, '26');

// Load available professors from users table
$professors = db()->query("SELECT id, full_name, username FROM users WHERE role = 'Advisor' AND is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

// Available sections & capacity configuration
$max_capacity = 40;
$available_sections = ['BSIT 1', 'BSIT 2', 'BSIT 3', 'BSIT 4', 'BSIT 5'];

function get_section_counts($conn, $available_sections) {
    $counts = [];
    foreach ($available_sections as $sec) {
        $counts[$sec] = 0;
    }
    $secQuery = $conn->query("SELECT section, COUNT(*) as count FROM tbl_students GROUP BY section");
    if ($secQuery) {
        while ($row = $secQuery->fetch_assoc()) {
            $sName = trim($row['section']);
            if (isset($counts[$sName])) {
                $counts[$sName] = (int)$row['count'];
            }
        }
    }
    return $counts;
}

$section_counts = get_section_counts($conn, $available_sections);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $password = $_POST['password'] ?? '';

    // Assign professor: Admin selects/types, Advisor assigns self
    if ($user['role'] === 'Admin') {
        $advisor_id = (int)($_POST['advisor_id'] ?? 0);
        $advisor_search = trim($_POST['advisor_search'] ?? '');
        if ($advisor_id <= 0 && !empty($advisor_search)) {
            $cleanSearch = preg_replace('/^prof\.?\s*/i', '', $advisor_search);
            foreach ($professors as $p) {
                if (strcasecmp($p['full_name'], $advisor_search) === 0 ||
                    strcasecmp($p['full_name'], $cleanSearch) === 0 ||
                    stripos($p['full_name'], $cleanSearch) !== false ||
                    stripos($p['full_name'], $advisor_search) !== false) {
                    $advisor_id = (int)$p['id'];
                    break;
                }
            }
        }
    } else {
        $advisor_id = (int)$user['id'];
    }

    if (empty($fullname) || empty($password) || empty($year_level) || empty($section)) {
        $error_message = 'Please fill in all required fields.';
    } elseif ($user['role'] === 'Admin' && $advisor_id <= 0) {
        $error_message = 'Please select an assigned professor for this student.';
    } else {
        // Enforce maximum capacity limit of 40 students per section
        $secCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_students WHERE section = ?");
        $secCheck->bind_param('s', $section);
        $secCheck->execute();
        $current_sec_count = (int)($secCheck->get_result()->fetch_assoc()['cnt'] ?? 0);

        if ($current_sec_count >= $max_capacity) {
            $error_message = "Section '{$section}' is currently full ({$current_sec_count}/{$max_capacity}). Please select another section.";
            $section_counts = get_section_counts($conn, $available_sections);
        } else {
            // Always re-generate inside a transaction (locked) — never trust the form value
            $conn->begin_transaction();
            try {
                $school_no = generate_next_student_no($conn, '26');
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO tbl_students (student_no, full_name, year_level, section, gender, password_hash, advisor_id, professor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssii", $school_no, $fullname, $year_level, $section, $gender, $password_hash, $advisor_id, $advisor_id);
                $stmt->execute();
                $conn->commit();
                redirect_to("students.php?msg=added");
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = 'Error adding student: ' . $e->getMessage();
            }
            // Refresh preview number after error
            $next_student_no = peek_next_student_no($conn, '26');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Student - Prediction System</title>
    <link rel="icon" type="image/png" href="au.png">
    <link rel="shortcut icon" type="image/png" href="au.png">
    <link rel="apple-touch-icon" href="au.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-image: url('bg.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .form-container {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            padding: 30px 35px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 480px;
        }

        .form-header {
            margin-bottom: 25px;
            text-align: center;
        }

        .form-header h2 {
            color: #1e293b;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-header p {
            color: #64748b;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: 14px;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            background-color: #fff;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .password-toggle-eye {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            border-radius: 4px;
            transition: color 0.15s ease;
        }

        .password-toggle-eye:hover {
            color: #1e293b;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .prof-combobox-wrapper {
            position: relative;
            width: 100%;
        }

        .prof-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            z-index: 100;
            overflow: hidden;
        }

        .prof-list {
            max-height: 126px; /* Exactly 3 items of 42px each */
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .prof-list::-webkit-scrollbar {
            width: 6px;
        }

        .prof-list::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        .prof-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .prof-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .prof-item {
            height: 42px;
            padding: 0 14px;
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #1e293b;
            cursor: pointer;
            transition: background-color 0.15s ease, color 0.15s ease;
            border-bottom: 1px solid #f1f5f9;
            box-sizing: border-box;
        }

        .prof-item:last-child {
            border-bottom: none;
        }

        .prof-item:hover,
        .prof-item.selected,
        .prof-item.highlighted {
            background-color: #eff6ff;
            color: #1e3a8a;
            font-weight: 600;
        }

        .prof-no-match {
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: #94a3b8;
            font-style: italic;
            background: #f8fafc;
            box-sizing: border-box;
        }

        .btn-container {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background-color: #1e3a8a;
            color: white;
        }

        .btn-primary:hover {
            background-color: #172554;
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }
    </style>
</head>

<body>

    <div class="form-container">
        <div class="form-header">
            <h2>Add New Student</h2>
            <p>Fill in the required information below.</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div style="background-color: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px;">
                <?= h($error_message) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="form-group">
                <label for="fullname">Student Name</label>
                <input type="text" id="fullname" name="fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required value="<?= h($_POST['fullname'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="school_no">Student ID / Student Number <span style="font-size: 11px; font-weight: 400; color: #64748b;">(Auto-generated)</span></label>
                <input type="text" id="school_no" name="school_no" class="form-control" readonly
                    value="<?= h($next_student_no) ?>"
                    style="background-color: #f1f5f9; color: #475569; cursor: not-allowed; font-weight: 600; letter-spacing: 0.04em;"
                    title="This number is automatically assigned by the system">
            </div>

            <div class="form-group">
                <label for="password">Student Password</label>
                <div class="password-wrapper">
                    <input type="password" id="studentPassword" name="password" class="form-control" placeholder="Assign initial password" required>
                    <button type="button" class="password-toggle-eye" id="togglePwdBtn" onclick="togglePasswordVisibility()" aria-label="Show password" title="Show password">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="year_level">Year Level</label>
                <select id="year_level" name="year_level" class="form-control" required>
                    <option value="" disabled <?= empty($_POST['year_level']) ? 'selected' : '' ?>>Select Year Level</option>
                    <option value="1st Year" <?= (($_POST['year_level'] ?? '') === '1st Year') ? 'selected' : '' ?>>1st Year</option>
                    <option value="2nd Year" <?= (($_POST['year_level'] ?? '') === '2nd Year') ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3rd Year" <?= (($_POST['year_level'] ?? '') === '3rd Year') ? 'selected' : '' ?>>3rd Year</option>
                    <option value="4th Year" <?= (($_POST['year_level'] ?? '') === '4th Year') ? 'selected' : '' ?>>4th Year</option>
                </select>
            </div>

            <!-- SECTION LIMIT: ONLY 5 SECTIONS -->
            <div class="form-group">
                <label for="section">Section (5 Sections Available)</label>
                <select id="section" name="section" class="form-control" required>
                    <option value="" disabled <?= empty($_POST['section']) ? 'selected' : '' ?>>Select Section</option>
                    <?php foreach ($available_sections as $sec): 
                        $cnt = $section_counts[$sec] ?? 0;
                        $isFull = ($cnt >= $max_capacity);
                        $label = $sec . ' (' . $cnt . '/' . $max_capacity . ($isFull ? ' - FULL' : '') . ')';
                    ?>
                        <option value="<?= h($sec) ?>" <?= $isFull ? 'disabled style="color: #94a3b8; background-color: #f8fafc;"' : '' ?> <?= ((($_POST['section'] ?? '') === $sec) && !$isFull) ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender" name="gender" class="form-control" required>
                    <option value="" disabled <?= empty($_POST['gender']) ? 'selected' : '' ?>>Select Gender</option>
                    <option value="Male" <?= (($_POST['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                </select>
            </div>

            <!-- PROFESSOR ASSIGNMENT -->
            <div class="form-group">
                <label for="advisor_search">Assigned Professor <span style="color: #ef4444;">*</span></label>
                <?php if ($user['role'] === 'Admin'): ?>
                    <?php
                    $selected_advisor_id = (int)($_POST['advisor_id'] ?? 0);
                    $selected_advisor_name = trim($_POST['advisor_search'] ?? '');
                    if ($selected_advisor_id > 0 && empty($selected_advisor_name)) {
                        foreach ($professors as $p) {
                            if ((int)$p['id'] === $selected_advisor_id) {
                                $selected_advisor_name = $p['full_name'];
                                break;
                            }
                        }
                    }
                    ?>
                    <div class="prof-combobox-wrapper" id="profCombobox">
                        <input type="hidden" name="advisor_id" id="advisor_id" value="<?= $selected_advisor_id > 0 ? $selected_advisor_id : '' ?>">
                        <input 
                            type="text" 
                            id="advisor_search" 
                            name="advisor_search" 
                            class="form-control" 
                            placeholder="Type or select a professor name..." 
                            autocomplete="off" 
                            required 
                            value="<?= h($selected_advisor_name) ?>"
                        >
                        <div id="profDropdown" class="prof-dropdown" style="display: none;">
                            <div id="profList" class="prof-list"></div>
                            <div id="profNoMatch" class="prof-no-match" style="display: none;">No professor found</div>
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="advisor_id" value="<?= (int)$user['id'] ?>">
                    <input type="text" class="form-control" value="<?= h($user['full_name']) ?> (You)" readonly style="background: #f1f5f9; cursor: not-allowed;">
                <?php endif; ?>
            </div>

            <div class="btn-container">
                <a href="students.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Student</button>
            </div>
        </form>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('studentPassword');
            const btn = document.getElementById('togglePwdBtn');
            if (!passwordInput || !btn) return;

            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';

            const eyeOpen = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
            const eyeSlash = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

            btn.innerHTML = isPassword ? eyeSlash : eyeOpen;
            btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            btn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
        }

        // Searchable Professor Combobox with 3-row scrollable dropdown
        (function() {
            const PROFESSORS = <?= json_encode($professors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
            const combobox = document.getElementById('profCombobox');
            const searchInput = document.getElementById('advisor_search');
            const hiddenIdInput = document.getElementById('advisor_id');
            const dropdown = document.getElementById('profDropdown');
            const listContainer = document.getElementById('profList');
            const noMatch = document.getElementById('profNoMatch');

            if (!searchInput || !dropdown || !listContainer) return;

            let highlightedIndex = -1;
            let currentMatches = [];

            function cleanQuery(str) {
                return str.toLowerCase().replace(/^prof\.?\s*/i, '').trim();
            }

            function filterAndRankProfessors(query) {
                const rawQ = query.toLowerCase().trim();
                const q = cleanQuery(rawQ);

                if (!rawQ) {
                    return PROFESSORS.map(p => ({ ...p, score: 0 }));
                }

                const results = [];
                for (let i = 0; i < PROFESSORS.length; i++) {
                    const prof = PROFESSORS[i];
                    const name = prof.full_name.toLowerCase();
                    const username = (prof.username || '').toLowerCase();

                    if (name === rawQ || name === q) {
                        results.push({ ...prof, score: 100 });
                    } else if (name.startsWith(rawQ) || name.startsWith(q)) {
                        results.push({ ...prof, score: 80 });
                    } else if (name.includes(rawQ) || name.includes(q)) {
                        results.push({ ...prof, score: 60 });
                    } else if (username && (username.startsWith(q) || username.includes(q))) {
                        results.push({ ...prof, score: 40 });
                    } else {
                        const parts = name.split(/\s+/);
                        const matchPart = parts.some(p => p.startsWith(q) || p.includes(q));
                        if (matchPart) {
                            results.push({ ...prof, score: 50 });
                        }
                    }
                }

                // Move matching professors automatically to the top
                results.sort((a, b) => {
                    if (b.score !== a.score) return b.score - a.score;
                    return a.full_name.localeCompare(b.full_name);
                });

                return results;
            }

            function renderDropdown(query = '') {
                currentMatches = filterAndRankProfessors(query);
                listContainer.innerHTML = '';
                highlightedIndex = -1;

                if (currentMatches.length === 0) {
                    listContainer.style.display = 'none';
                    noMatch.style.display = 'flex';
                } else {
                    noMatch.style.display = 'none';
                    listContainer.style.display = 'block';

                    currentMatches.forEach((prof, idx) => {
                        const row = document.createElement('div');
                        row.className = 'prof-item';
                        row.dataset.id = prof.id;
                        row.dataset.name = prof.full_name;
                        row.dataset.index = idx;
                        row.textContent = prof.full_name;

                        if (String(hiddenIdInput.value) === String(prof.id) || searchInput.value.trim().toLowerCase() === prof.full_name.toLowerCase()) {
                            row.classList.add('selected');
                        }

                        row.addEventListener('mousedown', (e) => {
                            e.preventDefault(); // Prevent input blur before click is handled
                            selectProfessor(prof);
                        });

                        listContainer.appendChild(row);
                    });
                }

                dropdown.style.display = 'block';
            }

            function selectProfessor(prof) {
                searchInput.value = prof.full_name;
                hiddenIdInput.value = prof.id;
                closeDropdown();
            }

            function syncHiddenId() {
                const currentVal = searchInput.value.trim().toLowerCase();
                const cleanVal = cleanQuery(currentVal);
                const exact = PROFESSORS.find(p => p.full_name.toLowerCase() === currentVal || p.full_name.toLowerCase() === cleanVal);
                if (exact) {
                    hiddenIdInput.value = exact.id;
                } else {
                    const matches = filterAndRankProfessors(searchInput.value);
                    if (matches.length === 1) {
                        hiddenIdInput.value = matches[0].id;
                    } else if (matches.length > 0 && matches[0].score >= 60) {
                        hiddenIdInput.value = matches[0].id;
                    } else {
                        hiddenIdInput.value = '';
                    }
                }
            }

            function openDropdown() {
                renderDropdown(searchInput.value);
            }

            function closeDropdown() {
                dropdown.style.display = 'none';
                highlightedIndex = -1;
            }

            // Real-time search/filtering as the user types
            searchInput.addEventListener('input', () => {
                renderDropdown(searchInput.value);
                syncHiddenId();
            });

            // Focus and click open dropdown
            searchInput.addEventListener('focus', () => {
                openDropdown();
            });

            searchInput.addEventListener('click', () => {
                openDropdown();
            });

            // Keyboard navigation
            searchInput.addEventListener('keydown', (e) => {
                const items = listContainer.querySelectorAll('.prof-item');
                if (dropdown.style.display === 'none' && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                    openDropdown();
                    e.preventDefault();
                    return;
                }

                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIndex = (highlightedIndex + 1) % items.length;
                    updateHighlight(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIndex = (highlightedIndex - 1 + items.length) % items.length;
                    updateHighlight(items);
                } else if (e.key === 'Enter') {
                    if (highlightedIndex >= 0 && highlightedIndex < currentMatches.length) {
                        e.preventDefault();
                        selectProfessor(currentMatches[highlightedIndex]);
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown();
                }
            });

            function updateHighlight(items) {
                items.forEach((it, idx) => {
                    if (idx === highlightedIndex) {
                        it.classList.add('highlighted');
                        it.scrollIntoView({ block: 'nearest' });
                    } else {
                        it.classList.remove('highlighted');
                    }
                });
            }

            // Outside click closes dropdown
            document.addEventListener('click', (e) => {
                if (combobox && !combobox.contains(e.target)) {
                    closeDropdown();
                    syncHiddenId();
                }
            });
        })();
    </script>
</body>

</html>