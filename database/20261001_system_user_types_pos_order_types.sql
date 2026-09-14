-- Stonefellows / Gelato system user types + POS order types
-- Adds assignable Customer, Service, and Driver system roles to every existing organization.
-- A single-statement organization trigger keeps the same system role catalog available to future organizations.
-- Canonical POS order types are dine_in, delivery, and pickup.
-- Legacy bar orders normalize to dine_in; legacy takeout orders normalize to pickup.

INSERT INTO roles (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable)
SELECT o.id, 'Customer', 'customer', 'Customer account for ordering and customer-facing access.', 1, 0, 1
FROM organizations o
WHERE NOT EXISTS (
    SELECT 1 FROM roles r WHERE r.organization_id = o.id AND r.slug = 'customer'
);

INSERT INTO roles (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable)
SELECT o.id, 'Service', 'service', 'Front-of-house service account for restaurant guest and order workflows.', 1, 0, 1
FROM organizations o
WHERE NOT EXISTS (
    SELECT 1 FROM roles r WHERE r.organization_id = o.id AND r.slug = 'service'
);

INSERT INTO roles (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable)
SELECT o.id, 'Driver', 'driver', 'Delivery driver account for pickup, delivery, and customer handoff workflows.', 1, 0, 1
FROM organizations o
WHERE NOT EXISTS (
    SELECT 1 FROM roles r WHERE r.organization_id = o.id AND r.slug = 'driver'
);

DROP TRIGGER IF EXISTS organizations_seed_operational_user_types;
CREATE TRIGGER organizations_seed_operational_user_types
AFTER INSERT ON organizations
FOR EACH ROW
INSERT INTO roles (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable)
VALUES
    (NEW.id, 'Customer', 'customer', 'Customer account for ordering and customer-facing access.', 1, 0, 1),
    (NEW.id, 'Service', 'service', 'Front-of-house service account for restaurant guest and order workflows.', 1, 0, 1),
    (NEW.id, 'Driver', 'driver', 'Delivery driver account for pickup, delivery, and customer handoff workflows.', 1, 0, 1);

-- Normalize existing POS settings/checks to the new canonical order-type vocabulary.
UPDATE pos_settings
SET default_service_mode = CASE default_service_mode
    WHEN 'bar' THEN 'dine_in'
    WHEN 'takeout' THEN 'pickup'
    ELSE default_service_mode
END
WHERE default_service_mode IN ('bar', 'takeout');

UPDATE pos_checks
SET service_mode = CASE service_mode
    WHEN 'bar' THEN 'dine_in'
    WHEN 'takeout' THEN 'pickup'
    ELSE service_mode
END
WHERE service_mode IN ('bar', 'takeout');
