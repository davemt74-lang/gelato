-- Gelato Restaurant AI: Sales Cost + Margin Intelligence
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sales_cost_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  source_item_key VARCHAR(220) NULL,
  recipe_id BIGINT UNSIGNED NULL,
  cost_method VARCHAR(24) NOT NULL DEFAULT 'recipe',
  manual_unit_cost DECIMAL(14,4) NULL,
  servings_per_recipe DECIMAL(14,4) NOT NULL DEFAULT 1,
  waste_factor_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  last_costed_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_cost_profile_public (organization_id,public_id),
  UNIQUE KEY uq_sales_cost_profile_menu (organization_id,menu_item_id),
  UNIQUE KEY uq_sales_cost_profile_source (organization_id,source_item_key),
  KEY idx_sales_cost_profile_recipe (organization_id,recipe_id,status),
  CONSTRAINT fk_sales_cost_profile_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_cost_profile_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_cost_profile_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_cost_profile_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_sales_cost_profile_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('sales.costs.view','View sales cost and margin intelligence','View theoretical food cost, gross margin, purchasing spend, waste cost and cost coverage.','Sales Intelligence'),
 ('sales.costs.manage','Manage sales cost mappings','Map sold items to recipes or manual unit costs and maintain cost assumptions.','Sales Intelligence')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('sales.view','purchasing.view','inventory.view')
JOIN permissions newp ON newp.permission_key='sales.costs.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('sales.import','purchasing.manage','inventory.manage')
JOIN permissions newp ON newp.permission_key='sales.costs.manage';
