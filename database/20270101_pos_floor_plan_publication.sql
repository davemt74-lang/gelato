-- Serialize floor-plan saves, assignment changes, archive and POS sync per organization.
-- This row is separate from operational foreign-key parents used when checks are opened.
CREATE TABLE IF NOT EXISTS pos_floor_plan_publication_locks (
  organization_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (organization_id)
) ENGINE=InnoDB;

-- Preserve the previous fallback winner as the explicit default on upgrade.
UPDATE floor_plans p
JOIN (
  SELECT id, ROW_NUMBER() OVER (PARTITION BY organization_id ORDER BY is_default DESC,updated_at DESC,id DESC) choice
  FROM floor_plans WHERE archived_at IS NULL
) ranked ON ranked.id=p.id
SET p.is_default=(ranked.choice=1)
WHERE p.is_default<>(ranked.choice=1);
