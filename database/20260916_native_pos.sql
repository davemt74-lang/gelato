-- Gelato Restaurant AI: native restaurant POS
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pos_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  location_key VARCHAR(180) NOT NULL,
  tax_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
  service_charge_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
  default_service_mode VARCHAR(32) NOT NULL DEFAULT 'dine_in',
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_settings_scope (organization_id,location_key),
  KEY idx_pos_settings_location (organization_id,location_id),
  CONSTRAINT fk_pos_settings_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_settings_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_pos_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_checks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  check_number VARCHAR(48) NOT NULL,
  business_date DATE NOT NULL,
  service_mode VARCHAR(32) NOT NULL DEFAULT 'dine_in',
  table_name VARCHAR(120) NULL,
  guest_count INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_reason VARCHAR(500) NULL,
  tax_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  service_charge_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
  service_charge_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tip_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  notes TEXT NULL,
  cancel_reason VARCHAR(500) NULL,
  opened_by BIGINT UNSIGNED NOT NULL,
  closed_by BIGINT UNSIGNED NULL,
  cancelled_by BIGINT UNSIGNED NULL,
  opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  closed_at DATETIME(6) NULL,
  closed_hour TINYINT UNSIGNED NULL,
  cancelled_at DATETIME(6) NULL,
  sales_posted_at DATETIME(6) NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_check_public (organization_id,public_id),
  UNIQUE KEY uq_pos_check_number (organization_id,check_number),
  KEY idx_pos_checks_open (organization_id,location_id,status,opened_at),
  KEY idx_pos_checks_business_date (organization_id,location_id,business_date,status),
  CONSTRAINT fk_pos_check_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_check_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_pos_check_opener FOREIGN KEY (opened_by) REFERENCES users(id),
  CONSTRAINT fk_pos_check_closer FOREIGN KEY (closed_by) REFERENCES users(id),
  CONSTRAINT fk_pos_check_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_check_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  check_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  menu_item_price_id BIGINT UNSIGNED NOT NULL,
  item_name_snapshot VARCHAR(240) NOT NULL,
  option_name_snapshot VARCHAR(160) NOT NULL,
  category_name_snapshot VARCHAR(180) NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(14,2) NOT NULL,
  gross_amount DECIMAL(14,2) NOT NULL,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net_amount DECIMAL(14,2) NOT NULL,
  special_instructions VARCHAR(1000) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  void_reason VARCHAR(500) NULL,
  added_by BIGINT UNSIGNED NOT NULL,
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_pos_items_check (organization_id,check_id,status,id),
  KEY idx_pos_items_menu (organization_id,menu_item_id,created_at),
  CONSTRAINT fk_pos_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_item_check FOREIGN KEY (check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_item_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
  CONSTRAINT fk_pos_item_price FOREIGN KEY (menu_item_price_id) REFERENCES menu_item_prices(id),
  CONSTRAINT fk_pos_item_adder FOREIGN KEY (added_by) REFERENCES users(id),
  CONSTRAINT fk_pos_item_voider FOREIGN KEY (voided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_tenders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  check_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  tender_type VARCHAR(32) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  tip_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  received_amount DECIMAL(14,2) NULL,
  change_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  external_reference VARCHAR(255) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'captured',
  processed_by BIGINT UNSIGNED NOT NULL,
  processed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_tender_public (organization_id,public_id),
  KEY idx_pos_tenders_check (organization_id,check_id,status,processed_at),
  CONSTRAINT fk_pos_tender_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_tender_check FOREIGN KEY (check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_tender_processor FOREIGN KEY (processed_by) REFERENCES users(id),
  CONSTRAINT fk_pos_tender_voider FOREIGN KEY (voided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('pos.use','Use native POS','Open checks, add menu items and accept permitted tenders in the native Gelato POS.','Point of Sale'),
 ('pos.discount','Apply POS discounts','Apply manager-authorized check discounts and reasons.','Point of Sale'),
 ('pos.void','Void or cancel POS activity','Void ticket items or cancel an open check with a reason.','Point of Sale'),
 ('pos.manage','Manage native POS','Configure location tax/service-charge settings and native POS sales-source behavior.','Point of Sale')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Preserve manager/operations access intent without granting every employee POS privileges.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('sales.view','inventory.view','purchasing.view')
JOIN permissions newp ON newp.permission_key='pos.use';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('staff.manage','sales.integrations.manage','inventory.manage')
JOIN permissions newp ON newp.permission_key IN ('pos.discount','pos.void','pos.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('pos.use','pos.discount','pos.void','pos.manage');
