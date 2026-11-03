-- Bind staff invitations to a durable identity. Legacy unbound links remain unusable.
ALTER TABLE account_invitations
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER organization_id,
  ADD COLUMN first_name VARCHAR(100) NULL AFTER user_id,
  ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name,
  ADD COLUMN display_name VARCHAR(160) NULL AFTER last_name,
  ADD COLUMN phone VARCHAR(40) NULL AFTER display_name,
  ADD COLUMN job_title VARCHAR(160) NULL AFTER phone,
  ADD COLUMN delivery_status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER revoked_at,
  ADD COLUMN delivery_attempted_at DATETIME(6) NULL AFTER delivery_status,
  ADD KEY idx_account_invitation_user (organization_id,user_id,accepted_at,revoked_at),
  ADD CONSTRAINT fk_account_invitation_user FOREIGN KEY (user_id) REFERENCES users(id);
