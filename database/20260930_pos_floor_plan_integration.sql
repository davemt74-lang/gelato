-- Gelato Restaurant AI: native POS + floor plan integration
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pos_floor_plan_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  floor_plan_public_id VARCHAR(80) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_floor_plan_location (organization_id,location_id),
  KEY idx_pos_floor_plan_selected (organization_id,floor_plan_public_id),
  CONSTRAINT fk_pos_floor_plan_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_pos_floor_plan_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_pos_floor_plan_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE service_tables
  ADD COLUMN floor_plan_public_id VARCHAR(80) NULL AFTER section_id,
  ADD COLUMN floor_plan_component_id VARCHAR(120) NULL AFTER floor_plan_public_id,
  ADD COLUMN floor_plan_synced_at DATETIME(6) NULL AFTER floor_plan_component_id,
  ADD UNIQUE KEY uq_service_table_floor_component (organization_id,location_id,floor_plan_public_id,floor_plan_component_id),
  ADD KEY idx_service_table_floor_plan (organization_id,location_id,floor_plan_public_id,status);
