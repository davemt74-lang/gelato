-- Per-user simulator UI preferences (no camera streams, voice consent, or audio).
CREATE TABLE glasses_user_display_preferences (
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  settings_json LONGTEXT NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id,user_id),
  CONSTRAINT fk_glasses_user_display_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_user_display_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
