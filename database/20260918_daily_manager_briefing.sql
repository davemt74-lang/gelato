-- Gelato Restaurant AI: Daily Manager / GM Briefing
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS manager_daily_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  location_key VARCHAR(80) NOT NULL,
  business_date DATE NOT NULL,
  opening_note TEXT NULL,
  closing_note TEXT NULL,
  opening_acknowledged_at DATETIME(6) NULL,
  opening_acknowledged_by BIGINT UNSIGNED NULL,
  closing_acknowledged_at DATETIME(6) NULL,
  closing_acknowledged_by BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_manager_daily_log (organization_id, location_key, business_date),
  KEY idx_manager_daily_log_date (organization_id, business_date, location_id),
  CONSTRAINT fk_manager_daily_log_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_manager_daily_log_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_manager_daily_log_opening_user FOREIGN KEY (opening_acknowledged_by) REFERENCES users(id),
  CONSTRAINT fk_manager_daily_log_closing_user FOREIGN KEY (closing_acknowledged_by) REFERENCES users(id),
  CONSTRAINT fk_manager_daily_log_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_manager_daily_log_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manager_daily_exception_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  manager_daily_log_id BIGINT UNSIGNED NOT NULL,
  exception_key VARCHAR(96) NOT NULL,
  note VARCHAR(1200) NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  acknowledged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_manager_exception_ack (manager_daily_log_id, exception_key),
  KEY idx_manager_exception_org (organization_id, acknowledged_at),
  CONSTRAINT fk_manager_exception_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_manager_exception_log FOREIGN KEY (manager_daily_log_id) REFERENCES manager_daily_logs(id) ON DELETE CASCADE,
  CONSTRAINT fk_manager_exception_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('manager.brief.view','View Daily Manager Brief','View manager-only daily restaurant operating summaries, financial totals, staffing coverage and exceptions.','Management'),
 ('manager.brief.manage','Manage Daily Manager Brief','Acknowledge operating exceptions and record opening/closing manager notes.','Management'),
 ('manager.brief.agent','Use Daily Manager Agent brief','Allow the Restaurant Agent to return the manager-only daily operating brief.','Management')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Existing operational managers inherit the manager brief. Ordinary POS/task/employee access does not.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('pos.manage','staff.manage')
JOIN permissions newp ON newp.permission_key IN ('manager.brief.view','manager.brief.manage','manager.brief.agent');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('manager.brief.view','manager.brief.manage','manager.brief.agent');