-- Gelato AR Glasses Vision Label Registry & Detection Profiles
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_label_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  detector_name VARCHAR(120) NOT NULL DEFAULT '*',
  model_label VARCHAR(160) NOT NULL,
  normalized_label VARCHAR(160) NOT NULL,
  minimum_confidence DECIMAL(5,4) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_label_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_label_detector (organization_id,detector_name,normalized_label),
  KEY idx_glasses_vision_label_ingredient (organization_id,ingredient_id,status),
  KEY idx_glasses_vision_label_detector_status (organization_id,detector_name,status,normalized_label),
  CONSTRAINT fk_glasses_vision_label_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_label_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_label_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_label_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
