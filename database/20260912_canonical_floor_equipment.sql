-- Canonical Floor Planner <-> Equipment Catalog integration
-- Import after 20260912_floor_planner.sql and 20260912_equipment_catalog_brain.sql.
-- Equipment assets remain the source of truth. The floor plan stores structural layout;
-- equipment placement coordinates live on the canonical equipment_assets row.

SET NAMES utf8mb4;

ALTER TABLE equipment_assets
  ADD COLUMN IF NOT EXISTS floor_plan_x_ft DECIMAL(9,3) NULL AFTER floor_plan_component_id,
  ADD COLUMN IF NOT EXISTS floor_plan_y_ft DECIMAL(9,3) NULL AFTER floor_plan_x_ft,
  ADD COLUMN IF NOT EXISTS floor_plan_rotation_deg DECIMAL(7,2) NOT NULL DEFAULT 0.00 AFTER floor_plan_y_ft,
  ADD COLUMN IF NOT EXISTS floor_plan_z_index INT NOT NULL DEFAULT 1 AFTER floor_plan_rotation_deg,
  ADD COLUMN IF NOT EXISTS floor_plan_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER floor_plan_z_index,
  ADD COLUMN IF NOT EXISTS floor_plan_placed_at DATETIME(6) NULL AFTER floor_plan_locked;

CREATE INDEX IF NOT EXISTS idx_equipment_assets_floor_plan
  ON equipment_assets (organization_id, floor_plan_public_id, archived_at);

CREATE INDEX IF NOT EXISTS idx_equipment_assets_plan_component
  ON equipment_assets (organization_id, floor_plan_component_id, archived_at);
