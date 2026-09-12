-- Gelato Restaurant AI: time clock, attendance, opt-in voice identity and proactive employee Agent
SET NAMES utf8mb4;

-- The schedule foreign keys are added by the dated follow-up migration after
-- all 20260913 feature migrations. This keeps fresh installs deterministic even
-- when same-day filenames sort differently across environments.
CREATE TABLE IF NOT EXISTS time_clock_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  schedule_shift_id BIGINT UNSIGNED NULL,
  clocked_in_at DATETIME(6) NOT NULL,
  clocked_out_at DATETIME(6) NULL,
  clock_in_source VARCHAR(32) NOT NULL DEFAULT 'manual',
  clock_out_source VARCHAR(32) NULL,
  clock_in_note VARCHAR(1000) NULL,
  clock_out_note VARCHAR(1000) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  corrected_by BIGINT UNSIGNED NULL,
  correction_reason VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_clock_public (organization_id,public_id),
  KEY idx_time_clock_user_open (organization_id,user_id,status,clocked_in_at),
  KEY idx_time_clock_shift (organization_id,schedule_shift_id),
  CONSTRAINT fk_time_clock_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_time_clock_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_time_clock_corrector FOREIGN KEY (corrected_by) REFERENCES users(id),
  CONSTRAINT fk_time_clock_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_time_clock_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_clock_breaks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  time_clock_entry_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME(6) NOT NULL,
  ended_at DATETIME(6) NULL,
  break_type VARCHAR(24) NOT NULL DEFAULT 'rest',
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_time_clock_break_entry (time_clock_entry_id,started_at),
  CONSTRAINT fk_time_clock_break_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_time_clock_break_entry FOREIGN KEY (time_clock_entry_id) REFERENCES time_clock_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_clock_break_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  schedule_shift_id BIGINT UNSIGNED NULL,
  time_clock_entry_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(48) NOT NULL,
  severity VARCHAR(20) NOT NULL DEFAULT 'info',
  summary VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_attendance_user (organization_id,user_id,created_at),
  KEY idx_attendance_type (organization_id,event_type,created_at),
  CONSTRAINT fk_attendance_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_attendance_clock FOREIGN KEY (time_clock_entry_id) REFERENCES time_clock_entries(id),
  CONSTRAINT fk_attendance_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_identity_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'disabled',
  consent_version VARCHAR(32) NULL,
  consented_at DATETIME(6) NULL,
  enrolled_at DATETIME(6) NULL,
  disabled_at DATETIME(6) NULL,
  provider VARCHAR(48) NOT NULL DEFAULT 'gelato_local_voiceprint_v1',
  provider_reference VARCHAR(255) NULL,
  voiceprint_json JSON NULL,
  match_threshold DECIMAL(5,4) NOT NULL DEFAULT 0.8600,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_verified_at DATETIME(6) NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_voice_profile_user (organization_id,user_id),
  KEY idx_voice_profile_status (organization_id,status),
  CONSTRAINT fk_voice_profile_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_voice_profile_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_identity_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  duration_ms INT UNSIGNED NULL,
  transcript VARCHAR(1000) NULL,
  feature_json JSON NULL,
  sample_status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voice_sample_public (organization_id,public_id),
  KEY idx_voice_sample_user (organization_id,user_id,sample_status,created_at),
  CONSTRAINT fk_voice_sample_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_voice_sample_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_identity_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  session_user_id BIGINT UNSIGNED NOT NULL,
  matched_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  confidence DECIMAL(6,5) NULL,
  threshold_used DECIMAL(5,4) NULL,
  context VARCHAR(80) NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_voice_event_public (organization_id,public_id),
  KEY idx_voice_event_session (organization_id,session_user_id,created_at),
  KEY idx_voice_event_match (organization_id,matched_user_id,created_at),
  CONSTRAINT fk_voice_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_voice_event_session_user FOREIGN KEY (session_user_id) REFERENCES users(id),
  CONSTRAINT fk_voice_event_matched_user FOREIGN KEY (matched_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proactive_agent_preferences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  listening_enabled TINYINT(1) NOT NULL DEFAULT 0,
  proactive_voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
  wake_phrase VARCHAR(120) NOT NULL DEFAULT 'Hey Gelato',
  shift_reminders TINYINT(1) NOT NULL DEFAULT 1,
  task_reminders TINYINT(1) NOT NULL DEFAULT 1,
  break_reminders TINYINT(1) NOT NULL DEFAULT 1,
  last_enabled_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_proactive_preferences_user (organization_id,user_id),
  CONSTRAINT fk_proactive_preferences_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_proactive_preferences_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proactive_agent_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  event_type VARCHAR(48) NOT NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  message VARCHAR(1000) NOT NULL,
  action_url VARCHAR(500) NULL,
  dedupe_key VARCHAR(220) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  delivered_at DATETIME(6) NULL,
  acknowledged_at DATETIME(6) NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_proactive_event_dedupe (organization_id,user_id,dedupe_key),
  KEY idx_proactive_event_pending (organization_id,user_id,status,created_at),
  CONSTRAINT fk_proactive_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_proactive_event_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('timeclock.self','Use personal time clock','Clock in/out, take breaks and review personal attendance.','Time Clock'),
 ('timeclock.view','View time clock','View restaurant time-clock and attendance status.','Time Clock'),
 ('timeclock.manage','Manage time clock','Correct time entries and manage attendance records with an audit reason.','Time Clock'),
 ('timeclock.agent','Use time-clock Agent skills','Allow Restaurant AI to read time-clock status and perform permitted self actions.','Time Clock'),
 ('attendance.view','View attendance intelligence','View late, no-show, configured-hour and scheduled-versus-actual labor intelligence.','Time Clock'),
 ('voice.self','Manage own voice profile','Opt in, enroll, re-record, verify, disable or delete your own voice profile.','Voice Agent'),
 ('voice.manage','Manage voice enrollment status','View employee voice-enrollment status and organization voice settings without exposing raw voiceprints.','Voice Agent'),
 ('voice.agent','Use voice-personalized Agent','Allow the Restaurant AI to personalize voice conversations after an opt-in voice match.','Voice Agent'),
 ('agent.proactive','Use proactive Restaurant Agent','Receive permission-scoped proactive shift, task, break and operations alerts.','AI Agent')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);