-- Gelato Vision Lab V9 Section 2 — Recipe Step Recognition
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_step_recognitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  scene_snapshot_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  recognition_key CHAR(64) NOT NULL,
  state VARCHAR(24) NOT NULL,
  recognized_step_key VARCHAR(120) NULL,
  recognized_step_order INT NULL,
  recognized_step_text VARCHAR(1000) NULL,
  next_step_key VARCHAR(120) NULL,
  next_step_order INT NULL,
  next_step_text VARCHAR(1000) NULL,
  confidence DECIMAL(9,6) NOT NULL DEFAULT 0,
  margin DECIMAL(9,6) NOT NULL DEFAULT 0,
  evidence_json JSON NOT NULL,
  candidates_json JSON NOT NULL,
  recognition_hash CHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_step_recognition_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_step_recognition_identity (organization_id,recognition_key),
  KEY idx_glasses_vision_step_recognition_build (organization_id,build_session_id,created_at),
  KEY idx_glasses_vision_step_recognition_scene (organization_id,scene_snapshot_id),
  CONSTRAINT fk_glasses_vision_step_recognition_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_step_recognition_scene FOREIGN KEY (scene_snapshot_id) REFERENCES glasses_vision_scene_snapshots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_step_recognition_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
