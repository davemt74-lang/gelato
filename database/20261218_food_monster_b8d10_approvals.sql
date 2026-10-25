-- FM-B8D10: durable user-bound, human-confirmed Agent Chat approvals.
-- No model-selected high-impact action may execute during proposal creation.
CREATE TABLE food_monster_agent_approval_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  thread_public_id VARCHAR(30) NOT NULL,
  turn_public_id VARCHAR(24) NOT NULL,
  action VARCHAR(80) NOT NULL,
  resource_public_id VARCHAR(100) NOT NULL,
  request_json JSON NOT NULL,
  resource_fingerprint CHAR(64) NOT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'pending',
  expires_at DATETIME(6) NOT NULL,
  resolved_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_fm_agent_approval_public (organization_id,public_id),
  UNIQUE KEY uq_fm_agent_approval_turn (organization_id,actor_user_id,turn_public_id,action),
  KEY ix_fm_agent_approvals_owner (organization_id,actor_user_id,thread_public_id,state,expires_at),
  CONSTRAINT fk_fm_agent_approval_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_agent_approval_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
