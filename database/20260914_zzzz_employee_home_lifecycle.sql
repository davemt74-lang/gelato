-- Gelato Restaurant AI: employee home, self-service, announcements, policies and onboarding
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS employee_lifecycle_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NULL,
  onboarding_status VARCHAR(32) NOT NULL DEFAULT 'not_started',
  emergency_contact_name VARCHAR(180) NULL,
  emergency_contact_relationship VARCHAR(120) NULL,
  emergency_contact_phone VARCHAR(40) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_lifecycle_org_user (organization_id,user_id),
  KEY idx_employee_lifecycle_onboarding (organization_id,onboarding_status),
  CONSTRAINT fk_employee_lifecycle_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_lifecycle_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_lifecycle_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_employee_lifecycle_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  title VARCHAR(220) NOT NULL,
  body TEXT NOT NULL,
  priority VARCHAR(24) NOT NULL DEFAULT 'normal',
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  starts_at DATETIME(6) NULL,
  ends_at DATETIME(6) NULL,
  published_at DATETIME(6) NULL,
  published_by BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_announcement_public (organization_id,public_id),
  KEY idx_employee_announcement_active (organization_id,status,starts_at,ends_at),
  CONSTRAINT fk_employee_announcement_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_announcement_publisher FOREIGN KEY (published_by) REFERENCES users(id),
  CONSTRAINT fk_employee_announcement_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_employee_announcement_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_announcement_reads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  announcement_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  read_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_announcement_read (announcement_id,user_id),
  KEY idx_employee_announcement_reads_user (organization_id,user_id,read_at),
  CONSTRAINT fk_employee_announcement_read_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_announcement_read_announcement FOREIGN KEY (announcement_id) REFERENCES employee_announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_announcement_read_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_policy_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  title VARCHAR(220) NOT NULL,
  version_label VARCHAR(60) NOT NULL DEFAULT '1.0',
  body_text MEDIUMTEXT NOT NULL,
  effective_date DATE NULL,
  requires_acknowledgement TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  published_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_policy_public (organization_id,public_id),
  KEY idx_employee_policy_status (organization_id,status,effective_date),
  CONSTRAINT fk_employee_policy_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_policy_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_employee_policy_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_policy_acknowledgements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  policy_document_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  version_label VARCHAR(60) NOT NULL,
  acknowledged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_policy_ack (policy_document_id,user_id,version_label),
  KEY idx_employee_policy_ack_user (organization_id,user_id,acknowledged_at),
  CONSTRAINT fk_employee_policy_ack_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_policy_ack_policy FOREIGN KEY (policy_document_id) REFERENCES employee_policy_documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_policy_ack_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_checklist_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  phase VARCHAR(24) NOT NULL DEFAULT 'onboarding',
  title VARCHAR(220) NOT NULL,
  description VARCHAR(1200) NULL,
  due_at DATETIME(6) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  self_completable TINYINT(1) NOT NULL DEFAULT 1,
  assigned_by BIGINT UNSIGNED NULL,
  completed_by BIGINT UNSIGNED NULL,
  completed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_checklist_public (organization_id,public_id),
  KEY idx_employee_checklist_user (organization_id,user_id,status,due_at),
  CONSTRAINT fk_employee_checklist_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_checklist_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_employee_checklist_assigner FOREIGN KEY (assigned_by) REFERENCES users(id),
  CONSTRAINT fk_employee_checklist_completer FOREIGN KEY (completed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('employee.self','Use employee home','View and maintain the employee self-service home experience.','Employees'),
 ('employee.manage','Manage employee lifecycle','Manage employee onboarding profiles and checklists.','Employees'),
 ('employee.announcements.manage','Manage employee announcements','Create, publish and archive employee announcements.','Employees'),
 ('employee.policies.manage','Manage employee policies','Create, publish and archive employee policy documents.','Employees')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id
FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.self'
WHERE existing_permission.permission_key IN ('schedule.self','timeclock.self','training.self_view','tasks.self','agent.employee_view');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,p.id
FROM role_permissions rp
JOIN permissions existing_permission ON existing_permission.id=rp.permission_id
JOIN permissions p ON p.permission_key='employee.manage'
WHERE existing_permission.permission_key='staff.manage';