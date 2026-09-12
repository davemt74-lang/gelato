-- Gelato Restaurant AI: wholesale customer accounts + private buyer Agent portal
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wholesale_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  wholesale_lead_id BIGINT UNSIGNED NULL,
  business_name VARCHAR(200) NOT NULL,
  account_status VARCHAR(32) NOT NULL DEFAULT 'active',
  primary_email VARCHAR(254) NULL,
  phone VARCHAR(50) NULL,
  website VARCHAR(500) NULL,
  billing_email VARCHAR(254) NULL,
  business_type VARCHAR(80) NULL,
  price_tier VARCHAR(80) NULL,
  payment_terms VARCHAR(120) NULL,
  preferred_fulfillment VARCHAR(80) NULL,
  flavor_preferences TEXT NULL,
  package_preferences_json JSON NULL,
  private_label_interest TINYINT(1) NOT NULL DEFAULT 0,
  customer_notes TEXT NULL,
  internal_notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  archived_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_accounts_org_public (organization_id, public_id),
  UNIQUE KEY uq_wholesale_accounts_lead (wholesale_lead_id),
  KEY idx_wholesale_accounts_status (organization_id, account_status, archived_at, updated_at),
  CONSTRAINT fk_wholesale_accounts_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_accounts_lead FOREIGN KEY (wholesale_lead_id) REFERENCES wholesale_leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_wholesale_accounts_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_accounts_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_account_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  account_role VARCHAR(40) NOT NULL DEFAULT 'buyer',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_account_user (wholesale_account_id, user_id),
  UNIQUE KEY uq_wholesale_org_user (organization_id, user_id),
  KEY idx_wholesale_account_users_account (organization_id, wholesale_account_id, status),
  CONSTRAINT fk_wholesale_account_users_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_account_users_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_account_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_account_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  address_line_1 VARCHAR(200) NULL,
  address_line_2 VARCHAR(200) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  postal_code VARCHAR(30) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'US',
  contact_name VARCHAR(180) NULL,
  phone VARCHAR(50) NULL,
  delivery_notes TEXT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_location_public (organization_id, public_id),
  KEY idx_wholesale_locations_account (wholesale_account_id, status, is_primary),
  CONSTRAINT fk_wholesale_locations_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_locations_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_portal_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(254) NOT NULL,
  contact_name VARCHAR(180) NULL,
  account_role VARCHAR(40) NOT NULL DEFAULT 'buyer',
  token_hash CHAR(64) NOT NULL,
  invited_by BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  accepted_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_portal_invite_token (token_hash),
  KEY idx_wholesale_portal_invite_email (organization_id, email, accepted_at, revoked_at),
  KEY idx_wholesale_portal_invite_account (wholesale_account_id, created_at),
  CONSTRAINT fk_wholesale_portal_invite_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_portal_invite_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_portal_invite_user FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_quotes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  quote_number VARCHAR(80) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  items_json JSON NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  valid_until DATE NULL,
  customer_message TEXT NULL,
  terms_text TEXT NULL,
  sent_at DATETIME(6) NULL,
  accepted_at DATETIME(6) NULL,
  declined_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_quote_public (organization_id, public_id),
  UNIQUE KEY uq_wholesale_quote_number (organization_id, quote_number),
  KEY idx_wholesale_quotes_account (wholesale_account_id, status, updated_at),
  CONSTRAINT fk_wholesale_quotes_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_quotes_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_quotes_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_quotes_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  source_quote_id BIGINT UNSIGNED NULL,
  public_id VARCHAR(80) NOT NULL,
  order_number VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'requested',
  items_json JSON NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  fulfillment_type VARCHAR(40) NULL,
  requested_for DATE NULL,
  promised_for DATETIME(6) NULL,
  delivered_at DATETIME(6) NULL,
  customer_notes TEXT NULL,
  internal_notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_order_public (organization_id, public_id),
  UNIQUE KEY uq_wholesale_order_number (organization_id, order_number),
  UNIQUE KEY uq_wholesale_orders_source_quote (source_quote_id),
  KEY idx_wholesale_orders_account (wholesale_account_id, status, updated_at),
  CONSTRAINT fk_wholesale_orders_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_orders_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_orders_quote FOREIGN KEY (source_quote_id) REFERENCES wholesale_quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_wholesale_orders_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_orders_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_customer_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  submitted_by BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(80) NOT NULL,
  request_type VARCHAR(40) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  subject VARCHAR(220) NOT NULL,
  details TEXT NOT NULL,
  metadata_json JSON NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wholesale_request_public (organization_id, public_id),
  KEY idx_wholesale_requests_account (wholesale_account_id, status, created_at),
  CONSTRAINT fk_wholesale_requests_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_requests_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_requests_submitter FOREIGN KEY (submitted_by) REFERENCES users(id),
  CONSTRAINT fk_wholesale_requests_resolver FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_agent_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  wholesale_account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  message_role VARCHAR(20) NOT NULL,
  content TEXT NOT NULL,
  skill VARCHAR(80) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_wholesale_agent_history (wholesale_account_id, user_id, created_at),
  CONSTRAINT fk_wholesale_agent_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_wholesale_agent_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('wholesale_portal.view', 'Use wholesale customer portal', 'Open the private wholesale buyer dashboard for the linked customer account.', 'Wholesale Customer Portal'),
  ('wholesale_portal.agent', 'Use wholesale customer Agent', 'Chat with the customer-scoped wholesale Agent about the linked account, quotes, orders, and requests.', 'Wholesale Customer Portal'),
  ('wholesale_portal.profile_edit', 'Edit wholesale customer profile', 'Update the linked wholesale business profile, preferences, and fulfillment information.', 'Wholesale Customer Portal'),
  ('wholesale_portal.requests', 'Submit wholesale requests', 'Submit reorder, sample, product, delivery, and support requests for the linked wholesale account.', 'Wholesale Customer Portal'),
  ('wholesale_portal.quotes', 'View and accept wholesale quotes', 'View quotes for the linked account and accept active quotes.', 'Wholesale Customer Portal'),
  ('wholesale_portal.orders', 'View wholesale orders', 'View order history and current fulfillment status for the linked account.', 'Wholesale Customer Portal')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), category=VALUES(category);

INSERT INTO roles (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable)
SELECT id, 'Wholesale Customer', 'wholesale_customer', 'External wholesale buyer with access only to their own customer portal and scoped Agent.', 1, 0, 1
FROM organizations
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), is_system_role=1, is_owner_role=0, is_assignable=1;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.permission_key IN (
  'wholesale_portal.view','wholesale_portal.agent','wholesale_portal.profile_edit',
  'wholesale_portal.requests','wholesale_portal.quotes','wholesale_portal.orders'
)
WHERE r.slug='wholesale_customer';
