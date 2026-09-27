-- Gelato Glasses Plugin Foundation
SET NAMES utf8mb4;

CREATE TABLE glasses_pairing_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_pairing_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_pairing_code_hash (code_hash),
  KEY idx_glasses_pairing_active (organization_id,expires_at,consumed_at),
  CONSTRAINT fk_glasses_pairing_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_pairing_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_pairing_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_pairing_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  hardware_identifier VARCHAR(190) NULL,
  display_name VARCHAR(120) NOT NULL,
  platform VARCHAR(40) NOT NULL DEFAULT 'inmo_air3',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  token_hash CHAR(64) NOT NULL,
  sdk_version VARCHAR(80) NULL,
  app_version VARCHAR(80) NULL,
  system_version VARCHAR(80) NULL,
  capabilities_json JSON NULL,
  last_seen_at DATETIME(6) NULL,
  paired_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  paired_by BIGINT UNSIGNED NULL,
  revoked_at DATETIME(6) NULL,
  revoked_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_device_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_device_token_hash (token_hash),
  UNIQUE KEY uq_glasses_device_hardware (organization_id,hardware_identifier),
  KEY idx_glasses_device_location (organization_id,location_id,status,last_seen_at),
  KEY idx_glasses_device_station (organization_id,station_id,status),
  CONSTRAINT fk_glasses_device_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_device_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_device_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_device_paired_by FOREIGN KEY (paired_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_device_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_device_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  station_id BIGINT UNSIGNED NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_events_device (organization_id,device_id,created_at),
  KEY idx_glasses_events_type (organization_id,event_type,created_at),
  CONSTRAINT fk_glasses_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_event_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_event_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_event_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('glasses.view','View AR glasses','View paired AR glasses, station assignments and connection status.','Kitchen Display'),
 ('glasses.manage','Manage AR glasses','Pair, assign, rename and revoke AR glasses used with Kitchen Display.','Kitchen Display')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='kds.view'
JOIN permissions newp ON newp.permission_key='glasses.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='kds.configure'
JOIN permissions newp ON newp.permission_key IN ('glasses.view','glasses.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.permission_key IN ('glasses.view','glasses.manage')
WHERE r.is_owner_role=1;
