-- Stonefellows / Gelato Menu Operations + central Agent Brain integration
SET NAMES utf8mb4;

-- Portable DDL: UpgradeService treats duplicate-column error 1060 as a safe retry.
ALTER TABLE menu_items ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER version;

CREATE TABLE menu_item_operational_status (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  is_sold_out TINYINT(1) NOT NULL DEFAULT 0,
  sold_out_reason VARCHAR(500) NULL,
  resume_at DATETIME(6) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_item_operational_location (organization_id,menu_item_id,location_id),
  KEY idx_menu_item_operational_active (organization_id,location_id,is_sold_out,resume_at),
  CONSTRAINT fk_menu_item_operational_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_item_operational_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_operational_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_operational_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_price_operational_status (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_price_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  is_sold_out TINYINT(1) NOT NULL DEFAULT 0,
  sold_out_reason VARCHAR(500) NULL,
  resume_at DATETIME(6) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_price_operational_location (organization_id,menu_item_price_id,location_id),
  KEY idx_menu_price_operational_active (organization_id,location_id,is_sold_out,resume_at),
  CONSTRAINT fk_menu_price_operational_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_price_operational_price FOREIGN KEY (menu_item_price_id) REFERENCES menu_item_prices(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_price_operational_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_price_operational_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_availability_schedules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  channel VARCHAR(32) NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_menu_schedule_lookup (organization_id,menu_item_id,channel,location_id,day_of_week,is_enabled),
  CONSTRAINT fk_menu_schedule_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_schedule_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_schedule_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_menu_schedule_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_operation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  menu_item_price_id BIGINT UNSIGNED NULL,
  location_id BIGINT UNSIGNED NULL,
  summary VARCHAR(500) NOT NULL,
  details_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_operation_event_public (organization_id,public_id),
  KEY idx_menu_operation_events_recent (organization_id,created_at),
  KEY idx_menu_operation_events_item (organization_id,menu_item_id,created_at),
  CONSTRAINT fk_menu_operation_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_operation_event_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_menu_operation_event_price FOREIGN KEY (menu_item_price_id) REFERENCES menu_item_prices(id) ON DELETE SET NULL,
  CONSTRAINT fk_menu_operation_event_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_menu_operation_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
