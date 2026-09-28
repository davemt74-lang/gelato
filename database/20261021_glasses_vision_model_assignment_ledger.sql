-- Gelato AR Glasses Vision Model Assignment Ledger hardening
SET NAMES utf8mb4;

CREATE TABLE glasses_vision_model_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  assignment_key CHAR(64) NOT NULL,
  detector_name VARCHAR(120) NOT NULL,
  rollout_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NULL,
  action VARCHAR(24) NOT NULL,
  selection VARCHAR(24) NOT NULL,
  rollout_status VARCHAR(24) NULL,
  canary_percent DECIMAL(5,2) NULL,
  canary_bucket DECIMAL(5,2) NULL,
  compatibility_json JSON NULL,
  issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_vision_assignment (device_id,assignment_key),
  KEY idx_glasses_vision_assignment_build (organization_id,build_session_id,issued_at),
  KEY idx_glasses_vision_assignment_rollout (organization_id,rollout_id,issued_at),
  CONSTRAINT fk_glasses_vision_assignment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_assignment_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_assignment_build FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_vision_assignment_rollout FOREIGN KEY (rollout_id) REFERENCES glasses_vision_model_rollouts(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_vision_assignment_package FOREIGN KEY (package_id) REFERENCES glasses_vision_model_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE glasses_vision_model_device_reports
  ADD COLUMN assignment_id BIGINT UNSIGNED NULL AFTER device_id,
  ADD KEY idx_glasses_vision_model_reports_assignment (organization_id,assignment_id,created_at),
  ADD CONSTRAINT fk_glasses_vision_model_report_assignment
    FOREIGN KEY (assignment_id) REFERENCES glasses_vision_model_assignments(id) ON DELETE SET NULL;
