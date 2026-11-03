-- Complete the existing hiring schema without importing browser-local prototype data.
ALTER TABLE resume_submissions
  ADD COLUMN public_id VARCHAR(48) NULL,
  ADD COLUMN request_hash CHAR(64) NULL,
  ADD COLUMN payload_hash CHAR(64) NULL,
  ADD COLUMN answers_json JSON NULL,
  ADD COLUMN converted_user_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY uq_resume_org_public_id (organization_id, public_id),
  ADD UNIQUE KEY uq_resume_org_request (organization_id, request_hash),
  ADD CONSTRAINT fk_resume_converted_user FOREIGN KEY (converted_user_id) REFERENCES users(id);

-- Existing database records retain their content and gain a stable private-review id.
UPDATE resume_submissions SET public_id=CONCAT('application-',REPLACE(UUID(),'-','')) WHERE public_id IS NULL;

CREATE TABLE hiring_intake_limits (
  organization_id BIGINT UNSIGNED NOT NULL,
  bucket_hash CHAR(64) NOT NULL,
  window_started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (organization_id, bucket_hash),
  CONSTRAINT fk_hiring_limit_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
