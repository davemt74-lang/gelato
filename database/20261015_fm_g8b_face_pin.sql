-- FM-G8B-PIN: hashed employee PINs and superadmin-trusted devices.
-- Face templates and camera frames remain encrypted on the browser, not here.
CREATE TABLE IF NOT EXISTS fm_pin_credentials (
 organization_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 pin_hash VARCHAR(255) NOT NULL,
 failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 locked_until DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,user_id),
 CONSTRAINT fk_fmpin_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
 CONSTRAINT fk_fmpin_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fm_pin_devices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id BIGINT UNSIGNED NOT NULL,
 secret_hash CHAR(64) NOT NULL,
 label VARCHAR(100) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 locked_until DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 expires_at DATETIME(6) NOT NULL,
 revoked_at DATETIME(6) NULL,
 UNIQUE KEY uq_fmpin_device_secret (secret_hash),
 KEY idx_fmpin_device_org (organization_id,revoked_at),
 CONSTRAINT fk_fmpin_device_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
 CONSTRAINT fk_fmpin_device_actor FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fm_pin_device_accounts (
 device_id BIGINT UNSIGNED NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 consented_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (device_id,user_id),
 CONSTRAINT fk_fmpin_da_device FOREIGN KEY (device_id) REFERENCES fm_pin_devices(id) ON DELETE CASCADE,
 CONSTRAINT fk_fmpin_da_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
 CONSTRAINT fk_fmpin_da_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
