-- Gelato AR Glasses Station Calibration & Ingredient Zones
SET NAMES utf8mb4;

CREATE TABLE glasses_station_calibrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  platform VARCHAR(40) NOT NULL DEFAULT 'inmo_air3',
  frame_width INT UNSIGNED NOT NULL,
  frame_height INT UNSIGNED NOT NULL,
  pixel_format VARCHAR(40) NOT NULL DEFAULT 'grayscale8',
  source_hash CHAR(64) NOT NULL,
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  activated_by BIGINT UNSIGNED NULL,
  activated_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_station_calibration_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_station_calibration_version (organization_id,station_id,version),
  KEY idx_glasses_station_calibration_active (organization_id,location_id,station_id,status,version),
  CONSTRAINT fk_glasses_station_calibration_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_station_calibration_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_station_calibration_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_station_calibration_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_station_calibration_activator FOREIGN KEY (activated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_station_calibration_zones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  calibration_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  zone_key VARCHAR(160) NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  x_norm DECIMAL(8,6) NOT NULL,
  y_norm DECIMAL(8,6) NOT NULL,
  width_norm DECIMAL(8,6) NOT NULL,
  height_norm DECIMAL(8,6) NOT NULL,
  priority INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_station_zone_key (calibration_id,zone_key),
  KEY idx_glasses_station_zone_ingredient (organization_id,calibration_id,ingredient_id,priority),
  CONSTRAINT fk_glasses_station_zone_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_station_zone_calibration FOREIGN KEY (calibration_id) REFERENCES glasses_station_calibrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_station_zone_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
