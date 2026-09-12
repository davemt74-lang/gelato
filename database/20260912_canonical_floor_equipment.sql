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
END$$
DELIMITER ;

SET @gelato_allow_floor_placement = NULL;
