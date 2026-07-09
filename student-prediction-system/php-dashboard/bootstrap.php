<?php
declare(strict_types=1);

session_start();

const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'student_prediction_system';
const API_BASE_URL = 'http://127.0.0.1:5000';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $conn->set_charset('utf8mb4');
    $conn->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $conn->select_db(DB_NAME);
    ensure_schema($conn);
    return $conn;
}

function ensure_schema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('Admin','Faculty','Advisor') NOT NULL DEFAULT 'Advisor',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
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
            alert_type ENUM('Risk','Academic','Attendance') NOT NULL,
            severity ENUM('Low','Medium','High','Critical') NOT NULL,
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
    ];

    foreach ($statements as $statement) {
        $conn->query($statement);
    }

    $cols = $conn->query("SHOW COLUMNS FROM tbl_academic_records LIKE 'semi_final_grade'");
    if ($cols->num_rows === 0) {
        $conn->query("ALTER TABLE tbl_academic_records ADD COLUMN semi_final_grade DECIMAL(5,2) DEFAULT 0 AFTER midterm_grade");
        $conn->query("ALTER TABLE tbl_academic_records ADD COLUMN final_grade DECIMAL(5,2) DEFAULT 0 AFTER semi_final_grade");
    }

    seed_users($conn);
    $ready = true;
}

function seed_users(mysqli $conn): void
{
    $count = (int) $conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'];
    if ($count > 0) {
        return;
    }

    $users = [
        ['System Administrator', 'admin', 'admin123', 'Admin'],
        ['Academic Advisor', 'advisor', 'advisor123', 'Advisor'],
    ];

    $stmt = $conn->prepare('INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)');
    foreach ($users as [$name, $username, $password, $role]) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bind_param('ssss', $name, $username, $hash, $role);
        $stmt->execute();
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: index.php');
        exit;
    }
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function api_request(string $method, string $path, ?array $payload = null): array
{
    $ch = curl_init(API_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error) {
        return ['ok' => false, 'status' => 0, 'error' => $error ?: 'Unable to reach Flask API.'];
    }

    $data = json_decode($raw, true);
    if ($status >= 400) {
        return ['ok' => false, 'status' => $status, 'error' => $data['error'] ?? 'API request failed.'];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data];
}

function model_metadata(): array
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'model_metadata.json';
    if (!is_file($path)) {
        return [];
    }

    $json = json_decode((string) file_get_contents($path), true);
    return is_array($json) ? $json : [];
}

function status_class(string $status): string
{
    return match ($status) {
        'Pass' => 'status-pass',
        'At-Risk' => 'status-risk',
        'Fail' => 'status-fail',
        default => 'status-muted',
    };
}

function severity_class(string $severity): string
{
    return match ($severity) {
        'Low' => 'status-muted',
        'Medium' => 'status-pass',
        'High' => 'status-risk',
        'Critical' => 'status-fail',
        default => 'status-muted',
    };
}
function create_alert(int $student_id, int $user_id, string $type, string $severity, string $message): bool
{
    $stmt = db()->prepare("INSERT INTO tbl_alerts (student_id, user_id, alert_type, severity, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisss', $student_id, $user_id, $type, $severity, $message);
    return $stmt->execute();
}

function get_alerts(int $user_id, bool $only_unread = false): array
{
    $sql = "SELECT a.*, s.full_name, s.student_no FROM tbl_alerts a 
            JOIN tbl_students s ON a.student_id = s.id 
            WHERE a.user_id = ?";
    if ($only_unread) {
        $sql .= " AND a.is_read = 0";
    }
    $sql .= " ORDER BY a.created_at DESC";
    
    $stmt = db()->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function page_header(string $title): void
{
    $user = current_user();
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
            <a href="predictions.php">Prediction</a>
            <a href="students.php">Students</a>
            <a href="alerts.php">Alerts</a>
            <a href="reports.php">Reports</a>
            <?php if ($user && $user['role'] === 'Advisor'): ?>
                <a href="scholarships.php">Scholarships</a>
            <?php endif; ?>
        </nav>
        <?php if ($user): ?>
            <div class="user-menu">
                <span><?= h($user['full_name']) ?></span>
                <a class="button button-ghost" href="logout.php">Logout</a>
            </div>
        <?php endif; ?>
    </header>
    <main class="page">
    <?php
}

function page_footer(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}

function latest_predictions(int $limit = 8, ?int $advisor_id = null): array
{
    $sql = "SELECT p.*, s.student_no, s.full_name, s.year_level, s.section
             FROM tbl_predictions p
             INNER JOIN tbl_students s ON s.id = p.student_id";
    
    if ($advisor_id !== null) {
        $sql .= " WHERE s.advisor_id = ?";
    }
    
    $sql .= " ORDER BY p.created_at DESC LIMIT ?";
    
    $stmt = db()->prepare($sql);
    if ($advisor_id !== null) {
        $stmt->bind_param('ii', $advisor_id, $limit);
    } else {
        $stmt->bind_param('i', $limit);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function prediction_counts(?int $advisor_id = null): array
{
    $counts = ['Pass' => 0, 'At-Risk' => 0, 'Fail' => 0];
    $sql = "SELECT predicted_status, COUNT(*) AS total
             FROM tbl_predictions p
             INNER JOIN (
                SELECT student_id, MAX(id) AS latest_id
                FROM tbl_predictions
                GROUP BY student_id
             ) latest ON latest.latest_id = p.id
             INNER JOIN tbl_students s ON s.id = p.student_id";
    
    if ($advisor_id !== null) {
        $sql .= " WHERE s.advisor_id = ?";
    }
    
    $sql .= " GROUP BY predicted_status";
    
    $stmt = db()->prepare($sql);
    if ($advisor_id !== null) {
        $stmt->bind_param('i', $advisor_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $counts[$row['predicted_status']] = (int) $row['total'];
    }
    return $counts;
}

function total_students(?int $advisor_id = null): int
{
    if ($advisor_id !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) AS total FROM tbl_students WHERE advisor_id = ?');
        $stmt->bind_param('i', $advisor_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }
    return (int) db()->query('SELECT COUNT(*) AS total FROM tbl_students')->fetch_assoc()['total'];
}

function get_student_advice(int $student_id): array
{
    $stmt = db()->prepare("SELECT a.*, u.full_name as advisor_name 
                          FROM tbl_advice a 
                          JOIN users u ON a.advisor_id = u.id 
                          WHERE a.student_id = ? 
                          ORDER BY a.created_at DESC");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
