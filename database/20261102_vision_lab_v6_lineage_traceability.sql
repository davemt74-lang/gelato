-- Gelato Vision Lab V6 — Dataset Lineage & Model Traceability
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_training_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  training_release_id BIGINT UNSIGNED NOT NULL,
  qualification_id BIGINT UNSIGNED NULL,
  run_key VARCHAR(190) NOT NULL,
  trainer VARCHAR(120) NOT NULL,
  trainer_version VARCHAR(80) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'completed',
  config_json JSON NOT NULL,
  metrics_json JSON NULL,
  output_sha256 CHAR(64) NULL,
  started_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_training_run_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_training_run_key (organization_id,run_key),
  KEY idx_glasses_vision_training_run_release (organization_id,training_release_id,created_at),
  CONSTRAINT fk_glasses_vision_training_run_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_training_run_release FOREIGN KEY (training_release_id) REFERENCES glasses_vision_training_releases(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_training_run_qualification FOREIGN KEY (qualification_id) REFERENCES glasses_vision_training_qualifications(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_training_run_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_lineage_edges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  from_kind VARCHAR(40) NOT NULL,
  from_public_id VARCHAR(190) NOT NULL,
  from_hash CHAR(64) NULL,
  relation VARCHAR(64) NOT NULL,
  to_kind VARCHAR(40) NOT NULL,
  to_public_id VARCHAR(190) NOT NULL,
  to_hash CHAR(64) NULL,
  evidence_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_lineage_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_lineage_edge (organization_id,from_kind,from_public_id,relation,to_kind,to_public_id),
  KEY idx_glasses_vision_lineage_from (organization_id,from_kind,from_public_id,created_at),
  KEY idx_glasses_vision_lineage_to (organization_id,to_kind,to_public_id,created_at),
  KEY idx_glasses_vision_lineage_from_hash (organization_id,from_hash),
  KEY idx_glasses_vision_lineage_to_hash (organization_id,to_hash),
  CONSTRAINT fk_glasses_vision_lineage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_lineage_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
