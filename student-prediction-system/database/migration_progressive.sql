-- ============================================================
-- Progressive Prediction System — Database Migration
-- Run against: student_prediction_system
-- ============================================================

USE student_prediction_system;

-- ------------------------------------------------------------
-- 1. Add grading_period + predicted_grade + missing_components
--    to tbl_predictions
-- ------------------------------------------------------------
ALTER TABLE tbl_predictions
    ADD COLUMN IF NOT EXISTS grading_period ENUM('Prelim','Midterm','Semi-Final','Final') NULL
        AFTER academic_record_id,
    ADD COLUMN IF NOT EXISTS predicted_grade DECIMAL(5,2) NULL
        AFTER predicted_status,
    ADD COLUMN IF NOT EXISTS missing_components TEXT NULL
        AFTER risk_factors;

-- ------------------------------------------------------------
-- 2. Grade components breakdown per period
--    (NULL = not entered / missing; actual 0 must be stored as 0)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_grade_components (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    student_id          INT NOT NULL,
    academic_year       VARCHAR(20) NOT NULL,
    semester            VARCHAR(30) NOT NULL,
    period              ENUM('Prelim','Midterm','Semi-Final','Final') NOT NULL,
    exam_score          DECIMAL(5,2) NULL COMMENT 'NULL = not yet entered, 0 = actual zero',
    quiz_score          DECIMAL(5,2) NULL,
    activity_score      DECIMAL(5,2) NULL,
    assignment_score    DECIMAL(5,2) NULL,
    project_score       DECIMAL(5,2) NULL,
    attendance_rate     DECIMAL(5,2) NULL,
    lab_score           DECIMAL(5,2) NULL,
    computed_grade      DECIMAL(5,2) NULL COMMENT 'Weighted average of filled components',
    missing_components  TEXT NULL COMMENT 'JSON list of component names that were not entered',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_gc_student FOREIGN KEY (student_id)
        REFERENCES tbl_students(id) ON DELETE CASCADE,
    UNIQUE KEY uq_gc_period
        (student_id, academic_year, semester, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Configurable grading weights per period
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_grading_weights (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    period      ENUM('Prelim','Midterm','Semi-Final','Final') NOT NULL,
    component   VARCHAR(60) NOT NULL,
    weight      DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_by  INT NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_gw_user FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_gw_period_component (period, component)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Seed default grading weights (30/20/15/15/20)
--    Only insert if table is empty
-- ------------------------------------------------------------
INSERT IGNORE INTO tbl_grading_weights (period, component, weight) VALUES
    ('Prelim',     'Exam',       30),
    ('Prelim',     'Quiz',       20),
    ('Prelim',     'Activities', 15),
    ('Prelim',     'Assignment', 15),
    ('Prelim',     'Project',    20),
    ('Midterm',    'Exam',       30),
    ('Midterm',    'Quiz',       20),
    ('Midterm',    'Activities', 15),
    ('Midterm',    'Assignment', 15),
    ('Midterm',    'Project',    20),
    ('Semi-Final', 'Exam',       30),
    ('Semi-Final', 'Quiz',       20),
    ('Semi-Final', 'Activities', 15),
    ('Semi-Final', 'Assignment', 15),
    ('Semi-Final', 'Project',    20),
    ('Final',      'Exam',       30),
    ('Final',      'Quiz',       20),
    ('Final',      'Activities', 15),
    ('Final',      'Assignment', 15),
    ('Final',      'Project',    20);
