-- Durable anonymous assistant admission. All timestamps written by the runtime are UTC.
CREATE TABLE IF NOT EXISTS public_agent_usage_buckets (
  organization_id BIGINT UNSIGNED NOT NULL,
  bucket_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  window_started_at DATETIME(6) NOT NULL,
  window_ends_at DATETIME(6) NOT NULL,
  used_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (organization_id,bucket_type,bucket_hash),
  KEY idx_public_agent_bucket_expiry (organization_id,bucket_type,window_ends_at),
  CONSTRAINT fk_public_agent_bucket_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_agent_request_leases (
  organization_id BIGINT UNSIGNED NOT NULL,
  lease_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reserved_token_units INT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (organization_id,lease_id),
  KEY idx_public_agent_lease_expiry (organization_id,expires_at),
  CONSTRAINT fk_public_agent_lease_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
