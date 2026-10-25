-- FM-B8D13C: verified private, per-step checkpoints for durable read plans.
-- Task ownership and cancellation/retry policy remain in the parent task run.
CREATE TABLE food_monster_agent_task_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_run_id BIGINT UNSIGNED NOT NULL,
  step_index TINYINT UNSIGNED NOT NULL,
  tool_name VARCHAR(96) NOT NULL,
  plan_fingerprint CHAR(64) NOT NULL,
  observation_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_fm_agent_task_step (task_run_id,step_index),
  CONSTRAINT fk_fm_agent_step_run FOREIGN KEY (task_run_id)
    REFERENCES food_monster_agent_task_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
