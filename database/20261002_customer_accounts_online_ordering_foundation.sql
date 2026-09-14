-- Stonefellows / Gelato customer accounts + online-ordering identity foundation
SET NAMES utf8mb4;

ALTER TABLE crm_customers
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER organization_id,
  ADD UNIQUE KEY uq_crm_customer_user (organization_id,user_id),
  ADD KEY idx_crm_customer_user_lookup (user_id,status),
  ADD CONSTRAINT fk_crm_customer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('customer.portal','Use customer account','Access the customer-facing account, profile and order-history experience.','Customer'),
 ('online_ordering.use','Place online orders','Create customer-facing pickup and delivery orders through the restaurant online-ordering flow.','Online Ordering')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Customer is a deliberately narrow public-facing role. It receives only
-- customer-facing permissions; it does not inherit staff, POS, CRM-management,
-- reporting, scheduling, or administration access.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.permission_key IN ('customer.portal','online_ordering.use')
WHERE r.slug='customer';

-- Future Customer roles receive the same narrow permission set even when an
-- organization is created after this migration has already run.
DROP TRIGGER IF EXISTS roles_seed_customer_account_permissions;
CREATE TRIGGER roles_seed_customer_account_permissions
AFTER INSERT ON roles
FOR EACH ROW
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT NEW.id,p.id
FROM permissions p
WHERE NEW.slug='customer'
  AND p.permission_key IN ('customer.portal','online_ordering.use');

-- Owners retain visibility into the customer account capability catalog.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.permission_key IN ('customer.portal','online_ordering.use')
WHERE r.is_owner_role=1;
