-- Gelato Restaurant AI: Wholesale Commerce Contract + Acquisition Worksheet
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wholesale_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(220) NOT NULL,
  category VARCHAR(120) NULL,
  description TEXT NULL,
  recipe_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_products_org_public (organization_id, public_id),
  KEY idx_wholesale_products_org_status (organization_id, status, archived_at, name),
  KEY idx_wholesale_products_recipe (organization_id, recipe_id),
  CONSTRAINT fk_wholesale_products_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_products_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
  CONSTRAINT fk_wholesale_products_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_products_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_skus (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_product_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  sku VARCHAR(100) NOT NULL,
  name VARCHAR(220) NOT NULL,
  sell_uom VARCHAR(80) NOT NULL DEFAULT 'case',
  units_per_sell_uom DECIMAL(14,4) NOT NULL DEFAULT 1,
  minimum_quantity DECIMAL(14,4) NOT NULL DEFAULT 1,
  quantity_increment DECIMAL(14,4) NOT NULL DEFAULT 1,
  recipe_yield_per_batch DECIMAL(14,4) NULL,
  recipe_yield_unit VARCHAR(80) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_skus_org_public (organization_id, public_id),
  UNIQUE KEY uq_wholesale_skus_org_sku (organization_id, sku),
  KEY idx_wholesale_skus_product (organization_id, wholesale_product_id, status, archived_at),
  CONSTRAINT fk_wholesale_skus_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_skus_product FOREIGN KEY (wholesale_product_id) REFERENCES wholesale_products(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_skus_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_skus_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_price_lists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  minimum_order_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  effective_from DATE NULL,
  effective_until DATE NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_price_lists_org_public (organization_id, public_id),
  KEY idx_wholesale_price_lists_active (organization_id, status, is_default, effective_from, effective_until),
  CONSTRAINT fk_wholesale_price_lists_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_price_lists_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_price_lists_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_price_list_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  price_list_id BIGINT UNSIGNED NOT NULL,
  wholesale_sku_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  unit_price DECIMAL(12,4) NOT NULL,
  minimum_quantity DECIMAL(14,4) NULL,
  effective_from DATE NULL,
  effective_until DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_price_item_public (organization_id, public_id),
  KEY idx_wholesale_price_item_lookup (organization_id, price_list_id, wholesale_sku_id, effective_from, effective_until),
  CONSTRAINT fk_wholesale_price_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_price_items_list FOREIGN KEY (price_list_id) REFERENCES wholesale_price_lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_price_items_sku FOREIGN KEY (wholesale_sku_id) REFERENCES wholesale_skus(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_price_items_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_account_price_lists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  price_list_id BIGINT UNSIGNED NOT NULL,
  effective_from DATE NULL,
  effective_until DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_wholesale_account_price_active (organization_id, wholesale_account_id, effective_from, effective_until),
  UNIQUE KEY uq_wholesale_account_price_assignment (wholesale_account_id, price_list_id, effective_from),
  CONSTRAINT fk_wholesale_account_price_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_account_price_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_account_price_list FOREIGN KEY (price_list_id) REFERENCES wholesale_price_lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_account_price_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE wholesale_quotes
  ADD COLUMN price_list_id BIGINT UNSIGNED NULL AFTER wholesale_account_id,
  ADD COLUMN tax_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER tax_total,
  ADD KEY idx_wholesale_quotes_price_list (organization_id, price_list_id);

ALTER TABLE wholesale_orders
  ADD COLUMN price_list_id BIGINT UNSIGNED NULL AFTER wholesale_account_id,
  ADD COLUMN tax_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER tax_total,
  ADD KEY idx_wholesale_orders_price_list (organization_id, price_list_id);

CREATE TABLE IF NOT EXISTS wholesale_quote_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_quote_id BIGINT UNSIGNED NOT NULL,
  line_number INT UNSIGNED NOT NULL,
  wholesale_sku_id BIGINT UNSIGNED NULL,
  sku_snapshot VARCHAR(100) NULL,
  item_name_snapshot VARCHAR(220) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  sell_uom_snapshot VARCHAR(80) NULL,
  unit_price_snapshot DECIMAL(12,4) NOT NULL,
  line_subtotal DECIMAL(12,2) NOT NULL,
  pricing_source VARCHAR(40) NOT NULL DEFAULT 'canonical',
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_quote_line (wholesale_quote_id, line_number),
  KEY idx_wholesale_quote_items_org (organization_id, wholesale_quote_id),
  CONSTRAINT fk_wholesale_quote_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_quote_items_quote FOREIGN KEY (wholesale_quote_id) REFERENCES wholesale_quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_quote_items_sku FOREIGN KEY (wholesale_sku_id) REFERENCES wholesale_skus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_id BIGINT UNSIGNED NOT NULL,
  line_number INT UNSIGNED NOT NULL,
  wholesale_sku_id BIGINT UNSIGNED NULL,
  sku_snapshot VARCHAR(100) NULL,
  item_name_snapshot VARCHAR(220) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  sell_uom_snapshot VARCHAR(80) NULL,
  unit_price_snapshot DECIMAL(12,4) NOT NULL,
  line_subtotal DECIMAL(12,2) NOT NULL,
  pricing_source VARCHAR(40) NOT NULL DEFAULT 'canonical',
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_order_line (wholesale_order_id, line_number),
  KEY idx_wholesale_order_items_org (organization_id, wholesale_order_id),
  CONSTRAINT fk_wholesale_order_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_order_items_order FOREIGN KEY (wholesale_order_id) REFERENCES wholesale_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_order_items_sku FOREIGN KEY (wholesale_sku_id) REFERENCES wholesale_skus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_quote_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_quote_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_wholesale_quote_events (organization_id, wholesale_quote_id, created_at),
  CONSTRAINT fk_wholesale_quote_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_quote_events_quote FOREIGN KEY (wholesale_quote_id) REFERENCES wholesale_quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_quote_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_wholesale_order_events (organization_id, wholesale_order_id, created_at),
  CONSTRAINT fk_wholesale_order_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_order_events_order FOREIGN KEY (wholesale_order_id) REFERENCES wholesale_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_order_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_acquisition_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_lead_id BIGINT UNSIGNED NOT NULL,
  acquisition_channel VARCHAR(100) NULL,
  qualification_status VARCHAR(40) NOT NULL DEFAULT 'prospect',
  buyer_role VARCHAR(120) NULL,
  location_count INT UNSIGNED NULL,
  estimated_monthly_units DECIMAL(14,2) NULL,
  estimated_monthly_revenue DECIMAL(12,2) NULL,
  sample_interest VARCHAR(120) NULL,
  decision_timeline VARCHAR(160) NULL,
  pain_points TEXT NULL,
  buying_process TEXT NULL,
  storage_notes TEXT NULL,
  pricing_notes TEXT NULL,
  next_step TEXT NULL,
  completion_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_acquisition_lead (wholesale_lead_id),
  KEY idx_wholesale_acquisition_org (organization_id, qualification_status, updated_at),
  CONSTRAINT fk_wholesale_acquisition_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_acquisition_lead FOREIGN KEY (wholesale_lead_id) REFERENCES wholesale_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_acquisition_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_acquisition_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wholesale_price_lists (organization_id,public_id,name,currency,minimum_order_amount,is_default,status)
SELECT o.id,CONCAT('wpl-default-',o.id),'Standard Wholesale','USD',0,1,'active'
FROM organizations o
WHERE NOT EXISTS (
  SELECT 1 FROM wholesale_price_lists p WHERE p.organization_id=o.id AND p.archived_at IS NULL
);
