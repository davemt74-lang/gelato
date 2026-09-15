-- Stonefellows / Gelato pickup fulfillment operations
SET NAMES utf8mb4;

ALTER TABLE online_orders
  ADD COLUMN fulfilled_at DATETIME(6) NULL AFTER requested_ready_at,
  ADD COLUMN fulfilled_by_user_id BIGINT UNSIGNED NULL AFTER fulfilled_at,
  ADD COLUMN fulfillment_note VARCHAR(500) NULL AFTER fulfilled_by_user_id,
  ADD KEY idx_online_orders_fulfillment (organization_id,location_id,fulfilled_at,requested_ready_at),
  ADD CONSTRAINT fk_online_order_fulfilled_by FOREIGN KEY (fulfilled_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('online_orders.fulfill','Fulfill pickup orders','Mark physically ready customer pickup orders as handed to the customer.','Online Orders')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Existing POS operators and KDS updaters inherit pickup handoff capability.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.use','kds.update')
JOIN permissions newp ON newp.permission_key='online_orders.fulfill';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.permission_key='online_orders.fulfill'
WHERE r.is_owner_role=1;
