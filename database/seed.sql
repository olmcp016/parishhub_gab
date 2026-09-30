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
VALUES (1, 4, 'Alfonso', 'Bernardo', 'admin@parishhub.local', '$2a$10$4Q6c1w0J0m1G0d1zZ2wZ6.6iZ7m9n0G8mF7d1s0d2f3g4h5j6k7l8', '09171234567', 'active');

-- Sample Secretary
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (2, 2, 'Lourdes', 'Magpantay', 'secretary@parishhub.local', '$2a$10$4Q6c1w0J0m1G0d1zZ2wZ6.6iZ7m9n0G8mF7d1s0d2f3g4h5j6k7l8', '09181234567', 'active');

-- Sample Treasurer
INSERT INTO users (user_id, role_id, firstname, lastname, email, password, phone, status)
VALUES (3, 3, 'Carmela', 'Dizon', 'treasurer@parishhub.local', '$2a$10$4Q6c1w0J0m1G0d1zZ2wZ6.6iZ7m9n0G8mF7d1s0d2f3g4h5j6k7l8', '09191234567', 'active');

-- Sample Priests
INSERT INTO priests (priest_id, full_name, title, contact_number, email, status) VALUES
(1, 'Alfonso Bernardo', 'Rev. Fr.', '09201234567', 'fralfonso@parishhub.local', 'active'),
(2, 'Miguel Sison', 'Rev. Fr.', '09211234567', 'frmiguel@parishhub.local', 'active');

-- Services
INSERT INTO services (service_id, service_name, category, description, fee, requirements, duration_minutes) VALUES
(1, 'Mass Intention - Thanksgiving', 'Mass Intention', 'Misa ng pasasalamat para sa biyaya, kaarawan, at iba pa.', 50.00, '', 30),
(2, 'Mass Intention - Petition/Healing', 'Mass Intention', 'Misa para sa kalusugan at kagalingan ng mga may sakit.', 50.00, '', 30),
(3, 'Mass Intention - Souls in Purgatory', 'Mass Intention', 'Misa para sa kaluluwa ng mga namayapa.', 50.00, '', 30),
(4, 'Wedding Ceremony', 'Wedding', 'Sakramento ng Matrimonyo. Package includes red carpet, lighting, and basic floral arrangement.', 7500.00, 'Baptismal Certificate (For Marriage Purposes), Confirmation Certificate (For Marriage Purposes), Marriage License, Pre-Cana Seminar Certificate, CENOMAR, 2x2 ID Pictures', 90),
(5, 'Special Baptism (Solo)', 'Baptism', 'Sakramento ng Binyag. Special schedule exclusive for your family.', 1500.00, 'Birth Certificate of the child, Marriage Contract of parents (if married), List of Ninong and Ninang (max 10 pairs)', 60),
(6, 'Regular Baptism (Communal)', 'Baptism', 'Sakramento ng Binyag. Kasabay ang iba pang bibinyagan tuwing Linggo.', 500.00, 'Birth Certificate of the child, Marriage Contract of parents (if married), List of Ninong and Ninang', 60),
(7, 'Funeral Mass', 'Funeral', 'Misa para sa namayapa bago ilibing.', 1000.00, 'Death Certificate', 60),
(8, 'House/Business Blessing', 'Blessing', 'Pagbabasbas ng bahay, opisina, o negosyo.', 1000.00, '', 60),
(9, 'Vehicle Blessing', 'Blessing', 'Pagbabasbas ng sasakyan. Please park at the designated church parking area.', 300.00, '', 15);

-- Sample announcement
INSERT INTO announcements (title, content, posted_by, is_pinned, status) VALUES
('Welcome to Our Online Parish Portal', 'Malugod po kaming nagpapasalamat sa inyong pagbisita. Maaari na po kayong mag-book ng inyong mga Mass Intentions at schedule ng Binyag o Kasal gamit ang website na ito. Salamat po at pagpalain tayong lahat ng Maykapal.', 1, TRUE, 'published');

-- Sample settings
INSERT INTO settings (setting_key, setting_value) VALUES
('parish_name', 'San Juan Nepomuceno Parish'),
('parish_address', 'Poblacion, San Juan, Batangas'),
('office_hours', 'Tue-Sun: 8:00 AM - 12:00 NN, 1:00 PM - 5:00 PM (Closed Mondays)'),
('contact_number', '(043) 123-4567 / 0917-123-4567'),
('contact_email', 'sanjuanparish@parishhub.local'),
('mass_schedule', 'Mon-Sat: 6:00 AM | Sunday: 5:30 AM, 7:00 AM, 8:30 AM, 10:00 AM, 4:00 PM, 5:30 PM'),
('theme_primary', '#5d101d'),
('theme_secondary', '#cda434');
