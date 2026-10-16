-- FM-G9B: independent employee shift PIN; never usable for website sign-in.
CREATE TABLE IF NOT EXISTS fm_employee_clock_pins (
 organization_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 pin_hash VARCHAR(255) NOT NULL,
 failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 locked_until DATETIME(6) NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,user_id),
 CONSTRAINT fk_fm9b_pin_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
 CONSTRAINT fk_fm9b_pin_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_fm9b_pin_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
