-- Gelato Vision Lab V9 Section 7 — Governed Human Confirmation & Kitchen Handoff
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_handoff_confirmations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  confirmation_key VARCHAR(190) NOT NULL,
  final_validation_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  handoff_id BIGINT UNSIGNED NULL,
  status VARCHAR(24) NOT NULL,
  final_validation_hash CHAR(64) NOT NULL,
  build_context_hash CHAR(64) NOT NULL,
  confirmation_json JSON NOT NULL,
  result_json JSON NULL,
  confirmed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_handoff_confirmation_public (organization_id,public_id),
  UNIQUE KEY uq_glasses_vision_handoff_confirmation_key (organization_id,confirmation_key),
  UNIQUE KEY uq_glasses_vision_handoff_confirmation_validation (organization_id,final_validation_id),
  KEY idx_glasses_vision_handoff_confirmation_build (organization_id,build_session_id,created_at),
  CONSTRAINT fk_glasses_vision_handoff_confirmation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_handoff_confirmation_validation FOREIGN KEY (final_validation_id) REFERENCES glasses_vision_final_validations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_handoff_confirmation_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_handoff_confirmation_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_glasses_vision_handoff_confirmation_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_handoff_confirmation_handoff FOREIGN KEY (handoff_id) REFERENCES glasses_kds_handoffs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
