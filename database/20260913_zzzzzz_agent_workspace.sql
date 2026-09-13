-- Gelato Restaurant AI: persistent global Agent workspace, conversations and action receipts
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS agent_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  title VARCHAR(220) NOT NULL DEFAULT 'New conversation',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  primary_channel VARCHAR(24) NOT NULL DEFAULT 'text',
  last_message_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_conversation_public (organization_id,public_id),
  KEY idx_agent_conversation_user (organization_id,user_id,status,last_message_at),
  CONSTRAINT fk_agent_conversation_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_agent_conversation_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  role VARCHAR(24) NOT NULL,
  channel VARCHAR(24) NOT NULL DEFAULT 'text',
  content MEDIUMTEXT NOT NULL,
  skill VARCHAR(120) NULL,
  tool_name VARCHAR(120) NULL,
  voice_event_public_id VARCHAR(80) NULL,
  structured_json JSON NULL,
  sources_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_message_public (organization_id,public_id),
  KEY idx_agent_message_thread (conversation_id,id),
  KEY idx_agent_message_user (organization_id,user_id,created_at),
  CONSTRAINT fk_agent_message_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_agent_message_thread FOREIGN KEY (conversation_id) REFERENCES agent_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_message_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_agent_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  skill VARCHAR(120) NOT NULL,
  route VARCHAR(160) NOT NULL,
  action_status VARCHAR(24) NOT NULL DEFAULT 'completed',
  request_json JSON NULL,
  result_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_restaurant_agent_action_public (organization_id,public_id),
  KEY idx_restaurant_agent_action_thread (conversation_id,created_at),
  KEY idx_restaurant_agent_action_user (organization_id,user_id,created_at),
  CONSTRAINT fk_restaurant_agent_action_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_restaurant_agent_action_thread FOREIGN KEY (conversation_id) REFERENCES agent_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_restaurant_agent_action_message FOREIGN KEY (message_id) REFERENCES agent_messages(id) ON DELETE SET NULL,
  CONSTRAINT fk_restaurant_agent_action_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('agent.workspace','Use global Restaurant Agent workspace','Use persistent Restaurant Agent conversations and canvas.','AI Agent'),
 ('agent.history_all','View organization Agent history','View Restaurant Agent conversations across employees for authorized audit/management workflows.','AI Agent')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);