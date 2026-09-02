-- ==========================================================
-- PARISHHUB — Complete Database (Schema + Seed Data)
-- Import this ONE file directly via phpMyAdmin's Import tab
-- and you're ready to log in immediately.
--
-- Demo login credentials (change after first login):
--   Admin (Parish Priest): admin@parishhub.local     / Password@123
--   Secretary:             secretary@parishhub.local / Password@123
--   Treasurer:             treasurer@parishhub.local / Password@123
-- Register a new Parishioner account from the site's Register page.
-- ==========================================================

-- ==========================================================
-- PARISHHUB DATABASE SCHEMA
-- Mass Intention & Parish Service Management System
-- Engine: InnoDB | Charset: utf8mb4
-- ==========================================================

CREATE DATABASE IF NOT EXISTS parishhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE parishhub;

SET FOREIGN_KEY_CHECKS = 0;

-- ==========================================================
-- 1. ROLES
-- ==========================================================
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE, -- Parishioner, Secretary, Treasurer, Admin
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==========================================================
-- 2. PERMISSIONS
-- ==========================================================
DROP TABLE IF EXISTS permissions;
CREATE TABLE permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE, -- e.g. 'appointments.approve'
    description VARCHAR(255)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS role_permissions;
CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 3. USERS (base account for all system users)
-- ==========================================================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- bcrypt hash
    phone VARCHAR(20),
    address VARCHAR(255),
    birthdate DATE,
    gender ENUM('Male','Female','Other'),
    profile_photo VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    email_verified_at DATETIME DEFAULT NULL,
    remember_token VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

-- ==========================================================
-- 4. PARISHIONERS (extends users)
-- ==========================================================
DROP TABLE IF EXISTS parishioners;
CREATE TABLE parishioners (
    parishioner_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    baptism_date DATE,
    confirmation_date DATE,
    marital_status ENUM('Single','Married','Widowed','Separated') DEFAULT 'Single',
    occupation VARCHAR(100),
    emergency_contact_name VARCHAR(150),
    emergency_contact_number VARCHAR(20),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 5. PRIESTS
-- ==========================================================
DROP TABLE IF EXISTS priests;
CREATE TABLE priests (
    priest_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL, -- nullable: priest may or may not have a login account
    full_name VARCHAR(150) NOT NULL,
    title VARCHAR(50) DEFAULT 'Rev. Fr.',
    specialization VARCHAR(150), -- e.g. weddings, healing mass
    contact_number VARCHAR(20),
    email VARCHAR(150),
    status ENUM('active','on_leave','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
-- 6. SERVICES (Mass Intention types, Sacraments, Blessings)
-- ==========================================================
DROP TABLE IF EXISTS services;
CREATE TABLE services (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(150) NOT NULL, -- e.g. Mass Intention - Living, Wedding, Baptism
    category ENUM('Mass Intention','Wedding','Baptism','Funeral','Blessing','Confirmation','First Communion') NOT NULL,
    description TEXT,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    requirements TEXT, -- human readable summary; detailed list in `requirements` table
    duration_minutes INT DEFAULT 60,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==========================================================
-- 7. REQUIREMENTS (per service)
-- ==========================================================
DROP TABLE IF EXISTS requirements;
CREATE TABLE requirements (
    requirement_id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    requirement_name VARCHAR(150) NOT NULL, -- e.g. "Baptismal Certificate"
    is_mandatory BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 8. APPOINTMENT STATUS (lookup table)
-- ==========================================================
DROP TABLE IF EXISTS appointment_status;
CREATE TABLE appointment_status (
    status_id INT AUTO_INCREMENT PRIMARY KEY,
    status_name VARCHAR(50) NOT NULL UNIQUE -- Pending, Approved, Rejected, Payment Verified, Confirmed, Completed, Cancelled
) ENGINE=InnoDB;

-- ==========================================================
-- 9. APPOINTMENTS
-- ==========================================================
DROP TABLE IF EXISTS appointments;
CREATE TABLE appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    parishioner_id INT NOT NULL,
    service_id INT NOT NULL,
    priest_id INT DEFAULT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status_id INT NOT NULL DEFAULT 1, -- default Pending
    remarks TEXT,
    approved_by INT DEFAULT NULL, -- secretary user_id
    approved_at DATETIME DEFAULT NULL,
    confirmed_by INT DEFAULT NULL, -- priest/admin user_id
    confirmed_at DATETIME DEFAULT NULL,
    cancelled_reason VARCHAR(255) DEFAULT NULL,
    date_of_death DATE DEFAULT NULL, -- required only for Funeral Mass bookings; used to compute the 9-day mourning period
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parishioner_id) REFERENCES parishioners(parishioner_id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(service_id),
    FOREIGN KEY (priest_id) REFERENCES priests(priest_id) ON DELETE SET NULL,
    FOREIGN KEY (status_id) REFERENCES appointment_status(status_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (confirmed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_appt_date (appointment_date),
    INDEX idx_appt_status (status_id)
) ENGINE=InnoDB;

-- ==========================================================
-- 10. MASS INTENTIONS (detail table specific to Mass Intention service)
-- ==========================================================
DROP TABLE IF EXISTS mass_intentions;
CREATE TABLE mass_intentions (
    intention_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    intention_type ENUM('Living','Dead','Thanksgiving','Healing','Birthday') NOT NULL,
    offerer_name VARCHAR(150) NOT NULL,
    intention_for VARCHAR(255) NOT NULL, -- name(s) the mass is offered for
    message TEXT, -- optional prayer intention text
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 11. UPLOADED DOCUMENTS
-- ==========================================================
DROP TABLE IF EXISTS uploaded_documents;
CREATE TABLE uploaded_documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    requirement_id INT DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_id) REFERENCES requirements(requirement_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
-- 12. CALENDAR / EVENTS
-- ==========================================================
DROP TABLE IF EXISTS calendar;
CREATE TABLE calendar (
    calendar_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    calendar_date DATE NOT NULL,
    is_blocked BOOLEAN DEFAULT FALSE, -- blocked = no appointments allowed
    notes VARCHAR(255),
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

DROP TABLE IF EXISTS events;
CREATE TABLE events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    event_time TIME,
    location VARCHAR(150),
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (calendar_id) REFERENCES calendar(calendar_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
-- 13. ANNOUNCEMENTS
-- ==========================================================
DROP TABLE IF EXISTS announcements;
CREATE TABLE announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    posted_by INT NOT NULL,
    is_pinned BOOLEAN DEFAULT FALSE,
    status ENUM('draft','published','archived') DEFAULT 'published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 14. PAYMENT METHODS
-- ==========================================================
DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
    method_id INT AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(50) NOT NULL UNIQUE -- Cash, GCash, PayMaya, Bank Transfer, Credit/Debit Card
) ENGINE=InnoDB;

-- ==========================================================
-- 15. PAYMENTS
-- ==========================================================
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    reference_number VARCHAR(100) UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    method_id INT NOT NULL,
    payment_status ENUM('pending','verified','failed','refunded') DEFAULT 'pending',
    payment_date DATETIME DEFAULT NULL,
    verified_by INT DEFAULT NULL, -- treasurer user_id
    verified_at DATETIME DEFAULT NULL,
    proof_of_payment VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (method_id) REFERENCES payment_methods(method_id),
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
-- 16. TRANSACTIONS (payment gateway / ledger log)
-- ==========================================================
DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    gateway VARCHAR(50), -- e.g. GCash API, PayMongo, Manual
    gateway_transaction_id VARCHAR(150),
    status VARCHAR(50),
    raw_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 17. OFFICIAL RECEIPTS
-- ==========================================================
DROP TABLE IF EXISTS official_receipts;
CREATE TABLE official_receipts (
    receipt_id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL UNIQUE,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    issued_by INT NOT NULL, -- treasurer user_id
    issue_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    pdf_path VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ==========================================================
-- 18. NOTIFICATIONS
-- ==========================================================
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('email','sms','website') NOT NULL DEFAULT 'website',
    category ENUM('appointment','payment','announcement','system') DEFAULT 'system',
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 19. CHAT MESSAGES (chatbot + live support log)
-- ==========================================================
DROP TABLE IF EXISTS chat_messages;
CREATE TABLE chat_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL, -- null = guest/unauthenticated
    session_id VARCHAR(100) NOT NULL,
    sender ENUM('user','bot') NOT NULL,
    message TEXT NOT NULL,
    intent VARCHAR(100) DEFAULT NULL, -- matched FAQ/intent key
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
-- 20. ACTIVITY LOGS (audit trail)
-- ==========================================================
DROP TABLE IF EXISTS activity_logs;
CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(150) NOT NULL, -- e.g. "Approved Appointment #45"
    module VARCHAR(100), -- e.g. "Appointments"
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
-- 21. REPORTS (saved/generated report metadata)
-- ==========================================================
DROP TABLE IF EXISTS reports;
CREATE TABLE reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(100) NOT NULL, -- Daily Revenue, Monthly Revenue, Most Requested Services...
    generated_by INT NOT NULL,
    date_from DATE,
    date_to DATE,
    file_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ==========================================================
-- 22. SETTINGS (system-wide configuration, key-value)
-- ==========================================================
DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================
-- SEED DATA
-- ==========================================================
-- ==========================================================
-- PARISHHUB SEED DATA
-- Run after schema.sql
-- ==========================================================
USE parishhub;

-- Roles
INSERT INTO roles (role_id, role_name, description) VALUES
(1, 'Parishioner', 'Regular parish member requesting services'),
(2, 'Secretary', 'Manages appointments, calendar, and announcements'),
(3, 'Treasurer', 'Manages payments, receipts, and financial reports'),
(4, 'Admin', 'Parish Priest - full system access');

-- Appointment statuses
INSERT INTO appointment_status (status_id, status_name) VALUES
(1, 'Pending'),
(2, 'Approved'),
(3, 'Rejected'),
(4, 'Payment Verified'),
(5, 'Confirmed'),
(6, 'Completed'),
(7, 'Cancelled');

-- Payment methods
INSERT INTO payment_methods (method_id, method_name) VALUES
(1, 'Cash'),
(2, 'GCash'),
(3, 'Maya'),
(4, 'Bank Transfer'),
(5, 'Credit/Debit Card');

-- Default Admin account (Parish Priest)
-- Default password for all 3 seeded accounts below: Password@123 (CHANGE AFTER FIRST LOGIN)
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (1, 4, 'Juan', 'Dela Cruz', 'admin@parishhub.local', '$2y$10$iTPk50S3LQnM4V5ZTM8K1O83xaEGTZk4lpT109fKuon3QqNrCAT.S', '09171234567', 'active');

-- Sample Secretary
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (2, 2, 'Maria', 'Santos', 'secretary@parishhub.local', '$2y$10$iTPk50S3LQnM4V5ZTM8K1O83xaEGTZk4lpT109fKuon3QqNrCAT.S', '09181234567', 'active');

-- Sample Treasurer
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (3, 3, 'Jose', 'Reyes', 'treasurer@parishhub.local', '$2y$10$iTPk50S3LQnM4V5ZTM8K1O83xaEGTZk4lpT109fKuon3QqNrCAT.S', '09191234567', 'active');

-- Sample Priests
INSERT INTO priests (priest_id, full_name, title, specialization, contact_number, email, status) VALUES
(1, 'Fr. Antonio Villanueva', 'Rev. Fr.', 'Weddings, Baptisms', '09201234567', 'frantonio@parishhub.local', 'active'),
(2, 'Fr. Michael Ramos', 'Rev. Fr.', 'Healing Mass, Funerals', '09211234567', 'frmichael@parishhub.local', 'active');

-- Services
INSERT INTO services (service_id, service_name, category, description, fee, requirements, duration_minutes) VALUES
(1, 'Mass Intention - Living', 'Mass Intention', 'Mass offered for the intention of a living person.', 100.00, 'Name of person(s) for the intention', 30),
(2, 'Mass Intention - Dead', 'Mass Intention', 'Mass offered for the eternal repose of a departed soul.', 100.00, 'Name of the deceased', 30),
(3, 'Mass Intention - Thanksgiving', 'Mass Intention', 'Mass offered in thanksgiving.', 100.00, 'Name of person(s) offering thanks', 30),
(4, 'Mass Intention - Healing', 'Mass Intention', 'Mass offered for healing and recovery.', 100.00, 'Name of person(s) needing healing', 30),
(5, 'Mass Intention - Birthday', 'Mass Intention', 'Mass offered for a birthday blessing.', 100.00, 'Name of celebrant', 30),
(6, 'Wedding Ceremony', 'Wedding', 'Sacrament of Holy Matrimony.', 5000.00, 'Baptismal & Confirmation Certificates, Marriage License, Pre-Cana Seminar Certificate, CENOMAR', 90),
(7, 'Baptism', 'Baptism', 'Sacrament of Baptism for infants/children/adults.', 1000.00, 'Birth Certificate, Baptismal form, Godparents list', 60),
(8, 'Funeral Mass', 'Funeral', 'Mass for the deceased and funeral rites.', 2000.00, 'Death Certificate', 60),
(9, 'House Blessing', 'Blessing', 'Blessing of a home or establishment.', 500.00, 'Address of location, contact number', 45),
(10, 'Confirmation', 'Confirmation', 'Sacrament of Confirmation.', 800.00, 'Baptismal Certificate, Confirmation form, Sponsor information', 90),
(11, 'First Communion', 'First Communion', 'Sacrament of the Holy Eucharist - first reception.', 500.00, 'Baptismal Certificate, Catechism completion', 90);

-- Sample announcement
INSERT INTO announcements (title, content, posted_by, is_pinned, status) VALUES
('Welcome to PARISHHUB', 'Our parish now accepts Mass Intentions, Sacrament bookings, and appointment scheduling online. Thank you for being part of our community!', 1, TRUE, 'published');

-- Sample settings
INSERT INTO settings (setting_key, setting_value) VALUES
('parish_name', 'Our Lady of Grace Parish'),
('parish_address', '123 Sampaguita St., Quezon City, Metro Manila'),
('office_hours', 'Mon-Sat: 8:00 AM - 5:00 PM'),
('contact_number', '(02) 8123-4567'),
('contact_email', 'parishoffice@parishhub.local'),
('mass_schedule', 'Weekdays: 6:00 AM & 6:00 PM | Sunday: 6AM, 8AM, 10AM, 4PM, 6PM'),
('theme_primary', '#B8860B'),
('theme_secondary', '#3E2723');
