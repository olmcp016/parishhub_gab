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
VALUES (1, 4, 'Juan', 'Dela Cruz', 'admin@parishhub.local', '$2a$10$4Q6c1w0J0m1G0d1zZ2wZ6.6iZ7m9n0G8mF7d1s0d2f3g4h5j6k7l8', '09171234567', 'active');

-- Sample Secretary
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (2, 2, 'Maria', 'Santos', 'secretary@parishhub.local', '$2a$10$4Q6c1w0J0m1G0d1zZ2wZ6.6iZ7m9n0G8mF7d1s0d2f3g4h5j6k7l8', '09181234567', 'active');

-- Sample Treasurer
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (3, 3, 'Jose', 'Reyes', 'treasurer@parishhub.local', '$2a$10$4Q6c1w0J0m1G0d1zZ2wZ6.6iZ7m9n0G8mF7d1s0d2f3g4h5j6k7l8', '09191234567', 'active');

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
