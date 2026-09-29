ALTER TABLE glasses_vision_mined_candidates
  MODIFY production_error_id BIGINT UNSIGNED NULL,
  ADD COLUMN source_kind VARCHAR(40) NOT NULL DEFAULT 'production_error' AFTER production_error_id,
  ADD COLUMN media_id BIGINT UNSIGNED NULL AFTER source_kind,
  ADD COLUMN correction_id BIGINT UNSIGNED NULL AFTER media_id,
  ADD COLUMN training_session_id BIGINT UNSIGNED NULL AFTER correction_id,
  ADD COLUMN training_value_json JSON NULL AFTER lineage_json,
  ADD KEY idx_glasses_vision_mined_media (organization_id,media_id,status,score),
  ADD KEY idx_glasses_vision_mined_correction (organization_id,correction_id,status,score),
  ADD KEY idx_glasses_vision_mined_training_session (organization_id,training_session_id,status,score),
  ADD KEY idx_glasses_vision_mined_source_kind (organization_id,source_kind,status,score),
  ADD CONSTRAINT fk_glasses_vision_mined_media FOREIGN KEY (media_id) REFERENCES glasses_vision_training_media(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_glasses_vision_mined_correction FOREIGN KEY (correction_id) REFERENCES glasses_vision_annotation_corrections(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_glasses_vision_mined_training_session FOREIGN KEY (training_session_id) REFERENCES glasses_training_sessions(id) ON DELETE SET NULL;
