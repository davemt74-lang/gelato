-- Gelato AR Glasses Controlled Expo Handoff
SET NAMES utf8mb4;

CREATE TABLE glasses_kds_handoffs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  validation_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  kds_order_item_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(24) NOT NULL,
  to_status VARCHAR(24) NOT NULL,
  validation_snapshot_hash CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'completed',
  completed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_handoff_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_handoff_session (build_session_id),
  KEY idx_glasses_handoff_kds (organization_id,kds_order_item_id,created_at),
  CONSTRAINT fk_glasses_handoff_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_handoff_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_handoff_validation FOREIGN KEY (validation_id) REFERENCES glasses_product_validations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_handoff_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_handoff_kds FOREIGN KEY (kds_order_item_id) REFERENCES kds_order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
