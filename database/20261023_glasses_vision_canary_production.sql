-- Gelato AR Glasses Canary Production Evaluation & Automatic Rollback Governance
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_canary_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  rollout_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  sample_key VARCHAR(190) NOT NULL,
  cohort VARCHAR(16) NOT NULL,
  observation_count INT UNSIGNED NOT NULL DEFAULT 0,
  correction_count INT UNSIGNED NOT NULL DEFAULT 0,
  low_confidence_count INT UNSIGNED NOT NULL DEFAULT 0,
  unexpected_count INT UNSIGNED NOT NULL DEFAULT 0,
  validation_failed TINYINT(1) NOT NULL DEFAULT 0,
  build_duration_ms INT UNSIGNED NULL,
  inference_count INT UNSIGNED NOT NULL DEFAULT 0,
  inference_latency_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  timeout_count INT UNSIGNED NOT NULL DEFAULT 0,
  runtime_error_count INT UNSIGNED NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_canary_sample (assignment_id,sample_key),
  KEY idx_glasses_vision_canary_rollout (organization_id,rollout_id,cohort,created_at),
  KEY idx_glasses_vision_canary_device (organization_id,device_id,created_at),
  CONSTRAINT fk_glasses_vision_canary_sample_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_sample_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_sample_assignment FOREIGN KEY (assignment_id) REFERENCES glasses_vision_model_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_sample_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_sample_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_canary_health_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  rollout_id BIGINT UNSIGNED NOT NULL,
  health_state VARCHAR(24) NOT NULL,
  target_samples INT UNSIGNED NOT NULL DEFAULT 0,
  baseline_samples INT UNSIGNED NOT NULL DEFAULT 0,
  target_devices INT UNSIGNED NOT NULL DEFAULT 0,
  baseline_devices INT UNSIGNED NOT NULL DEFAULT 0,
  metrics_json JSON NOT NULL,
  reasons_json JSON NULL,
  auto_action VARCHAR(32) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_vision_canary_health (organization_id,rollout_id,created_at),
  CONSTRAINT fk_glasses_vision_canary_health_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_health_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_canary_package_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  source_rollout_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  hold_until DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_canary_package_hold (organization_id,package_id),
  KEY idx_glasses_vision_canary_hold_until (organization_id,hold_until),
  CONSTRAINT fk_glasses_vision_canary_hold_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_hold_package FOREIGN KEY (package_id) REFERENCES glasses_vision_model_packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_canary_hold_rollout FOREIGN KEY (source_rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
