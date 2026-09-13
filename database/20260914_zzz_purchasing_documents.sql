-- Private purchasing invoices, receipts and packing slips
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS purchasing_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  purchase_order_id BIGINT UNSIGNED NULL,
  goods_receipt_id BIGINT UNSIGNED NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(40) NOT NULL DEFAULT 'invoice',
  extraction_status VARCHAR(32) NOT NULL DEFAULT 'uploaded',
  extracted_json JSON NULL,
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchasing_document_public (organization_id, public_id),
  KEY idx_purchasing_document_po (purchase_order_id, created_at),
  KEY idx_purchasing_document_receipt (goods_receipt_id, created_at),
  CONSTRAINT fk_purchasing_document_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_purchasing_document_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchasing_document_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchasing_document_file FOREIGN KEY (file_id) REFERENCES files(id),
  CONSTRAINT fk_purchasing_document_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;