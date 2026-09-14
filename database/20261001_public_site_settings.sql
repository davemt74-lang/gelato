-- Stonefellows public website settings.
-- Safe to run through the normal one-click Upgrade flow.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS public_site_settings (
  organization_id BIGINT UNSIGNED NOT NULL,
  tagline VARCHAR(255) NULL,
  hours_text TEXT NULL,
  address_line_1 VARCHAR(200) NULL,
  address_line_2 VARCHAR(200) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  postal_code VARCHAR(30) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(254) NULL,
  instagram_url VARCHAR(500) NULL,
  facebook_url VARCHAR(500) NULL,
  tiktok_url VARCHAR(500) NULL,
  youtube_url VARCHAR(500) NULL,
  x_url VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id),
  CONSTRAINT fk_public_site_settings_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_site_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
