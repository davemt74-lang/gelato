-- Section 259: each production stage is resolved on a single, authorized KDS line.
-- Automated captures remain unlinked until an employee confirms order identity.
CREATE TABLE glasses_order_photo_stages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 kds_item_id BIGINT UNSIGNED NOT NULL,
 stage VARCHAR(24) NOT NULL,
 camera_slot TINYINT UNSIGNED NOT NULL,
 photo_library_id BIGINT UNSIGNED NULL,
 status VARCHAR(24) NOT NULL,
 bypass_reason VARCHAR(250) NULL,
 reviewer_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 reviewed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (id),
 UNIQUE KEY uq_order_photo_stage (organization_id,kds_item_id,stage),
 KEY idx_order_photo_stage_location (organization_id,location_id,status,reviewed_at),
 CONSTRAINT fk_order_photo_stage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_order_photo_stage_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
 CONSTRAINT fk_order_photo_stage_kds FOREIGN KEY (kds_item_id) REFERENCES kds_order_items(id),
 CONSTRAINT fk_order_photo_stage_library FOREIGN KEY (photo_library_id) REFERENCES glasses_camera_photo_library(id),
 CONSTRAINT fk_order_photo_stage_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id),
 CONSTRAINT chk_order_photo_stage_state CHECK (status IN ('confirmed','bypassed')),
 CONSTRAINT chk_order_photo_stage_slot CHECK (camera_slot IN (2,3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
