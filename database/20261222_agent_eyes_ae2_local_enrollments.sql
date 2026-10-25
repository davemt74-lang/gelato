-- Agent Eyes AE2: owner-attested, consented local recognition enrollment.
-- No photographs, face descriptors, or voiceprints are stored on the server.
-- This table confers NO login, PIN, employee-clock, or Agent action authority.
CREATE TABLE IF NOT EXISTS fm_eyes_local_enrollments (
 organization_id BIGINT UNSIGNED NOT NULL,
 device_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL,
 consented_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 revoked_at DATETIME(6) NULL,
 PRIMARY KEY (organization_id,device_id,user_id),
 KEY idx_fm_eyes_user (organization_id,user_id,revoked_at),
 CONSTRAINT fk_fm_eyes_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
 CONSTRAINT fk_fm_eyes_device FOREIGN KEY (device_id) REFERENCES fm_pin_devices(id) ON DELETE CASCADE,
 CONSTRAINT fk_fm_eyes_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_fm_eyes_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
