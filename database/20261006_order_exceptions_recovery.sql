-- Stonefellows / Gelato order exceptions + recovery operations
SET NAMES utf8mb4;

CREATE TABLE order_exceptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  online_order_id BIGINT UNSIGNED NOT NULL,
  pos_check_id BIGINT UNSIGNED NOT NULL,
  pos_check_item_id BIGINT UNSIGNED NULL,
  kds_order_item_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  exception_type VARCHAR(40) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  severity VARCHAR(16) NOT NULL DEFAULT 'medium',
  summary VARCHAR(500) NOT NULL,
  details TEXT NULL,
  customer_message VARCHAR(1000) NULL,
  recovery_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  requires_manager TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  escalated_by BIGINT UNSIGNED NULL,
  resolved_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  escalated_at DATETIME(6) NULL,
  resolved_at DATETIME(6) NULL,
  resolution_note VARCHAR(1000) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_exception_public (organization_id,public_id),
  KEY idx_order_exception_queue (organization_id,location_id,status,severity,created_at),
  KEY idx_order_exception_order (organization_id,online_order_id,status,created_at),
  KEY idx_order_exception_check (organization_id,pos_check_id,status,created_at),
  CONSTRAINT fk_order_exception_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_order_exception_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_order_exception_online_order FOREIGN KEY (online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_exception_check FOREIGN KEY (pos_check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_exception_pos_item FOREIGN KEY (pos_check_item_id) REFERENCES pos_check_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_exception_kds_item FOREIGN KEY (kds_order_item_id) REFERENCES kds_order_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_exception_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_order_exception_escalator FOREIGN KEY (escalated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_exception_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  online_order_id BIGINT UNSIGNED NOT NULL,
  pos_check_id BIGINT UNSIGNED NOT NULL,
  exception_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  refund_method VARCHAR(32) NOT NULL DEFAULT 'manual',
  reason VARCHAR(500) NOT NULL,
  external_reference VARCHAR(255) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'recorded',
  processed_by BIGINT UNSIGNED NOT NULL,
  processed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_refund_public (organization_id,public_id),
  KEY idx_pos_refund_check (organization_id,pos_check_id,status,processed_at),
  KEY idx_pos_refund_order (organization_id,online_order_id,status,processed_at),
  CONSTRAINT fk_pos_refund_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_refund_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_pos_refund_order FOREIGN KEY (online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_refund_check FOREIGN KEY (pos_check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_refund_exception FOREIGN KEY (exception_id) REFERENCES order_exceptions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pos_refund_processor FOREIGN KEY (processed_by) REFERENCES users(id),
  CONSTRAINT fk_pos_refund_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('order_recovery.view','View order recovery','View online-order exceptions, delays, remakes, no-shows and recovery history.','Online Orders'),
 ('order_recovery.manage','Manage order recovery','Create, escalate and resolve order exceptions and perform kitchen recovery actions.','Online Orders'),
 ('order_recovery.refund','Record POS refunds','Record manager-authorized cash or external-terminal refunds against paid online orders.','Point of Sale')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.use','kds.view','crm.view')
JOIN permissions newp ON newp.permission_key='order_recovery.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.manage','staff.manage','kds.configure')
JOIN permissions newp ON newp.permission_key IN ('order_recovery.view','order_recovery.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='pos.manage'
JOIN permissions newp ON newp.permission_key='order_recovery.refund';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('order_recovery.view','order_recovery.manage','order_recovery.refund');
