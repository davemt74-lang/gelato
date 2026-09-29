-- Gelato Vision Lab V7 Section 2 — Lineage-Aware Failure Analysis
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_failure_analyses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  analysis_hash CHAR(64) NOT NULL,
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  filter_json JSON NOT NULL,
  result_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_failure_analysis_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_failure_analysis_hash (organization_id,analysis_hash),
  KEY idx_glasses_vision_failure_analysis_created (organization_id,created_at),
  CONSTRAINT fk_glasses_vision_failure_analysis_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_failure_analysis_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
