-- Gelato Vision Lab V9 Section 8 — Exception Recovery, Rework & Revalidation
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_rework_cases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  case_key VARCHAR(190) NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  source_final_validation_id BIGINT UNSIGNED NOT NULL,
  latest_final_validation_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(24) NOT NULL,
  source_validation_hash CHAR(64) NOT NULL,
  reason_json JSON NOT NULL,
  resolution_json JSON NULL,
  opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  resolved_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_rework_case_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_rework_case_key (organization_id,case_key),
  KEY idx_glasses_vision_rework_case_build (organization_id,build_session_id,status,opened_at),
  CONSTRAINT fk_glasses_vision_rework_case_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_rework_case_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_rework_case_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_rework_case_source_validation FOREIGN KEY (source_final_validation_id) REFERENCES glasses_vision_final_validations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_rework_case_latest_validation FOREIGN KEY (latest_final_validation_id) REFERENCES glasses_vision_final_validations(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_rework_case_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_rework_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  rework_case_id BIGINT UNSIGNED NOT NULL,
  attempt_key VARCHAR(190) NOT NULL,
  scene_snapshot_id BIGINT UNSIGNED NOT NULL,
  final_validation_id BIGINT UNSIGNED NOT NULL,
  state VARCHAR(32) NOT NULL,
  attempt_json JSON NOT NULL,
  attempt_hash CHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_rework_attempt_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_rework_attempt_key (organization_id,attempt_key),
  KEY idx_glasses_vision_rework_attempt_case (organization_id,rework_case_id,created_at),
  CONSTRAINT fk_glasses_vision_rework_attempt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_rework_attempt_case FOREIGN KEY (rework_case_id) REFERENCES glasses_vision_rework_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_rework_attempt_scene FOREIGN KEY (scene_snapshot_id) REFERENCES glasses_vision_scene_snapshots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_rework_attempt_validation FOREIGN KEY (final_validation_id) REFERENCES glasses_vision_final_validations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
