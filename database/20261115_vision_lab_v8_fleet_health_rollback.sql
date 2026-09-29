-- Gelato Vision Lab V8 Section 6 — Fleet Model Health & Rollback Intelligence
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_fleet_health_analyses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  rollout_id BIGINT UNSIGNED NULL,
  window_started_at DATETIME(6) NOT NULL,
  window_ended_at DATETIME(6) NOT NULL,
  source_fingerprint CHAR(64) NOT NULL,
  fleet_state VARCHAR(24) NOT NULL,
  recommendation VARCHAR(32) NOT NULL,
  evidence_json JSON NOT NULL,
  result_json JSON NOT NULL,
  analysis_hash CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_fleet_health_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_fleet_health_identity (organization_id,package_id,rollout_id,window_started_at,window_ended_at,source_fingerprint),
  KEY idx_glasses_vision_fleet_health_state (organization_id,fleet_state,window_ended_at),
  KEY idx_glasses_vision_fleet_health_rollout (organization_id,rollout_id,window_ended_at),
  CONSTRAINT fk_glasses_vision_fleet_health_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_fleet_health_package FOREIGN KEY (package_id) REFERENCES glasses_vision_model_packages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_fleet_health_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_fleet_health_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_fleet_health_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  analysis_id BIGINT UNSIGNED NOT NULL,
  rollout_id BIGINT UNSIGNED NOT NULL,
  action_type VARCHAR(32) NOT NULL,
  status VARCHAR(24) NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  evidence_json JSON NOT NULL,
  action_hash CHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_fleet_action_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_fleet_action_once (organization_id,analysis_id,action_type),
  KEY idx_glasses_vision_fleet_action_rollout (organization_id,rollout_id,created_at),
  CONSTRAINT fk_glasses_vision_fleet_action_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_fleet_action_analysis FOREIGN KEY (analysis_id) REFERENCES glasses_vision_fleet_health_analyses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_fleet_action_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_fleet_action_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
