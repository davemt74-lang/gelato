-- Gelato Vision Lab V11 Section 9 — Shadow Deployment & Production Validation
SET NAMES utf8mb4;

ALTER TABLE glasses_vision_shadow_runs
  ADD COLUMN release_candidate_id BIGINT UNSIGNED NULL AFTER challenger_package_id,
  ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER release_candidate_id,
  ADD COLUMN station_id BIGINT UNSIGNED NULL AFTER location_id,
  ADD COLUMN operator_user_id BIGINT UNSIGNED NULL AFTER station_id,
  ADD COLUMN v11_policy_json JSON NULL AFTER operator_user_id,
  ADD KEY idx_glasses_vision_shadow_v11_rc (organization_id,release_candidate_id,status),
  ADD CONSTRAINT fk_glasses_vision_shadow_v11_rc FOREIGN KEY (release_candidate_id) REFERENCES glasses_vision_model_release_candidates(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_glasses_vision_shadow_v11_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_shadow_v11_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_shadow_v11_operator FOREIGN KEY (operator_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE glasses_vision_shadow_events
  ADD COLUMN champion_latency_ms DECIMAL(10,3) NULL AFTER challenger_mean_confidence,
  ADD COLUMN challenger_latency_ms DECIMAL(10,3) NULL AFTER champion_latency_ms,
  ADD COLUMN champion_timeout TINYINT(1) NOT NULL DEFAULT 0 AFTER challenger_latency_ms,
  ADD COLUMN challenger_timeout TINYINT(1) NOT NULL DEFAULT 0 AFTER champion_timeout,
  ADD COLUMN champion_runtime_error TINYINT(1) NOT NULL DEFAULT 0 AFTER challenger_timeout,
  ADD COLUMN challenger_runtime_error TINYINT(1) NOT NULL DEFAULT 0 AFTER champion_runtime_error;

CREATE TABLE glasses_vision_shadow_validations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  rollout_id BIGINT UNSIGNED NOT NULL,
  release_candidate_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  policy_json JSON NOT NULL,
  metrics_json JSON NOT NULL,
  coverage_json JSON NOT NULL,
  result_json JSON NOT NULL,
  validation_hash CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_shadow_validation_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_shadow_validation_hash (organization_id,validation_hash),
  KEY idx_glasses_vision_shadow_validation_rollout (organization_id,rollout_id,status,created_at),
  CONSTRAINT fk_glasses_vision_shadow_validation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_shadow_validation_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_shadow_validation_rc FOREIGN KEY (release_candidate_id) REFERENCES glasses_vision_model_release_candidates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_shadow_validation_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
