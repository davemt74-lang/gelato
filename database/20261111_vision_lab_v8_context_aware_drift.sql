-- Gelato Vision Lab V8 Section 2 — Context-Aware Drift Detection
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_context_drift_analyses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  health_snapshot_id BIGINT UNSIGNED NOT NULL,
  baseline_id BIGINT UNSIGNED NULL,
  classification VARCHAR(32) NOT NULL,
  context_score DECIMAL(9,6) NOT NULL DEFAULT 0,
  performance_score DECIMAL(9,6) NOT NULL DEFAULT 0,
  confidence_score DECIMAL(9,6) NOT NULL DEFAULT 0,
  baseline_fingerprint CHAR(64) NOT NULL,
  context_fingerprint CHAR(64) NOT NULL,
  evidence_json JSON NOT NULL,
  result_json JSON NOT NULL,
  analysis_hash CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_context_drift_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_context_drift_identity (organization_id,health_snapshot_id,baseline_fingerprint,context_fingerprint),
  KEY idx_glasses_vision_context_drift_classification (organization_id,classification,created_at),
  KEY idx_glasses_vision_context_drift_snapshot (organization_id,health_snapshot_id,created_at),
  CONSTRAINT fk_glasses_vision_context_drift_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_context_drift_snapshot FOREIGN KEY (health_snapshot_id) REFERENCES glasses_vision_model_health_snapshots(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_context_drift_baseline FOREIGN KEY (baseline_id) REFERENCES glasses_vision_drift_baselines(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_context_drift_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
