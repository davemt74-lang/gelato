-- Daily staff prep checklist and inventory Need More queue.
-- These operational lists do not modify stock quantities or approved prep plans.
CREATE TABLE IF NOT EXISTS daily_quicklist_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  list_type VARCHAR(20) NOT NULL,
  list_date DATE NULL,
  title VARCHAR(220) NOT NULL,
  source_inventory_public_id VARCHAR(80) NULL,
  recipe_public_id VARCHAR(80) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'todo',
  quantity_note VARCHAR(120) NULL,
  elapsed_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  timer_started_at DATETIME(6) NULL,
  admin_note TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_daily_quicklist_org_public (organization_id,public_id),
  KEY idx_daily_quicklist_date (organization_id,list_type,list_date,archived_at,status),
  KEY idx_daily_quicklist_inventory (organization_id,source_inventory_public_id,archived_at),
  CONSTRAINT fk_daily_quicklist_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_daily_quicklist_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_daily_quicklist_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_quicklist_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  detail_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_daily_quicklist_history (organization_id,item_id,created_at),
  CONSTRAINT fk_daily_quicklist_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_daily_quicklist_event_item FOREIGN KEY (item_id) REFERENCES daily_quicklist_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_daily_quicklist_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
