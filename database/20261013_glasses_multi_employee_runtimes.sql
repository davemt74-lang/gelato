-- Section 266: isolated operator runtime assignments for paired glasses.
-- Active-only unique slots let historical ended assignments coexist on MySQL 8.
CREATE TABLE glasses_employee_runtimes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 public_id VARCHAR(80) NOT NULL,
 device_id BIGINT UNSIGNED NOT NULL,
 employee_user_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 active_slot TINYINT UNSIGNED NULL DEFAULT 1,
 started_by BIGINT UNSIGNED NULL,
 ended_by BIGINT UNSIGNED NULL,
 started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 last_seen_at DATETIME(6) NULL,
 ended_at DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (id),
 UNIQUE KEY uq_glasses_runtime_public (organization_id,public_id),
 UNIQUE KEY uq_glasses_runtime_live_device (organization_id,device_id,active_slot),
 UNIQUE KEY uq_glasses_runtime_live_employee (organization_id,employee_user_id,active_slot),
 KEY idx_glasses_runtime_org (organization_id,status,started_at),
 CONSTRAINT fk_glasses_runtime_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_glasses_runtime_device FOREIGN KEY (device_id) REFERENCES glasses_devices(id) ON DELETE CASCADE,
 CONSTRAINT fk_glasses_runtime_employee FOREIGN KEY (employee_user_id) REFERENCES users(id),
 CONSTRAINT fk_glasses_runtime_started_by FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_glasses_runtime_ended_by FOREIGN KEY (ended_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
