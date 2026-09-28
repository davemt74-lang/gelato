-- Gelato Vision Lab V6 — Dataset Curation & Group-Aware Split Governance
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_dataset_curation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  dataset_id BIGINT UNSIGNED NOT NULL,
  sample_id BIGINT UNSIGNED NOT NULL,
  decision VARCHAR(24) NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'manual',
  actor_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_vision_curation_latest (organization_id,dataset_id,sample_id,id),
  KEY idx_glasses_vision_curation_decision (organization_id,dataset_id,decision,id),
  CONSTRAINT fk_glasses_vision_curation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_curation_dataset FOREIGN KEY (dataset_id) REFERENCES glasses_vision_dataset_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_curation_sample FOREIGN KEY (sample_id) REFERENCES glasses_vision_training_samples(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_curation_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_dataset_split_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  dataset_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  seed INT UNSIGNED NOT NULL DEFAULT 74,
  train_ratio DECIMAL(8,6) NOT NULL DEFAULT 0.700000,
  val_ratio DECIMAL(8,6) NOT NULL DEFAULT 0.150000,
  test_ratio DECIMAL(8,6) NOT NULL DEFAULT 0.150000,
  policy_json JSON NOT NULL,
  manifest_json JSON NOT NULL,
  plan_hash CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  applied_by BIGINT UNSIGNED NULL,
  applied_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_split_plan_public (organization_id,public_id),
  KEY idx_glasses_vision_split_plan_dataset (organization_id,dataset_id,status,created_at),
  CONSTRAINT fk_glasses_vision_split_plan_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_split_plan_dataset FOREIGN KEY (dataset_id) REFERENCES glasses_vision_dataset_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_split_plan_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_glasses_vision_split_plan_applier FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_vision_dataset_split_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  split_plan_id BIGINT UNSIGNED NOT NULL,
  sample_id BIGINT UNSIGNED NOT NULL,
  group_key CHAR(64) NOT NULL,
  group_label VARCHAR(500) NOT NULL,
  split_name VARCHAR(16) NOT NULL,
  grouping_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_split_plan_sample (split_plan_id,sample_id),
  KEY idx_glasses_vision_split_group (organization_id,split_plan_id,group_key,split_name),
  CONSTRAINT fk_glasses_vision_split_assignment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_split_assignment_plan FOREIGN KEY (split_plan_id) REFERENCES glasses_vision_dataset_split_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_split_assignment_sample FOREIGN KEY (sample_id) REFERENCES glasses_vision_training_samples(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
