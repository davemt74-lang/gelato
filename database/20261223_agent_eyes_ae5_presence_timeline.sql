-- Agent Eyes AE5: opt-in named-presence timeline, scoped to organization.
-- No raw images, descriptors, microphone audio or anonymous visitor identities.
CREATE TABLE IF NOT EXISTS fm_eyes_presence_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(32) NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_eyes5_event (organization_id,event_key),
  KEY idx_eyes5_person (organization_id,user_id,occurred_at),
  KEY idx_eyes5_recency (organization_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
