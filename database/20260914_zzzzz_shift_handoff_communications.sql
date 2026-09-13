-- Gelato Restaurant AI: shift handoffs, targeted employee communications and arrival briefing
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS employee_shift_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  message_type VARCHAR(24) NOT NULL DEFAULT 'handoff',
  location_id BIGINT UNSIGNED NULL,
  position_id BIGINT UNSIGNED NULL,
  schedule_shift_id BIGINT UNSIGNED NULL,
  target_user_id BIGINT UNSIGNED NULL,
  station VARCHAR(120) NULL,
  title VARCHAR(220) NOT NULL,
  body TEXT NOT NULL,
  priority VARCHAR(24) NOT NULL DEFAULT 'normal',
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  effective_from DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  effective_until DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_shift_message_public (organization_id,public_id),
  KEY idx_employee_shift_message_active (organization_id,status,effective_from,effective_until),
  KEY idx_employee_shift_message_location (organization_id,location_id,status),
  KEY idx_employee_shift_message_position (organization_id,position_id,status),
  KEY idx_employee_shift_message_shift (organization_id,schedule_shift_id,status),
  KEY idx_employee_shift_message_user (organization_id,target_user_id,status),
  CONSTRAINT fk_employee_shift_message_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_shift_message_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_employee_shift_message_position FOREIGN KEY (position_id) REFERENCES positions(id),
  CONSTRAINT fk_employee_shift_message_shift FOREIGN KEY (schedule_shift_id) REFERENCES schedule_shifts(id),
  CONSTRAINT fk_employee_shift_message_target_user FOREIGN KEY (target_user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_shift_message_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_employee_shift_message_updater FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT fk_employee_shift_message_resolver FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_shift_message_reads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  read_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_shift_message_read (message_id,user_id),
  KEY idx_employee_shift_message_reads_user (organization_id,user_id,read_at),
  CONSTRAINT fk_employee_shift_message_read_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_shift_message_read_message FOREIGN KEY (message_id) REFERENCES employee_shift_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_shift_message_read_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('employee.handoffs.view','View shift handoffs','View shift handoffs and targeted shift communications that apply to the employee.','Employees'),
 ('employee.handoffs.create','Create shift handoffs','Leave scoped handoff notes for the next restaurant shift.','Employees'),
 ('employee.handoffs.manage','Manage shift handoffs','Create targeted shift communications and resolve employee handoffs.','Employees')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id
FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.handoffs.view'
WHERE existing_permission.permission_key IN ('employee.self','schedule.self','tasks.self','agent.employee_view','employee.manage','staff.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id
FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.handoffs.create'
WHERE existing_permission.permission_key IN ('employee.self','schedule.self','tasks.self','agent.employee_view','employee.manage','staff.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id
FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.handoffs.manage'
WHERE existing_permission.permission_key IN ('employee.manage','staff.manage','employee.announcements.manage');
