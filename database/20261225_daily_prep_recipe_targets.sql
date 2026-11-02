-- Shared target batch yield for recipe-backed daily prep; canonical recipes are unchanged.
ALTER TABLE daily_quicklist_items
  ADD COLUMN target_yield_quantity DECIMAL(12,3) NULL AFTER recipe_public_id,
  ADD COLUMN target_yield_unit VARCHAR(80) NULL AFTER target_yield_quantity;
