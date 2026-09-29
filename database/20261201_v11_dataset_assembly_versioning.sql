ALTER TABLE glasses_vision_dataset_versions
  ADD COLUMN assembly_policy_json JSON NULL AFTER manifest_json,
  ADD COLUMN assembly_manifest_json JSON NULL AFTER assembly_policy_json,
  ADD COLUMN assembly_hash CHAR(64) NULL AFTER assembly_manifest_json,
  ADD COLUMN assembled_by BIGINT UNSIGNED NULL AFTER assembly_hash,
  ADD COLUMN assembled_at DATETIME(6) NULL AFTER assembled_by,
  ADD KEY idx_glasses_vision_dataset_assembly (organization_id,assembly_hash,status),
  ADD CONSTRAINT fk_glasses_vision_dataset_assembled_by FOREIGN KEY (assembled_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE glasses_vision_dataset_items
  ADD COLUMN source_media_id BIGINT UNSIGNED NULL AFTER sample_id,
  ADD COLUMN source_mined_candidate_id BIGINT UNSIGNED NULL AFTER source_media_id,
  ADD COLUMN training_value_score SMALLINT UNSIGNED NULL AFTER source_mined_candidate_id,
  ADD COLUMN eligibility_snapshot VARCHAR(24) NULL AFTER training_value_score,
  ADD COLUMN provenance_json JSON NULL AFTER eligibility_snapshot,
  ADD KEY idx_glasses_vision_dataset_item_media (organization_id,source_media_id),
  ADD KEY idx_glasses_vision_dataset_item_candidate (organization_id,source_mined_candidate_id),
  ADD CONSTRAINT fk_glasses_vision_dataset_item_media FOREIGN KEY (source_media_id) REFERENCES glasses_vision_training_media(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_glasses_vision_dataset_item_candidate FOREIGN KEY (source_mined_candidate_id) REFERENCES glasses_vision_mined_candidates(id) ON DELETE SET NULL;
