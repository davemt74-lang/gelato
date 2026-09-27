-- Gelato AR Glasses Build Session Engine
SET NAMES utf8mb4;

CREATE TABLE glasses_build_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  kds_order_item_id BIGINT UNSIGNED NOT NULL,
  pos_check_item_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  source_revision CHAR(64) NULL,
  context_json JSON NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_build_public (organization_id,public_id),
  KEY idx_glasses_build_kds (organization_id,kds_order_item_id,status),
  KEY idx_glasses_build_device (organization_id,device_id,status,updated_at),
  CONSTRAINT fk_glasses_build_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_build_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_build_kds FOREIGN KEY (kds_order_item_id) REFERENCES kds_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_build_pos_item FOREIGN KEY (pos_check_item_id) REFERENCES pos_check_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_build_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_build_components (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  component_key VARCHAR(160) NOT NULL,
  ingredient_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(180) NOT NULL,
  expected_quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  detected_quantity DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  unit VARCHAR(80) NULL,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'waiting',
  confidence DECIMAL(6,5) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  first_detected_at DATETIME(6) NULL,
  confirmed_at DATETIME(6) NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_build_component (build_session_id,component_key),
  KEY idx_glasses_build_component_state (organization_id,build_session_id,status,sort_order),
  CONSTRAINT fk_glasses_component_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_component_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_component_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_build_observations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  observation_key VARCHAR(190) NOT NULL,
  component_key VARCHAR(160) NULL,
  action VARCHAR(24) NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  confidence DECIMAL(6,5) NULL,
  tracking_id VARCHAR(190) NULL,
  bbox_json JSON NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_observation_key (build_session_id,observation_key),
  KEY idx_glasses_observation_component (organization_id,build_session_id,component_key,created_at),
  CONSTRAINT fk_glasses_observation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_observation_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_build_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  build_session_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  component_key VARCHAR(160) NULL,
  payload_json JSON NULL,
  actor_device_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_glasses_build_events (organization_id,build_session_id,created_at),
  KEY idx_glasses_build_event_type (organization_id,event_type,created_at),
  CONSTRAINT fk_glasses_build_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_build_event_session FOREIGN KEY (build_session_id) REFERENCES glasses_build_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_build_event_device FOREIGN KEY (actor_device_id) REFERENCES glasses_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
