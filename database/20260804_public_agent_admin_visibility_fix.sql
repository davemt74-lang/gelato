-- Fatso public-agent and Knowledge Center Admin visibility repair.
-- Import once after database/20260804_public_agent_knowledge_center.sql.
-- MySQL 8.0+ / MariaDB 10.11+. Safe to re-run.

SET NAMES utf8mb4;

-- Owners and roles that can already edit public/organization content receive
-- the complete public-agent and Knowledge Center management permission set.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT r.id, new_permission.id
FROM roles r
INNER JOIN permissions new_permission
  ON new_permission.permission_key IN (
    'public_agent.view',
    'public_agent.edit',
    'knowledge.view',
    'knowledge.create',
    'knowledge.edit',
    'knowledge.delete'
  )
LEFT JOIN role_permissions existing_grant
  ON existing_grant.role_id = r.id
LEFT JOIN permissions existing_permission
  ON existing_permission.id = existing_grant.permission_id
WHERE r.is_owner_role = 1
   OR existing_permission.permission_key IN (
     'settings.organization_edit',
     'public_pages.edit',
     'brand.edit'
   );

-- Roles that can already view public content receive read-only access to the
-- new Admin pages. Editing remains governed by the grants above.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT r.id, new_permission.id
FROM roles r
INNER JOIN permissions new_permission
  ON new_permission.permission_key IN ('public_agent.view', 'knowledge.view')
LEFT JOIN role_permissions existing_grant
  ON existing_grant.role_id = r.id
LEFT JOIN permissions existing_permission
  ON existing_permission.id = existing_grant.permission_id
WHERE r.is_owner_role = 1
   OR existing_permission.permission_key IN (
     'public_pages.view',
     'brand.view',
     'forms.view'
   );
