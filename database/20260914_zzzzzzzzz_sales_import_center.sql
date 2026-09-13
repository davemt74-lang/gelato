-- Gelato Restaurant AI: Sales Import Center
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sales_import_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  provider VARCHAR(48) NOT NULL DEFAULT 'csv',
  cadence VARCHAR(16) NOT NULL DEFAULT 'manual',
  default_granularity VARCHAR(16) NOT NULL DEFAULT 'daily',
  default_service_period VARCHAR(32) NOT NULL DEFAULT 'all',
  header_signature CHAR(64) NOT NULL,
  mapping_json JSON NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  last_success_at DATETIME(6) NULL,
  last_period_start DATE NULL,
  last_period_end DATE NULL,
  last_filename VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_import_profile_public (organization_id,public_id),
  KEY idx_sales_import_profile_signature (organization_id,header_signature,status),
  KEY idx_sales_import_profile_cadence (organization_id,cadence,status,last_period_end),
  CONSTRAINT fk_sales_import_profile_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sales_import_profile_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_sales_import_profile_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_sales_import_profile_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales_import_batches
  ADD COLUMN import_profile_id BIGINT UNSIGNED NULL AFTER location_id,
  ADD KEY idx_sales_import_profile (organization_id,import_profile_id,created_at),
  ADD CONSTRAINT fk_sales_import_batch_profile FOREIGN KEY (import_profile_id) REFERENCES sales_import_profiles(id) ON DELETE SET NULL;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('sales.import_profiles.manage','Manage sales import profiles','Save CSV column mappings, cadence expectations and reusable manual import profiles.','Sales Intelligence')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='sales.import'
JOIN permissions newp ON newp.permission_key='sales.import_profiles.manage';
