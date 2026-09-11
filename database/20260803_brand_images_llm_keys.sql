-- Fatso's Restaurant Training Workspace
-- Brand image uploads, encrypted LLM credentials, and permission-management update.
-- Import this once after the original database/install.sql has already been imported.

START TRANSACTION;

ALTER TABLE brand_settings
  ADD COLUMN cover_file_id BIGINT UNSIGNED NULL AFTER logo_file_id,
  ADD CONSTRAINT fk_brand_settings_cover FOREIGN KEY (cover_file_id) REFERENCES files(id);

CREATE TABLE llm_api_credentials (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  encrypted_key MEDIUMTEXT NOT NULL,
  nonce VARCHAR(128) NOT NULL,
  encryption_method VARCHAR(64) NOT NULL DEFAULT 'sodium_secretbox_v1',
  key_last_four CHAR(4) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'configured',
  last_verified_at DATETIME(6) NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_llm_credentials_org_provider (organization_id, provider),
  KEY idx_llm_credentials_org_status (organization_id, status),
  CONSTRAINT fk_llm_credentials_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_llm_credentials_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, name, description, category) VALUES
('llm_keys.view','View LLM API key status','View whether organization LLM provider credentials are configured.','Integrations'),
('llm_keys.edit','Manage LLM API keys','Add, replace, or remove encrypted organization LLM provider credentials.','Integrations')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  category = VALUES(category);

COMMIT;
