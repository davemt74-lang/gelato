-- Fatso public landing-page agent and Knowledge Center.
-- Import once after the earlier dated migrations.
-- MySQL 8.0+ / MariaDB 10.11+. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS public_agent_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  agent_name VARCHAR(120) NOT NULL DEFAULT 'Restaurant Assistant',
  welcome_message VARCHAR(500) NOT NULL DEFAULT 'Hi! Ask me about our restaurant, jobs, training, or application process.',
  input_placeholder VARCHAR(220) NOT NULL DEFAULT 'Ask a question…',
  provider VARCHAR(32) NOT NULL DEFAULT 'auto',
  model VARCHAR(160) NULL,
  system_prompt TEXT NULL,
  max_context_chunks SMALLINT UNSIGNED NOT NULL DEFAULT 6,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_public_agent_settings_org (organization_id),
  CONSTRAINT fk_public_agent_settings_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_agent_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id CHAR(36) NOT NULL,
  title VARCHAR(240) NOT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
  original_filename VARCHAR(255) NULL,
  mime_type VARCHAR(150) NULL,
  content LONGTEXT NOT NULL,
  content_sha256 CHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'published',
  agent_enabled TINYINT(1) NOT NULL DEFAULT 1,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_knowledge_documents_org_public (organization_id, public_id),
  KEY idx_knowledge_documents_agent (organization_id, status, agent_enabled, archived_at),
  KEY idx_knowledge_documents_updated (organization_id, updated_at),
  CONSTRAINT fk_knowledge_documents_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_knowledge_documents_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_knowledge_documents_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_document_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  content MEDIUMTEXT NOT NULL,
  content_sha256 CHAR(64) NOT NULL,
  token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_knowledge_chunk_document_index (document_id, chunk_index),
  KEY idx_knowledge_chunks_org_document (organization_id, document_id),
  FULLTEXT KEY ft_knowledge_chunks_content (content),
  CONSTRAINT fk_knowledge_chunks_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_knowledge_chunks_document FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO public_agent_settings (organization_id)
SELECT o.id FROM organizations o
ON DUPLICATE KEY UPDATE organization_id = VALUES(organization_id);

INSERT INTO permissions (permission_key, name, description, category)
VALUES
  ('public_agent.view', 'View public AI agent settings', 'View the public landing-page agent configuration and provider readiness.', 'AI & Knowledge'),
  ('public_agent.edit', 'Edit public AI agent settings', 'Enable, disable, and configure the public landing-page AI agent.', 'AI & Knowledge'),
  ('knowledge.view', 'View Knowledge Center', 'View organization knowledge documents and indexing status.', 'AI & Knowledge'),
  ('knowledge.create', 'Create knowledge documents', 'Create typed documents and import supported text files.', 'AI & Knowledge'),
  ('knowledge.edit', 'Edit knowledge documents', 'Edit, publish, or remove documents from public-agent retrieval.', 'AI & Knowledge'),
  ('knowledge.delete', 'Delete knowledge documents', 'Permanently delete Knowledge Center documents and generated chunks.', 'AI & Knowledge')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  category = VALUES(category);
