-- Gelato AR Glasses Drift Recovery, Recalibration & Guided Remediation
SET NAMES utf8mb4;

ALTER TABLE glasses_vision_drift_incidents
  ADD COLUMN recovery_status VARCHAR(32) NOT NULL DEFAULT 'open' AFTER category,
  ADD COLUMN remediation_type VARCHAR(48) NULL AFTER recovery_status,
  ADD COLUMN remediation_notes VARCHAR(2000) NULL AFTER remediation_type,
  ADD COLUMN validation_started_at DATETIME(6) NULL AFTER remediation_notes,
  ADD COLUMN validation_stable_samples INT UNSIGNED NOT NULL DEFAULT 0 AFTER validation_started_at,
  ADD COLUMN reopened_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER validation_stable_samples,
  ADD COLUMN calibration_public_id VARCHAR(64) NULL AFTER reopened_count,
  ADD COLUMN replacement_package_id BIGINT UNSIGNED NULL AFTER calibration_public_id,
  ADD COLUMN active_learning_reference VARCHAR(190) NULL AFTER replacement_package_id,
  ADD COLUMN last_action_by BIGINT UNSIGNED NULL AFTER active_learning_reference,
  ADD COLUMN last_action_at DATETIME(6) NULL AFTER last_action_by,
  ADD CONSTRAINT fk_glasses_vision_drift_replacement_package FOREIGN KEY (replacement_package_id) REFERENCES glasses_vision_model_packages(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_drift_last_action_user FOREIGN KEY (last_action_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE glasses_vision_drift_baselines
  DROP INDEX uq_glasses_vision_drift_baseline,
  ADD KEY idx_glasses_vision_drift_baseline_identity (organization_id,package_id,location_id,station_id,status,established_at);

CREATE TABLE glasses_vision_drift_recovery_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  incident_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(48) NOT NULL,
  previous_status VARCHAR(32) NULL,
  next_status VARCHAR(32) NULL,
  remediation_type VARCHAR(48) NULL,
  notes VARCHAR(2000) NULL,
  evidence_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_vision_drift_recovery_incident (organization_id,incident_id,created_at),
  CONSTRAINT fk_glasses_vision_drift_recovery_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_drift_recovery_incident FOREIGN KEY (incident_id) REFERENCES glasses_vision_drift_incidents(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_drift_recovery_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
