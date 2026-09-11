-- Restaurant Training Workspace - Fresh Installation
-- Import this ONE file into an empty MySQL 8.0+ or MariaDB 10.11+ database.
-- Do not import schema.sql or seed.sql separately when using this file.

-- Restaurant Training, Hiring, and Menu Knowledge Platform
-- Target: MySQL 8.0+ or MariaDB 10.11+
-- Character set: utf8mb4
-- This schema is backend-ready architecture. The current browser prototype does not connect to it.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE organizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  legal_name VARCHAR(200) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Phoenix',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_organizations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  address_line_1 VARCHAR(200) NULL,
  address_line_2 VARCHAR(200) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  postal_code VARCHAR(30) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'US',
  phone VARCHAR(40) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_locations_org_status (organization_id, status),
  CONSTRAINT fk_locations_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  profile_image_file_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'invited',
  email_verified_at DATETIME(6) NULL,
  last_login_at DATETIME(6) NULL,
  password_changed_at DATETIME(6) NULL,
  failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NULL,
  storage_driver VARCHAR(32) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(150) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  visibility VARCHAR(32) NOT NULL DEFAULT 'organization_private',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_files_storage_path (storage_driver, storage_path),
  KEY idx_files_org_visibility (organization_id, visibility),
  CONSTRAINT fk_files_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD CONSTRAINT fk_users_profile_image FOREIGN KEY (profile_image_file_id) REFERENCES files(id);

CREATE TABLE organization_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  primary_location_id BIGINT UNSIGNED NULL,
  employee_number VARCHAR(80) NULL,
  job_title VARCHAR(160) NULL,
  hire_date DATE NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_membership_org_user (organization_id, user_id),
  KEY idx_membership_location (primary_location_id),
  CONSTRAINT fk_membership_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_membership_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_membership_location FOREIGN KEY (primary_location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_key VARCHAR(120) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  category VARCHAR(80) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_key (permission_key),
  KEY idx_permissions_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  is_system_role TINYINT(1) NOT NULL DEFAULT 0,
  is_owner_role TINYINT(1) NOT NULL DEFAULT 0,
  is_assignable TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_org_slug (organization_id, slug),
  KEY idx_roles_org_owner (organization_id, is_owner_role),
  CONSTRAINT fk_roles_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_role_scope (membership_id, role_id, location_id),
  KEY idx_user_roles_active (membership_id, revoked_at),
  CONSTRAINT fk_user_roles_membership FOREIGN KEY (membership_id) REFERENCES organization_memberships(id),
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_user_roles_location FOREIGN KEY (location_id) REFERENCES locations(id),
  CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE positions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_positions_org_slug (organization_id, slug),
  CONSTRAINT fk_positions_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_positions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ended_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_position_active (membership_id, position_id, status),
  CONSTRAINT fk_user_positions_membership FOREIGN KEY (membership_id) REFERENCES organization_memberships(id),
  CONSTRAINT fk_user_positions_position FOREIGN KEY (position_id) REFERENCES positions(id),
  CONSTRAINT fk_user_positions_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_token_hash CHAR(64) NOT NULL,
  csrf_token_hash CHAR(64) NOT NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(500) NULL,
  last_activity_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_sessions_token (session_token_hash),
  KEY idx_auth_sessions_user_active (user_id, revoked_at, expires_at),
  CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  used_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_token (token_hash),
  KEY idx_password_reset_user (user_id, expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(254) NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL,
  invited_by BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  accepted_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_invitation_token (token_hash),
  KEY idx_account_invitation_email (organization_id, email, accepted_at),
  CONSTRAINT fk_account_invitation_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_account_invitation_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_account_invitation_position FOREIGN KEY (position_id) REFERENCES positions(id),
  CONSTRAINT fk_account_invitation_inviter FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE brand_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  restaurant_name VARCHAR(180) NOT NULL,
  legal_name VARCHAR(220) NULL,
  logo_file_id BIGINT UNSIGNED NULL,
  square_logo_file_id BIGINT UNSIGNED NULL,
  favicon_file_id BIGINT UNSIGNED NULL,
  default_profile_file_id BIGINT UNSIGNED NULL,
  primary_color CHAR(7) NOT NULL DEFAULT '#d94a2b',
  secondary_color CHAR(7) NOT NULL DEFAULT '#ff835f',
  accent_color CHAR(7) NOT NULL DEFAULT '#d94a2b',
  background_color CHAR(7) NOT NULL DEFAULT '#f4f3ef',
  text_color CHAR(7) NOT NULL DEFAULT '#171815',
  theme_settings_json JSON NULL,
  contact_settings_json JSON NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_brand_settings_org (organization_id),
  CONSTRAINT fk_brand_settings_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_brand_settings_logo FOREIGN KEY (logo_file_id) REFERENCES files(id),
  CONSTRAINT fk_brand_settings_square_logo FOREIGN KEY (square_logo_file_id) REFERENCES files(id),
  CONSTRAINT fk_brand_settings_favicon FOREIGN KEY (favicon_file_id) REFERENCES files(id),
  CONSTRAINT fk_brand_settings_default_profile FOREIGN KEY (default_profile_file_id) REFERENCES files(id),
  CONSTRAINT fk_brand_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_pages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  page_type VARCHAR(64) NOT NULL,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  published_version INT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_public_pages_org_slug (organization_id, slug),
  KEY idx_public_pages_status (organization_id, status),
  CONSTRAINT fk_public_pages_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_public_pages_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_public_pages_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_page_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id BIGINT UNSIGNED NOT NULL,
  section_type VARCHAR(80) NOT NULL,
  heading VARCHAR(255) NULL,
  content_json JSON NOT NULL,
  settings_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_public_page_sections_order (page_id, sort_order),
  CONSTRAINT fk_public_page_sections_page FOREIGN KEY (page_id) REFERENCES public_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  form_type VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_forms_org_slug_version (organization_id, slug, version),
  KEY idx_forms_org_status (organization_id, status),
  CONSTRAINT fk_forms_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_forms_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_forms_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NULL,
  description VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_form_sections_order (form_id, sort_order),
  CONSTRAINT fk_form_sections_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_fields (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NULL,
  field_key VARCHAR(120) NOT NULL,
  field_type VARCHAR(64) NOT NULL,
  label VARCHAR(220) NOT NULL,
  help_text VARCHAR(500) NULL,
  placeholder VARCHAR(255) NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_system_field TINYINT(1) NOT NULL DEFAULT 0,
  settings_json JSON NULL,
  validation_json JSON NULL,
  conditional_rules_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_form_fields_key (form_id, field_key),
  KEY idx_form_fields_order (form_id, sort_order),
  CONSTRAINT fk_form_fields_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
  CONSTRAINT fk_form_fields_section FOREIGN KEY (section_id) REFERENCES form_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_field_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  field_id BIGINT UNSIGNED NOT NULL,
  option_value VARCHAR(255) NOT NULL,
  option_label VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_form_field_options_order (field_id, sort_order),
  CONSTRAINT fk_form_field_options_field FOREIGN KEY (field_id) REFERENCES form_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  submission_type VARCHAR(80) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'submitted',
  submitted_by_user_id BIGINT UNSIGNED NULL,
  submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(500) NULL,
  consent_snapshot_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_form_submissions_queue (organization_id, submission_type, status, submitted_at),
  CONSTRAINT fk_form_submissions_form FOREIGN KEY (form_id) REFERENCES forms(id),
  CONSTRAINT fk_form_submissions_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_form_submissions_user FOREIGN KEY (submitted_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_submission_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  field_id BIGINT UNSIGNED NOT NULL,
  answer_text LONGTEXT NULL,
  answer_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_submission_answer_field (submission_id, field_id),
  CONSTRAINT fk_submission_answers_submission FOREIGN KEY (submission_id) REFERENCES form_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_submission_answers_field FOREIGN KEY (field_id) REFERENCES form_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
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

CREATE TABLE resume_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  form_submission_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  applicant_user_id BIGINT UNSIGNED NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(40) NULL,
  position_interest VARCHAR(180) NULL,
  availability_summary TEXT NULL,
  experience_summary TEXT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'new',
  assigned_reviewer_id BIGINT UNSIGNED NULL,
  source VARCHAR(120) NULL,
  submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resume_form_submission (form_submission_id),
  KEY idx_resume_queue (organization_id, status, submitted_at),
  KEY idx_resume_email (organization_id, email),
  KEY idx_resume_job (job_id, status, submitted_at),
  CONSTRAINT fk_resume_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_resume_form_submission FOREIGN KEY (form_submission_id) REFERENCES form_submissions(id),
  CONSTRAINT fk_resume_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_resume_applicant FOREIGN KEY (applicant_user_id) REFERENCES users(id),
  CONSTRAINT fk_resume_reviewer FOREIGN KEY (assigned_reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resume_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resume_submission_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  file_type VARCHAR(64) NOT NULL DEFAULT 'resume',
  uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_resume_file (resume_submission_id, file_id),
  CONSTRAINT fk_resume_files_resume FOREIGN KEY (resume_submission_id) REFERENCES resume_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_resume_files_file FOREIGN KEY (file_id) REFERENCES files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resume_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resume_submission_id BIGINT UNSIGNED NOT NULL,
  author_user_id BIGINT UNSIGNED NOT NULL,
  note_text TEXT NOT NULL,
  is_private TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_resume_notes_resume (resume_submission_id, created_at),
  CONSTRAINT fk_resume_notes_resume FOREIGN KEY (resume_submission_id) REFERENCES resume_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_resume_notes_author FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resume_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resume_submission_id BIGINT UNSIGNED NOT NULL,
  previous_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  changed_by BIGINT UNSIGNED NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_resume_status_history (resume_submission_id, created_at),
  CONSTRAINT fk_resume_status_history_resume FOREIGN KEY (resume_submission_id) REFERENCES resume_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_resume_status_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resume_interviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resume_submission_id BIGINT UNSIGNED NOT NULL,
  scheduled_by BIGINT UNSIGNED NOT NULL,
  assigned_manager_id BIGINT UNSIGNED NULL,
  scheduled_at DATETIME(6) NOT NULL,
  location VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
  notes TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_resume_interviews_schedule (scheduled_at, status),
  CONSTRAINT fk_resume_interviews_resume FOREIGN KEY (resume_submission_id) REFERENCES resume_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_resume_interviews_scheduler FOREIGN KEY (scheduled_by) REFERENCES users(id),
  CONSTRAINT fk_resume_interviews_manager FOREIGN KEY (assigned_manager_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_sections_org_slug (organization_id, slug),
  CONSTRAINT fk_menu_sections_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  description TEXT NULL,
  preparation_notes TEXT NULL,
  behavior_tags_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_items_org_slug (organization_id, slug),
  KEY idx_menu_items_section_active (section_id, is_active),
  CONSTRAINT fk_menu_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_items_section FOREIGN KEY (section_id) REFERENCES menu_sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  option_name VARCHAR(160) NOT NULL,
  size_code VARCHAR(80) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  sort_order INT NOT NULL DEFAULT 0,
  active_from DATETIME(6) NULL,
  active_until DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_menu_item_prices_active (menu_item_id, active_from, active_until),
  CONSTRAINT fk_menu_item_prices_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingredients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  canonical_name VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  category VARCHAR(100) NULL,
  verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
  supplier_notes TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ingredients_org_slug (organization_id, slug),
  CONSTRAINT fk_ingredients_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_ingredients (
  menu_item_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(180) NULL,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  can_remove TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (menu_item_id, ingredient_id),
  CONSTRAINT fk_menu_item_ingredients_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_ingredients_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE allergen_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  is_major_us_allergen TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allergen_tags_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingredient_allergens (
  ingredient_id BIGINT UNSIGNED NOT NULL,
  allergen_tag_id BIGINT UNSIGNED NOT NULL,
  evidence_type VARCHAR(64) NOT NULL,
  verification_status VARCHAR(40) NOT NULL DEFAULT 'needs_verification',
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME(6) NULL,
  notes VARCHAR(500) NULL,
  PRIMARY KEY (ingredient_id, allergen_tag_id),
  CONSTRAINT fk_ingredient_allergens_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ingredient_allergens_tag FOREIGN KEY (allergen_tag_id) REFERENCES allergen_tags(id),
  CONSTRAINT fk_ingredient_allergens_verifier FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  note_type VARCHAR(64) NOT NULL,
  title VARCHAR(220) NOT NULL,
  summary TEXT NOT NULL,
  detail TEXT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_menu_notes_priority (organization_id, is_active, priority),
  CONSTRAINT fk_menu_notes_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_menu_notes_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
  CONSTRAINT fk_menu_notes_section FOREIGN KEY (section_id) REFERENCES menu_sections(id),
  CONSTRAINT fk_menu_notes_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE position_training_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  position_id BIGINT UNSIGNED NOT NULL,
  requirement_type VARCHAR(80) NOT NULL,
  requirement_reference_id VARCHAR(160) NOT NULL,
  minimum_score DECIMAL(5,2) NULL,
  required_mastery DECIMAL(5,2) NULL,
  expires_after_days INT UNSIGNED NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_position_requirements (position_id, is_required, sort_order),
  CONSTRAINT fk_position_requirements_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  assignment_type VARCHAR(80) NOT NULL,
  assignment_reference_id VARCHAR(160) NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  due_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'assigned',
  PRIMARY KEY (id),
  KEY idx_training_assignments_user (user_id, status, due_at),
  KEY idx_training_assignments_org (organization_id, status, due_at),
  CONSTRAINT fk_training_assignments_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_training_assignments_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_training_assignments_position FOREIGN KEY (position_id) REFERENCES positions(id),
  CONSTRAINT fk_training_assignments_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  quiz_type VARCHAR(80) NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  correct_count INT UNSIGNED NOT NULL,
  question_count INT UNSIGNED NOT NULL,
  passed TINYINT(1) NOT NULL,
  started_at DATETIME(6) NOT NULL,
  completed_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_quiz_attempts_user (user_id, completed_at),
  KEY idx_quiz_attempts_org_score (organization_id, quiz_type, completed_at),
  CONSTRAINT fk_quiz_attempts_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_quiz_attempts_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_quiz_attempts_position FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_attempt_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  question_key VARCHAR(180) NOT NULL,
  selected_answer_json JSON NOT NULL,
  correct_answer_json JSON NOT NULL,
  is_correct TINYINT(1) NOT NULL,
  menu_reference_type VARCHAR(64) NULL,
  menu_reference_id BIGINT UNSIGNED NULL,
  explanation TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_quiz_answers_attempt (attempt_id),
  CONSTRAINT fk_quiz_answers_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE flashcard_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  card_key VARCHAR(180) NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  ingredient_id BIGINT UNSIGNED NULL,
  result VARCHAR(32) NOT NULL,
  mastery_level DECIMAL(5,2) NOT NULL DEFAULT 0,
  interval_days INT UNSIGNED NOT NULL DEFAULT 0,
  next_review_at DATETIME(6) NULL,
  reviewed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_flashcard_due (user_id, next_review_at),
  CONSTRAINT fk_flashcard_reviews_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_flashcard_reviews_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_flashcard_reviews_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
  CONSTRAINT fk_flashcard_reviews_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_training_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  training_date DATE NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  question_count INT UNSIGNED NOT NULL,
  completed_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_daily_training_user_date_position (user_id, training_date, position_id),
  CONSTRAINT fk_daily_training_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_daily_training_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_daily_training_position FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE simulation_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  simulation_type VARCHAR(64) NOT NULL,
  scenario_key VARCHAR(180) NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  passed TINYINT(1) NOT NULL,
  mistakes_json JSON NULL,
  response_json JSON NULL,
  completed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_simulation_user_type (user_id, simulation_type, completed_at),
  CONSTRAINT fk_simulation_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_simulation_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_simulation_position FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  certification_type VARCHAR(120) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'issued',
  score DECIMAL(5,2) NULL,
  issued_by BIGINT UNSIGNED NOT NULL,
  issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_certifications_user_active (user_id, status, expires_at),
  CONSTRAINT fk_certifications_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_certifications_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_certifications_position FOREIGN KEY (position_id) REFERENCES positions(id),
  CONSTRAINT fk_certifications_issuer FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  source_type VARCHAR(80) NOT NULL,
  source_id VARCHAR(180) NULL,
  event_data_json JSON NULL,
  occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_training_events_user (user_id, occurred_at),
  KEY idx_training_events_org_type (organization_id, event_type, occurred_at),
  CONSTRAINT fk_training_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_training_events_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  agent_type VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  access_scope VARCHAR(80) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  settings_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_profile_type (organization_id, agent_type),
  CONSTRAINT fk_agent_profiles_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  agent_profile_id BIGINT UNSIGNED NOT NULL,
  rule_key VARCHAR(160) NOT NULL,
  name VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  condition_json JSON NOT NULL,
  severity VARCHAR(32) NOT NULL DEFAULT 'info',
  message_template TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_rules_key (organization_id, rule_key),
  CONSTRAINT fk_agent_rules_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_agent_rules_profile FOREIGN KEY (agent_profile_id) REFERENCES agent_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_insights (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  agent_profile_id BIGINT UNSIGNED NOT NULL,
  insight_type VARCHAR(100) NOT NULL,
  severity VARCHAR(32) NOT NULL,
  title VARCHAR(220) NOT NULL,
  message TEXT NOT NULL,
  source_type VARCHAR(80) NULL,
  source_id VARCHAR(180) NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  read_at DATETIME(6) NULL,
  resolved_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_agent_insights_queue (organization_id, status, severity, created_at),
  CONSTRAINT fk_agent_insights_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_agent_insights_profile FOREIGN KEY (agent_profile_id) REFERENCES agent_profiles(id),
  CONSTRAINT fk_agent_insights_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_briefings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  agent_profile_id BIGINT UNSIGNED NOT NULL,
  briefing_date DATE NOT NULL,
  briefing_type VARCHAR(80) NOT NULL,
  summary_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_briefing (agent_profile_id, briefing_date, briefing_type),
  CONSTRAINT fk_agent_briefings_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_agent_briefings_profile FOREIGN KEY (agent_profile_id) REFERENCES agent_profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(100) NOT NULL,
  title VARCHAR(220) NOT NULL,
  message TEXT NOT NULL,
  action_url VARCHAR(500) NULL,
  read_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_notifications_user_unread (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(160) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id VARCHAR(180) NULL,
  previous_values_json JSON NULL,
  new_values_json JSON NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_audit_org_time (organization_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  KEY idx_audit_actor (actor_user_id, created_at),
  CONSTRAINT fk_audit_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- Baseline permission and allergen seed data.
-- Replace organization IDs and owner credentials through the installation workflow.

INSERT INTO permissions (permission_key, name, description, category) VALUES
('dashboard.view','View dashboard','View permitted training dashboards.','Dashboard'),
('agent.owner_view','View owner system agent','View organization-wide training and resume intelligence.','Agent'),
('agent.employee_view','Use employee training agent','Use the employee-scoped menu training agent.','Agent'),
('users.view','View accounts','View user accounts.','Accounts'),
('users.create','Create accounts','Create user accounts and invitations.','Accounts'),
('users.edit','Edit accounts','Edit account profiles and status.','Accounts'),
('users.suspend','Suspend accounts','Suspend and reactivate user access.','Accounts'),
('users.assign_roles','Assign roles','Assign account types to users.','Accounts'),
('roles.view','View account types','View roles and permissions.','Permissions'),
('roles.create','Create account types','Create custom organization roles.','Permissions'),
('roles.edit','Edit account types','Edit custom roles.','Permissions'),
('roles.assign_permissions','Assign permissions','Grant permissions to organization roles.','Permissions'),
('training.self_view','View own training','View personal assignments, scores, and certifications.','Training'),
('training.assign','Assign training','Assign training requirements to users.','Training'),
('training.view_employee_progress','View employee progress','View progress for assigned employees.','Training'),
('training.view_all_progress','View all progress','View organization-wide training records.','Training'),
('training.issue_certifications','Issue certifications','Issue and revoke training certifications.','Training'),
('resumes.view','View resumes','View resume submissions.','Hiring'),
('jobs.view','View jobs','View job openings and publishing status.','Hiring'),
('jobs.create','Create jobs','Create restaurant job openings.','Hiring'),
('jobs.edit','Edit jobs','Edit job content and requirements.','Hiring'),
('jobs.publish','Publish jobs','Publish, pause, and archive job openings.','Hiring'),
('resumes.review','Review resumes','Change resume status and reviewer assignment.','Hiring'),
('resumes.add_notes','Add resume notes','Add private hiring notes.','Hiring'),
('resumes.convert_to_employee','Convert applicants','Create employee accounts from approved resumes.','Hiring'),
('forms.view','View forms','View form definitions and versions.','Content'),
('forms.create','Create forms','Create organization forms.','Content'),
('forms.edit','Edit forms','Edit and publish form versions.','Content'),
('public_pages.view','View public pages','View public page configuration.','Content'),
('public_pages.edit','Edit public pages','Edit and publish public page sections.','Content'),
('brand.view','View brand settings','View organization identity settings.','Organization'),
('brand.edit','Edit brand settings','Edit branding and public contact settings.','Organization'),
('reports.view','View reports','View training and hiring reports.','Organization'),
('settings.self_edit','Edit own profile','Edit personal profile settings.','Organization'),
('settings.organization_edit','Edit organization settings','Edit organization-wide settings.','Organization'),
('audit.view','View audit log','View privileged activity history.','Organization');

INSERT INTO allergen_tags (code, name, description, is_major_us_allergen) VALUES
('milk','Milk','Milk and milk-derived ingredients.',1),
('egg','Egg','Egg and egg-derived ingredients.',1),
('fish','Fish','Fish and fish-derived ingredients.',1),
('crustacean_shellfish','Crustacean Shellfish','Crab, lobster, shrimp, and related shellfish.',1),
('tree_nuts','Tree Nuts','Tree nuts and tree-nut-derived ingredients.',1),
('peanuts','Peanuts','Peanuts and peanut-derived ingredients.',1),
('wheat','Wheat','Wheat and wheat-derived ingredients.',1),
('soy','Soybeans','Soy and soy-derived ingredients.',1),
('sesame','Sesame','Sesame and sesame-derived ingredients.',1);

