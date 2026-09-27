-- Gelato AR Glasses Recipe → Build Definition
SET NAMES utf8mb4;

CREATE TABLE glasses_build_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'needs_review',
  source_hash CHAR(64) NOT NULL,
  definition_json JSON NOT NULL,
  compiled_by BIGINT UNSIGNED NULL,
  compiled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_glasses_build_definition_public (organization_id,public_id),
  KEY idx_glasses_build_definition_item (organization_id,menu_item_id,status,version),
  KEY idx_glasses_build_definition_recipe (organization_id,recipe_id),
  CONSTRAINT fk_glasses_definition_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_definition_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_glasses_definition_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
  CONSTRAINT fk_glasses_definition_compiler FOREIGN KEY (compiled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE glasses_build_sessions
  ADD COLUMN build_definition_id BIGINT UNSIGNED NULL AFTER menu_item_id;

ALTER TABLE glasses_build_sessions
  ADD KEY idx_glasses_build_definition (organization_id,build_definition_id),
  ADD CONSTRAINT fk_glasses_build_session_definition FOREIGN KEY (build_definition_id) REFERENCES glasses_build_definitions(id) ON DELETE SET NULL;
