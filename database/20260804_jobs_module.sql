-- Fatso Jobs Module migration for an existing installation. Import once.

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(120) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(180) NOT NULL,
  department VARCHAR(120) NULL,
  location_name VARCHAR(180) NULL,
  employment_type VARCHAR(80) NULL,
  schedule_text VARCHAR(500) NULL,
  pay_range VARCHAR(180) NULL,
  summary TEXT NULL,
  description LONGTEXT NULL,
  responsibilities_json JSON NULL,
  requirements_json JSON NULL,
  benefits_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  published_at DATETIME(6) NULL,
  closes_at DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jobs_org_public_id (organization_id, public_id),
  UNIQUE KEY uq_jobs_org_slug (organization_id, slug),
  KEY idx_jobs_public (organization_id, status, published_at, closes_at),
  CONSTRAINT fk_jobs_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_jobs_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_jobs_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @add_resume_job_column = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'resume_submissions' AND column_name = 'job_id') = 0,
  'ALTER TABLE resume_submissions ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER form_submission_id',
  'SELECT 1'
);
PREPARE stmt FROM @add_resume_job_column;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_resume_job_index = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'resume_submissions' AND index_name = 'idx_resume_job') = 0,
  'ALTER TABLE resume_submissions ADD KEY idx_resume_job (job_id, status, submitted_at)',
  'SELECT 1'
);
PREPARE stmt FROM @add_resume_job_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_resume_job_fk = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'resume_submissions' AND constraint_name = 'fk_resume_job') = 0,
  'ALTER TABLE resume_submissions ADD CONSTRAINT fk_resume_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @add_resume_job_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO permissions (permission_key,name,description,category) VALUES
('jobs.view','View jobs','View job openings and publishing status.','Hiring'),
('jobs.create','Create jobs','Create restaurant job openings.','Hiring'),
('jobs.edit','Edit jobs','Edit job content and requirements.','Hiring'),
('jobs.publish','Publish jobs','Publish, pause, and archive job openings.','Hiring')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id
FROM roles r
JOIN permissions p ON p.permission_key IN ('jobs.view','jobs.create','jobs.edit','jobs.publish')
WHERE r.slug IN ('super_admin','manager');
