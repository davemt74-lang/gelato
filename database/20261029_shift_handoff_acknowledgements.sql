-- G2H: explicit receipt of a scoped handoff, distinct from simply reading it.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS employee_shift_handoff_acknowledgements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  acknowledged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_handoff_ack (message_id,user_id),
  KEY idx_employee_handoff_ack_user (organization_id,user_id,acknowledged_at),
  CONSTRAINT fk_employee_handoff_ack_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_employee_handoff_ack_message FOREIGN KEY (message_id) REFERENCES employee_shift_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_handoff_ack_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
