-- Gelato Vision Lab V6 — Training Release Builder
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_training_releases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  dataset_id BIGINT UNSIGNED NOT NULL,
  split_plan_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'built',
  training_profile VARCHAR(80) NOT NULL,
  profile_json JSON NOT NULL,
  manifest_json JSON NOT NULL,
  release_hash CHAR(64) NOT NULL,
  artifact_relative_path VARCHAR(500) NOT NULL,
  artifact_sha256 CHAR(64) NOT NULL,
  artifact_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_training_release_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_training_release_hash (organization_id,release_hash),
  KEY idx_glasses_vision_training_release_dataset (organization_id,dataset_id,created_at),
  KEY idx_glasses_vision_training_release_split (organization_id,split_plan_id),
  CONSTRAINT fk_glasses_vision_training_release_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_training_release_dataset FOREIGN KEY (dataset_id) REFERENCES glasses_vision_dataset_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_training_release_split FOREIGN KEY (split_plan_id) REFERENCES glasses_vision_dataset_split_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_training_release_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
