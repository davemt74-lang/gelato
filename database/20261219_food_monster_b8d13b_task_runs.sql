-- FM-B8D13B: durable, owner-bound Agent Chat task execution journal.
-- Never run a model-selected write a second time after an uncertain outcome.
CREATE TABLE food_monster_agent_task_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  thread_public_id VARCHAR(30) NOT NULL,
  turn_public_id VARCHAR(24) NOT NULL,
  plan_fingerprint CHAR(64) NOT NULL,
  plan_json JSON NOT NULL,
  impact VARCHAR(16) NOT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'running',
  lease_token CHAR(32) NOT NULL,
  lease_expires_at DATETIME(6) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  planned_steps INT UNSIGNED NOT NULL DEFAULT 0,
  completed_steps INT UNSIGNED NOT NULL DEFAULT 0,
  result_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  resolved_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fm_task_turn (organization_id,actor_user_id,turn_public_id),
  KEY ix_fm_task_owner_state (organization_id,actor_user_id,thread_public_id,state,updated_at),
  CONSTRAINT fk_fm_task_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_task_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
