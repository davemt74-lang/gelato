-- Email-first customer enrollment. No account or CRM claim is created until
-- the recipient submits an unexpired token and chooses/proves their password.
CREATE TABLE customer_account_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(254) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(40) NULL,
  email_marketing TINYINT(1) NOT NULL DEFAULT 0,
  sms_marketing TINYINT(1) NOT NULL DEFAULT 0,
  return_path VARCHAR(500) NOT NULL DEFAULT 'customer-account.php',
  token_hash CHAR(64) NULL,
  delivery_status VARCHAR(16) NOT NULL DEFAULT 'pending',
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_requested_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  used_at DATETIME(6) NULL,
  verification_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_verification_email (organization_id,email),
  UNIQUE KEY uq_customer_verification_token (token_hash),
  KEY idx_customer_verification_expiry (expires_at,used_at),
  CONSTRAINT fk_customer_verification_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
