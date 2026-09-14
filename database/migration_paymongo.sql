ALTER TYPE payment_status_type ADD VALUE IF NOT EXISTS 'cancelled';

INSERT INTO payment_methods (method_id, method_name)
SELECT 7, 'PayMongo (Online)'
WHERE NOT EXISTS (SELECT 1 FROM payment_methods WHERE method_id = 7);
