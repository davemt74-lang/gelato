-- Stonefellows / Gelato Menu Manager + Add Food builder
SET NAMES utf8mb4;

CREATE TABLE menu_item_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(24) NOT NULL DEFAULT 'food',
  lifecycle_status VARCHAR(24) NOT NULL DEFAULT 'draft',
  kitchen_station VARCHAR(120) NULL,
  public_menu_enabled TINYINT(1) NOT NULL DEFAULT 1,
  online_order_enabled TINYINT(1) NOT NULL DEFAULT 1,
  pos_enabled TINYINT(1) NOT NULL DEFAULT 1,
  packages_enabled TINYINT(1) NOT NULL DEFAULT 1,
  catering_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_item_profile_item (organization_id,menu_item_id),
  KEY idx_menu_item_profile_status (organization_id,item_type,lifecycle_status),
  CONSTRAINT fk_menu_item_profile_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_item_profile_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_profile_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_menu_item_profile_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_location_availability (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_item_location (organization_id,menu_item_id,location_id),
  KEY idx_menu_item_location_available (organization_id,location_id,is_available),
  CONSTRAINT fk_menu_item_location_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_item_location_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_location_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_location_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_modifier_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  modifier_type VARCHAR(24) NOT NULL DEFAULT 'add_on',
  min_select INT UNSIGNED NOT NULL DEFAULT 0,
  max_select INT UNSIGNED NOT NULL DEFAULT 10,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_modifier_group_public (organization_id,public_id),
  KEY idx_menu_modifier_group_item (organization_id,menu_item_id,status,sort_order),
  CONSTRAINT fk_menu_modifier_group_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_modifier_group_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_modifier_group_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_modifier_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  modifier_group_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  default_price_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_modifier_option_public (organization_id,public_id),
  KEY idx_menu_modifier_option_group (organization_id,modifier_group_id,status,sort_order),
  CONSTRAINT fk_menu_modifier_option_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_modifier_option_group FOREIGN KEY (modifier_group_id) REFERENCES menu_modifier_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_modifier_option_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_modifier_option_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  modifier_option_id BIGINT UNSIGNED NOT NULL,
  menu_item_price_id BIGINT UNSIGNED NOT NULL,
  amount_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_modifier_option_price (modifier_option_id,menu_item_price_id),
  KEY idx_menu_modifier_option_price_item (organization_id,menu_item_price_id),
  CONSTRAINT fk_menu_modifier_option_price_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_modifier_option_price_option FOREIGN KEY (modifier_option_id) REFERENCES menu_modifier_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_modifier_option_price_price FOREIGN KEY (menu_item_price_id) REFERENCES menu_item_prices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pos_check_items ADD COLUMN IF NOT EXISTS modifier_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER gross_amount;

CREATE TABLE pos_check_item_modifiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  pos_check_item_id BIGINT UNSIGNED NOT NULL,
  modifier_option_id BIGINT UNSIGNED NULL,
  modifier_group_name_snapshot VARCHAR(160) NOT NULL,
  modifier_name_snapshot VARCHAR(160) NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_pos_item_modifier_item (organization_id,pos_check_item_id),
  CONSTRAINT fk_pos_item_modifier_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_item_modifier_item FOREIGN KEY (pos_check_item_id) REFERENCES pos_check_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_item_modifier_option FOREIGN KEY (modifier_option_id) REFERENCES menu_modifier_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('menu.manage','Manage menu','Create and edit menu categories, food items, sizes, ingredients, add-ons and distribution.','Menu'),
 ('menu.view','View menu manager','View editable menu structure and food-item configuration.','Menu')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.manage','public_pages.edit')
JOIN permissions newp ON newp.permission_key='menu.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='pos.manage'
JOIN permissions newp ON newp.permission_key='menu.manage';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('menu.view','menu.manage');
