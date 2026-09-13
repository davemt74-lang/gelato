-- Gelato Restaurant AI: Wholesale Demand Commitments
SET NAMES utf8mb4;

ALTER TABLE wholesale_skus
  ADD COLUMN content_uom VARCHAR(80) NULL AFTER units_per_sell_uom;

CREATE TABLE IF NOT EXISTS inventory_commitments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(60) NOT NULL,
  source_public_id VARCHAR(180) NOT NULL,
  source_parent_public_id VARCHAR(120) NOT NULL,
  commitment_date DATE NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  unit VARCHAR(80) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  basis_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  released_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_commitment_public (organization_id, public_id),
  UNIQUE KEY uq_inventory_commitment_source_item (organization_id, source_type, source_public_id, inventory_item_id),
  KEY idx_inventory_commitment_demand (organization_id, status, commitment_date, inventory_item_id),
  KEY idx_inventory_commitment_parent (organization_id, source_type, source_parent_public_id, status),
  CONSTRAINT fk_inventory_commitment_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_inventory_commitment_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_inventory_commitment_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_inventory_commitment_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
