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
define('API_BASE_URL', 'http://127.0.0.1:5000');

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
            predicted_status ENUM('Pass','At-Risk','Fail') NOT NULL,
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0,
            recommendation TEXT NOT NULL,
            risk_factors TEXT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach ($statements as$statement) {
        $conn->query($statement);
    }

    $cols =$conn->query("SHOW COLUMNS FROM tbl_academic_records LIKE 'semi_final_grade'");
    if ($cols->num_rows === 0) {$conn->query("ALTER TABLE tbl_academic_records ADD COLUMN semi_final_grade DECIMAL(5,2) DEFAULT 0 AFTER midterm_grade");
        $conn->query("ALTER TABLE tbl_academic_records ADD COLUMN final_grade DECIMAL(5,2) DEFAULT 0 AFTER semi_final_grade");
    }

    seed_users($conn);$ready = true;
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

function add_professor(string $full_name, string $department, string $email): bool
{
    try {
        $stmt = db()->prepare("INSERT INTO tbl_professors (full_name, department, email) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $full_name, $department, $email);
        return $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function page_header($title) {$user = current_user();

    $unread_count = 0;
    if ($user) {$current_user_id = (int) $user['id'];$stmtNotif = db()->prepare(
            "SELECT COUNT(*) AS total 
             FROM tbl_alerts 
             WHERE user_id = ? 
               AND (alert_type = 'Student Update' OR message LIKE '%updated their self-assessment profile%') 
               AND is_read = 0"
        );
        $stmtNotif->bind_param('i',$current_user_id);
        $stmtNotif->execute();$resNotif = $stmtNotif->get_result()->fetch_assoc();$unread_count = isset($resNotif['total']) ? (int)$resNotif['total'] : 0;
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> | Arellano BSIT Prediction System</title>
        <link rel="stylesheet" href="assets.css">
        <link rel="stylesheet" href="css.css">
    </head>
    <body>
    <header class="topbar">
        <a class="brand" href="dashboard.php" aria-label="Dashboard">
            <span class="brand-mark">
               <img src="au.png" alt="AU Logo" class="brand-logo">
            </span>
            <span>
                <strong>BSIT Prediction System</strong>
                <small>Arellano University</small>
            </span>
        </a>
        <nav class="nav">
            <a href="dashboard.php">Dashboard</a>

            <?php if ($user && ($user['role'] === 'Admin' || $user['role'] === 'Advisor')): ?>
                <a href="predictions.php">Prediction</a>
                <a href="students.php">Students</a>
                <a href="alerts.php">Alerts</a>
                <a href="reports.php">Reports</a>
            <?php endif; ?>

            <?php if ($user && $user['role'] === 'Admin'): ?>
                <a href="professors.php">Professors</a>
            <?php endif; ?>

            <?php if ($user && $user['role'] === 'Advisor'): ?>
                <a href="scholarships.php">Scholarships</a>
            <?php endif; ?>
        </nav>

        <?php if ($user): ?>
            <div class="user-menu" style="display: flex; align-items: center; gap: 1.25rem;">

                <a href="notifications.php" title="Student Assessment Updates" style="position: relative; text-decoration: none; font-size: 1.3rem; display: inline-flex; align-items: center; color: currentColor;">
                    🔔
                    <?php if ($unread_count > 0): ?>
                        <span style="position: absolute; top: -6px; right: -8px; background: #dc3545; color: #ffffff; border-radius: 50%; padding: 2px 6px; font-size: 0.65rem; font-weight: bold; line-height: 1;">
                            <?= $unread_count ?>
                        </span>
                    <?php endif; ?>
                </a>

                <span><?= h($user['full_name']) ?></span>
                <a class="button button-ghost" href="logout.php">Logout</a>
            </div>
        <?php endif; ?>
    </header>
    <main class="page">
    <?php
}

function page_footer() {
    ?>
    </main>
    </body>
    </html>
    <?php
}

function latest_predictions($limit = 8, $advisor_id = null) {$sql = "SELECT p.*, s.student_no, s.full_name, s.year_level, s.section
             FROM tbl_predictions p
             INNER JOIN tbl_students s ON s.id = p.student_id";
    
    if ($advisor_id !== null) {$sql .= " WHERE s.advisor_id = ?";
    }
    
    $sql .= " ORDER BY p.created_at DESC LIMIT ?";
    
    $stmt = db()->prepare($sql);
    if ($advisor_id !== null) {$stmt->bind_param('ii', $advisor_id,$limit);
    } else {
        $stmt->bind_param('i',$limit);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function prediction_counts($advisor_id = null) {
    $counts = array('Pass' => 0, 'At-Risk' => 0, 'Fail' => 0);$sql = "SELECT predicted_status, COUNT(*) AS total
             FROM tbl_predictions p
             INNER JOIN (
                SELECT student_id, MAX(id) AS latest_id
                FROM tbl_predictions
                GROUP BY student_id
             ) latest ON latest.latest_id = p.id    
             INNER JOIN tbl_students s ON s.id = p.student_id";
    
    if ($advisor_id !== null) {$sql .= " WHERE s.advisor_id = ?";
    }
    
    $sql .= " GROUP BY predicted_status";
    
    $stmt = db()->prepare($sql);
    if ($advisor_id !== null) {
        $stmt->bind_param('i',$advisor_id);
    }
    $stmt->execute();
    $result =$stmt->get_result();

    while ($row = $result->fetch_assoc()) {$counts[$row['predicted_status']] = (int)$row['total'];
    }
    return $counts;
}

function total_students($advisor_id = null) {
    if ($advisor_id !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) AS total FROM tbl_students WHERE advisor_id = ?');$stmt->bind_param('i', $advisor_id);$stmt->execute();
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