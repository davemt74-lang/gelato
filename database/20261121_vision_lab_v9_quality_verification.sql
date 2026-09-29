-- Gelato Vision Lab V9 Section 5 — Build Quality / Placement Verification
SET NAMES utf8mb4;
CREATE TABLE glasses_vision_quality_verifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 public_id VARCHAR(80) NOT NULL,
 scene_snapshot_id BIGINT UNSIGNED NOT NULL,
 build_session_id BIGINT UNSIGNED NOT NULL,
 verification_key CHAR(64) NOT NULL,
 state VARCHAR(24) NOT NULL,
 component_key VARCHAR(160) NULL,
 score DECIMAL(9,6) NULL,
 confidence DECIMAL(9,6) NOT NULL DEFAULT 0,
 evidence_json JSON NOT NULL,
 result_json JSON NOT NULL,
 verification_hash CHAR(64) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id),
 UNIQUE KEY uq_glasses_vision_quality_public(organization_id,public_id),
 UNIQUE KEY uq_glasses_vision_quality_identity(organization_id,verification_key),
 KEY idx_glasses_vision_quality_build(organization_id,build_session_id,created_at),
 CONSTRAINT fk_glasses_vision_quality_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_glasses_vision_quality_scene FOREIGN KEY(scene_snapshot_id) REFERENCES glasses_vision_scene_snapshots(id) ON DELETE RESTRICT,
 CONSTRAINT fk_glasses_vision_quality_build FOREIGN KEY(build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
