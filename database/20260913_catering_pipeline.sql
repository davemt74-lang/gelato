-- Gelato Restaurant AI: catering inquiry + event sales pipeline
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS catering_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  company_name VARCHAR(200) NULL,
  contact_name VARCHAR(180) NOT NULL,
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(50) NULL,
  event_type VARCHAR(100) NOT NULL,
  event_date DATE NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  guest_count INT UNSIGNED NULL,
  venue_name VARCHAR(220) NULL,
  venue_address VARCHAR(500) NULL,
  service_style VARCHAR(120) NULL,
  menu_interests TEXT NULL,
  dietary_requirements TEXT NULL,
  beverage_service VARCHAR(160) NULL,
  staffing_needs VARCHAR(180) NULL,
  rentals_needs TEXT NULL,
  budget_range VARCHAR(120) NULL,
  fulfillment_preference VARCHAR(100) NULL,
  notes TEXT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'public_catering_form',
  pipeline_stage VARCHAR(40) NOT NULL DEFAULT 'new',
  estimated_value DECIMAL(12,2) NULL,
  probability_percent TINYINT UNSIGNED NOT NULL DEFAULT 10,
  assigned_to BIGINT UNSIGNED NULL,
  next_followup_at DATETIME(6) NULL,
  last_contact_at DATETIME(6) NULL,
  quoted_at DATETIME(6) NULL,
  booked_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  lost_at DATETIME(6) NULL,
  loss_reason VARCHAR(500) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catering_leads_org_public (organization_id, public_id),
  KEY idx_catering_pipeline (organization_id, pipeline_stage, archived_at, event_date),
  KEY idx_catering_assigned (organization_id, assigned_to, next_followup_at),
  KEY idx_catering_email (organization_id, email),
  KEY idx_catering_event_date (organization_id, event_date, pipeline_stage),
  CONSTRAINT fk_catering_leads_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_catering_leads_assignee FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catering_lead_activities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  catering_lead_id BIGINT UNSIGNED NOT NULL,
  activity_type VARCHAR(50) NOT NULL DEFAULT 'note',
  summary VARCHAR(500) NOT NULL,
  details TEXT NULL,
  metadata_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_catering_activity_lead (catering_lead_id, created_at),
  KEY idx_catering_activity_org (organization_id, created_at),
  CONSTRAINT fk_catering_activity_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_catering_activity_lead FOREIGN KEY (catering_lead_id) REFERENCES catering_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_catering_activity_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('catering.view', 'View catering pipeline', 'View catering inquiries, event details, activities, dates, and projected value.', 'Catering'),
  ('catering.manage', 'Manage catering pipeline', 'Edit catering inquiries, move stages, assign follow-up, quote and record activity.', 'Catering'),
  ('catering.agent', 'Use catering Agent skills', 'Allow the private Restaurant Agent to search, summarize, and reason over catering opportunities.', 'Catering')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  category = VALUES(category);
