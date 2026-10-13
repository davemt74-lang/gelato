-- Section 257: Persist restaurant/station camera roles and capture-zone configuration.
-- Physical device identifiers are browser-local; they are intentionally not saved server-side.
CREATE TABLE glasses_camera_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  slot TINYINT UNSIGNED NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  role VARCHAR(24) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  zone_x DECIMAL(7,5) NOT NULL DEFAULT 0.10000,
  zone_y DECIMAL(7,5) NOT NULL DEFAULT 0.10000,
  zone_width DECIMAL(7,5) NOT NULL DEFAULT 0.80000,
  zone_height DECIMAL(7,5) NOT NULL DEFAULT 0.80000,
  rotation SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_camera_org_location_slot (organization_id,location_id,slot),
  KEY idx_glasses_camera_station (organization_id,location_id,station_id,enabled),
  CONSTRAINT fk_glasses_camera_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_camera_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_camera_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_camera_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_glasses_camera_slot CHECK (slot BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
