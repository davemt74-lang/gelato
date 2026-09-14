-- Stonefellows / Gelato online ordering + customer inbox
SET NAMES utf8mb4;

CREATE TABLE customer_inbox_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  message_type VARCHAR(32) NOT NULL DEFAULT 'promotion',
  title VARCHAR(180) NOT NULL,
  preview_text VARCHAR(320) NULL,
  body_text TEXT NOT NULL,
  cta_label VARCHAR(80) NULL,
  cta_url VARCHAR(500) NULL,
  promo_code VARCHAR(80) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'sent',
  starts_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  sent_by BIGINT UNSIGNED NULL,
  sent_at DATETIME(6) NULL,
  archived_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_inbox_message_public (organization_id,public_id),
  KEY idx_customer_inbox_message_status (organization_id,status,starts_at,expires_at),
  KEY idx_customer_inbox_message_location (organization_id,location_id,status),
  CONSTRAINT fk_customer_inbox_message_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_customer_inbox_message_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_inbox_message_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_inbox_message_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_inbox_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  delivered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  read_at DATETIME(6) NULL,
  dismissed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_inbox_recipient (message_id,customer_id),
  KEY idx_customer_inbox_customer (organization_id,customer_id,read_at,delivered_at),
  CONSTRAINT fk_customer_inbox_recipient_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_customer_inbox_recipient_message FOREIGN KEY (message_id) REFERENCES customer_inbox_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_inbox_recipient_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_inbox_preferences (
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  promotions_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (customer_id),
  KEY idx_customer_inbox_preferences_org (organization_id,promotions_enabled,customer_id),
  CONSTRAINT fk_customer_inbox_preferences_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_customer_inbox_preferences_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_inbox_preferences_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE online_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  pos_check_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  idempotency_key VARCHAR(80) NOT NULL,
  service_mode VARCHAR(24) NOT NULL DEFAULT 'pickup',
  payment_mode VARCHAR(32) NOT NULL DEFAULT 'pay_at_pickup',
  status VARCHAR(24) NOT NULL DEFAULT 'submitted',
  requested_ready_at DATETIME(6) NULL,
  customer_note VARCHAR(1000) NULL,
  submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_online_order_public (organization_id,public_id),
  UNIQUE KEY uq_online_order_idempotency (organization_id,customer_id,idempotency_key),
  UNIQUE KEY uq_online_order_pos_check (pos_check_id),
  KEY idx_online_orders_customer (organization_id,customer_id,submitted_at),
  KEY idx_online_orders_location (organization_id,location_id,status,submitted_at),
  CONSTRAINT fk_online_order_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_online_order_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_online_order_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id),
  CONSTRAINT fk_online_order_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_online_order_pos_check FOREIGN KEY (pos_check_id) REFERENCES pos_checks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('customer_promotions.manage','Send customer inbox promotions','Create and send in-account promotions to eligible customer accounts.','Customer CRM')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Existing CRM managers and owners can compose inbox promotions.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='crm.manage'
JOIN permissions newp ON newp.permission_key='customer_promotions.manage';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.permission_key='customer_promotions.manage'
WHERE r.is_owner_role=1;
