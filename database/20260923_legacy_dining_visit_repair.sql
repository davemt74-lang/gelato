-- Gelato Restaurant AI: repair legacy dining visit identity for pre-visit split checks
--
-- The 20260922 migration had to assign every pre-existing service context a
-- unique visit-legacy-* value because no visit identity existed yet. Table
-- Service did, however, retain append-only check_split_created events with the
-- source check public id. Rebuild only those explicit split chains here. Do not
-- infer relationships merely because two checks share a physical table.
SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS legacy_dining_visit_edges;
CREATE TEMPORARY TABLE legacy_dining_visit_edges (
  child_context_id BIGINT UNSIGNED NOT NULL,
  parent_context_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (child_context_id),
  KEY idx_legacy_visit_parent (parent_context_id)
) ENGINE=MEMORY;

INSERT INTO legacy_dining_visit_edges (child_context_id,parent_context_id)
SELECT DISTINCT child.id,parent.id
FROM service_events e
JOIN service_check_contexts child
  ON child.organization_id=e.organization_id
 AND child.check_id=e.check_id
JOIN pos_checks source_check
  ON source_check.organization_id=e.organization_id
 AND source_check.public_id=JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.sourceCheckPublicId'))
JOIN service_check_contexts parent
  ON parent.organization_id=source_check.organization_id
 AND parent.check_id=source_check.id
WHERE e.event_type='check_split_created'
  AND e.metadata_json IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.sourceCheckPublicId')) IS NOT NULL
  AND child.visit_group_id LIKE 'visit-legacy-%'
  AND parent.visit_group_id LIKE 'visit-legacy-%'
  AND child.id<>parent.id
ON DUPLICATE KEY UPDATE parent_context_id=VALUES(parent_context_id);

DROP TEMPORARY TABLE IF EXISTS legacy_dining_visit_roots;
CREATE TEMPORARY TABLE legacy_dining_visit_roots (
  child_context_id BIGINT UNSIGNED NOT NULL,
  root_context_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (child_context_id),
  KEY idx_legacy_visit_root (root_context_id)
) ENGINE=MEMORY;

INSERT INTO legacy_dining_visit_roots (child_context_id,root_context_id)
WITH RECURSIVE ancestry AS (
  SELECT
    e.child_context_id,
    e.parent_context_id AS ancestor_context_id,
    1 AS depth,
    CAST(CONCAT(e.child_context_id,',',e.parent_context_id) AS CHAR(4096)) AS visit_path
  FROM legacy_dining_visit_edges e

  UNION ALL

  SELECT
    a.child_context_id,
    e.parent_context_id,
    a.depth+1,
    CONCAT(a.visit_path,',',e.parent_context_id)
  FROM ancestry a
  JOIN legacy_dining_visit_edges e
    ON e.child_context_id=a.ancestor_context_id
  WHERE a.depth<64
    AND FIND_IN_SET(e.parent_context_id,a.visit_path)=0
), ranked AS (
  SELECT
    child_context_id,
    ancestor_context_id,
    ROW_NUMBER() OVER (
      PARTITION BY child_context_id
      ORDER BY depth DESC,ancestor_context_id ASC
    ) AS root_rank
  FROM ancestry
)
SELECT child_context_id,ancestor_context_id
FROM ranked
WHERE root_rank=1;

UPDATE service_check_contexts child
JOIN legacy_dining_visit_roots repair
  ON repair.child_context_id=child.id
JOIN service_check_contexts root_context
  ON root_context.organization_id=child.organization_id
 AND root_context.id=repair.root_context_id
SET child.visit_group_id=root_context.visit_group_id,
    child.updated_at=NOW(6)
WHERE child.visit_group_id LIKE 'visit-legacy-%'
  AND root_context.visit_group_id LIKE 'visit-legacy-%'
  AND child.visit_group_id<>root_context.visit_group_id;

-- Historical split children did not always inherit the CRM customer. Fill only
-- null customer ids, and only when the repaired visit has exactly one distinct
-- non-null customer. Existing conflicting customer assignments are preserved.
UPDATE pos_checks target
JOIN service_check_contexts target_context
  ON target_context.organization_id=target.organization_id
 AND target_context.check_id=target.id
JOIN (
  SELECT
    cx.organization_id,
    cx.visit_group_id,
    MAX(c.customer_id) AS customer_id
  FROM service_check_contexts cx
  JOIN pos_checks c
    ON c.organization_id=cx.organization_id
   AND c.id=cx.check_id
  WHERE cx.visit_group_id LIKE 'visit-legacy-%'
    AND c.customer_id IS NOT NULL
  GROUP BY cx.organization_id,cx.visit_group_id
  HAVING COUNT(DISTINCT c.customer_id)=1
) known_customer
  ON known_customer.organization_id=target_context.organization_id
 AND known_customer.visit_group_id=target_context.visit_group_id
SET target.customer_id=known_customer.customer_id,
    target.revision=target.revision+1,
    target.updated_at=NOW(6)
WHERE target.customer_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS legacy_dining_visit_roots;
DROP TEMPORARY TABLE IF EXISTS legacy_dining_visit_edges;
