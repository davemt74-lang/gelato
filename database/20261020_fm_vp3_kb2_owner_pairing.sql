-- FM-KB2 private owner-authorized VP3 HomeServer pairing.
-- Each installed Food Monster instance owns its own isolated rows.
CREATE TABLE IF NOT EXISTS fm_vp3_instance (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 public_id CHAR(36) NOT NULL UNIQUE,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fm_vp3_instance (id, public_id) VALUES (1, UUID());

CREATE TABLE IF NOT EXISTS fm_vp3_connections (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(32) NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(120) NOT NULL,
 scopes_json VARCHAR(255) NOT NULL,
 pairing_code_sha256 CHAR(64) NULL,
 access_token_sha256 CHAR(64) NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 code_expires_at DATETIME(6) NOT NULL,
 activated_at DATETIME(6) NULL,
 revoked_at DATETIME(6) NULL,
 last_used_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_fm_vp3_connection_public (public_id),
 UNIQUE KEY uq_fm_vp3_connection_code (pairing_code_sha256),
 UNIQUE KEY uq_fm_vp3_connection_token (access_token_sha256),
 KEY idx_fm_vp3_connection_owner (organization_id,owner_user_id,status),
 KEY idx_fm_vp3_connection_expires (status,code_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
