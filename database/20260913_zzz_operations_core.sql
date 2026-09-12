-- Gelato Restaurant AI: universal inventory + task/Prep engine
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  normalized_key VARCHAR(180) NOT NULL,
  name VARCHAR(220) NOT NULL,
  category VARCHAR(100) NULL,
  base_unit VARCHAR(80) NULL,
  storage_location VARCHAR(180) NULL,
  vendor_name VARCHAR(180) NULL,
  vendor_sku VARCHAR(120) NULL,
  on_hand_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  par_level DECIMAL(14,4) NULL,
  reorder_point DECIMAL(14,4) NULL,
  unit_cost DECIMAL(12,4) NULL,
  menu_source_count INT UNSIGNED NOT NULL DEFAULT 0,
  recipe_source_count INT UNSIGNED NOT NULL DEFAULT 0,
  source_metadata_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  last_counted_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_org_public (organization_id, public_id),
  UNIQUE KEY uq_inventory_org_normalized (organization_id, normalized_key),
  KEY idx_inventory_stock (organization_id, status, on_hand_quantity, reorder_point),
  KEY idx_inventory_name (organization_id, name),
  CONSTRAINT fk_inventory_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_inventory_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_inventory_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_item_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_public_id VARCHAR(160) NOT NULL,
  source_name VARCHAR(240) NULL,
  quantity_per_source DECIMAL(14,4) NULL,
  unit VARCHAR(80) NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_source (inventory_item_id, source_type, source_public_id),
  KEY idx_inventory_sources_org (organization_id, source_type, source_public_id),
  CONSTRAINT fk_inventory_sources_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_inventory_sources_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  transaction_type VARCHAR(40) NOT NULL,
  quantity_delta DECIMAL(14,4) NOT NULL DEFAULT 0,
  resulting_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit VARCHAR(80) NULL,
  note VARCHAR(1000) NULL,
  source_type VARCHAR(60) NULL,
  source_public_id VARCHAR(160) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_inventory_transactions_item (inventory_item_id, created_at),
  KEY idx_inventory_transactions_org (organization_id, transaction_type, created_at),
  CONSTRAINT fk_inventory_transactions_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_inventory_transactions_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_inventory_transactions_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(140) NOT NULL,
  slug VARCHAR(140) NOT NULL,
  description VARCHAR(500) NULL,
  icon VARCHAR(40) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_category_public (organization_id, public_id),
  UNIQUE KEY uq_task_category_slug (organization_id, slug),
  KEY idx_task_category_status (organization_id, status, sort_order),
  CONSTRAINT fk_task_category_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_task_category_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_task_category_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  source_type VARCHAR(60) NULL,
  source_public_id VARCHAR(160) NULL,
  title VARCHAR(240) NOT NULL,
  description TEXT NULL,
  quantity DECIMAL(12,3) NULL,
  unit VARCHAR(80) NULL,
  station VARCHAR(120) NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  due_at DATETIME(6) NULL,
  estimated_minutes INT UNSIGNED NULL,
  requires_photo TINYINT(1) NOT NULL DEFAULT 0,
  requires_verification TINYINT(1) NOT NULL DEFAULT 0,
  verified_at DATETIME(6) NULL,
  verified_by BIGINT UNSIGNED NULL,
  original_transcript TEXT NULL,
  ai_confidence DECIMAL(5,4) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_restaurant_tasks_public (organization_id, public_id),
  UNIQUE KEY uq_restaurant_tasks_source (organization_id, source_type, source_public_id),
  KEY idx_restaurant_tasks_queue (organization_id, status, due_at, priority),
  KEY idx_restaurant_tasks_category (organization_id, category_id, status, due_at),
  CONSTRAINT fk_restaurant_tasks_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_restaurant_tasks_category FOREIGN KEY (category_id) REFERENCES task_categories(id),
  CONSTRAINT fk_restaurant_tasks_verifier FOREIGN KEY (verified_by) REFERENCES users(id),
  CONSTRAINT fk_restaurant_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_restaurant_tasks_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_task_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  assignment_role VARCHAR(32) NOT NULL DEFAULT 'assignee',
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  accepted_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_assignment (task_id, user_id, assignment_role),
  KEY idx_task_assignment_user (organization_id, user_id, completed_at),
  CONSTRAINT fk_task_assignment_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_task_assignment_task FOREIGN KEY (task_id) REFERENCES restaurant_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_assignment_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_task_assignment_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_task_time_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME(6) NOT NULL,
  ended_at DATETIME(6) NULL,
  duration_seconds INT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_task_time_task (task_id, started_at),
  KEY idx_task_time_user (organization_id, user_id, started_at),
  CONSTRAINT fk_task_time_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_task_time_task FOREIGN KEY (task_id) REFERENCES restaurant_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_time_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_task_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_task_events_task (task_id, created_at),
  KEY idx_task_events_org (organization_id, event_type, created_at),
  CONSTRAINT fk_task_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_task_events_task FOREIGN KEY (task_id) REFERENCES restaurant_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_task_proofs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  caption VARCHAR(500) NULL,
  proof_status VARCHAR(32) NOT NULL DEFAULT 'submitted',
  ai_review_json JSON NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  reviewed_at DATETIME(6) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_task_proofs_task (task_id, created_at),
  CONSTRAINT fk_task_proofs_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_task_proofs_task FOREIGN KEY (task_id) REFERENCES restaurant_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_proofs_file FOREIGN KEY (file_id) REFERENCES files(id),
  CONSTRAINT fk_task_proofs_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id),
  CONSTRAINT fk_task_proofs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('inventory.view','View inventory','View the universal restaurant inventory and source relationships.','Inventory'),
 ('inventory.manage','Manage inventory','Edit par levels, units, vendors, stock adjustments, receiving and waste.','Inventory'),
 ('inventory.agent','Use inventory Agent skills','Allow Restaurant AI to read and reason over inventory.','Inventory'),
 ('tasks.view','View restaurant tasks','View universal prep and operations tasks.','Tasks'),
 ('tasks.manage','Manage restaurant tasks','Create, edit, assign, verify and categorize restaurant tasks.','Tasks'),
 ('tasks.self','Work assigned tasks','Start, stop and complete tasks assigned to the logged-in employee.','Tasks'),
 ('tasks.proof','Upload task proof','Upload private photo proof to assigned restaurant tasks.','Tasks'),
 ('tasks.agent','Use task Agent skills','Allow Restaurant AI to read and act on restaurant task data within permissions.','Tasks')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO task_categories (organization_id,public_id,name,slug,description,icon,sort_order)
SELECT id,CONCAT('cat-',slug),name,slug,description,icon,sort_order FROM organizations CROSS JOIN (
 SELECT 'prep' slug,'Prep' name,'Food and product preparation' description,'prep' icon,10 sort_order UNION ALL
 SELECT 'opening','Opening','Opening shift tasks','open',20 UNION ALL
 SELECT 'closing','Closing','Closing shift tasks','close',30 UNION ALL
 SELECT 'cleaning','Cleaning','Cleaning and sanitation tasks','clean',40 UNION ALL
 SELECT 'inventory','Inventory','Counts, receiving and stock tasks','inventory',50 UNION ALL
 SELECT 'catering','Catering','Catering execution tasks','catering',60 UNION ALL
 SELECT 'wholesale','Wholesale','Wholesale production and fulfillment','wholesale',70 UNION ALL
 SELECT 'maintenance','Maintenance','Equipment and facility maintenance','maintenance',80 UNION ALL
 SELECT 'training','Training','Employee training and certifications','training',90 UNION ALL
 SELECT 'manager','Manager','Manager and administrative tasks','manager',100 UNION ALL
 SELECT 'delivery','Delivery','Delivery, loading and logistics','delivery',110 UNION ALL
 SELECT 'general','General','General restaurant tasks','task',120
) defaults;
