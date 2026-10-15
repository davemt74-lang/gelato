-- FM-G8D2A: timestamped scoped participant history and owned chat folders.
CREATE TABLE IF NOT EXISTS fm_participant_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id BIGINT UNSIGNED NOT NULL,
 public_id VARCHAR(64) NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 goal_id BIGINT UNSIGNED NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(48) NOT NULL,
 privacy_scope VARCHAR(16) NOT NULL DEFAULT 'operational',
 source VARCHAR(32) NOT NULL DEFAULT 'goal',
 summary VARCHAR(180) NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_fm_event_public (organization_id,public_id),
 KEY idx_fm_event_user (organization_id,user_id,occurred_at),
 KEY idx_fm_event_goal (organization_id,goal_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fm_participant_chat_folders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id BIGINT UNSIGNED NOT NULL,
 public_id VARCHAR(64) NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 thread_public_id VARCHAR(64) NOT NULL,
 title VARCHAR(180) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_fm_folder_public (organization_id,public_id),
 UNIQUE KEY uq_fm_folder_thread (organization_id,user_id,thread_public_id),
 KEY idx_fm_folder_user (organization_id,user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
