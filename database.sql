-- =============================================
-- Hardware Monitoring System - Database Schema
-- =============================================

CREATE DATABASE IF NOT EXISTS u297738695_hardware CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE u297738695_hardware;

-- Users
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','member') NOT NULL DEFAULT 'member',
    is_logged_in TINYINT(1) NOT NULL DEFAULT 0,
    device_token VARCHAR(255) DEFAULT NULL,
    login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Buildings
CREATE TABLE buildings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Rooms
CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    building_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Assets
CREATE TABLE assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) DEFAULT NULL,
    type ENUM('komputer','laptop','printer','scanner','server','network','lainnya') DEFAULT NULL,
    brand VARCHAR(100) DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    serial_number VARCHAR(100) DEFAULT NULL,
    condition_status ENUM('baik','perlu_perhatian','rusak') DEFAULT 'baik',
    building_id INT UNSIGNED DEFAULT NULL,
    room_id INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_registered TINYINT(1) NOT NULL DEFAULT 0,
    registered_by INT UNSIGNED DEFAULT NULL,
    registered_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON SET NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON SET NULL,
    FOREIGN KEY (registered_by) REFERENCES users(id) ON SET NULL
) ENGINE=InnoDB;

-- QR Batches (untuk bulk generate)
CREATE TABLE qr_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_name VARCHAR(100) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    prefix VARCHAR(10) NOT NULL DEFAULT 'HW',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Maintenance Logs
CREATE TABLE maintenance_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    action VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    performed_by INT UNSIGNED NOT NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Location Logs
CREATE TABLE location_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    building_id INT UNSIGNED DEFAULT NULL,
    room_id INT UNSIGNED DEFAULT NULL,
    building_name VARCHAR(100) DEFAULT NULL,
    room_name VARCHAR(100) DEFAULT NULL,
    moved_by INT UNSIGNED NOT NULL,
    moved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (moved_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =============================================
-- Default Admin User
-- Password: Admin@123 (ganti setelah login pertama!)
-- =============================================
INSERT INTO users (name, username, password, role) VALUES
('Administrator', 'admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Member Satu', 'member1', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'member');

-- Note: password hash di atas = "password" untuk testing
-- Segera ganti via menu profil setelah install!
