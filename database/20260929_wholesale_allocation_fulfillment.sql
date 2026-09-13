-- Gelato Restaurant AI: Wholesale W3 Inventory Allocation + Fulfillment
SET NAMES utf8mb4;

ALTER TABLE wholesale_orders
  ADD COLUMN wholesale_account_location_id BIGINT UNSIGNED NULL AFTER wholesale_account_id,
  ADD COLUMN requested_window_start DATETIME(6) NULL AFTER requested_for,
  ADD COLUMN requested_window_end DATETIME(6) NULL AFTER requested_window_start,
  ADD COLUMN promised_window_start DATETIME(6) NULL AFTER promised_for,
  ADD COLUMN promised_window_end DATETIME(6) NULL AFTER promised_window_start,
  ADD KEY idx_wholesale_orders_location (organization_id, wholesale_account_location_id),
  ADD KEY idx_wholesale_orders_fulfillment_window (organization_id, status, promised_window_start, promised_window_end),
  ADD CONSTRAINT fk_wholesale_orders_account_location FOREIGN KEY (wholesale_account_location_id) REFERENCES wholesale_account_locations(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS wholesale_fulfillments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_location_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  fulfillment_number VARCHAR(120) NOT NULL,
  fulfillment_type VARCHAR(40) NOT NULL DEFAULT 'pickup',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  requested_window_start DATETIME(6) NULL,
  requested_window_end DATETIME(6) NULL,
  promised_window_start DATETIME(6) NULL,
  promised_window_end DATETIME(6) NULL,
  notes TEXT NULL,
  dispatched_at DATETIME(6) NULL,
  delivered_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_fulfillment_public (organization_id, public_id),
  UNIQUE KEY uq_wholesale_fulfillment_number (organization_id, fulfillment_number),
  KEY idx_wholesale_fulfillment_order (organization_id, wholesale_order_id, status, created_at),
  KEY idx_wholesale_fulfillment_window (organization_id, status, promised_window_start, promised_window_end),
  CONSTRAINT fk_wholesale_fulfillment_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_fulfillment_order FOREIGN KEY (wholesale_order_id) REFERENCES wholesale_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_fulfillment_location FOREIGN KEY (wholesale_account_location_id) REFERENCES wholesale_account_locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_wholesale_fulfillment_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_fulfillment_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_fulfillment_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_fulfillment_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_fulfillment_item (wholesale_fulfillment_id, wholesale_order_item_id),
  KEY idx_wholesale_fulfillment_item_order_line (organization_id, wholesale_order_item_id),
  CONSTRAINT fk_wholesale_fulfillment_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_fulfillment_items_batch FOREIGN KEY (wholesale_fulfillment_id) REFERENCES wholesale_fulfillments(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_fulfillment_items_order_line FOREIGN KEY (wholesale_order_item_id) REFERENCES wholesale_order_items(id) ON DELETE CASCADE,
  CONSTRAINT chk_wholesale_fulfillment_item_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_fulfillment_consumptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_fulfillment_id BIGINT UNSIGNED NOT NULL,
  wholesale_fulfillment_item_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_item_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  inventory_transaction_id BIGINT UNSIGNED NOT NULL,
  source_inventory_commitment_public_id VARCHAR(80) NULL,
  quantity DECIMAL(14,4) NOT NULL,
  unit VARCHAR(80) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_fulfillment_consumption (wholesale_fulfillment_item_id, inventory_item_id),
  UNIQUE KEY uq_wholesale_fulfillment_inventory_tx (inventory_transaction_id),
  KEY idx_wholesale_fulfillment_consumption_order_line (organization_id, wholesale_order_item_id, inventory_item_id),
  KEY idx_wholesale_fulfillment_consumption_batch (organization_id, wholesale_fulfillment_id),
  CONSTRAINT fk_wholesale_fulfillment_consumption_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_fulfillment_consumption_batch FOREIGN KEY (wholesale_fulfillment_id) REFERENCES wholesale_fulfillments(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_fulfillment_consumption_item FOREIGN KEY (wholesale_fulfillment_item_id) REFERENCES wholesale_fulfillment_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_fulfillment_consumption_order_line FOREIGN KEY (wholesale_order_item_id) REFERENCES wholesale_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_fulfillment_consumption_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_wholesale_fulfillment_consumption_transaction FOREIGN KEY (inventory_transaction_id) REFERENCES inventory_transactions(id),
  CONSTRAINT chk_wholesale_fulfillment_consumption_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
