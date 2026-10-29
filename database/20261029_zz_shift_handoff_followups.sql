-- B8D17G2I: durable handoff escalation and documented resolution.
-- A communication is not a canonical restaurant task; a task link is evidence only.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS employee_shift_handoff_followups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  escalated_at DATETIME(6) NULL,
  escalated_by BIGINT UNSIGNED NULL,
  escalation_reason VARCHAR(1000) NULL,
  resolution_outcome VARCHAR(32) NULL,
  resolution_note TEXT NULL,
  linked_task_public_id VARCHAR(80) NULL,
  resolved_at DATETIME(6) NULL,
  resolved_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shift_handoff_followup_message (message_id),
  KEY idx_shift_handoff_followup_escalation (organization_id,escalated_at),
  KEY idx_shift_handoff_followup_resolution (organization_id,resolved_at),
  CONSTRAINT fk_shift_handoff_followup_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_shift_handoff_followup_message FOREIGN KEY (message_id) REFERENCES employee_shift_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_shift_handoff_followup_escalator FOREIGN KEY (escalated_by) REFERENCES users(id),
  CONSTRAINT fk_shift_handoff_followup_resolver FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
