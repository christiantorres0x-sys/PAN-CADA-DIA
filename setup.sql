-- PAN CADA DIA — complete database setup (single file, no separate migrations).
-- Fresh install: just run this file.
-- If you already created the database with an older version of this file,
-- uncomment the next line once to rebuild it from scratch (this ERASES all data):
-- DROP DATABASE IF EXISTS pan_cada_dia;

CREATE DATABASE IF NOT EXISTS pan_cada_dia
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pan_cada_dia;

CREATE TABLE IF NOT EXISTS users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'Admin',
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feeding_sites (
    site_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL,
    barangay VARCHAR(120) NULL,
    municipality VARCHAR(120) NULL,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feeding_programs (
    program_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    duration_days INT UNSIGNED NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('ACTIVE','COMPLETED','INACTIVE') NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS beneficiaries (
    beneficiary_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NULL,
    program_id INT UNSIGNED NULL,
    full_name VARCHAR(180) NOT NULL,
    first_name VARCHAR(80) NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(100) NULL,
    beneficiary_code VARCHAR(10) NULL,
    birth_date DATE NOT NULL,
    sex ENUM('Male','Female') NOT NULL,
    grade_level VARCHAR(80) NULL,
    program_status ENUM('ENROLLED','COMPLETED','INACTIVE') NOT NULL DEFAULT 'ENROLLED',
    date_enlisted DATE NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_beneficiary_site
        FOREIGN KEY (site_id) REFERENCES feeding_sites(site_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_beneficiary_program
        FOREIGN KEY (program_id) REFERENCES feeding_programs(program_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_beneficiary_code (beneficiary_code),
    INDEX idx_beneficiary_name (full_name),
    INDEX idx_beneficiary_birthdate (birth_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_records (
    record_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id INT UNSIGNED NOT NULL,
    record_date DATE NOT NULL,
    milestone ENUM('BASELINE','MIDLINE','ENDLINE') NULL,
    height_cm DECIMAL(5,2) NOT NULL,
    weight_kg DECIMAL(5,2) NOT NULL,
    bmi DECIMAL(5,2) NOT NULL,
    nutritional_status VARCHAR(80) NULL,
    remarks TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_health_beneficiary
        FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(beneficiary_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_health_user
        FOREIGN KEY (recorded_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_health_beneficiary_milestone (beneficiary_id, milestone),
    INDEX idx_health_beneficiary_date (beneficiary_id, record_date),
    INDEX idx_health_milestone (milestone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
    attendance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_status ENUM('PRESENT','ABSENT') NOT NULL DEFAULT 'PRESENT',
    remarks VARCHAR(255) NULL,
    recorded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_beneficiary
        FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(beneficiary_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_user
        FOREIGN KEY (recorded_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_attendance_beneficiary_date (beneficiary_id, attendance_date),
    INDEX idx_attendance_date_status (attendance_date, attendance_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity VARCHAR(80) NULL,
    entity_id INT UNSIGNED NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT INTO users (full_name, username, password_hash, role)
SELECT 'System Administrator', 'admin',
       '$2y$12$Brmg1..UVzMoaLtjOGHg7e72HSMnDnocdyX/GLfWNk2qdGVbNdFMu',
       'Admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO feeding_sites (site_name, barangay, municipality)
SELECT 'Main Feeding Site', 'To be configured', 'To be configured'
WHERE NOT EXISTS (SELECT 1 FROM feeding_sites LIMIT 1);

INSERT INTO feeding_programs (program_name, description, duration_days)
SELECT 'PAN CADA DIA – Initial Program', 'Configure the actual CCES feeding period here.', NULL
WHERE NOT EXISTS (SELECT 1 FROM feeding_programs LIMIT 1);
