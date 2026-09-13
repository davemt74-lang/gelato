-- Gelato Restaurant AI: enforce Wholesale Commerce price-list references
SET NAMES utf8mb4;

ALTER TABLE wholesale_quotes
  ADD CONSTRAINT fk_wholesale_quotes_price_list
  FOREIGN KEY (price_list_id) REFERENCES wholesale_price_lists(id) ON DELETE SET NULL;

ALTER TABLE wholesale_orders
  ADD CONSTRAINT fk_wholesale_orders_price_list
  FOREIGN KEY (price_list_id) REFERENCES wholesale_price_lists(id) ON DELETE SET NULL;
