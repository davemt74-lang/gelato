-- Gelato Vision Lab V11 Section 10 — Governed Canary Release & Automatic Safety Rollback
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_canary_stage_validations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  rollout_id BIGINT UNSIGNED NOT NULL,
  release_candidate_id BIGINT UNSIGNED NOT NULL,
  shadow_validation_id BIGINT UNSIGNED NOT NULL,
  stage_percent DECIMAL(5,2) NOT NULL,
  status VARCHAR(24) NOT NULL,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  policy_json JSON NOT NULL,
  metrics_json JSON NOT NULL,
  coverage_json JSON NOT NULL,
  result_json JSON NOT NULL,
  stage_hash CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_v11_canary_stage_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_v11_canary_stage_hash (organization_id,stage_hash),
  KEY idx_glasses_v11_canary_stage_rollout (organization_id,rollout_id,stage_percent,status,created_at),
  CONSTRAINT fk_glasses_v11_canary_stage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_v11_canary_stage_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_v11_canary_stage_rc FOREIGN KEY (release_candidate_id) REFERENCES glasses_vision_model_release_candidates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_v11_canary_stage_shadow FOREIGN KEY (shadow_validation_id) REFERENCES glasses_vision_shadow_validations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_v11_canary_stage_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_production_acceptances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  rollout_id BIGINT UNSIGNED NOT NULL,
  release_candidate_id BIGINT UNSIGNED NOT NULL,
  stage_validation_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'accepted',
  manifest_json JSON NOT NULL,
  acceptance_hash CHAR(64) NOT NULL,
  accepted_by BIGINT UNSIGNED NULL,
  accepted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_v11_prod_accept_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_v11_prod_accept_hash (organization_id,acceptance_hash),
  UNIQUE KEY uq_glasses_v11_prod_accept_rollout (organization_id,rollout_id),
  CONSTRAINT fk_glasses_v11_prod_accept_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_v11_prod_accept_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_v11_prod_accept_rc FOREIGN KEY (release_candidate_id) REFERENCES glasses_vision_model_release_candidates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_v11_prod_accept_stage FOREIGN KEY (stage_validation_id) REFERENCES glasses_vision_canary_stage_validations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_v11_prod_accept_actor FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
