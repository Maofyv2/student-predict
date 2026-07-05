CREATE DATABASE IF NOT EXISTS student_prediction_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE student_prediction_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin','Faculty','Advisor') NOT NULL DEFAULT 'Advisor',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_students (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_surveys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    internet_access TINYINT(1) NOT NULL,
    digital_literacy TINYINT NOT NULL,
    device_availability VARCHAR(80) NOT NULL,
    study_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_academic_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(30) NOT NULL,
    prelim_grade DECIMAL(5,2) NOT NULL,
    midterm_grade DECIMAL(5,2) NOT NULL,
    attendance_rate DECIMAL(5,2) NOT NULL,
    lab_score DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_academic_student FOREIGN KEY (student_id) REFERENCES tbl_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_predictions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_alerts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
