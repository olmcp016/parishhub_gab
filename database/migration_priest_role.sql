INSERT INTO roles (role_name, description)
SELECT 'Priest', 'Parish priest - view-only schedule and Mass Intention access'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Priest');
