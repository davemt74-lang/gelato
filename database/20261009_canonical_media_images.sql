-- Stonefellows / Gelato canonical media references
SET NAMES utf8mb4;

ALTER TABLE menu_items
  ADD COLUMN IF NOT EXISTS main_image_file_id BIGINT UNSIGNED NULL AFTER behavior_tags_json;

ALTER TABLE ingredients
  ADD COLUMN IF NOT EXISTS image_file_id BIGINT UNSIGNED NULL AFTER category;

ALTER TABLE locations
  ADD COLUMN IF NOT EXISTS cover_image_file_id BIGINT UNSIGNED NULL AFTER longitude;

ALTER TABLE menu_items
  ADD CONSTRAINT fk_menu_items_main_image FOREIGN KEY (main_image_file_id) REFERENCES files(id) ON DELETE SET NULL;

ALTER TABLE ingredients
  ADD CONSTRAINT fk_ingredients_image FOREIGN KEY (image_file_id) REFERENCES files(id) ON DELETE SET NULL;

ALTER TABLE locations
  ADD CONSTRAINT fk_locations_cover_image FOREIGN KEY (cover_image_file_id) REFERENCES files(id) ON DELETE SET NULL;
