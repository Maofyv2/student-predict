<?php
if (!defined('E_DEPRECATED')) {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    error_reporting(E_ALL & ~E_NOTICE);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'student_prediction_system');
define('API_BASE_URL', 'https://student-prediction-system-xn6k.onrender.com');

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function db() {
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $conn->set_charset('utf8mb4');$conn->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $conn->select_db(DB_NAME);
    ensure_schema($conn);
    return $conn;
}

function ensure_schema($conn) {
    static $ready = false;
    if ($ready) {
        return;
    }

    $statements = array(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('Admin','Faculty','Advisor') NOT NULL DEFAULT 'Advisor',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_professors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(160) NOT NULL,
            department VARCHAR(100) NOT NULL,
            email VARCHAR(120) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_no VARCHAR(40) NOT NULL UNIQUE,
            full_name VARCHAR(160) NOT NULL,
            year_level VARCHAR(30) NOT NULL,
            section VARCHAR(60) NOT NULL,
            gender VARCHAR(30) DEFAULT '',
            household_income DECIMAL(10,2) NOT NULL DEFAULT 0,
            parental_education TINYINT NOT NULL DEFAULT 1,
            scholarship_status VARCHAR(60) DEFAULT 'None',
            working_student TINYINT(1) NOT NULL DEFAULT 0,
            advisor_id INT NULL,
            password_hash VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_student_advisor FOREIGN KEY (advisor_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_surveys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            internet_access TINYINT(1) NOT NULL,
            digital_literacy TINYINT NOT NULL,
            device_availability VARCHAR(80) NOT NULL,
            study_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_survey_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_academic_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            semester VARCHAR(30) NOT NULL,
            prelim_grade DECIMAL(5,2) NOT NULL,
            midterm_grade DECIMAL(5,2) NOT NULL,
            semi_final_grade DECIMAL(5,2) DEFAULT 0,
            final_grade DECIMAL(5,2) DEFAULT 0,
            attendance_rate DECIMAL(5,2) NOT NULL,
            lab_score DECIMAL(5,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_academic_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_predictions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            academic_record_id INT NULL,
            grading_period ENUM('Prelim','Midterm','Semi-Final','Final') NULL,
            predicted_status ENUM('Pass','At-Risk','Fail') NOT NULL,
            predicted_grade DECIMAL(5,2) NULL,
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0,
            recommendation TEXT NOT NULL,
            risk_factors TEXT NULL,
            missing_components TEXT NULL,
            feature_payload TEXT NOT NULL,
            model_accuracy DECIMAL(6,4) NULL,
            f1_score_log DECIMAL(6,4) NULL,
            algorithm VARCHAR(120) NOT NULL DEFAULT 'XGBoost Classification',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_prediction_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE,
            CONSTRAINT fk_prediction_academic FOREIGN KEY (academic_record_id) REFERENCES tbl_academic_records(id) ON DELETE SET NULL,
            CONSTRAINT fk_prediction_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            user_id INT NOT NULL,
            alert_type ENUM('Risk','Academic','Attendance','Student Update') NOT NULL,
            severity ENUM('Low','Medium','High','Critical','Info') NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_alert_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE,
            CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_advice (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            advisor_id INT NOT NULL,
            advice_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_advice_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE,
            CONSTRAINT fk_advice_advisor FOREIGN KEY (advisor_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_grade_components (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            student_id          INT NOT NULL,
            academic_year       VARCHAR(20) NOT NULL,
            semester            VARCHAR(30) NOT NULL,
            period              ENUM('Prelim','Midterm','Semi-Final','Final') NOT NULL,
            exam_score          DECIMAL(5,2) NULL,
            quiz_score          DECIMAL(5,2) NULL,
            activity_score      DECIMAL(5,2) NULL,
            assignment_score    DECIMAL(5,2) NULL,
            project_score       DECIMAL(5,2) NULL,
            attendance_rate     DECIMAL(5,2) NULL,
            lab_score           DECIMAL(5,2) NULL,
            computed_grade      DECIMAL(5,2) NULL,
            missing_components  TEXT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_gc_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE,
            UNIQUE KEY uq_gc_period (student_id, academic_year, semester, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tbl_grading_weights (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            period      ENUM('Prelim','Midterm','Semi-Final','Final') NOT NULL,
            component   VARCHAR(60) NOT NULL,
            weight      DECIMAL(5,2) NOT NULL DEFAULT 0,
            max_score   DECIMAL(6,2) NOT NULL DEFAULT 100,
            created_by  INT NULL,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gw_period_component (period, component)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach ($statements as $statement) {
        $conn->query($statement);
    }

    // --- Migrate tbl_predictions: add new columns if missing ---
    $predCols = $conn->query("SHOW COLUMNS FROM tbl_predictions LIKE 'grading_period'");
    if ($predCols->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_predictions ADD COLUMN grading_period ENUM('Prelim','Midterm','Semi-Final','Final') NULL AFTER academic_record_id");
        $conn->query("ALTER TABLE tbl_predictions ADD COLUMN predicted_grade DECIMAL(5,2) NULL AFTER predicted_status");
        $conn->query("ALTER TABLE tbl_predictions ADD COLUMN missing_components TEXT NULL AFTER risk_factors");
    }

    $cols = $conn->query("SHOW COLUMNS FROM tbl_academic_records LIKE 'semi_final_grade'");
    if ($cols->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_academic_records ADD COLUMN semi_final_grade DECIMAL(5,2) DEFAULT 0 AFTER midterm_grade");
        $conn->query("ALTER TABLE tbl_academic_records ADD COLUMN final_grade DECIMAL(5,2) DEFAULT 0 AFTER semi_final_grade");
    }

    // --- Migrate tbl_grading_weights: add max_score if missing ---
    $gwCols = $conn->query("SHOW COLUMNS FROM tbl_grading_weights LIKE 'max_score'");
    if ($gwCols->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_grading_weights ADD COLUMN max_score DECIMAL(6,2) NOT NULL DEFAULT 100 AFTER weight");
    }

    // --- Migrate tbl_grade_components: add scores_json if missing ---
    $gcCols = $conn->query("SHOW COLUMNS FROM tbl_grade_components LIKE 'scores_json'");
    if ($gcCols->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_grade_components ADD COLUMN scores_json TEXT NULL AFTER missing_components");
    }

    // --- Migrate tbl_surveys: add household_income, parental_education, working_student if missing ---
    $survCols = $conn->query("SHOW COLUMNS FROM tbl_surveys LIKE 'household_income'");
    if ($survCols->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_surveys ADD COLUMN household_income DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER study_hours");
    }
    $survCols2 = $conn->query("SHOW COLUMNS FROM tbl_surveys LIKE 'parental_education'");
    if ($survCols2->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_surveys ADD COLUMN parental_education TINYINT NOT NULL DEFAULT 1 AFTER household_income");
    }
    $survCols3 = $conn->query("SHOW COLUMNS FROM tbl_surveys LIKE 'working_student'");
    if ($survCols3->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_surveys ADD COLUMN working_student TINYINT(1) NOT NULL DEFAULT 0 AFTER parental_education");
    }

    // --- Migrate tbl_students: add professor_id if missing and sync with advisor_id ---
    $stuProfCol = $conn->query("SHOW COLUMNS FROM tbl_students LIKE 'professor_id'");
    if ($stuProfCol->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_students ADD COLUMN professor_id INT NULL AFTER advisor_id");
        $conn->query("UPDATE tbl_students SET professor_id = advisor_id WHERE advisor_id IS NOT NULL");
    }

    // --- Migrate tbl_professors: add user_id if missing ---
    $profUserCol = $conn->query("SHOW COLUMNS FROM tbl_professors LIKE 'user_id'");
    if ($profUserCol->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_professors ADD COLUMN user_id INT NULL AFTER email");
    }

    seed_users($conn);
    seed_grading_weights($conn);
    $ready = true;
}

function seed_users($conn) {
    $res =$conn->query('SELECT COUNT(*) AS total FROM users');
    $row =$res->fetch_assoc();
    $count = (int)$row['total'];
    if ($count > 0) {
        return;
    }

    $users = array(
        array('System Administrator', 'admin', 'admin123', 'Admin'),
        array('Academic Advisor', 'advisor', 'advisor123', 'Advisor')
    );

    $stmt =$conn->prepare('INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)');
    foreach ($users as$u) {
        $name =$u[0];
        $username =$u[1];
        $password =$u[2];
        $role =$u[3];
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bind_param('ssss',$name, $username,$hash, $role);$stmt->execute();
    }
}

function seed_grading_weights($conn) {
    $res = $conn->query('SELECT COUNT(*) AS total FROM tbl_grading_weights');
    $row = $res->fetch_assoc();
    if ((int)$row['total'] > 0) return;

    // [period, component, weight%, max_score]
    $defaults = array(
        array('Prelim',     'Exam',       30, 100),
        array('Prelim',     'Quiz',       20,  50),
        array('Prelim',     'Activities', 15,  50),
        array('Prelim',     'Assignment', 15,  50),
        array('Prelim',     'Project',    20, 100),
        array('Midterm',    'Exam',       30, 100),
        array('Midterm',    'Quiz',       20,  50),
        array('Midterm',    'Activities', 15,  50),
        array('Midterm',    'Assignment', 15,  50),
        array('Midterm',    'Project',    20, 100),
        array('Semi-Final', 'Exam',       30, 100),
        array('Semi-Final', 'Quiz',       20,  50),
        array('Semi-Final', 'Activities', 15,  50),
        array('Semi-Final', 'Assignment', 15,  50),
        array('Semi-Final', 'Project',    20, 100),
        array('Final',      'Exam',       30, 100),
        array('Final',      'Quiz',       20,  50),
        array('Final',      'Activities', 15,  50),
        array('Final',      'Assignment', 15,  50),
        array('Final',      'Project',    20, 100),
    );
    $stmt = $conn->prepare('INSERT IGNORE INTO tbl_grading_weights (period, component, weight, max_score) VALUES (?, ?, ?, ?)');
    foreach ($defaults as $d) {
        $p = $d[0]; $c = $d[1]; $w = (float)$d[2]; $m = (float)$d[3];
        $stmt->bind_param('ssdd', $p, $c, $w, $m);
        $stmt->execute();
    }
}

/**
 * Get grading weights for a period.
 * Returns ['Exam' => ['weight'=>30,'max_score'=>100], ...]
 */
function get_grading_weights(string $period): array {
    $stmt = db()->prepare('SELECT component, weight, max_score FROM tbl_grading_weights WHERE period = ? ORDER BY id ASC');
    $stmt->bind_param('s', $period);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result = array();
    foreach ($rows as $r) {
        $result[$r['component']] = array(
            'weight'    => (float)$r['weight'],
            'max_score' => (float)($r['max_score'] ?: 100),
        );
    }
    return $result;
}

/**
 * Get grading weights only (no max_score) — used for JS payload.
 * Returns ['Exam'=>30, 'Quiz'=>20, ...] per period.
 */
function get_all_grading_weights(): array {
    $rows = db()->query('SELECT period, component, weight, max_score FROM tbl_grading_weights ORDER BY period, id')->fetch_all(MYSQLI_ASSOC);
    $result = array();
    foreach ($rows as $r) {
        $result[$r['period']][$r['component']] = array(
            'weight'    => (float)$r['weight'],
            'max_score' => (float)($r['max_score'] ?: 100),
        );
    }
    return $result;
}

/**
 * Enforce role-based access. Redirects to dashboard if not allowed.
 */
function require_role(array $allowed): void {
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $allowed, true)) {
        header('Location: dashboard.php?denied=1');
        exit;
    }
}

/**
 * Compute the weighted grade from raw component scores.
 * Each weight entry: ['weight'=>30, 'max_score'=>100]
 * Formula: (raw_score / max_score) * weight, then rescale to 100% from available weight.
 * Returns [grade, missingList, present] or null if insufficient data.
 */
function compute_weighted_grade(array $components, array $weights, int $minRequired = 1): ?array {
    $totalWeight   = 0;
    $weightedScore = 0;
    $missing       = array();
    $present       = 0;

    foreach ($weights as $name => $cfg) {
        // Support both old format (scalar weight) and new format (['weight'=>x,'max_score'=>y])
        if (is_array($cfg)) {
            $weight   = (float)$cfg['weight'];
            $maxScore = (float)($cfg['max_score'] ?: 100);
        } else {
            $weight   = (float)$cfg;
            $maxScore = 100.0;
        }

        $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
        $val = null;

        if (isset($components[$name]) && $components[$name] !== null && $components[$name] !== '') {
            $val = $components[$name];
        } elseif (isset($components[$cleanName]) && $components[$cleanName] !== null && $components[$cleanName] !== '') {
            $val = $components[$cleanName];
        } elseif (isset($components[$cleanName . '_score']) && $components[$cleanName . '_score'] !== null && $components[$cleanName . '_score'] !== '') {
            $val = $components[$cleanName . '_score'];
        } elseif ($cleanName === 'activities' && isset($components['activity_score']) && $components['activity_score'] !== null && $components['activity_score'] !== '') {
            $val = $components['activity_score'];
        } elseif ($cleanName === 'attendance' && isset($components['attendance_rate']) && $components['attendance_rate'] !== null && $components['attendance_rate'] !== '') {
            $val = $components['attendance_rate'];
        } elseif ($cleanName === 'lab' && isset($components['lab_score']) && $components['lab_score'] !== null && $components['lab_score'] !== '') {
            $val = $components['lab_score'];
        }

        if ($val === null) {
            $missing[] = $name;
        } else {
            $rawScore      = (float)$val;
            // Normalize: convert raw to percentage of max_score, then apply weight
            $normalizedPct = ($maxScore > 0) ? ($rawScore / $maxScore) * 100.0 : 0;
            $totalWeight   += $weight;
            $weightedScore += $normalizedPct * ($weight / 100.0);
            $present++;
        }
    }

    if ($present < $minRequired) {
        return null;
    }

    // Rescale to 100% based on available weight
    $grade = ($totalWeight > 0) ? ($weightedScore / $totalWeight) * 100 : 0;
    return array('grade' => round($grade, 2), 'missing' => $missing, 'present' => $present);
}

/**
 * Save or update grade components for a student/period.
 */
function save_grade_components(array $data): bool {
    $stmt = db()->prepare(
        'INSERT INTO tbl_grade_components
            (student_id, academic_year, semester, period, exam_score, quiz_score, activity_score,
             assignment_score, project_score, attendance_rate, lab_score, computed_grade, missing_components)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            exam_score         = VALUES(exam_score),
            quiz_score         = VALUES(quiz_score),
            activity_score     = VALUES(activity_score),
            assignment_score   = VALUES(assignment_score),
            project_score      = VALUES(project_score),
            attendance_rate    = VALUES(attendance_rate),
            lab_score          = VALUES(lab_score),
            computed_grade     = VALUES(computed_grade),
            missing_components = VALUES(missing_components),
            updated_at         = CURRENT_TIMESTAMP'
    );
    $stmt->bind_param(
        'isssdddddddd s',
        $data['student_id'],
        $data['academic_year'],
        $data['semester'],
        $data['period'],
        $data['exam_score'],
        $data['quiz_score'],
        $data['activity_score'],
        $data['assignment_score'],
        $data['project_score'],
        $data['attendance_rate'],
        $data['lab_score'],
        $data['computed_grade'],
        $data['missing_components']
    );
    return $stmt->execute();
}

/**
 * Get the actual (completed) period grades for a student.
 * Returns ['Prelim'=>85.5, 'Midterm'=>87.0, ...] for periods with real data.
 */
function get_student_period_grades(int $studentId, string $academicYear, string $semester): array {
    $stmt = db()->prepare(
        'SELECT period, computed_grade FROM tbl_grade_components
         WHERE student_id = ? AND academic_year = ? AND semester = ?
         AND computed_grade IS NOT NULL'
    );
    $stmt->bind_param('iss', $studentId, $academicYear, $semester);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result = array();
    foreach ($rows as $r) {
        $result[$r['period']] = (float)$r['computed_grade'];
    }
    return $result;
}

/**
 * Get all predictions for a student, optionally filtered by academic year/semester.
 */
function get_student_predictions(int $studentId, ?string $academicYear = null, ?string $semester = null): array {
    $sql = 'SELECT p.*, u.full_name as created_by_name
            FROM tbl_predictions p
            LEFT JOIN users u ON u.id = p.created_by
            WHERE p.student_id = ?';
    $params = array($studentId);
    $types  = 'i';
    if ($academicYear) { $sql .= ' AND p.feature_payload LIKE ?'; $params[] = '%' . $academicYear . '%'; $types .= 's'; }
    $sql .= ' ORDER BY p.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get predictions grouped by grading_period for a student.
 */
function get_predictions_by_period(int $studentId, string $academicYear, string $semester): array {
    $stmt = db()->prepare(
        'SELECT grading_period, predicted_grade, predicted_status, confidence, created_at
         FROM tbl_predictions
         WHERE student_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result = array();
    foreach ($rows as $r) {
        $period = $r['grading_period'];
        if ($period && !isset($result[$period])) {
            $result[$period] = $r; // latest prediction per period
        }
    }
    return $result;
}

function current_user() {
    return isset($_SESSION['user']) ?$_SESSION['user'] : null;
}

function require_login() {
    if (!current_user()) {
        header('Location: index.php');
        exit;
    }
}

function redirect_to($path) {
    header('Location: ' . $path);
    exit;
}

function api_request_local(string $method, string $path, ?array $payload = null): array {
    $localUrl = 'http://127.0.0.1:5000' . $path;
    $ch = curl_init($localUrl);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 10,
    ));
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    }
    $raw    = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $error) {
        return array('ok' => false, 'status' => 0, 'error' => $error ?: 'Unable to reach local Flask API.');
    }
    $data = json_decode($raw, true);
    if ($status >= 400) {
        return array('ok' => false, 'status' => $status, 'error' => $data['error'] ?? 'API request failed.');
    }
    return array('ok' => true, 'status' => $status, 'data' => $data);
}

function api_request($method, $path,$payload = null) {
    $ch = curl_init(API_BASE_URL .$path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
    ));

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error) {
        return array('ok' => false, 'status' => 0, 'error' => $error ? $error : 'Unable to reach Flask API.');
    }

    $data = json_decode($raw, true);
    if ($status >= 400) {$err_msg = isset($data['error']) ?$data['error'] : 'API request failed.';
        return array('ok' => false, 'status' => $status, 'error' =>$err_msg);
    }

    return array('ok' => true, 'status' => $status, 'data' =>$data);
}

function model_metadata() {
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'model_metadata.json';
    if (!is_file($path)) {
        return array();
    }

    $json = json_decode((string) file_get_contents($path), true);
    return is_array($json) ?$json : array();
}

function status_class($status) {
    switch ($status) {
        case 'Pass':
            return 'status-pass';
        case 'At-Risk':
            return 'status-risk';
        case 'Fail':
            return 'status-fail';
        default:
            return 'status-muted';
    }
}

function severity_class($severity) {
    switch ($severity) {
        case 'Low':
        case 'Info':
            return 'status-muted';
        case 'Medium':
            return 'status-pass';
        case 'High':
            return 'status-risk';
        case 'Critical':
            return 'status-fail';
        default:
            return 'status-muted';
    }
}

function create_alert($student_id,$user_id, $type,$severity, $message) {$stmt = db()->prepare("INSERT INTO tbl_alerts (student_id, user_id, alert_type, severity, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisss',$student_id, $user_id,$type, $severity,$message);
    return $stmt->execute();
}

function get_alerts($user_id, $only_unread = false) {$sql = "SELECT a.*, s.full_name, s.student_no FROM tbl_alerts a 
            JOIN tbl_students s ON a.student_id = s.id 
            WHERE a.user_id = ?";
    if ($only_unread) {$sql .= " AND a.is_read = 0";
    }
    $sql .= " ORDER BY a.created_at DESC";
    
    $stmt = db()->prepare($sql);$stmt->bind_param('i', $user_id);$stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_professors() {
    $result = db()->query("SELECT * FROM tbl_professors ORDER BY full_name ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : array();
}

function add_professor(string $full_name, string $department, string $email, string $username = '', string $password = ''): bool
{
    $conn = db();
    try {
        $userId = null;
        if ($username !== '' && $password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'Advisor';
            $stmtUser = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
            $stmtUser->bind_param('ssss', $full_name, $username, $hash, $role);
            $stmtUser->execute();

            $chkU = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chkU->bind_param('s', $username);
            $chkU->execute();
            $uRow = $chkU->get_result()->fetch_assoc();
            $userId = $uRow['id'] ?? null;
        }

        $stmt = $conn->prepare("INSERT INTO tbl_professors (full_name, department, email, user_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $full_name, $department, $email, $userId);
        return $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function update_professor(int $professor_id, string $full_name, string $department, string $email, string $username = '', string $new_password = ''): bool
{
    $conn = db();
    try {
        $stmtProf = $conn->prepare("SELECT user_id, email, full_name FROM tbl_professors WHERE id = ?");
        $stmtProf->bind_param('i', $professor_id);
        $stmtProf->execute();
        $prof = $stmtProf->get_result()->fetch_assoc();
        if (!$prof) return false;

        $userId = $prof['user_id'];

        if (!$userId) {
            $stmtFind = $conn->prepare("SELECT id FROM users WHERE full_name = ? OR username = ? LIMIT 1");
            $stmtFind->bind_param('ss', $prof['full_name'], $username);
            $stmtFind->execute();
            $foundUser = $stmtFind->get_result()->fetch_assoc();
            $userId = $foundUser['id'] ?? null;
        }

        $stmtUpProf = $conn->prepare("UPDATE tbl_professors SET full_name = ?, department = ?, email = ?, user_id = ? WHERE id = ?");
        $stmtUpProf->bind_param('sssii', $full_name, $department, $email, $userId, $professor_id);
        $stmtUpProf->execute();

        if ($userId) {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmtUpUser = $conn->prepare("UPDATE users SET full_name = ?, username = ?, password_hash = ? WHERE id = ?");
                $stmtUpUser->bind_param('sssi', $full_name, $username, $hash, $userId);
            } else {
                $stmtUpUser = $conn->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
                $stmtUpUser->bind_param('ssi', $full_name, $username, $userId);
            }
            $stmtUpUser->execute();
        } elseif ($username !== '' && $new_password !== '') {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $role = 'Advisor';
            $stmtIns = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)");
            $stmtIns->bind_param('ssss', $full_name, $username, $hash, $role);
            $stmtIns->execute();
            $newUid = (int)$stmtIns->insert_id;
            $conn->query("UPDATE tbl_professors SET user_id = {$newUid} WHERE id = {$professor_id}");
        }

        return true;
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function page_header($title) {
    $user = current_user();
    $student = $_SESSION['student'] ?? null;
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');

    $unread_count = 0;
    if ($user) {
        $current_user_id = (int) $user['id'];
        $stmtNotif = db()->prepare(
            "SELECT COUNT(*) AS total 
             FROM tbl_alerts 
             WHERE user_id = ? 
               AND (alert_type = 'Student Update' OR message LIKE '%updated their self-assessment profile%') 
               AND is_read = 0"
        );
        $stmtNotif->bind_param('i', $current_user_id);
        $stmtNotif->execute();
        $resNotif = $stmtNotif->get_result()->fetch_assoc();
        $unread_count = isset($resNotif['total']) ? (int)$resNotif['total'] : 0;
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> | Arellano BSIT Prediction System</title>
        <link rel="stylesheet" href="assets.css?v=<?= filemtime(__DIR__ . '/assets.css') ?>">
    </head>
    <body>
    <div class="app-layout" id="appLayout">
        <!-- Collapsible Left Sidebar (Mini-Variant Drawer) -->
        <aside class="app-sidebar" id="appSidebar" aria-label="Main Navigation">
            <div class="sidebar-header">
                <a class="brand" href="<?= $student ? 'student_portal.php' : 'dashboard.php' ?>" aria-label="Home" title="Arellano BSIT Analytics">
                    <span class="brand-mark">
                       <img src="au.png" alt="Arellano University Logo" class="brand-logo">
                    </span>
                    <span class="brand-text">
                        <strong>BSIT Analytics</strong>
                        <small>Arellano University</small>
                    </span>
                </a>
            </div>

            <div class="sidebar-body">
                <div class="sidebar-section-title">Navigation</div>
                <nav class="sidebar-nav">
                    <?php if ($user): ?>
                        <a href="dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" title="Dashboard">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            </span>
                            <span class="nav-label">Dashboard</span>
                        </a>
                        <a href="students.php" class="<?= ($currentPage === 'students.php' || $currentPage === 'add_student.php') ? 'active' : '' ?>" title="Students">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                            <span class="nav-label">Students</span>
                        </a>
                        <a href="reports.php" class="<?= $currentPage === 'reports.php' ? 'active' : '' ?>" title="Reports">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </span>
                            <span class="nav-label">Reports</span>
                        </a>
                        <a href="alerts.php" class="<?= $currentPage === 'alerts.php' ? 'active' : '' ?>" title="Alerts">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h24s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                            </span>
                            <span class="nav-label">Alerts</span>
                        </a>

                        <?php if ($user['role'] === 'Advisor'): ?>
                            <div class="sidebar-section-title">Advisor Tools</div>
                            <a href="enter_scores.php" class="<?= $currentPage === 'enter_scores.php' ? 'active' : '' ?>" title="Enter Scores">
                                <span class="nav-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                </span>
                                <span class="nav-label">Enter Scores</span>
                            </a>
                            <a href="predictions.php" class="<?= $currentPage === 'predictions.php' ? 'active' : '' ?>" title="Predictions">
                                <span class="nav-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                </span>
                                <span class="nav-label">Predictions</span>
                            </a>
                            <a href="grading_config.php" class="<?= $currentPage === 'grading_config.php' ? 'active' : '' ?>" title="Grading Criteria">
                                <span class="nav-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                                </span>
                                <span class="nav-label">Grading Criteria</span>
                            </a>
                            <a href="scholarships.php" class="<?= $currentPage === 'scholarships.php' ? 'active' : '' ?>" title="Scholarships">
                                <span class="nav-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                                </span>
                                <span class="nav-label">Scholarships</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($user['role'] === 'Admin'): ?>
                            <div class="sidebar-section-title">Admin Management</div>
                            <a href="grading_config.php" class="<?= $currentPage === 'grading_config.php' ? 'active' : '' ?>" title="Grading Criteria">
                                <span class="nav-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                                </span>
                                <span class="nav-label">Grading Criteria</span>
                            </a>
                            <a href="professors.php" class="<?= $currentPage === 'professors.php' ? 'active' : '' ?>" title="Professors">
                                <span class="nav-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                </span>
                                <span class="nav-label">Professors</span>
                            </a>
                        <?php endif; ?>
                    <?php elseif ($student): ?>
                        <a href="student_portal.php" class="<?= $currentPage === 'student_portal.php' ? 'active' : '' ?>" title="Student Portal">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            <span class="nav-label">Student Portal</span>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <div class="sidebar-footer">
                <?php if ($user): ?>
                    <div class="sidebar-user" title="<?= h($user['full_name']) ?> (<?= h($user['role']) ?>)">
                        <div class="user-avatar-circle">
                            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                        </div>
                        <div class="user-details">
                            <span class="user-name"><?= h($user['full_name']) ?></span>
                            <span class="role-pill role-<?= strtolower($user['role']) ?>"><?= h($user['role']) ?></span>
                        </div>
                    </div>
                <?php elseif ($student): ?>
                    <div class="sidebar-user" title="<?= h($student['full_name']) ?> (Student)">
                        <div class="user-avatar-circle">
                            <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
                        </div>
                        <div class="user-details">
                            <span class="user-name"><?= h($student['full_name']) ?></span>
                            <span class="role-pill">Student</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Mobile overlay backdrop -->
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

        <!-- Main Content Area -->
        <div class="app-main-wrap">
            <header class="top-utility-bar">
                <div class="topbar-left">
                    <button type="button" class="topbar-hamburger-btn" id="topbarToggle" aria-label="Toggle navigation menu" title="Toggle menu">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                        </svg>
                    </button>
                    <span class="topbar-context-title"><?= h($title) ?></span>
                </div>

                <div class="topbar-right">
                    <?php if ($user): ?>
                        <a href="notifications.php" class="nav-notifications <?= $currentPage === 'notifications.php' ? 'active' : '' ?>" title="Student Assessment Updates">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h24s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                            <span>Notifications</span>
                            <?php if ($unread_count > 0): ?>
                                <span class="badge-count"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="button button-outline" href="logout.php">Logout</a>
                    <?php elseif ($student): ?>
                        <a class="button button-outline" href="logout.php">Logout</a>
                    <?php endif; ?>
                </div>
            </header>

            <main class="page">
    <?php
}

function page_footer() {
    ?>
            </main>
        </div>
    </div>

    <script>
    (function() {
        const layout = document.getElementById('appLayout');
        const topbarToggle = document.getElementById('topbarToggle');
        const backdrop = document.getElementById('sidebarBackdrop');

        if (!layout) return;

        // Restore persisted mini-variant state on desktop
        const isMini = localStorage.getItem('sidebar_mini') === 'true';
        if (window.innerWidth >= 992 && isMini) {
            layout.classList.add('sidebar-mini');
        }

        function toggleSidebar() {
            if (window.innerWidth < 992) {
                // Mobile: toggle open/close
                layout.classList.toggle('sidebar-mobile-open');
            } else {
                // Desktop: toggle mini-variant drawer (liliit lang ito ngunit hindi tuluyang mawawala)
                const mini = layout.classList.toggle('sidebar-mini');
                localStorage.setItem('sidebar_mini', mini ? 'true' : 'false');
            }
        }

        if (topbarToggle) topbarToggle.addEventListener('click', toggleSidebar);

        if (backdrop) {
            backdrop.addEventListener('click', function() {
                layout.classList.remove('sidebar-mobile-open');
            });
        }
    })();
    </script>
    </body>
    </html>
    <?php
}

function latest_predictions($limit = 8, $advisor_id = null) {
    $sql = "SELECT p.*, s.student_no, s.full_name, s.year_level, s.section
            FROM tbl_predictions p
            INNER JOIN tbl_students s ON s.id = p.student_id";
    
    if ($advisor_id !== null) {
        $sql .= " WHERE (s.advisor_id = ? OR s.professor_id = ?)";
    }
    
    $sql .= " ORDER BY p.created_at DESC LIMIT ?";
    
    $stmt = db()->prepare($sql);
    if ($advisor_id !== null) {
        $stmt->bind_param('iii', $advisor_id, $advisor_id, $limit);
    } else {
        $stmt->bind_param('i', $limit);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function prediction_counts($advisor_id = null) {
    $counts = array('Pass' => 0, 'At-Risk' => 0, 'Fail' => 0);
    $sql = "SELECT predicted_status, COUNT(*) AS total
            FROM tbl_predictions p
            INNER JOIN (
                SELECT student_id, MAX(id) AS latest_id
                FROM tbl_predictions
                GROUP BY student_id
            ) latest ON latest.latest_id = p.id    
            INNER JOIN tbl_students s ON s.id = p.student_id";
    
    if ($advisor_id !== null) {
        $sql .= " WHERE (s.advisor_id = ? OR s.professor_id = ?)";
    }
    
    $sql .= " GROUP BY predicted_status";
    
    $stmt = db()->prepare($sql);
    if ($advisor_id !== null) {
        $stmt->bind_param('ii', $advisor_id, $advisor_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $counts[$row['predicted_status']] = (int)$row['total'];
    }
    return $counts;
}

function total_students($advisor_id = null) {
    if ($advisor_id !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) AS total FROM tbl_students WHERE (advisor_id = ? OR professor_id = ?)');
        $stmt->bind_param('ii', $advisor_id, $advisor_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }
    $res = db()->query('SELECT COUNT(*) AS total FROM tbl_students');
    return (int) $res->fetch_assoc()['total'];
}

function get_student_advice($student_id) {$stmt = db()->prepare("SELECT a.*, u.full_name as advisor_name 
                          FROM tbl_advice a 
                          JOIN users u ON a.advisor_id = u.id 
                          WHERE a.student_id = ? 
                          ORDER BY a.created_at DESC");
    $stmt->bind_param('i', $student_id);$stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}