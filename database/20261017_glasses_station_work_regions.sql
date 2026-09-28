-- Gelato AR Glasses Transfer / Work Regions
SET NAMES utf8mb4;

CREATE TABLE glasses_station_calibration_regions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  calibration_id BIGINT UNSIGNED NOT NULL,
  region_key VARCHAR(160) NOT NULL,
  region_type VARCHAR(40) NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  x_norm DECIMAL(8,6) NOT NULL,
  y_norm DECIMAL(8,6) NOT NULL,
  width_norm DECIMAL(8,6) NOT NULL,
  height_norm DECIMAL(8,6) NOT NULL,
  priority INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_station_region_key (calibration_id,region_key),
  KEY idx_glasses_station_region_type (organization_id,calibration_id,region_type,priority),
  CONSTRAINT fk_glasses_station_region_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_station_region_calibration FOREIGN KEY (calibration_id) REFERENCES glasses_station_calibrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
