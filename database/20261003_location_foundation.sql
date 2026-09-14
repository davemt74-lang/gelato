-- Stonefellows / Gelato canonical multi-location store foundation
SET NAMES utf8mb4;

ALTER TABLE locations
  ADD COLUMN public_slug VARCHAR(180) NULL,
  ADD COLUMN email VARCHAR(254) NULL,
  ADD COLUMN timezone VARCHAR(64) NULL,
  ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0,
  ADD COLUMN dine_in_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN pickup_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN delivery_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN online_ordering_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN delivery_radius_miles DECIMAL(6,2) NULL,
  ADD COLUMN delivery_minimum DECIMAL(10,2) NULL,
  ADD COLUMN delivery_fee DECIMAL(10,2) NULL,
  ADD COLUMN pickup_lead_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  ADD COLUMN delivery_lead_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  ADD COLUMN latitude DECIMAL(10,7) NULL,
  ADD COLUMN longitude DECIMAL(10,7) NULL;

UPDATE locations
SET public_slug = CONCAT('location-', id)
WHERE public_slug IS NULL OR TRIM(public_slug) = '';

ALTER TABLE locations
  MODIFY public_slug VARCHAR(180) NOT NULL,
  ADD UNIQUE KEY uq_locations_org_public_slug (organization_id, public_slug),
  ADD KEY idx_locations_org_primary (organization_id, is_primary, status, sort_order);

-- Existing organizations receive one deterministic primary location.
UPDATE locations l
JOIN (
  SELECT organization_id, MIN(id) AS location_id
  FROM locations
  WHERE status = 'active'
  GROUP BY organization_id
) first_location ON first_location.location_id = l.id
SET l.is_primary = 1;

-- Preserve compatibility with older/internal code paths that still insert only
-- organization_id + name + status. The new manager always supplies its own slug,
-- but legacy inserts receive a collision-resistant canonical slug automatically.
DROP TRIGGER IF EXISTS locations_seed_canonical_defaults;
CREATE TRIGGER locations_seed_canonical_defaults
BEFORE INSERT ON locations
FOR EACH ROW
SET
  NEW.public_slug = CASE
    WHEN NEW.public_slug IS NULL OR TRIM(NEW.public_slug) = ''
      THEN CONCAT('location-', LEFT(REPLACE(UUID(), '-', ''), 16))
    ELSE NEW.public_slug
  END,
  NEW.is_primary = CASE
    WHEN NEW.status = 'active'
      AND NOT EXISTS (
        SELECT 1 FROM locations existing
        WHERE existing.organization_id = NEW.organization_id
          AND existing.status = 'active'
          AND existing.is_primary = 1
      )
      THEN 1
    ELSE 0
  END;

CREATE TABLE location_hours (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  slot_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  opens_at TIME NULL,
  closes_at TIME NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  label VARCHAR(80) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_location_hours_slot (location_id, day_of_week, slot_order),
  KEY idx_location_hours_org_day (organization_id, location_id, day_of_week),
  CONSTRAINT fk_location_hours_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_location_hours_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, name, description, category) VALUES
 ('locations.view', 'View locations', 'View canonical restaurant location configuration and operating details.', 'Locations'),
 ('locations.manage', 'Manage locations', 'Create, edit, archive, restore and configure restaurant locations and hours.', 'Locations')
ON DUPLICATE KEY UPDATE
 name = VALUES(name),
 description = VALUES(description),
 category = VALUES(category);

-- Existing organization/public-site administrators inherit location management.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id = rp.permission_id
JOIN permissions newp ON newp.permission_key IN ('locations.view', 'locations.manage')
WHERE oldp.permission_key IN ('settings.organization_edit', 'public_pages.edit');

-- POS managers can see location configuration without receiving edit authority.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id = rp.permission_id AND oldp.permission_key = 'pos.manage'
JOIN permissions newp ON newp.permission_key = 'locations.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN ('locations.view', 'locations.manage')
WHERE r.is_owner_role = 1;
