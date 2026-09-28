-- Gelato Vision Lab V4 — Dataset Intelligence, Coverage Maps & Automatic Collection Plans
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_sample_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  sample_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  decision VARCHAR(32) NOT NULL,
  canonical_label VARCHAR(190) NULL,
  notes VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_sample_review_user (sample_id,reviewer_user_id),
  KEY idx_glasses_vision_sample_review_sample (organization_id,sample_id,created_at),
  CONSTRAINT fk_glasses_vision_sample_review_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_sample_review_sample FOREIGN KEY (sample_id) REFERENCES glasses_vision_training_samples(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_sample_review_user FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_dataset_intelligence_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  dataset_id BIGINT UNSIGNED NULL,
  snapshot_type VARCHAR(32) NOT NULL DEFAULT 'organization',
  metrics_json JSON NOT NULL,
  gaps_json JSON NOT NULL,
  readiness_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_dataset_intel_public (organization_id,public_id),
  KEY idx_glasses_vision_dataset_intel_dataset (organization_id,dataset_id,created_at),
  CONSTRAINT fk_glasses_vision_dataset_intel_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_dataset_intel_dataset FOREIGN KEY (dataset_id) REFERENCES glasses_vision_dataset_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_dataset_intel_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_collection_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  dataset_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  source_snapshot_id BIGINT UNSIGNED NULL,
  plan_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  accepted_by BIGINT UNSIGNED NULL,
  accepted_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_collection_plan_public (organization_id,public_id),
  KEY idx_glasses_vision_collection_plan_status (organization_id,status,created_at),
  CONSTRAINT fk_glasses_vision_collection_plan_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_collection_plan_dataset FOREIGN KEY (dataset_id) REFERENCES glasses_vision_dataset_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_collection_plan_snapshot FOREIGN KEY (source_snapshot_id) REFERENCES glasses_vision_dataset_intelligence_snapshots(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_collection_plan_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_glasses_vision_collection_plan_acceptor FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
