-- Gelato Restaurant AI: location table-turn + host-readiness policy
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS table_turn_policies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  reset_target_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  ready_buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  urgent_threshold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_table_turn_policy_location (organization_id,location_id),
  KEY idx_table_turn_policy_location (organization_id,location_id),
  CONSTRAINT fk_table_turn_policy_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_table_turn_policy_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_table_turn_policy_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_table_turn_policy_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_table_turn_reset_target CHECK (reset_target_minutes BETWEEN 1 AND 120),
  CONSTRAINT chk_table_turn_ready_buffer CHECK (ready_buffer_minutes <= 60),
  CONSTRAINT chk_table_turn_urgent_threshold CHECK (urgent_threshold_minutes BETWEEN 1 AND 120)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
