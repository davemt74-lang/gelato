-- Gelato Restaurant AI: factual employee development evidence, coaching, recognition and manager-authored development goals
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS employee_coaching_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  note_type VARCHAR(32) NOT NULL DEFAULT 'coaching',
  title VARCHAR(220) NOT NULL,
  body TEXT NOT NULL,
  occurred_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_coaching_public (organization_id,public_id),
  KEY idx_employee_coaching_user (organization_id,user_id,occurred_at,created_at),
  CONSTRAINT fk_employee_coaching_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_coaching_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_coaching_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_employee_coaching_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_recognitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  recognition_type VARCHAR(40) NOT NULL DEFAULT 'manager_recognition',
  title VARCHAR(220) NOT NULL,
  body TEXT NOT NULL,
  visibility VARCHAR(32) NOT NULL DEFAULT 'employee_visible',
  awarded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  awarded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_recognition_public (organization_id,public_id),
  KEY idx_employee_recognition_user (organization_id,user_id,awarded_at),
  CONSTRAINT fk_employee_recognition_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_recognition_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_recognition_awarder FOREIGN KEY (awarded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_development_goals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(220) NOT NULL,
  objective TEXT NOT NULL,
  success_criteria TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  start_date DATE NULL,
  target_date DATE NULL,
  completed_at DATETIME(6) NULL,
  manager_user_id BIGINT UNSIGNED NOT NULL,
  manager_notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_development_public (organization_id,public_id),
  KEY idx_employee_development_user (organization_id,user_id,status,target_date),
  CONSTRAINT fk_employee_development_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_development_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_development_manager FOREIGN KEY (manager_user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_development_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_employee_development_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('employee.performance.view','View employee development evidence','View factual job-related attendance, training, task, recognition and development history.','Employees'),
 ('employee.performance.manage','Manage employee coaching and development','Create private coaching notes and manager-authored development goals.','Employees'),
 ('employee.recognition.manage','Manage employee recognition','Create employee-visible or manager-private recognition entries.','Employees')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.performance.view'
WHERE existing_permission.permission_key IN ('employee.manage','staff.manage','attendance.view');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.performance.manage'
WHERE existing_permission.permission_key IN ('employee.manage','staff.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.recognition.manage'
WHERE existing_permission.permission_key IN ('employee.manage','staff.manage');
