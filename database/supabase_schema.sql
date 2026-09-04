-- ==========================================================
-- PARISHHUB DATABASE SCHEMA (POSTGRESQL / SUPABASE)
-- Converted for Supabase SQL Editor
-- ==========================================================

-- Enable pgcrypto extension for UUIDs or cryptographic functions if needed
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ==========================================================
-- CUSTOM TYPES (ENUMS)
-- ==========================================================
DO $$ BEGIN
    CREATE TYPE user_gender AS ENUM ('Male', 'Female', 'Other');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE user_status AS ENUM ('active', 'inactive', 'suspended');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE marital_status_type AS ENUM ('Single', 'Married', 'Widowed', 'Separated');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE priest_status AS ENUM ('active', 'on_leave', 'inactive');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE service_category AS ENUM (
        'Mass Intention',
        'Wedding',
        'Baptism',
        'Funeral',
        'Blessing',
        'Confirmation',
        'First Communion'
    );
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE mass_intention_type AS ENUM ('Living', 'Dead', 'Thanksgiving', 'Healing', 'Birthday');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE announcement_status AS ENUM ('draft', 'published', 'archived');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE payment_status_type AS ENUM ('pending', 'verified', 'failed', 'refunded');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE notification_type AS ENUM ('email', 'sms', 'website');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE notification_category AS ENUM ('appointment', 'payment', 'announcement', 'system');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE chat_sender_type AS ENUM ('user', 'bot');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ==========================================================
-- TRIGGER HELPER: auto-update updated_at timestamps
-- ==========================================================
CREATE OR REPLACE FUNCTION trigger_set_timestamp()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ==========================================================
-- 1. ROLES
-- ==========================================================
CREATE TABLE IF NOT EXISTS roles (
    role_id SERIAL PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 2. PERMISSIONS
-- ==========================================================
CREATE TABLE IF NOT EXISTS permissions (
    permission_id SERIAL PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE,
    permission_id INT NOT NULL REFERENCES permissions(permission_id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

-- ==========================================================
-- 3. USERS
-- ==========================================================
CREATE TABLE IF NOT EXISTS users (
    user_id SERIAL PRIMARY KEY,
    role_id INT NOT NULL REFERENCES roles(role_id),
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address VARCHAR(255),
    birthdate DATE,
    gender user_gender,
    profile_photo VARCHAR(255) DEFAULT NULL,
    status user_status DEFAULT 'active',
    email_verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    remember_token VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS set_timestamp_users ON users;
CREATE TRIGGER set_timestamp_users
BEFORE UPDATE ON users
FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();

-- ==========================================================
-- 4. PARISHIONERS
-- ==========================================================
CREATE TABLE IF NOT EXISTS parishioners (
    parishioner_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL UNIQUE REFERENCES users(user_id) ON DELETE CASCADE,
    baptism_date DATE,
    confirmation_date DATE,
    marital_status marital_status_type DEFAULT 'Single',
    occupation VARCHAR(100),
    emergency_contact_name VARCHAR(150),
    emergency_contact_number VARCHAR(20)
);

-- ==========================================================
-- 5. PRIESTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS priests (
    priest_id SERIAL PRIMARY KEY,
    user_id INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    full_name VARCHAR(150) NOT NULL,
    title VARCHAR(50) DEFAULT 'Rev. Fr.',
    contact_number VARCHAR(20),
    email VARCHAR(150),
    status priest_status DEFAULT 'active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 6. SERVICES
-- ==========================================================
CREATE TABLE IF NOT EXISTS services (
    service_id SERIAL PRIMARY KEY,
    service_name VARCHAR(150) NOT NULL,
    category service_category NOT NULL,
    description TEXT,
    fee NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    requirements TEXT,
    duration_minutes INT DEFAULT 60,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS set_timestamp_services ON services;
CREATE TRIGGER set_timestamp_services
BEFORE UPDATE ON services
FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();

-- ==========================================================
-- 7. REQUIREMENTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS requirements (
    requirement_id SERIAL PRIMARY KEY,
    service_id INT NOT NULL REFERENCES services(service_id) ON DELETE CASCADE,
    requirement_name VARCHAR(150) NOT NULL,
    is_mandatory BOOLEAN DEFAULT TRUE
);

-- ==========================================================
-- 8. APPOINTMENT STATUS
-- ==========================================================
CREATE TABLE IF NOT EXISTS appointment_status (
    status_id SERIAL PRIMARY KEY,
    status_name VARCHAR(50) NOT NULL UNIQUE
);

-- ==========================================================
-- 9. APPOINTMENTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS appointments (
    appointment_id SERIAL PRIMARY KEY,
    parishioner_id INT NOT NULL REFERENCES parishioners(parishioner_id) ON DELETE CASCADE,
    service_id INT NOT NULL REFERENCES services(service_id),
    priest_id INT DEFAULT NULL REFERENCES priests(priest_id) ON DELETE SET NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status_id INT NOT NULL DEFAULT 1 REFERENCES appointment_status(status_id),
    remarks TEXT,
    approved_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    approved_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    confirmed_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    confirmed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    cancelled_reason VARCHAR(255) DEFAULT NULL,
    date_of_death DATE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_appt_date ON appointments (appointment_date);
CREATE INDEX IF NOT EXISTS idx_appt_status ON appointments (status_id);

DROP TRIGGER IF EXISTS set_timestamp_appointments ON appointments;
CREATE TRIGGER set_timestamp_appointments
BEFORE UPDATE ON appointments
FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();

-- ==========================================================
-- 10. MASS INTENTIONS
-- ==========================================================
CREATE TABLE IF NOT EXISTS mass_intentions (
    intention_id SERIAL PRIMARY KEY,
    appointment_id INT NOT NULL REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    intention_type mass_intention_type NOT NULL,
    offerer_name VARCHAR(150) NOT NULL,
    intention_for VARCHAR(255) NOT NULL,
    message TEXT
);

-- ==========================================================
-- 11. UPLOADED DOCUMENTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS uploaded_documents (
    document_id SERIAL PRIMARY KEY,
    appointment_id INT NOT NULL REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    requirement_id INT DEFAULT NULL REFERENCES requirements(requirement_id) ON DELETE SET NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    uploaded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    verified BOOLEAN DEFAULT FALSE
);

-- ==========================================================
-- 12. CALENDAR / EVENTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS calendar (
    calendar_id SERIAL PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    calendar_date DATE NOT NULL,
    is_blocked BOOLEAN DEFAULT FALSE,
    notes VARCHAR(255),
    created_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
    event_id SERIAL PRIMARY KEY,
    calendar_id INT DEFAULT NULL REFERENCES calendar(calendar_id) ON DELETE SET NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    event_time TIME,
    location VARCHAR(150),
    created_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 13. ANNOUNCEMENTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS announcements (
    announcement_id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    posted_by INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    is_pinned BOOLEAN DEFAULT FALSE,
    status announcement_status DEFAULT 'published',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS set_timestamp_announcements ON announcements;
CREATE TRIGGER set_timestamp_announcements
BEFORE UPDATE ON announcements
FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();

-- ==========================================================
-- 14. PAYMENT METHODS
-- ==========================================================
CREATE TABLE IF NOT EXISTS payment_methods (
    method_id SERIAL PRIMARY KEY,
    method_name VARCHAR(50) NOT NULL UNIQUE
);

-- ==========================================================
-- 15. PAYMENTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS payments (
    payment_id SERIAL PRIMARY KEY,
    appointment_id INT NOT NULL REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    reference_number VARCHAR(100) UNIQUE,
    amount NUMERIC(10,2) NOT NULL,
    method_id INT NOT NULL REFERENCES payment_methods(method_id),
    payment_status payment_status_type DEFAULT 'pending',
    payment_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    verified_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    proof_of_payment VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 16. TRANSACTIONS
-- ==========================================================
CREATE TABLE IF NOT EXISTS transactions (
    transaction_id SERIAL PRIMARY KEY,
    payment_id INT NOT NULL REFERENCES payments(payment_id) ON DELETE CASCADE,
    gateway VARCHAR(50),
    gateway_transaction_id VARCHAR(150),
    status VARCHAR(50),
    raw_response TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 17. OFFICIAL RECEIPTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS official_receipts (
    receipt_id SERIAL PRIMARY KEY,
    payment_id INT NOT NULL UNIQUE REFERENCES payments(payment_id) ON DELETE CASCADE,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    issued_by INT NOT NULL REFERENCES users(user_id),
    issue_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    pdf_path VARCHAR(255) DEFAULT NULL
);

-- ==========================================================
-- 18. NOTIFICATIONS
-- ==========================================================
CREATE TABLE IF NOT EXISTS notifications (
    notification_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    type notification_type NOT NULL DEFAULT 'website',
    category notification_category DEFAULT 'system',
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 19. CHAT MESSAGES
-- ==========================================================
CREATE TABLE IF NOT EXISTS chat_messages (
    message_id SERIAL PRIMARY KEY,
    user_id INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    session_id VARCHAR(100) NOT NULL,
    sender chat_sender_type NOT NULL,
    message TEXT NOT NULL,
    intent VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 20. ACTIVITY LOGS
-- ==========================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    log_id SERIAL PRIMARY KEY,
    user_id INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    action VARCHAR(150) NOT NULL,
    module VARCHAR(100),
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 21. REPORTS
-- ==========================================================
CREATE TABLE IF NOT EXISTS reports (
    report_id SERIAL PRIMARY KEY,
    report_type VARCHAR(100) NOT NULL,
    generated_by INT NOT NULL REFERENCES users(user_id),
    date_from DATE,
    date_to DATE,
    file_path VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================================
-- 22. SETTINGS
-- ==========================================================
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS set_timestamp_settings ON settings;
CREATE TRIGGER set_timestamp_settings
BEFORE UPDATE ON settings
FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();

-- ==========================================================
-- DEFAULT SEED DATA (Roles, Statuses, Payment Methods)
-- ==========================================================
INSERT INTO roles (role_id, role_name, description) VALUES
(1, 'Parishioner', 'Regular parishioner user account'),
(2, 'Secretary', 'Parish office secretary - reviews appointments'),
(3, 'Treasurer', 'Parish office treasurer - verifies payments and issues receipts'),
(4, 'Admin', 'System administrator')
ON CONFLICT (role_id) DO NOTHING;

INSERT INTO appointment_status (status_id, status_name) VALUES
(1, 'Pending'),
(2, 'Approved'),
(3, 'Rejected'),
(4, 'Payment Verified'),
(5, 'Confirmed'),
(6, 'Completed'),
(7, 'Cancelled')
ON CONFLICT (status_id) DO NOTHING;

INSERT INTO payment_methods (method_id, method_name) VALUES
(1, 'Cash'),
(2, 'GCash'),
(3, 'PayMaya'),
(4, 'Bank Transfer'),
(5, 'Credit/Debit Card')
ON CONFLICT (method_id) DO NOTHING;

-- Reset sequence to prevent ID collisions on new inserts
SELECT setval('roles_role_id_seq', (SELECT MAX(role_id) FROM roles));
SELECT setval('appointment_status_status_id_seq', (SELECT MAX(status_id) FROM appointment_status));
SELECT setval('payment_methods_method_id_seq', (SELECT MAX(method_id) FROM payment_methods));

-- ==========================================================
-- COMPATIBILITY FUNCTIONS FOR MYSQL SYNTAX IN POSTGRESQL
-- ==========================================================
CREATE OR REPLACE FUNCTION YEAR(d timestamp with time zone) RETURNS integer AS $$
BEGIN RETURN EXTRACT(YEAR FROM d); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION YEAR(d date) RETURNS integer AS $$
BEGIN RETURN EXTRACT(YEAR FROM d); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION MONTH(d timestamp with time zone) RETURNS integer AS $$
BEGIN RETURN EXTRACT(MONTH FROM d); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION MONTH(d date) RETURNS integer AS $$
BEGIN RETURN EXTRACT(MONTH FROM d); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION CURDATE() RETURNS date AS $$
BEGIN RETURN CURRENT_DATE; END; $$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION NOW() RETURNS timestamp with time zone AS $$
BEGIN RETURN CURRENT_TIMESTAMP; END; $$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION YEARWEEK(d date, mode integer DEFAULT 0) RETURNS integer AS $$
BEGIN RETURN (EXTRACT(YEAR FROM d) * 100 + EXTRACT(WEEK FROM d)); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION YEARWEEK(d timestamp with time zone, mode integer DEFAULT 0) RETURNS integer AS $$
BEGIN RETURN (EXTRACT(YEAR FROM d) * 100 + EXTRACT(WEEK FROM d)); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION DATE_FORMAT(d timestamp with time zone, fmt text) RETURNS text AS $$
BEGIN RETURN to_char(d, 'YYYY-MM'); END; $$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION DATE_FORMAT(d date, fmt text) RETURNS text AS $$
BEGIN RETURN to_char(d, 'YYYY-MM'); END; $$ LANGUAGE plpgsql IMMUTABLE;


