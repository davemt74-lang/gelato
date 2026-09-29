-- Gelato Vision Lab V9 Section 9 — Production Learning from Kitchen Outcomes
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_kitchen_outcomes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  event_key VARCHAR(190) NOT NULL,
  event_hash CHAR(64) NOT NULL,
  source_kind VARCHAR(64) NOT NULL,
  source_public_id VARCHAR(190) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(48) NOT NULL,
  disposition VARCHAR(48) NOT NULL,
  review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  evidence_json JSON NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  occurred_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_kitchen_outcome_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_kitchen_outcome_event (organization_id,event_key),
  KEY idx_glasses_vision_kitchen_outcome_review (organization_id,review_status,occurred_at),
  KEY idx_glasses_vision_kitchen_outcome_build (organization_id,build_session_id,occurred_at),
  CONSTRAINT fk_glasses_vision_kitchen_outcome_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_kitchen_outcome_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_kitchen_outcome_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_kitchen_outcome_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
