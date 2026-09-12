-- Canonical Floor Planner <-> Equipment Catalog integration
-- Import once after 20260912_floor_planner.sql and 20260912_equipment_catalog_brain.sql.
-- Equipment assets remain the source of truth. Structural layout stays in floor_plans.plan_json;
-- equipment identity, footprint, maintenance data, and placement live on equipment_assets.

SET NAMES utf8mb4;

ALTER TABLE equipment_assets
  ADD COLUMN floor_plan_x_ft DECIMAL(9,3) NULL AFTER floor_plan_component_id,
  ADD COLUMN floor_plan_y_ft DECIMAL(9,3) NULL AFTER floor_plan_x_ft,
  ADD COLUMN floor_plan_rotation_deg DECIMAL(7,2) NOT NULL DEFAULT 0.00 AFTER floor_plan_y_ft,
  ADD COLUMN floor_plan_z_index INT NOT NULL DEFAULT 1 AFTER floor_plan_rotation_deg,
  ADD COLUMN floor_plan_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER floor_plan_z_index,
  ADD COLUMN floor_plan_placed_at DATETIME(6) NULL AFTER floor_plan_locked,
  ADD KEY idx_equipment_assets_floor_plan (organization_id, floor_plan_public_id, archived_at),
  ADD KEY idx_equipment_assets_plan_component (organization_id, floor_plan_component_id, archived_at);

-- Placement is a protected capability. The general Equipment Catalog may edit identity,
-- dimensions, lifecycle, and maintenance data, but it cannot silently move an asset.
-- api/equipment-placement.php explicitly enables the connection-scoped guard only while
-- executing an authorized placement mutation.
DROP TRIGGER IF EXISTS trg_equipment_assets_canonical_insert;
DROP TRIGGER IF EXISTS trg_equipment_assets_canonical_update;
DROP TRIGGER IF EXISTS trg_floor_plans_canonical_archive;

DELIMITER $$
CREATE TRIGGER trg_equipment_assets_canonical_insert
BEFORE INSERT ON equipment_assets
FOR EACH ROW
BEGIN
  IF COALESCE(@gelato_allow_floor_placement, 0) <> 1 THEN
    SET NEW.floor_plan_public_id = NULL;
    SET NEW.floor_plan_component_id = NULL;
    SET NEW.floor_plan_x_ft = NULL;
    SET NEW.floor_plan_y_ft = NULL;
    SET NEW.floor_plan_rotation_deg = 0;
    SET NEW.floor_plan_z_index = 1;
    SET NEW.floor_plan_locked = 0;
    SET NEW.floor_plan_placed_at = NULL;
  END IF;
END$$

CREATE TRIGGER trg_equipment_assets_canonical_update
BEFORE UPDATE ON equipment_assets
FOR EACH ROW
BEGIN
  DECLARE v_plan_width DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_plan_depth DECIMAL(9,3) DEFAULT NULL;
  DECLARE v_width_ft DECIMAL(12,6) DEFAULT 0;
  DECLARE v_depth_ft DECIMAL(12,6) DEFAULT 0;
  DECLARE v_angle DOUBLE DEFAULT 0;
  DECLARE v_bound_width DOUBLE DEFAULT 0;
  DECLARE v_bound_depth DOUBLE DEFAULT 0;

  IF COALESCE(@gelato_allow_floor_placement, 0) <> 1 THEN
    SET NEW.floor_plan_public_id = OLD.floor_plan_public_id;
    SET NEW.floor_plan_component_id = OLD.floor_plan_component_id;
    SET NEW.floor_plan_x_ft = OLD.floor_plan_x_ft;
    SET NEW.floor_plan_y_ft = OLD.floor_plan_y_ft;
    SET NEW.floor_plan_rotation_deg = OLD.floor_plan_rotation_deg;
    SET NEW.floor_plan_z_index = OLD.floor_plan_z_index;
    SET NEW.floor_plan_locked = OLD.floor_plan_locked;
    SET NEW.floor_plan_placed_at = OLD.floor_plan_placed_at;
  END IF;

  IF NEW.floor_plan_public_id IS NOT NULL AND NEW.floor_plan_public_id <> '' THEN
    SELECT width_ft, depth_ft
      INTO v_plan_width, v_plan_depth
      FROM floor_plans
      WHERE organization_id = NEW.organization_id
        AND public_id = NEW.floor_plan_public_id
        AND archived_at IS NULL
      LIMIT 1;

    IF v_plan_width IS NULL OR v_plan_depth IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Equipment placement requires an active floor plan.';
    END IF;

    SET v_width_ft = COALESCE(NEW.width_inches,
      CASE NEW.asset_type
        WHEN 'oven' THEN 60
        WHEN 'mixer' THEN 30
        WHEN 'refrigeration' THEN 72
        WHEN 'gelato_machine' THEN 36
        WHEN 'dishwasher' THEN 30
        WHEN 'sink' THEN 36
        WHEN 'utensil' THEN 18
        WHEN 'smallware' THEN 18
        WHEN 'bar_equipment' THEN 36
        WHEN 'pos' THEN 24
        ELSE 36
      END) / 12.0;
    SET v_depth_ft = COALESCE(NEW.depth_inches,
      CASE NEW.asset_type
        WHEN 'oven' THEN 48
        WHEN 'mixer' THEN 36
        WHEN 'refrigeration' THEN 34
        WHEN 'gelato_machine' THEN 30
        WHEN 'dishwasher' THEN 30
        WHEN 'sink' THEN 24
        WHEN 'utensil' THEN 18
        WHEN 'smallware' THEN 18
        WHEN 'bar_equipment' THEN 24
        WHEN 'pos' THEN 18
        ELSE 30
      END) / 12.0;
    SET v_angle = RADIANS(MOD(COALESCE(NEW.floor_plan_rotation_deg, 0), 360));
    SET v_bound_width = ABS(v_width_ft * COS(v_angle)) + ABS(v_depth_ft * SIN(v_angle));
    SET v_bound_depth = ABS(v_width_ft * SIN(v_angle)) + ABS(v_depth_ft * COS(v_angle));

    IF NEW.floor_plan_x_ft IS NULL OR NEW.floor_plan_y_ft IS NULL
       OR NEW.floor_plan_x_ft < 0 OR NEW.floor_plan_y_ft < 0
       OR NEW.floor_plan_x_ft + v_bound_width > v_plan_width + 0.001
       OR NEW.floor_plan_y_ft + v_bound_depth > v_plan_depth + 0.001 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Equipment placement exceeds the floor-plan boundary.';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_floor_plans_canonical_archive
BEFORE UPDATE ON floor_plans
FOR EACH ROW
BEGIN
  DECLARE v_linked_assets INT DEFAULT 0;
  IF OLD.archived_at IS NULL AND NEW.archived_at IS NOT NULL THEN
    SELECT COUNT(*) INTO v_linked_assets
      FROM equipment_assets
      WHERE organization_id = OLD.organization_id
        AND floor_plan_public_id = OLD.public_id
        AND archived_at IS NULL
        AND operational_status <> 'retired';
    IF v_linked_assets > 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Move or unplace equipment before archiving this floor plan.';
    END IF;
  END IF;
END$$
DELIMITER ;

SET @gelato_allow_floor_placement = NULL;
