-- MySQL/MariaDB variant for deployments using database/schema.sql.
-- Review and run explicitly; this file is not executed by the application.
START TRANSACTION;

CREATE TABLE IF NOT EXISTS service_fee_rules (
    fee_rule_id INT AUTO_INCREMENT PRIMARY KEY,
    service_category VARCHAR(50) NOT NULL,
    schedule_type VARCHAR(10) NOT NULL,
    pss_classification VARCHAR(20) NOT NULL,
    base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    priest_stipend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    included_sponsors INT NOT NULL DEFAULT 0,
    additional_sponsor_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uq_service_fee_rule (service_category, schedule_type, pss_classification)
) ENGINE=InnoDB;

ALTER TABLE appointments ADD COLUMN pss_claim VARCHAR(20) NULL;
ALTER TABLE appointments ADD COLUMN pss_classification VARCHAR(20) NULL;
ALTER TABLE appointments ADD COLUMN sponsor_count INT NULL;
ALTER TABLE appointments ADD COLUMN wedding_sponsor_count INT NULL;
ALTER TABLE appointments ADD COLUMN fee_snapshot JSON NULL;
ALTER TABLE appointments ADD COLUMN pss_verified_by INT NULL;
ALTER TABLE appointments ADD COLUMN pss_verified_at DATETIME NULL;

INSERT INTO service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee) VALUES
 ('Baptism','Regular','pss',0,0,2,100), ('Baptism','Regular','non_pss',1000,0,2,100),
 ('Baptism','Special','pss',1000,1000,2,100), ('Baptism','Special','non_pss',1500,1000,2,100),
 ('Wedding','Regular','pss',0,0,4,100), ('Wedding','Regular','non_pss',3000,0,4,100),
 ('Wedding','Special','pss',4000,2000,4,100), ('Wedding','Special','non_pss',8000,2000,4,100),
 ('Funeral','Any','pss',0,0,0,0), ('Funeral','Any','non_pss',2000,1500,0,0),
 ('Wake','Any','pss',0,1500,0,0), ('Wake','Any','non_pss',0,1500,0,0)
ON DUPLICATE KEY UPDATE base_fee = VALUES(base_fee), priest_stipend = VALUES(priest_stipend), included_sponsors = VALUES(included_sponsors), additional_sponsor_fee = VALUES(additional_sponsor_fee);

COMMIT;
