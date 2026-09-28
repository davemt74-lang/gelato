-- Gelato AR Glasses Expo / Finishing Validation
SET NAMES utf8mb4;

CREATE TABLE glasses_finishing_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  handoff_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  kds_order_item_id BIGINT UNSIGNED NOT NULL,
  calibration_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  item_name VARCHAR(220) NOT NULL,
  special_instructions TEXT NULL,
  context_json JSON NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ready_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_finishing_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_finishing_handoff (handoff_id),
  KEY idx_glasses_finishing_status (organization_id,status,updated_at),
  KEY idx_glasses_finishing_kds (organization_id,kds_order_item_id,status),
  CONSTRAINT fk_glasses_finishing_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_handoff FOREIGN KEY (handoff_id) REFERENCES glasses_kds_handoffs(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_kds FOREIGN KEY (kds_order_item_id) REFERENCES kds_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_calibration FOREIGN KEY (calibration_id) REFERENCES glasses_station_calibrations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_finishing_checks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  finishing_session_id BIGINT UNSIGNED NOT NULL,
  check_key VARCHAR(160) NOT NULL,
  check_type VARCHAR(48) NOT NULL,
  label VARCHAR(255) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'waiting',
  confidence DECIMAL(6,5) NULL,
  evidence_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  confirmed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_finishing_check (finishing_session_id,check_key),
  KEY idx_glasses_finishing_check_status (organization_id,finishing_session_id,status,sort_order),
  CONSTRAINT fk_glasses_finishing_check_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_check_session FOREIGN KEY (finishing_session_id) REFERENCES glasses_finishing_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_finishing_observations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  finishing_session_id BIGINT UNSIGNED NOT NULL,
  observation_key VARCHAR(190) NOT NULL,
  observation_type VARCHAR(48) NOT NULL,
  label VARCHAR(255) NOT NULL,
  confidence DECIMAL(6,5) NOT NULL,
  tracking_id VARCHAR(190) NULL,
  bbox_json JSON NULL,
  calibration_id BIGINT UNSIGNED NULL,
  region_key VARCHAR(160) NULL,
  accepted TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_finishing_observation (finishing_session_id,observation_key),
  KEY idx_glasses_finishing_observation_session (organization_id,finishing_session_id,created_at),
  CONSTRAINT fk_glasses_finishing_observation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_observation_session FOREIGN KEY (finishing_session_id) REFERENCES glasses_finishing_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_observation_calibration FOREIGN KEY (calibration_id) REFERENCES glasses_station_calibrations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_finishing_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  finishing_session_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  check_key VARCHAR(160) NULL,
  payload_json JSON NULL,
  actor_device_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_finishing_events (organization_id,finishing_session_id,created_at),
  CONSTRAINT fk_glasses_finishing_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_event_session FOREIGN KEY (finishing_session_id) REFERENCES glasses_finishing_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_finishing_event_device FOREIGN KEY (actor_device_id) REFERENCES glasses_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
