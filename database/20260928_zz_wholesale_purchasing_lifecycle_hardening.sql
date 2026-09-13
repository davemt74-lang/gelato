-- Wholesale Purchasing + Lifecycle hardening: idempotent starter-order linkage
SET NAMES utf8mb4;

ALTER TABLE wholesale_purchase_worksheets
  ADD COLUMN starter_order_public_id VARCHAR(80) NULL AFTER starter_order_json,
  ADD UNIQUE KEY uq_wholesale_purchase_starter_order (organization_id, starter_order_public_id);
