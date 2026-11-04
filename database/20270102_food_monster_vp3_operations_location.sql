-- Existing menu and recipe links remain organization scoped.
ALTER TABLE fm_vp3_connections
  ADD COLUMN IF NOT EXISTS location_id BIGINT UNSIGNED NULL AFTER scopes_json;
