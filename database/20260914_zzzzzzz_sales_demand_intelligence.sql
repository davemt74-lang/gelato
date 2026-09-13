-- Gelato Restaurant AI: sales, demand and labor intelligence
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sales_integrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  provider VARCHAR(48) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'available',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  restaurant_external_id VARCHAR(180) NULL,
  credential_reference VARCHAR(255) NULL,
  settings_json JSON NULL,
  sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_sync_started_at DATETIME(6) NULL,
  last_synced_at DATETIME(6) NULL,
  last_sync_status VARCHAR(32) NULL,
  last_sync_message VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_integration_provider (organization_id,provider,location_id),
  KEY idx_sales_integration_primary (organization_id,is_primary,status),
  CONSTRAINT fk_sales_integration_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_integration_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_sales_integration_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_sales_integration_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  provider VARCHAR(48) NOT NULL DEFAULT 'csv',
  original_filename VARCHAR(255) NOT NULL,
  file_checksum CHAR(64) NOT NULL,
  detected_delimiter VARCHAR(8) NULL,
  detected_headers_json JSON NULL,
  mapping_json JSON NULL,
  period_start DATE NULL,
  period_end DATE NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'processing',
  error_summary TEXT NULL,
  imported_by BIGINT UNSIGNED NOT NULL,
  imported_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_import_public (organization_id,public_id),
  KEY idx_sales_import_checksum (organization_id,file_checksum,created_at),
  KEY idx_sales_import_period (organization_id,period_start,period_end),
  CONSTRAINT fk_sales_import_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_import_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_sales_import_user FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_import_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  import_batch_id BIGINT UNSIGNED NOT NULL,
  source_row_number INT UNSIGNED NOT NULL,
  row_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
  rejection_reason VARCHAR(1000) NULL,
  normalized_json JSON NULL,
  raw_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_import_row (import_batch_id,source_row_number),
  KEY idx_sales_import_row_status (organization_id,row_status,created_at),
  CONSTRAINT fk_sales_import_row_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_import_row_batch FOREIGN KEY (import_batch_id) REFERENCES sales_import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  location_key VARCHAR(180) NOT NULL DEFAULT 'all',
  source_provider VARCHAR(48) NOT NULL DEFAULT 'csv',
  import_batch_id BIGINT UNSIGNED NULL,
  granularity VARCHAR(16) NOT NULL DEFAULT 'daily',
  service_period VARCHAR(32) NOT NULL DEFAULT 'all',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  tickets INT UNSIGNED NOT NULL DEFAULT 0,
  covers INT UNSIGNED NOT NULL DEFAULT 0,
  gross_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tips_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  discounts_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  comps_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  voids_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  refunds_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  service_charges_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  source_metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_period (organization_id,location_key,source_provider,granularity,service_period,period_start,period_end),
  KEY idx_sales_period_range (organization_id,source_provider,granularity,period_start,period_end),
  KEY idx_sales_period_location (organization_id,location_id,period_start),
  CONSTRAINT fk_sales_period_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_period_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_sales_period_import FOREIGN KEY (import_batch_id) REFERENCES sales_import_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_item_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  sales_period_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  source_item_key VARCHAR(220) NOT NULL,
  source_item_id VARCHAR(180) NULL,
  item_name VARCHAR(240) NOT NULL,
  category_name VARCHAR(180) NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  gross_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  discounts_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  refunds_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_item_period (sales_period_id,source_item_key),
  KEY idx_sales_item_org_name (organization_id,item_name),
  KEY idx_sales_item_menu (organization_id,menu_item_id),
  CONSTRAINT fk_sales_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_item_period FOREIGN KEY (sales_period_id) REFERENCES sales_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_item_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_hourly (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  location_key VARCHAR(180) NOT NULL DEFAULT 'all',
  source_provider VARCHAR(48) NOT NULL DEFAULT 'csv',
  import_batch_id BIGINT UNSIGNED NULL,
  business_date DATE NOT NULL,
  hour_start TINYINT UNSIGNED NOT NULL,
  tickets INT UNSIGNED NOT NULL DEFAULT 0,
  covers INT UNSIGNED NOT NULL DEFAULT 0,
  gross_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_hour (organization_id,location_key,source_provider,business_date,hour_start),
  KEY idx_sales_hour_range (organization_id,source_provider,business_date,hour_start),
  CONSTRAINT fk_sales_hour_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_hour_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_sales_hour_import FOREIGN KEY (import_batch_id) REFERENCES sales_import_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_forecasts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  location_key VARCHAR(180) NOT NULL DEFAULT 'all',
  public_id VARCHAR(80) NOT NULL,
  forecast_date DATE NOT NULL,
  service_period VARCHAR(32) NOT NULL DEFAULT 'all',
  source_provider VARCHAR(48) NOT NULL,
  projected_tickets DECIMAL(12,2) NULL,
  projected_covers DECIMAL(12,2) NULL,
  projected_net_sales DECIMAL(14,2) NULL,
  projected_labor_hours DECIMAL(12,2) NULL,
  projected_labor_cost DECIMAL(14,2) NULL,
  scheduled_labor_hours DECIMAL(12,2) NULL,
  scheduled_labor_cost DECIMAL(14,2) NULL,
  confidence DECIMAL(5,4) NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  method VARCHAR(120) NOT NULL DEFAULT 'same_weekday_history',
  basis_json JSON NULL,
  generated_by BIGINT UNSIGNED NULL,
  generated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_forecast_public (organization_id,public_id),
  UNIQUE KEY uq_sales_forecast_date (organization_id,location_key,forecast_date,service_period,source_provider),
  KEY idx_sales_forecast_upcoming (organization_id,forecast_date,service_period),
  CONSTRAINT fk_sales_forecast_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_forecast_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_sales_forecast_user FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_forecast_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  sales_forecast_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  source_item_key VARCHAR(220) NOT NULL,
  item_name VARCHAR(240) NOT NULL,
  category_name VARCHAR(180) NULL,
  projected_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  projected_net_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  confidence DECIMAL(5,4) NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_forecast_item (sales_forecast_id,source_item_key),
  KEY idx_sales_forecast_item_org (organization_id,item_name),
  CONSTRAINT fk_sales_forecast_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_forecast_item_forecast FOREIGN KEY (sales_forecast_id) REFERENCES sales_forecasts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_forecast_item_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('sales.view','View sales intelligence','View imported sales, trends, item mix, labor metrics and forecasts.','Sales Intelligence'),
 ('sales.import','Import sales data','Upload and normalize restaurant sales CSV files.','Sales Intelligence'),
 ('sales.agent','Use sales intelligence Agent skills','Allow Gelato to answer factual sales, demand and staffing-capacity questions.','Sales Intelligence'),
 ('sales.integrations.manage','Manage sales integrations','Configure sales providers and select the canonical POS source.','Sales Intelligence')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Preserve manager/operations access intent.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('inventory.view','staff.manage','purchasing.view')
JOIN permissions newp ON newp.permission_key='sales.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('inventory.manage','staff.manage','purchasing.manage')
JOIN permissions newp ON newp.permission_key='sales.import';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('prep.intelligence.agent','schedule.agent','inventory.agent')
JOIN permissions newp ON newp.permission_key='sales.agent';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key='sales.integrations.manage' WHERE r.is_owner_role=1;
