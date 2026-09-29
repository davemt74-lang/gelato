-- Gelato Vision Lab V7 Section 6 — Champion/Challenger Evidence Review
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_model_evidence_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  experiment_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL,
  score INT UNSIGNED NOT NULL,
  policy_json JSON NOT NULL,
  evidence_json JSON NOT NULL,
  result_json JSON NOT NULL,
  review_hash CHAR(64) NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_evidence_review_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_evidence_review_hash (organization_id,review_hash),
  KEY idx_glasses_vision_evidence_review_experiment (organization_id,experiment_id,created_at),
  KEY idx_glasses_vision_evidence_review_status (organization_id,status,created_at),
  CONSTRAINT fk_glasses_vision_evidence_review_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_evidence_review_experiment FOREIGN KEY (experiment_id) REFERENCES glasses_vision_model_experiments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_evidence_review_actor FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
