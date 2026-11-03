-- Section 3K2F: evidence-only link between Need More and an ALREADY recorded
-- inventory receipt. No trigger or write to physical stock/ledger is created.
CREATE TABLE IF NOT EXISTS daily_quicklist_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  inventory_transaction_id BIGINT UNSIGNED NOT NULL,
  linked_by BIGINT UNSIGNED NOT NULL,
  linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_quicklist_receipt_item (item_id),
  UNIQUE KEY uq_quicklist_receipt_transaction (inventory_transaction_id),
  KEY idx_quicklist_receipt_tenant (organization_id,linked_at),
  CONSTRAINT fk_quicklist_receipt_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_quicklist_receipt_item FOREIGN KEY (item_id) REFERENCES daily_quicklist_items(id),
  CONSTRAINT fk_quicklist_receipt_txn FOREIGN KEY (inventory_transaction_id) REFERENCES inventory_transactions(id),
  CONSTRAINT fk_quicklist_receipt_actor FOREIGN KEY (linked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
