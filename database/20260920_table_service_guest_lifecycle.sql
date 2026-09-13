-- Gelato Restaurant AI: table service + guest order lifecycle
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS service_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_section_public (organization_id,public_id),
  UNIQUE KEY uq_service_section_name (organization_id,location_id,name),
  KEY idx_service_sections_location (organization_id,location_id,status,sort_order),
  CONSTRAINT fk_service_section_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_service_section_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_service_section_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_service_section_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_tables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(80) NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  shape VARCHAR(24) NOT NULL DEFAULT 'round',
  x_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000,
  y_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000,
  width_percent DECIMAL(7,3) NOT NULL DEFAULT 12.000,
  height_percent DECIMAL(7,3) NOT NULL DEFAULT 12.000,
  state VARCHAR(32) NOT NULL DEFAULT 'available',
  active_check_id BIGINT UNSIGNED NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  seated_at DATETIME(6) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_table_public (organization_id,public_id),
  UNIQUE KEY uq_service_table_name (organization_id,location_id,name),
  UNIQUE KEY uq_service_table_active_check (organization_id,active_check_id),
  KEY idx_service_tables_location (organization_id,location_id,status,state),
  KEY idx_service_tables_section (organization_id,section_id,status),
  CONSTRAINT fk_service_table_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_service_table_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_service_table_section FOREIGN KEY (section_id) REFERENCES service_sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_table_check FOREIGN KEY (active_check_id) REFERENCES pos_checks(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_table_server FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_table_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_service_table_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_check_contexts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  check_id BIGINT UNSIGNED NOT NULL,
  table_id BIGINT UNSIGNED NULL,
  server_user_id BIGINT UNSIGNED NULL,
  party_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  current_course_key VARCHAR(40) NOT NULL DEFAULT 'drinks',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  seated_at DATETIME(6) NULL,
  closed_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_context_check (organization_id,check_id),
  KEY idx_service_context_table (organization_id,location_id,table_id,status),
  KEY idx_service_context_server (organization_id,server_user_id,status),
  CONSTRAINT fk_service_context_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_service_context_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_service_context_check FOREIGN KEY (check_id) REFERENCES pos_checks(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_context_table FOREIGN KEY (table_id) REFERENCES service_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_context_server FOREIGN KEY (server_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_context_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_service_context_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_section_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  assigned_user_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_section_day (organization_id,section_id,business_date),
  KEY idx_service_assignment_user (organization_id,location_id,business_date,assigned_user_id),
  CONSTRAINT fk_service_assignment_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_service_assignment_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_service_assignment_section FOREIGN KEY (section_id) REFERENCES service_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_assignment_user FOREIGN KEY (assigned_user_id) REFERENCES users(id),
  CONSTRAINT fk_service_assignment_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  table_id BIGINT UNSIGNED NULL,
  check_id BIGINT UNSIGNED NULL,
  pos_check_item_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  note VARCHAR(1000) NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_service_events_table (organization_id,table_id,created_at),
  KEY idx_service_events_check (organization_id,check_id,created_at),
  KEY idx_service_events_type (organization_id,event_type,created_at),
  CONSTRAINT fk_service_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_service_event_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_service_event_table FOREIGN KEY (table_id) REFERENCES service_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_event_check FOREIGN KEY (check_id) REFERENCES pos_checks(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_event_item FOREIGN KEY (pos_check_item_id) REFERENCES pos_check_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_service_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pos_check_items
  ADD COLUMN seat_number SMALLINT UNSIGNED NULL AFTER special_instructions,
  ADD COLUMN course_key VARCHAR(40) NOT NULL DEFAULT 'main' AFTER seat_number,
  ADD COLUMN course_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER course_key,
  ADD KEY idx_pos_items_course (organization_id,check_id,course_sequence,course_key,status);

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('table_service.view','View table service','View table map, sections, table states, seats and course progress.','Table Service'),
 ('table_service.use','Operate table service','Seat parties, assign servers, order by seat/course, fire courses and transfer tables.','Table Service'),
 ('table_service.manage','Manage table service','Configure sections, tables, floor positions and section server assignments.','Table Service')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='pos.use'
JOIN permissions newp ON newp.permission_key IN ('table_service.view','table_service.use');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.manage','staff.manage')
JOIN permissions newp ON newp.permission_key='table_service.manage';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('table_service.view','table_service.use','table_service.manage');
