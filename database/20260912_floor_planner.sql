-- Gelato Floor Planner: database-backed layouts and role permissions
-- Existing installations: import this migration once after the current schema migrations.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS floor_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  plan_json JSON NOT NULL,
  width_ft DECIMAL(9,2) NOT NULL,
  depth_ft DECIMAL(9,2) NOT NULL,
  scale_px_per_ft DECIMAL(8,2) NOT NULL DEFAULT 24.00,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_floor_plans_org_public (organization_id, public_id),
  KEY idx_floor_plans_org_active (organization_id, archived_at, updated_at),
  KEY idx_floor_plans_org_default (organization_id, is_default, archived_at),
  CONSTRAINT fk_floor_plans_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_floor_plans_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_floor_plans_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('floorplans.view', 'View floor plans', 'Open saved restaurant floor plans and planning analytics.', 'Floor Plans'),
  ('floorplans.edit', 'Edit floor plans', 'Create, edit, save, and archive restaurant floor plans.', 'Floor Plans')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  category = VALUES(category);
