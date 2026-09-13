-- Gelato Restaurant AI: customer CRM linked to native POS
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crm_customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  first_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  display_name VARCHAR(240) NOT NULL,
  email VARCHAR(320) NULL,
  email_normalized VARCHAR(320) NULL,
  phone VARCHAR(64) NULL,
  phone_normalized VARCHAR(32) NULL,
  birthday_month TINYINT UNSIGNED NULL,
  birthday_day TINYINT UNSIGNED NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_customer_public (organization_id,public_id),
  KEY idx_crm_customer_name (organization_id,status,display_name),
  KEY idx_crm_customer_email (organization_id,email_normalized),
  KEY idx_crm_customer_phone (organization_id,phone_normalized),
  CONSTRAINT fk_crm_customer_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_crm_customer_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_crm_customer_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_customer_consents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  channel VARCHAR(24) NOT NULL,
  consent_status VARCHAR(24) NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  evidence_note VARCHAR(1000) NULL,
  captured_by BIGINT UNSIGNED NULL,
  captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_crm_consent_customer (organization_id,customer_id,channel,captured_at,id),
  CONSTRAINT fk_crm_consent_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_crm_consent_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_consent_user FOREIGN KEY (captured_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_customer_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  note_body TEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_crm_note_customer (organization_id,customer_id,created_at),
  CONSTRAINT fk_crm_note_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_crm_note_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_note_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_tag_slug (organization_id,slug),
  KEY idx_crm_tag_name (organization_id,name),
  CONSTRAINT fk_crm_tag_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_crm_tag_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_customer_tags (
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (customer_id,tag_id),
  KEY idx_crm_customer_tag_org (organization_id,tag_id,customer_id),
  CONSTRAINT fk_crm_customer_tag_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_crm_customer_tag_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_customer_tag_tag FOREIGN KEY (tag_id) REFERENCES crm_tags(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_customer_tag_user FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pos_checks
  ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER location_id,
  ADD KEY idx_pos_checks_customer (organization_id,customer_id,status,closed_at),
  ADD CONSTRAINT fk_pos_check_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('crm.view','View customer CRM','View customer profiles, transaction-derived visit history and CRM notes.','Customer CRM'),
 ('crm.manage','Manage customer CRM','Create, edit, archive and tag customer profiles and add internal notes.','Customer CRM'),
 ('crm.consent.manage','Manage customer consent','Record append-only email and SMS consent changes with source and evidence.','Customer CRM'),
 ('crm.pos_link','Link customers at POS','Search a minimal customer identity view and attach a customer to an open POS check.','Customer CRM')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- POS operators can find/link a customer, but full CRM access is not implied.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='pos.use'
JOIN permissions newp ON newp.permission_key='crm.pos_link';

-- Existing people/operations managers inherit full CRM management capability.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key IN ('staff.manage','pos.manage')
JOIN permissions newp ON newp.permission_key IN ('crm.view','crm.manage','crm.consent.manage','crm.pos_link');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.is_owner_role=1 AND p.permission_key IN ('crm.view','crm.manage','crm.consent.manage','crm.pos_link');
