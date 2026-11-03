-- Section 3K2G: numeric requested stock quantities and multiple previously recorded receipt links.
-- Free-form quantity_note is retained unchanged; no inventory stock or ledger writes.
ALTER TABLE daily_quicklist_items
  ADD COLUMN requested_stock_quantity DECIMAL(16,3) NULL AFTER quantity_note;

-- Keep a supporting index in place for the item foreign key before dropping the old unique index.
ALTER TABLE daily_quicklist_receipts
  ADD KEY idx_quicklist_receipt_item (organization_id,item_id,linked_at);

ALTER TABLE daily_quicklist_receipts
  DROP INDEX uq_quicklist_receipt_item,
  ADD COLUMN allocated_quantity DECIMAL(16,3) NULL AFTER inventory_transaction_id;

-- Existing Section 3K2F links represented the entire recorded receipt; preserve that evidence.
UPDATE daily_quicklist_receipts r
INNER JOIN inventory_transactions t ON t.id=r.inventory_transaction_id
SET r.allocated_quantity=t.quantity_delta WHERE r.allocated_quantity IS NULL;

ALTER TABLE daily_quicklist_receipts
  MODIFY allocated_quantity DECIMAL(16,3) NOT NULL,
  ADD CONSTRAINT ck_quicklist_receipt_positive CHECK (allocated_quantity>0);

ALTER TABLE daily_quicklist_items
  ADD CONSTRAINT ck_quicklist_requested_positive CHECK
    (requested_stock_quantity IS NULL OR requested_stock_quantity>0);
