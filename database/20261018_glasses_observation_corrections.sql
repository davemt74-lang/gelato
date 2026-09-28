-- Gelato AR Glasses Human Correction & Learning Ledger
SET NAMES utf8mb4;

CREATE TABLE glasses_observation_corrections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  observation_id BIGINT UNSIGNED NOT NULL,
  correction_key VARCHAR(190) NOT NULL,
  resolution VARCHAR(24) NOT NULL,
  target_component_key VARCHAR(160) NULL,
  corrected_quantity DECIMAL(10,3) NULL,
  reason VARCHAR(500) NULL,
  actor_device_id BIGINT UNSIGNED NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_observation_correction_key (build_session_id,correction_key),
  KEY idx_glasses_observation_correction_observation (organization_id,observation_id,id),
  KEY idx_glasses_observation_correction_session (organization_id,build_session_id,created_at),
  CONSTRAINT fk_glasses_observation_correction_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_observation_correction_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_observation_correction_observation FOREIGN KEY (observation_id) REFERENCES glasses_build_observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_observation_correction_device FOREIGN KEY (actor_device_id) REFERENCES glasses_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
