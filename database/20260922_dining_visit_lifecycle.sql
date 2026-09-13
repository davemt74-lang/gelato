-- Gelato Restaurant AI: dining visit identity across split/merged table-service checks
SET NAMES utf8mb4;

ALTER TABLE service_check_contexts
  ADD COLUMN visit_group_id VARCHAR(80) NOT NULL DEFAULT '' AFTER check_id,
  ADD KEY idx_service_context_visit (organization_id,location_id,visit_group_id,status);

UPDATE service_check_contexts
SET visit_group_id=CONCAT('visit-legacy-',LPAD(HEX(id),16,'0'))
WHERE visit_group_id='';
