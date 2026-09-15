-- Stonefellows / Gelato package deals + typed discount ledger
SET NAMES utf8mb4;

CREATE TABLE pos_discounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  pos_check_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  discount_type VARCHAR(48) NOT NULL,
  method VARCHAR(16) NOT NULL DEFAULT 'fixed',
  value DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reason VARCHAR(500) NOT NULL,
  reference_type VARCHAR(48) NULL,
  reference_id VARCHAR(120) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  applied_by BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_discount_public (organization_id,public_id),
  KEY idx_pos_discount_check (organization_id,pos_check_id,status,applied_at),
  KEY idx_pos_discount_type (organization_id,discount_type,status,applied_at),
  KEY idx_pos_discount_reference (organization_id,reference_type,reference_id,status),
  CONSTRAINT fk_pos_discount_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_discount_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_pos_discount_check FOREIGN KEY (pos_check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_discount_applier FOREIGN KEY (applied_by) REFERENCES users(id),
  CONSTRAINT fk_pos_discount_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE package_deals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  slug VARCHAR(140) NOT NULL,
  name VARCHAR(180) NOT NULL,
  eyebrow VARCHAR(120) NULL,
  description TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  discount_method VARCHAR(16) NOT NULL DEFAULT 'percent',
  discount_value DECIMAL(14,4) NOT NULL DEFAULT 10.0000,
  pickup_only TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME(6) NULL,
  ends_at DATETIME(6) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  paused_at DATETIME(6) NULL,
  ended_at DATETIME(6) NULL,
  archived_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_package_public (organization_id,public_id),
  UNIQUE KEY uq_package_slug (organization_id,slug),
  KEY idx_package_status (organization_id,status,sort_order,created_at),
  CONSTRAINT fk_package_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_package_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_package_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE package_deal_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  package_deal_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  label VARCHAR(140) NOT NULL,
  required_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_package_group_public (organization_id,public_id),
  KEY idx_package_group (organization_id,package_deal_id,sort_order,id),
  CONSTRAINT fk_package_group_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_package_group_package FOREIGN KEY (package_deal_id) REFERENCES package_deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE package_deal_group_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  package_deal_id BIGINT UNSIGNED NOT NULL,
  package_group_id BIGINT UNSIGNED NOT NULL,
  menu_item_price_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_package_group_price (package_group_id,menu_item_price_id),
  KEY idx_package_item (organization_id,package_deal_id,package_group_id,sort_order),
  CONSTRAINT fk_package_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_package_item_package FOREIGN KEY (package_deal_id) REFERENCES package_deals(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_item_group FOREIGN KEY (package_group_id) REFERENCES package_deal_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_item_price FOREIGN KEY (menu_item_price_id) REFERENCES menu_item_prices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE package_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  package_deal_id BIGINT UNSIGNED NOT NULL,
  online_order_id BIGINT UNSIGNED NOT NULL,
  pos_check_id BIGINT UNSIGNED NOT NULL,
  pos_discount_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  package_name_snapshot VARCHAR(180) NOT NULL,
  retail_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  package_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_method VARCHAR(16) NOT NULL,
  discount_value DECIMAL(14,4) NOT NULL,
  selection_json LONGTEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_package_redemption_public (organization_id,public_id),
  UNIQUE KEY uq_package_redemption_order (organization_id,online_order_id),
  KEY idx_package_redemption_deal (organization_id,package_deal_id,created_at),
  KEY idx_package_redemption_check (organization_id,pos_check_id),
  CONSTRAINT fk_package_redemption_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_package_redemption_deal FOREIGN KEY (package_deal_id) REFERENCES package_deals(id),
  CONSTRAINT fk_package_redemption_order FOREIGN KEY (online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_redemption_check FOREIGN KEY (pos_check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_redemption_discount FOREIGN KEY (pos_discount_id) REFERENCES pos_discounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('packages.view','View package deals','View pickup-only package deals, composition and redemption performance.','Sales'),
 ('packages.manage','Manage package deals','Create, duplicate, pause, resume, end and archive pickup package deals.','Sales'),
 ('discounts.view','View discounts','View typed discounts and their business reasons.','Point of Sale'),
 ('discounts.manage','Manage discounts','Apply or void manager-authorized discounts.','Point of Sale')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.manage','public_pages.edit')
JOIN permissions newp ON newp.permission_key IN ('packages.view','packages.manage','discounts.view');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='pos.manage'
JOIN permissions newp ON newp.permission_key='discounts.manage';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('packages.view','packages.manage','discounts.view','discounts.manage');