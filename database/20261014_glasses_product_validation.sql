-- Gelato AR Glasses Product Validation State Machine
SET NAMES utf8mb4;

CREATE TABLE glasses_product_validations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  snapshot_hash CHAR(64) NOT NULL,
  summary_json JSON NOT NULL,
  blockers_json JSON NULL,
  next_stage VARCHAR(40) NULL,
  evaluated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_validation_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_validation_session (build_session_id),
  KEY idx_glasses_validation_status (organization_id,status,updated_at),
  CONSTRAINT fk_glasses_validation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_validation_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_validation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  validation_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  from_status VARCHAR(32) NULL,
  to_status VARCHAR(32) NULL,
  payload_json JSON NULL,
  actor_device_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_validation_events (organization_id,validation_id,created_at),
  KEY idx_glasses_validation_session_events (organization_id,build_session_id,created_at),
  CONSTRAINT fk_glasses_validation_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_validation_event_validation FOREIGN KEY (validation_id) REFERENCES glasses_product_validations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_validation_event_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_validation_event_device FOREIGN KEY (actor_device_id) REFERENCES glasses_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
