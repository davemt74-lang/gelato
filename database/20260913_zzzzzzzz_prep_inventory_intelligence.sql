-- Gelato Restaurant AI: prep planning, historical intelligence and inventory forecasting
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS prep_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  plan_date DATE NOT NULL,
  service_period VARCHAR(32) NOT NULL DEFAULT 'all',
  title VARCHAR(220) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  projected_covers INT UNSIGNED NULL,
  demand_multiplier DECIMAL(7,3) NOT NULL DEFAULT 1.000,
  notes TEXT NULL,
  generated_source VARCHAR(60) NULL,
  generated_at DATETIME(6) NULL,
  published_at DATETIME(6) NULL,
  published_by BIGINT UNSIGNED NULL,
  closed_at DATETIME(6) NULL,
  closed_by BIGINT UNSIGNED NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prep_plan_public (organization_id, public_id),
  UNIQUE KEY uq_prep_plan_period (organization_id, plan_date, service_period),
  KEY idx_prep_plan_status (organization_id, status, plan_date),
  CONSTRAINT fk_prep_plan_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_prep_plan_publisher FOREIGN KEY (published_by) REFERENCES users(id),
  CONSTRAINT fk_prep_plan_closer FOREIGN KEY (closed_by) REFERENCES users(id),
  CONSTRAINT fk_prep_plan_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_prep_plan_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prep_recommendations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  normalized_key VARCHAR(180) NOT NULL,
  title VARCHAR(240) NOT NULL,
  recommended_quantity DECIMAL(14,3) NULL,
  unit VARCHAR(80) NOT NULL DEFAULT '',
  station VARCHAR(120) NULL,
  confidence DECIMAL(5,4) NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'proposed',
  basis_json JSON NULL,
  task_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prep_recommendation_public (organization_id, public_id),
  UNIQUE KEY uq_prep_recommendation_item (plan_id, normalized_key, unit),
  KEY idx_prep_recommendation_status (plan_id, status),
  KEY idx_prep_recommendation_task (organization_id, task_id),
  CONSTRAINT fk_prep_recommendation_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_prep_recommendation_plan FOREIGN KEY (plan_id) REFERENCES prep_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prep_plan_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  recommendation_id BIGINT UNSIGNED NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'manual',
  added_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prep_plan_task (task_id),
  KEY idx_prep_plan_tasks_plan (plan_id, created_at),
  KEY idx_prep_plan_tasks_org_task (organization_id, task_id),
  CONSTRAINT fk_prep_plan_tasks_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_prep_plan_tasks_plan FOREIGN KEY (plan_id) REFERENCES prep_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_prep_plan_tasks_recommendation FOREIGN KEY (recommendation_id) REFERENCES prep_recommendations(id) ON DELETE SET NULL,
  CONSTRAINT fk_prep_plan_tasks_user FOREIGN KEY (added_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prep_plan_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NULL,
  recommendation_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(600) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_prep_plan_events_plan (plan_id, created_at),
  KEY idx_prep_plan_events_org (organization_id, event_type, created_at),
  KEY idx_prep_plan_events_task (organization_id, task_id, created_at),
  CONSTRAINT fk_prep_plan_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_prep_plan_events_plan FOREIGN KEY (plan_id) REFERENCES prep_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_prep_plan_events_recommendation FOREIGN KEY (recommendation_id) REFERENCES prep_recommendations(id) ON DELETE SET NULL,
  CONSTRAINT fk_prep_plan_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prep_demand_signals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  signal_date DATE NOT NULL,
  service_period VARCHAR(32) NOT NULL DEFAULT 'all',
  signal_type VARCHAR(50) NOT NULL,
  item_key VARCHAR(180) NULL,
  item_name VARCHAR(240) NULL,
  quantity DECIMAL(14,3) NULL,
  unit VARCHAR(80) NULL,
  confidence DECIMAL(5,4) NULL,
  source_type VARCHAR(60) NULL,
  source_public_id VARCHAR(160) NULL,
  metadata_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prep_signal_public (organization_id, public_id),
  KEY idx_prep_signal_date (organization_id, signal_date, service_period, signal_type),
  KEY idx_prep_signal_source (organization_id, source_type, source_public_id),
  CONSTRAINT fk_prep_signal_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_prep_signal_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_forecasts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  required_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit VARCHAR(80) NOT NULL DEFAULT '',
  on_hand_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  projected_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  shortage_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  restock_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  confidence DECIMAL(5,4) NULL,
  basis_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_forecast_public (organization_id, public_id),
  UNIQUE KEY uq_inventory_forecast_item (plan_id, inventory_item_id, unit),
  KEY idx_inventory_forecast_shortage (organization_id, shortage_quantity, restock_quantity),
  KEY idx_inventory_forecast_inventory (organization_id, inventory_item_id),
  CONSTRAINT fk_inventory_forecast_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_inventory_forecast_plan FOREIGN KEY (plan_id) REFERENCES prep_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('prep.intelligence.view','View prep intelligence','View prep plans, recommendations, history and demand context.','Prep Intelligence'),
 ('prep.intelligence.manage','Manage prep intelligence','Generate, edit, publish and close prep plans and recommendations.','Prep Intelligence'),
 ('prep.intelligence.agent','Use prep intelligence Agent skills','Allow Restaurant AI to reason over prep history, forecasts and prep plans.','Prep Intelligence'),
 ('inventory.forecast.view','View inventory forecasts','View projected ingredient use, shortages and restock-to-par forecasts.','Inventory')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Preserve existing role intent: task/inventory access automatically gains the corresponding intelligence view.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='tasks.view'
JOIN permissions newp ON newp.permission_key='prep.intelligence.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='tasks.manage'
JOIN permissions newp ON newp.permission_key='prep.intelligence.manage';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='tasks.agent'
JOIN permissions newp ON newp.permission_key='prep.intelligence.agent';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='inventory.view'
JOIN permissions newp ON newp.permission_key='inventory.forecast.view';