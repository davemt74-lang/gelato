-- Gelato Vision Lab V11 Section 6 — Training Run Orchestration & Experiment Lineage
SET NAMES utf8mb4;

ALTER TABLE glasses_vision_training_runs
  ADD COLUMN experiment_id BIGINT UNSIGNED NULL AFTER qualification_id,
  ADD COLUMN parent_run_id BIGINT UNSIGNED NULL AFTER experiment_id,
  ADD COLUMN attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER parent_run_id,
  ADD COLUMN dataset_hash_snapshot CHAR(64) NULL AFTER attempt_no,
  ADD COLUMN assembly_hash_snapshot CHAR(64) NULL AFTER dataset_hash_snapshot,
  ADD COLUMN release_hash_snapshot CHAR(64) NULL AFTER assembly_hash_snapshot,
  ADD COLUMN qualification_hash_snapshot CHAR(64) NULL AFTER release_hash_snapshot,
  ADD COLUMN config_hash CHAR(64) NULL AFTER qualification_hash_snapshot,
  ADD COLUMN run_hash CHAR(64) NULL AFTER config_hash,
  ADD COLUMN artifacts_json JSON NULL AFTER metrics_json,
  ADD COLUMN metrics_hash CHAR(64) NULL AFTER artifacts_json,
  ADD COLUMN failure_json JSON NULL AFTER metrics_hash,
  ADD COLUMN requested_by BIGINT UNSIGNED NULL AFTER failure_json,
  ADD COLUMN started_by BIGINT UNSIGNED NULL AFTER requested_by,
  ADD COLUMN completed_by BIGINT UNSIGNED NULL AFTER started_by,
  ADD UNIQUE KEY uq_glasses_vision_training_run_hash (organization_id,run_hash),
  ADD KEY idx_glasses_vision_training_run_experiment (organization_id,experiment_id,attempt_no),
  ADD KEY idx_glasses_vision_training_run_parent (organization_id,parent_run_id),
  ADD CONSTRAINT fk_glasses_vision_training_run_experiment FOREIGN KEY (experiment_id) REFERENCES glasses_vision_model_experiments(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_glasses_vision_training_run_parent FOREIGN KEY (parent_run_id) REFERENCES glasses_vision_training_runs(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_training_run_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_training_run_started_by FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_training_run_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL;
