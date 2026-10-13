-- Section 260: customer-controlled, explicitly approved production photo sharing.
-- Defaults are OFF; delivery uses only the authenticated customer Inbox.
CREATE TABLE glasses_photo_delivery_settings (
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id,location_id),
  CONSTRAINT fk_gphoto_settings_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_settings_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_settings_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_customer_photo_preferences (
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id,customer_id),
  CONSTRAINT fk_gphoto_pref_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_pref_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_order_photo_shares (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  online_order_id BIGINT UNSIGNED NOT NULL,
  stage_id BIGINT UNSIGNED NOT NULL,
  photo_library_id BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NOT NULL,
  approved_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_by BIGINT UNSIGNED NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gphoto_share_stage (organization_id,stage_id),
  KEY idx_gphoto_share_order (organization_id,online_order_id,revoked_at),
  CONSTRAINT fk_gphoto_share_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_share_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_share_order FOREIGN KEY (online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_share_stage FOREIGN KEY (stage_id) REFERENCES glasses_order_photo_stages(id),
  CONSTRAINT fk_gphoto_share_photo FOREIGN KEY (photo_library_id) REFERENCES glasses_camera_photo_library(id),
  CONSTRAINT fk_gphoto_share_approved FOREIGN KEY (approved_by) REFERENCES users(id),
  CONSTRAINT fk_gphoto_share_revoked FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glasses_order_photo_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  online_order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  inbox_message_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_gphoto_notify_order (organization_id,online_order_id),
  CONSTRAINT fk_gphoto_notify_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_notify_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_notify_order FOREIGN KEY (online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_notify_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_gphoto_notify_message FOREIGN KEY (inbox_message_id) REFERENCES customer_inbox_messages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
