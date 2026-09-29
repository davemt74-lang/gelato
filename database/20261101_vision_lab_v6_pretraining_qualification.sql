-- Gelato Vision Lab V6 — Pre-Training Qualification Gate
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_training_qualifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  training_release_id BIGINT UNSIGNED NOT NULL,
  passed TINYINT(1) NOT NULL DEFAULT 0,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  policy_json JSON NOT NULL,
  result_json JSON NOT NULL,
  qualification_hash CHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_training_qualification_public (organization_id,public_id),
  KEY idx_glasses_vision_training_qualification_release (organization_id,training_release_id,created_at),
  KEY idx_glasses_vision_training_qualification_hash (organization_id,qualification_hash),
  CONSTRAINT fk_glasses_vision_training_qualification_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_training_qualification_release FOREIGN KEY (training_release_id) REFERENCES glasses_vision_training_releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_training_qualification_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
