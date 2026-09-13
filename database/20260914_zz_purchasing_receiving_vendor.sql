-- Gelato Restaurant AI: Purchasing + Receiving + Vendor Intelligence
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vendors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(220) NOT NULL,
  account_number VARCHAR(120) NULL,
  phone VARCHAR(60) NULL,
  email VARCHAR(254) NULL,
  ordering_email VARCHAR(254) NULL,
  website VARCHAR(500) NULL,
  minimum_order_amount DECIMAL(12,2) NULL,
  lead_time_days INT UNSIGNED NOT NULL DEFAULT 0,
  delivery_days_json JSON NULL,
  cutoff_time TIME NULL,
  payment_terms VARCHAR(160) NULL,
  notes TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_public (organization_id, public_id),
  UNIQUE KEY uq_vendor_name (organization_id, name),
  KEY idx_vendor_status (organization_id, status, name),
  CONSTRAINT fk_vendor_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_vendor_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_vendor_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  role_title VARCHAR(160) NULL,
  email VARCHAR(254) NULL,
  phone VARCHAR(60) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_contact_public (organization_id, public_id),
  KEY idx_vendor_contact_vendor (vendor_id, is_primary, name),
  CONSTRAINT fk_vendor_contact_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_vendor_contact_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_vendor_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  vendor_sku VARCHAR(120) NULL,
  vendor_item_name VARCHAR(240) NULL,
  pack_size DECIMAL(14,4) NULL,
  pack_unit VARCHAR(80) NULL,
  units_per_pack DECIMAL(14,4) NULL,
  price_per_pack DECIMAL(12,4) NULL,
  price_effective_at DATETIME(6) NULL,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_vendor_public (organization_id, public_id),
  UNIQUE KEY uq_inventory_vendor_pair (inventory_item_id, vendor_id, vendor_sku),
  KEY idx_inventory_vendor_preferred (organization_id, inventory_item_id, is_preferred, status),
  CONSTRAINT fk_inventory_vendor_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_inventory_vendor_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_vendor_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_vendor_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_inventory_vendor_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(100) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  expected_delivery_date DATE NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  shipping_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  submitted_at DATETIME(6) NULL,
  submitted_by BIGINT UNSIGNED NULL,
  received_at DATETIME(6) NULL,
  received_by BIGINT UNSIGNED NULL,
  cancelled_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_order_public (organization_id, public_id),
  UNIQUE KEY uq_purchase_order_number (organization_id, order_number),
  KEY idx_purchase_order_status (organization_id, status, expected_delivery_date),
  KEY idx_purchase_order_vendor (organization_id, vendor_id, created_at),
  CONSTRAINT fk_purchase_order_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_purchase_order_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_purchase_order_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id),
  CONSTRAINT fk_purchase_order_received_by FOREIGN KEY (received_by) REFERENCES users(id),
  CONSTRAINT fk_purchase_order_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_purchase_order_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  vendor_item_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  description VARCHAR(240) NOT NULL,
  ordered_packs DECIMAL(14,3) NOT NULL DEFAULT 0,
  pack_size DECIMAL(14,4) NULL,
  unit VARCHAR(80) NULL,
  ordered_base_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  received_base_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  price_per_pack DECIMAL(12,4) NULL,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_order_item_public (organization_id, public_id),
  KEY idx_purchase_order_item_order (purchase_order_id, id),
  KEY idx_purchase_order_item_inventory (organization_id, inventory_item_id),
  CONSTRAINT fk_purchase_order_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_purchase_order_item_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_order_item_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_purchase_order_item_vendor_item FOREIGN KEY (vendor_item_id) REFERENCES inventory_vendor_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  receipt_number VARCHAR(100) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  received_by BIGINT UNSIGNED NULL,
  vendor_invoice_number VARCHAR(120) NULL,
  notes TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_goods_receipt_public (organization_id, public_id),
  UNIQUE KEY uq_goods_receipt_number (organization_id, receipt_number),
  KEY idx_goods_receipt_po (purchase_order_id, received_at),
  CONSTRAINT fk_goods_receipt_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_goods_receipt_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  CONSTRAINT fk_goods_receipt_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_goods_receipt_user FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  goods_receipt_id BIGINT UNSIGNED NOT NULL,
  purchase_order_item_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  received_base_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit VARCHAR(80) NULL,
  actual_price_per_pack DECIMAL(12,4) NULL,
  damaged_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  missing_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  lot_code VARCHAR(120) NULL,
  expires_on DATE NULL,
  notes VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_goods_receipt_item_public (organization_id, public_id),
  KEY idx_goods_receipt_item_receipt (goods_receipt_id, id),
  CONSTRAINT fk_goods_receipt_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_goods_receipt_item_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_goods_receipt_item_po_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id),
  CONSTRAINT fk_goods_receipt_item_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_price_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  vendor_item_id BIGINT UNSIGNED NULL,
  price_per_pack DECIMAL(12,4) NOT NULL,
  pack_size DECIMAL(14,4) NULL,
  unit VARCHAR(80) NULL,
  source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
  source_public_id VARCHAR(160) NULL,
  effective_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_vendor_price_item (organization_id, inventory_item_id, effective_at),
  KEY idx_vendor_price_vendor (organization_id, vendor_id, effective_at),
  CONSTRAINT fk_vendor_price_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_vendor_price_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_vendor_price_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_vendor_price_vendor_item FOREIGN KEY (vendor_item_id) REFERENCES inventory_vendor_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_vendor_price_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(600) NOT NULL,
  metadata_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_purchase_order_events (purchase_order_id, created_at),
  CONSTRAINT fk_purchase_order_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_purchase_order_event_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_order_event_user FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key,name,description,category) VALUES
 ('vendors.view','View vendors','View restaurant vendors and purchasing catalog mappings.','Purchasing'),
 ('vendors.manage','Manage vendors','Create and edit vendors, contacts and vendor-item mappings.','Purchasing'),
 ('purchasing.view','View purchasing','View purchase suggestions and purchase orders.','Purchasing'),
 ('purchasing.manage','Manage purchasing','Create, edit, submit and cancel purchase orders.','Purchasing'),
 ('purchasing.agent','Use purchasing Agent skills','Allow Restaurant AI to reason over purchasing, vendors and purchase orders.','Purchasing'),
 ('receiving.view','View receiving','View goods receipts and purchase-order receiving status.','Receiving'),
 ('receiving.manage','Receive inventory','Record deliveries, shortages, damage, lots and inventory receipts.','Receiving')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id FROM role_permissions rp JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='inventory.view' JOIN permissions newp ON newp.permission_key IN ('vendors.view','purchasing.view','receiving.view');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id FROM role_permissions rp JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='inventory.manage' JOIN permissions newp ON newp.permission_key IN ('vendors.manage','purchasing.manage','receiving.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT rp.role_id,newp.id FROM role_permissions rp JOIN permissions oldp ON oldp.id=rp.permission_id AND oldp.permission_key='inventory.agent' JOIN permissions newp ON newp.permission_key='purchasing.agent';