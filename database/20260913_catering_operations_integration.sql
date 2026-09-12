-- Gelato Restaurant AI: shared restaurant operations engine with catering integration
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  source_type VARCHAR(40) NOT NULL DEFAULT 'catering',
  source_public_id VARCHAR(80) NOT NULL,
  title VARCHAR(240) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'planning',
  service_start_at DATETIME(6) NULL,
  service_end_at DATETIME(6) NULL,
  guest_count INT UNSIGNED NULL,
  venue_name VARCHAR(220) NULL,
  venue_address VARCHAR(500) NULL,
  fulfillment_preference VARCHAR(100) NULL,
  notes TEXT NULL,
  readiness_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_restaurant_operations_public (organization_id, public_id),
  UNIQUE KEY uq_restaurant_operations_source (organization_id, source_type, source_public_id),
  KEY idx_restaurant_operations_schedule (organization_id, status, service_start_at),
  CONSTRAINT fk_restaurant_operations_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_restaurant_operations_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_restaurant_operations_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_operation_menu_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  operation_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NULL,
  item_name VARCHAR(220) NOT NULL,
  target_servings DECIMAL(10,2) NULL,
  batches DECIMAL(10,3) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_operation_menu_operation (operation_id, id),
  KEY idx_operation_menu_recipe (organization_id, recipe_id),
  CONSTRAINT fk_operation_menu_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_operation_menu_operation FOREIGN KEY (operation_id) REFERENCES restaurant_operations(id) ON DELETE CASCADE,
  CONSTRAINT fk_operation_menu_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id),
  CONSTRAINT fk_operation_menu_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_operation_menu_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_operation_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  operation_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  recipe_id BIGINT UNSIGNED NULL,
  ingredient_name VARCHAR(220) NOT NULL,
  required_quantity DECIMAL(12,4) NULL,
  unit VARCHAR(80) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'planned',
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_operation_requirements_operation (operation_id, status, ingredient_name),
  KEY idx_operation_requirements_recipe (organization_id, recipe_id),
  CONSTRAINT fk_operation_requirements_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_operation_requirements_operation FOREIGN KEY (operation_id) REFERENCES restaurant_operations(id) ON DELETE CASCADE,
  CONSTRAINT fk_operation_requirements_menu FOREIGN KEY (menu_item_id) REFERENCES restaurant_operation_menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_operation_requirements_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id),
  CONSTRAINT fk_operation_requirements_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_operation_requirements_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_operation_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  operation_id BIGINT UNSIGNED NOT NULL,
  task_key VARCHAR(80) NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'operations',
  title VARCHAR(240) NOT NULL,
  description TEXT NULL,
  due_at DATETIME(6) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  assigned_to BIGINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_operation_task_key (operation_id, task_key),
  KEY idx_operation_tasks_due (organization_id, status, due_at),
  KEY idx_operation_tasks_assigned (organization_id, assigned_to, status, due_at),
  CONSTRAINT fk_operation_tasks_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_operation_tasks_operation FOREIGN KEY (operation_id) REFERENCES restaurant_operations(id) ON DELETE CASCADE,
  CONSTRAINT fk_operation_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(id),
  CONSTRAINT fk_operation_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_operation_tasks_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_operation_staff (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  operation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role_name VARCHAR(120) NOT NULL,
  shift_start_at DATETIME(6) NULL,
  shift_end_at DATETIME(6) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'planned',
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_operation_staff_operation (operation_id, shift_start_at),
  KEY idx_operation_staff_user (organization_id, user_id, shift_start_at),
  CONSTRAINT fk_operation_staff_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_operation_staff_operation FOREIGN KEY (operation_id) REFERENCES restaurant_operations(id) ON DELETE CASCADE,
  CONSTRAINT fk_operation_staff_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_operation_staff_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_operation_staff_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_catering_operations_insert;
DROP TRIGGER IF EXISTS trg_catering_operations_update;
DELIMITER //
CREATE TRIGGER trg_catering_operations_insert AFTER INSERT ON catering_leads
FOR EACH ROW
BEGIN
  IF NEW.pipeline_stage IN ('menu_proposal','tasting','quoted','contracted','deposit_paid','confirmed','completed') THEN
    INSERT INTO restaurant_operations
      (organization_id, public_id, source_type, source_public_id, title, status, service_start_at, service_end_at, guest_count, venue_name, venue_address, fulfillment_preference, notes)
    VALUES
      (NEW.organization_id, CONCAT('ops-', NEW.public_id), 'catering', NEW.public_id,
       CONCAT(COALESCE(NULLIF(NEW.company_name,''), NEW.contact_name), ' — ', NEW.event_type),
       CASE WHEN NEW.pipeline_stage='completed' THEN 'completed' WHEN NEW.pipeline_stage IN ('contracted','deposit_paid','confirmed') THEN 'active' ELSE 'planning' END,
       CASE WHEN NEW.event_date IS NULL THEN NULL ELSE TIMESTAMP(NEW.event_date, COALESCE(NEW.start_time,'12:00:00')) END,
       CASE WHEN NEW.event_date IS NULL THEN NULL ELSE TIMESTAMP(NEW.event_date, COALESCE(NEW.end_time,ADDTIME(COALESCE(NEW.start_time,'12:00:00'),'03:00:00'))) END,
       NEW.guest_count, NEW.venue_name, NEW.venue_address, NEW.fulfillment_preference, NEW.notes)
    ON DUPLICATE KEY UPDATE
      title=VALUES(title), status=VALUES(status), service_start_at=VALUES(service_start_at), service_end_at=VALUES(service_end_at),
      guest_count=VALUES(guest_count), venue_name=VALUES(venue_name), venue_address=VALUES(venue_address), fulfillment_preference=VALUES(fulfillment_preference), notes=VALUES(notes), updated_at=NOW(6);
  END IF;
END//

CREATE TRIGGER trg_catering_operations_update AFTER UPDATE ON catering_leads
FOR EACH ROW
BEGIN
  IF NEW.pipeline_stage IN ('menu_proposal','tasting','quoted','contracted','deposit_paid','confirmed','completed') THEN
    INSERT INTO restaurant_operations
      (organization_id, public_id, source_type, source_public_id, title, status, service_start_at, service_end_at, guest_count, venue_name, venue_address, fulfillment_preference, notes)
    VALUES
      (NEW.organization_id, CONCAT('ops-', NEW.public_id), 'catering', NEW.public_id,
       CONCAT(COALESCE(NULLIF(NEW.company_name,''), NEW.contact_name), ' — ', NEW.event_type),
       CASE WHEN NEW.pipeline_stage='completed' THEN 'completed' WHEN NEW.pipeline_stage IN ('contracted','deposit_paid','confirmed') THEN 'active' ELSE 'planning' END,
       CASE WHEN NEW.event_date IS NULL THEN NULL ELSE TIMESTAMP(NEW.event_date, COALESCE(NEW.start_time,'12:00:00')) END,
       CASE WHEN NEW.event_date IS NULL THEN NULL ELSE TIMESTAMP(NEW.event_date, COALESCE(NEW.end_time,ADDTIME(COALESCE(NEW.start_time,'12:00:00'),'03:00:00'))) END,
       NEW.guest_count, NEW.venue_name, NEW.venue_address, NEW.fulfillment_preference, NEW.notes)
    ON DUPLICATE KEY UPDATE
      title=VALUES(title), status=VALUES(status), service_start_at=VALUES(service_start_at), service_end_at=VALUES(service_end_at),
      guest_count=VALUES(guest_count), venue_name=VALUES(venue_name), venue_address=VALUES(venue_address), fulfillment_preference=VALUES(fulfillment_preference), notes=VALUES(notes), updated_at=NOW(6);
  ELSEIF NEW.pipeline_stage='lost' THEN
    UPDATE restaurant_operations SET status='cancelled',updated_at=NOW(6)
    WHERE organization_id=NEW.organization_id AND source_type='catering' AND source_public_id=NEW.public_id;
    UPDATE restaurant_operation_tasks t
      INNER JOIN restaurant_operations o ON o.id=t.operation_id
      SET t.status='cancelled',t.updated_at=NOW(6)
    WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id AND t.status NOT IN ('done','cancelled');
  END IF;

  IF NEW.pipeline_stage IN ('contracted','deposit_paid','confirmed') THEN
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'menu_lock','menu','Lock final menu',DATE_SUB(o.service_start_at,INTERVAL 7 DAY),'high',10 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'ingredients','inventory','Confirm ingredient requirements',DATE_SUB(o.service_start_at,INTERVAL 5 DAY),'high',20 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'prep_plan','prep','Finalize prep and production plan',DATE_SUB(o.service_start_at,INTERVAL 3 DAY),'high',30 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'production','prep','Complete production',DATE_SUB(o.service_start_at,INTERVAL 1 DAY),'critical',40 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'pack_load','logistics','Pack and load event order',DATE_SUB(o.service_start_at,INTERVAL 4 HOUR),'critical',50 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'setup','service','Event setup',DATE_SUB(o.service_start_at,INTERVAL 1 HOUR),'critical',60 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'service','service','Catering service',o.service_start_at,'critical',70 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
    INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
      SELECT o.organization_id,o.id,'breakdown','service','Breakdown and return',COALESCE(o.service_end_at,DATE_ADD(o.service_start_at,INTERVAL 3 HOUR)),'normal',80 FROM restaurant_operations o WHERE o.organization_id=NEW.organization_id AND o.source_type='catering' AND o.source_public_id=NEW.public_id;
  END IF;
END//
DELIMITER ;

-- Backfill operational records for catering already in planning or execution stages.
INSERT INTO restaurant_operations
  (organization_id, public_id, source_type, source_public_id, title, status, service_start_at, service_end_at, guest_count, venue_name, venue_address, fulfillment_preference, notes)
SELECT c.organization_id, CONCAT('ops-',c.public_id), 'catering', c.public_id,
       CONCAT(COALESCE(NULLIF(c.company_name,''),c.contact_name),' — ',c.event_type),
       CASE WHEN c.pipeline_stage='completed' THEN 'completed' WHEN c.pipeline_stage IN ('contracted','deposit_paid','confirmed') THEN 'active' ELSE 'planning' END,
       CASE WHEN c.event_date IS NULL THEN NULL ELSE TIMESTAMP(c.event_date,COALESCE(c.start_time,'12:00:00')) END,
       CASE WHEN c.event_date IS NULL THEN NULL ELSE TIMESTAMP(c.event_date,COALESCE(c.end_time,ADDTIME(COALESCE(c.start_time,'12:00:00'),'03:00:00'))) END,
       c.guest_count,c.venue_name,c.venue_address,c.fulfillment_preference,c.notes
FROM catering_leads c
WHERE c.archived_at IS NULL AND c.pipeline_stage IN ('menu_proposal','tasting','quoted','contracted','deposit_paid','confirmed','completed')
ON DUPLICATE KEY UPDATE title=VALUES(title),status=VALUES(status),service_start_at=VALUES(service_start_at),service_end_at=VALUES(service_end_at),guest_count=VALUES(guest_count),venue_name=VALUES(venue_name),venue_address=VALUES(venue_address),fulfillment_preference=VALUES(fulfillment_preference),notes=VALUES(notes),updated_at=NOW(6);

INSERT IGNORE INTO restaurant_operation_tasks (organization_id,operation_id,task_key,category,title,due_at,priority,sort_order)
SELECT o.organization_id,o.id,x.task_key,x.category,x.title,
       CASE x.task_key
         WHEN 'menu_lock' THEN DATE_SUB(o.service_start_at,INTERVAL 7 DAY)
         WHEN 'ingredients' THEN DATE_SUB(o.service_start_at,INTERVAL 5 DAY)
         WHEN 'prep_plan' THEN DATE_SUB(o.service_start_at,INTERVAL 3 DAY)
         WHEN 'production' THEN DATE_SUB(o.service_start_at,INTERVAL 1 DAY)
         WHEN 'pack_load' THEN DATE_SUB(o.service_start_at,INTERVAL 4 HOUR)
         WHEN 'setup' THEN DATE_SUB(o.service_start_at,INTERVAL 1 HOUR)
         WHEN 'service' THEN o.service_start_at
         ELSE COALESCE(o.service_end_at,DATE_ADD(o.service_start_at,INTERVAL 3 HOUR)) END,
       x.priority,x.sort_order
FROM restaurant_operations o
INNER JOIN catering_leads c ON c.organization_id=o.organization_id AND c.public_id=o.source_public_id AND o.source_type='catering'
CROSS JOIN (
  SELECT 'menu_lock' task_key,'menu' category,'Lock final menu' title,'high' priority,10 sort_order UNION ALL
  SELECT 'ingredients','inventory','Confirm ingredient requirements','high',20 UNION ALL
  SELECT 'prep_plan','prep','Finalize prep and production plan','high',30 UNION ALL
  SELECT 'production','prep','Complete production','critical',40 UNION ALL
  SELECT 'pack_load','logistics','Pack and load event order','critical',50 UNION ALL
  SELECT 'setup','service','Event setup','critical',60 UNION ALL
  SELECT 'service','service','Catering service','critical',70 UNION ALL
  SELECT 'breakdown','service','Breakdown and return','normal',80
) x
WHERE c.archived_at IS NULL AND c.pipeline_stage IN ('contracted','deposit_paid','confirmed');
