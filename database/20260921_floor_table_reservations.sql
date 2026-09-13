-- Gelato Restaurant AI: canonical table assets + reservations + host stand
SET NAMES utf8mb4;

-- A service table is the operational face of one physical equipment asset.
ALTER TABLE service_tables
  ADD COLUMN equipment_asset_id BIGINT UNSIGNED NULL AFTER section_id,
  ADD UNIQUE KEY uq_service_table_asset (organization_id,equipment_asset_id),
  ADD CONSTRAINT fk_service_table_asset FOREIGN KEY (equipment_asset_id) REFERENCES equipment_assets(id) ON DELETE SET NULL;

-- Combined tables may intentionally share one open POS check.
ALTER TABLE service_tables
  DROP INDEX uq_service_table_active_check,
  ADD KEY idx_service_table_active_check (organization_id,active_check_id);

CREATE TABLE IF NOT EXISTS table_combinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_table_combination_public (organization_id,public_id),
  UNIQUE KEY uq_table_combination_name (organization_id,location_id,name),
  KEY idx_table_combination_location (organization_id,location_id,status),
  CONSTRAINT fk_table_combination_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_table_combination_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_table_combination_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_table_combination_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_combination_members (
  combination_id BIGINT UNSIGNED NOT NULL,
  service_table_id BIGINT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (combination_id,service_table_id),
  KEY idx_combination_member_table (service_table_id),
  CONSTRAINT fk_combination_member_combination FOREIGN KEY (combination_id) REFERENCES table_combinations(id) ON DELETE CASCADE,
  CONSTRAINT fk_combination_member_table FOREIGN KEY (service_table_id) REFERENCES service_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  reservation_type VARCHAR(24) NOT NULL DEFAULT 'reservation',
  status VARCHAR(24) NOT NULL DEFAULT 'booked',
  customer_id BIGINT UNSIGNED NULL,
  guest_name VARCHAR(180) NOT NULL,
  guest_email VARCHAR(320) NULL,
  guest_phone VARCHAR(64) NULL,
  party_size SMALLINT UNSIGNED NOT NULL,
  scheduled_at DATETIME(6) NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  joined_waitlist_at DATETIME(6) NULL,
  quoted_wait_minutes SMALLINT UNSIGNED NULL,
  arrived_at DATETIME(6) NULL,
  seated_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  no_show_at DATETIME(6) NULL,
  seated_check_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'host',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_guest_reservation_public (organization_id,public_id),
  KEY idx_guest_reservation_schedule (organization_id,location_id,status,scheduled_at),
  KEY idx_guest_reservation_waitlist (organization_id,location_id,status,joined_waitlist_at),
  KEY idx_guest_reservation_customer (organization_id,customer_id,created_at),
  KEY idx_guest_reservation_check (organization_id,seated_check_id),
  CONSTRAINT fk_guest_reservation_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_guest_reservation_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_guest_reservation_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_guest_reservation_check FOREIGN KEY (seated_check_id) REFERENCES pos_checks(id) ON DELETE SET NULL,
  CONSTRAINT fk_guest_reservation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_guest_reservation_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_reservation_tables (
  reservation_id BIGINT UNSIGNED NOT NULL,
  service_table_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (reservation_id,service_table_id),
  KEY idx_guest_reservation_table_table (service_table_id,reservation_id),
  CONSTRAINT fk_guest_reservation_table_reservation FOREIGN KEY (reservation_id) REFERENCES guest_reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_guest_reservation_table_table FOREIGN KEY (service_table_id) REFERENCES service_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_reservation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  reservation_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(48) NOT NULL,
  note VARCHAR(1000) NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_guest_reservation_events (organization_id,reservation_id,created_at),
  CONSTRAINT fk_guest_reservation_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_guest_reservation_event_reservation FOREIGN KEY (reservation_id) REFERENCES guest_reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_guest_reservation_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep legacy Table Service percentage coordinates as a compatibility projection only.
-- The equipment asset / floor plan remains authoritative whenever linked and placed.
DROP TRIGGER IF EXISTS trg_service_table_asset_projection_insert;
DROP TRIGGER IF EXISTS trg_service_table_asset_projection_update;
DROP TRIGGER IF EXISTS trg_equipment_table_projection_update;
DELIMITER $$
CREATE TRIGGER trg_service_table_asset_projection_insert
BEFORE INSERT ON service_tables
FOR EACH ROW
BEGIN
  DECLARE v_plan_width DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_plan_depth DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_x DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_y DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_w DECIMAL(9,2) DEFAULT NULL;
  DECLARE v_d DECIMAL(9,2) DEFAULT NULL;
  IF NEW.equipment_asset_id IS NOT NULL THEN
    SELECT p.width_ft,p.depth_ft,a.floor_plan_x_ft,a.floor_plan_y_ft,a.width_inches,a.depth_inches
      INTO v_plan_width,v_plan_depth,v_x,v_y,v_w,v_d
      FROM equipment_assets a
      LEFT JOIN floor_plans p ON p.organization_id=a.organization_id AND p.public_id=a.floor_plan_public_id AND p.archived_at IS NULL
      WHERE a.id=NEW.equipment_asset_id AND a.organization_id=NEW.organization_id AND a.archived_at IS NULL LIMIT 1;
    IF v_plan_width IS NOT NULL AND v_plan_depth IS NOT NULL AND v_x IS NOT NULL AND v_y IS NOT NULL THEN
      SET NEW.x_percent=LEAST(95,GREATEST(0,(v_x/v_plan_width)*100));
      SET NEW.y_percent=LEAST(95,GREATEST(0,(v_y/v_plan_depth)*100));
      SET NEW.width_percent=LEAST(40,GREATEST(5,(COALESCE(v_w,36)/12/v_plan_width)*100));
      SET NEW.height_percent=LEAST(40,GREATEST(5,(COALESCE(v_d,36)/12/v_plan_depth)*100));
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_service_table_asset_projection_update
BEFORE UPDATE ON service_tables
FOR EACH ROW
BEGIN
  DECLARE v_plan_width DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_plan_depth DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_x DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_y DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_w DECIMAL(9,2) DEFAULT NULL;
  DECLARE v_d DECIMAL(9,2) DEFAULT NULL;
  IF NEW.equipment_asset_id IS NOT NULL THEN
    SELECT p.width_ft,p.depth_ft,a.floor_plan_x_ft,a.floor_plan_y_ft,a.width_inches,a.depth_inches
      INTO v_plan_width,v_plan_depth,v_x,v_y,v_w,v_d
      FROM equipment_assets a
      LEFT JOIN floor_plans p ON p.organization_id=a.organization_id AND p.public_id=a.floor_plan_public_id AND p.archived_at IS NULL
      WHERE a.id=NEW.equipment_asset_id AND a.organization_id=NEW.organization_id AND a.archived_at IS NULL LIMIT 1;
    IF v_plan_width IS NOT NULL AND v_plan_depth IS NOT NULL AND v_x IS NOT NULL AND v_y IS NOT NULL THEN
      SET NEW.x_percent=LEAST(95,GREATEST(0,(v_x/v_plan_width)*100));
      SET NEW.y_percent=LEAST(95,GREATEST(0,(v_y/v_plan_depth)*100));
      SET NEW.width_percent=LEAST(40,GREATEST(5,(COALESCE(v_w,36)/12/v_plan_width)*100));
      SET NEW.height_percent=LEAST(40,GREATEST(5,(COALESCE(v_d,36)/12/v_plan_depth)*100));
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_equipment_table_projection_update
AFTER UPDATE ON equipment_assets
FOR EACH ROW
BEGIN
  DECLARE v_plan_width DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_plan_depth DECIMAL(9,3) DEFAULT NULL;
  IF NEW.floor_plan_public_id IS NOT NULL AND NEW.floor_plan_public_id<>'' AND NEW.floor_plan_x_ft IS NOT NULL AND NEW.floor_plan_y_ft IS NOT NULL THEN
    SELECT width_ft,depth_ft INTO v_plan_width,v_plan_depth
      FROM floor_plans WHERE organization_id=NEW.organization_id AND public_id=NEW.floor_plan_public_id AND archived_at IS NULL LIMIT 1;
    IF v_plan_width IS NOT NULL AND v_plan_depth IS NOT NULL THEN
      UPDATE service_tables
        SET x_percent=LEAST(95,GREATEST(0,(NEW.floor_plan_x_ft/v_plan_width)*100)),
            y_percent=LEAST(95,GREATEST(0,(NEW.floor_plan_y_ft/v_plan_depth)*100)),
            width_percent=LEAST(40,GREATEST(5,(COALESCE(NEW.width_inches,36)/12/v_plan_width)*100)),
            height_percent=LEAST(40,GREATEST(5,(COALESCE(NEW.depth_inches,36)/12/v_plan_depth)*100)),
            updated_at=NOW(6)
        WHERE organization_id=NEW.organization_id AND equipment_asset_id=NEW.id;
    END IF;
  END IF;
END$$
DELIMITER ;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('host.view','View host stand','View reservations, waitlist, table availability and physical table readiness.','Host Stand'),
 ('host.use','Operate host stand','Create reservations and waitlist entries, mark arrivals, assign tables and seat guests into canonical POS checks.','Host Stand'),
 ('host.manage','Manage reservations and table assets','Manage table combinations, physical table asset links, floor placement and reservation policy.','Host Stand')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('table_service.view','pos.use')
JOIN permissions newp ON newp.permission_key='host.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='table_service.use'
JOIN permissions newp ON newp.permission_key='host.use';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('table_service.manage','equipment.edit','floorplans.edit')
JOIN permissions newp ON newp.permission_key='host.manage';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('host.view','host.use','host.manage');
