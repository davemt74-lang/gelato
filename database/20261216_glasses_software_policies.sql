-- Section 275: advisory software compatibility policy per restaurant and glasses platform.
-- Policy never installs binaries or grants device/employee authorization.
CREATE TABLE glasses_software_policies (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 platform VARCHAR(40) NOT NULL,
 minimum_version VARCHAR(48) NULL,
 target_version VARCHAR(48) NULL,
 notes VARCHAR(240) NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id),
 UNIQUE KEY uq_glasses_software_scope (organization_id,location_id,platform),
 CONSTRAINT fk_glasses_software_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_glasses_software_location FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE CASCADE,
 CONSTRAINT fk_glasses_software_editor FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
