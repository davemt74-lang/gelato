-- Gelato Restaurant AI: table cleaning + ready lifecycle
SET NAMES utf8mb4;

ALTER TABLE service_tables
  ADD COLUMN dirty_at DATETIME(6) NULL AFTER seated_at,
  ADD COLUMN cleaning_started_at DATETIME(6) NULL AFTER dirty_at,
  ADD COLUMN cleaning_started_by BIGINT UNSIGNED NULL AFTER cleaning_started_at,
  ADD COLUMN ready_at DATETIME(6) NULL AFTER cleaning_started_by,
  ADD COLUMN ready_by BIGINT UNSIGNED NULL AFTER ready_at,
  ADD KEY idx_service_table_cleaning (organization_id,location_id,state,dirty_at),
  ADD CONSTRAINT fk_service_table_cleaning_user FOREIGN KEY (cleaning_started_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_service_table_ready_user FOREIGN KEY (ready_by) REFERENCES users(id) ON DELETE SET NULL;

-- Give already-dirty legacy tables an honest starting point for elapsed-time reporting.
UPDATE service_tables
SET dirty_at=COALESCE(dirty_at,updated_at)
WHERE state='dirty';

-- Lifecycle timestamps and the Ready transition are enforced at the table record so
-- every path that dirties a table (POS close, combined-table reconciliation, transfers)
-- stays consistent. Dirty/Cleaning cannot be released by a raw state flip.
DROP TRIGGER IF EXISTS trg_service_table_cleaning_update;
DELIMITER $$
CREATE TRIGGER trg_service_table_cleaning_update
BEFORE UPDATE ON service_tables
FOR EACH ROW
BEGIN
  IF NEW.state='dirty' AND (OLD.state<>'dirty' OR NEW.dirty_at IS NULL) THEN
    SET NEW.dirty_at=COALESCE(NEW.dirty_at,NOW(6));
    SET NEW.cleaning_started_at=NULL;
    SET NEW.cleaning_started_by=NULL;
    SET NEW.ready_at=NULL;
    SET NEW.ready_by=NULL;
  END IF;

  IF NEW.state='cleaning' AND OLD.state<>'cleaning' THEN
    SET NEW.cleaning_started_at=COALESCE(NEW.cleaning_started_at,NOW(6));
    SET NEW.cleaning_started_by=COALESCE(NEW.cleaning_started_by,NEW.updated_by);
    SET NEW.ready_at=NULL;
    SET NEW.ready_by=NULL;
  END IF;

  IF NEW.state='available' AND OLD.state IN ('dirty','cleaning') THEN
    IF OLD.state<>'cleaning' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Table must enter Cleaning before it can become Available.';
    END IF;
    IF NEW.ready_at IS NULL OR NEW.ready_by IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Use Table Ready to release a Cleaning table.';
    END IF;
  END IF;
END$$
DELIMITER ;