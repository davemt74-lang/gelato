-- Gelato Restaurant AI: Wholesale W4 Receivables / Accounts Receivable
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wholesale_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  invoice_number VARCHAR(100) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  issue_date DATE NULL,
  due_date DATE NULL,
  payment_terms_snapshot VARCHAR(120) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  customer_reference VARCHAR(180) NULL,
  customer_note TEXT NULL,
  internal_note TEXT NULL,
  issued_at DATETIME(6) NULL,
  paid_at DATETIME(6) NULL,
  voided_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_invoice_public (organization_id,public_id),
  UNIQUE KEY uq_wholesale_invoice_number (organization_id,invoice_number),
  UNIQUE KEY uq_wholesale_invoice_order (wholesale_order_id),
  KEY idx_wholesale_invoice_account (organization_id,wholesale_account_id,status,due_date),
  KEY idx_wholesale_invoice_due (organization_id,status,due_date),
  CONSTRAINT fk_wholesale_invoice_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_invoice_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_invoice_order FOREIGN KEY (wholesale_order_id) REFERENCES wholesale_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_invoice_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_invoice_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_invoice_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_invoice_id BIGINT UNSIGNED NOT NULL,
  wholesale_order_item_id BIGINT UNSIGNED NULL,
  line_number INT UNSIGNED NOT NULL,
  sku_snapshot VARCHAR(100) NULL,
  item_name_snapshot VARCHAR(220) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  sell_uom_snapshot VARCHAR(80) NULL,
  unit_price_snapshot DECIMAL(12,4) NOT NULL,
  line_subtotal DECIMAL(14,2) NOT NULL,
  pricing_source VARCHAR(40) NOT NULL DEFAULT 'canonical',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_invoice_line (wholesale_invoice_id,line_number),
  KEY idx_wholesale_invoice_items_org (organization_id,wholesale_invoice_id),
  CONSTRAINT fk_wholesale_invoice_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_invoice_items_invoice FOREIGN KEY (wholesale_invoice_id) REFERENCES wholesale_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_invoice_items_order_line FOREIGN KEY (wholesale_order_item_id) REFERENCES wholesale_order_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_receivable_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  wholesale_invoice_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  entry_type VARCHAR(32) NOT NULL,
  amount_delta DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  effective_date DATE NOT NULL,
  payment_method VARCHAR(80) NULL,
  external_reference VARCHAR(180) NULL,
  note VARCHAR(1000) NULL,
  reverses_entry_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_receivable_public (organization_id,public_id),
  UNIQUE KEY uq_wholesale_receivable_reversal (reverses_entry_id),
  KEY idx_wholesale_receivable_invoice (organization_id,wholesale_invoice_id,effective_date,id),
  KEY idx_wholesale_receivable_account (organization_id,wholesale_account_id,effective_date,id),
  KEY idx_wholesale_receivable_type (organization_id,entry_type,effective_date),
  CONSTRAINT fk_wholesale_receivable_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_receivable_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_receivable_invoice FOREIGN KEY (wholesale_invoice_id) REFERENCES wholesale_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_receivable_reversal FOREIGN KEY (reverses_entry_id) REFERENCES wholesale_receivable_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wholesale_receivable_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_receivable_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_invoice_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_wholesale_receivable_events (organization_id,wholesale_invoice_id,created_at,id),
  CONSTRAINT fk_wholesale_receivable_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_receivable_event_invoice FOREIGN KEY (wholesale_invoice_id) REFERENCES wholesale_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_receivable_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('wholesale.receivables.view','View Wholesale receivables','View Wholesale invoices, balances, aging and payment history.','Wholesale'),
 ('wholesale.receivables.manage','Manage Wholesale receivables','Create and issue Wholesale invoices and record payments, credits, refunds and voids.','Wholesale')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

-- Preserve existing Wholesale access intent while keeping the A/R capability explicit.
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='wholesale.view'
JOIN permissions newp ON newp.permission_key='wholesale.receivables.view';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT DISTINCT rp.role_id,newp.id
FROM role_permissions rp
JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='wholesale.manage'
JOIN permissions newp ON newp.permission_key='wholesale.receivables.manage';
