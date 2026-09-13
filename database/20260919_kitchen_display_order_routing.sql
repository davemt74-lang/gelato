-- Gelato Restaurant AI: Kitchen Display + Order Routing
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS kds_stations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  target_seconds INT UNSIGNED NOT NULL DEFAULT 600,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_kds_station_public (organization_id,public_id),
  UNIQUE KEY uq_kds_station_slug (organization_id,location_id,slug),
  KEY idx_kds_station_location (organization_id,location_id,status,sort_order),
  CONSTRAINT fk_kds_station_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_kds_station_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_kds_station_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_kds_station_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kds_menu_routes (
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id,location_id,menu_item_id),
  KEY idx_kds_route_station (organization_id,station_id,menu_item_id),
  CONSTRAINT fk_kds_route_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_kds_route_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_kds_route_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
  CONSTRAINT fk_kds_route_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE CASCADE,
  CONSTRAINT fk_kds_route_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kds_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  check_id BIGINT UNSIGNED NOT NULL,
  pos_check_item_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  sent_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  fired_at DATETIME(6) NULL,
  started_at DATETIME(6) NULL,
  ready_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  last_action_by BIGINT UNSIGNED NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_kds_order_public (organization_id,public_id),
  UNIQUE KEY uq_kds_order_pos_line (organization_id,pos_check_item_id),
  KEY idx_kds_board (organization_id,location_id,station_id,status,sent_at),
  KEY idx_kds_check (organization_id,check_id,status,id),
  CONSTRAINT fk_kds_order_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_kds_order_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_kds_order_check FOREIGN KEY (check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_kds_order_pos_line FOREIGN KEY (pos_check_item_id) REFERENCES pos_check_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_kds_order_station FOREIGN KEY (station_id) REFERENCES kds_stations(id) ON DELETE SET NULL,
  CONSTRAINT fk_kds_order_user FOREIGN KEY (last_action_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kds_order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  kds_order_item_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  from_status VARCHAR(24) NULL,
  to_status VARCHAR(24) NULL,
  note VARCHAR(1000) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_kds_event_item (organization_id,kds_order_item_id,created_at,id),
  CONSTRAINT fk_kds_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_kds_event_item FOREIGN KEY (kds_order_item_id) REFERENCES kds_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_kds_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('kds.view','View Kitchen Display','View routed kitchen orders and timing for permitted restaurant locations.','Kitchen Display'),
 ('kds.update','Update Kitchen Display','Move routed kitchen items through hold, fire, preparation, ready and completion states.','Kitchen Display'),
 ('kds.configure','Configure Kitchen Display','Create kitchen stations and map menu items to kitchen stations.','Kitchen Display')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Operational managers inherit the complete KDS capability. KDS access is not granted to ordinary POS users automatically.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.manage','staff.manage')
JOIN permissions newp ON newp.permission_key IN ('kds.view','kds.update','kds.configure');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('kds.view','kds.update','kds.configure');