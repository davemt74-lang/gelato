-- Section 274: pairing confirmation and authenticated wearer confirmation.
-- Applied once by the existing upgrade runner (no unsupported IF NOT EXISTS).
ALTER TABLE glasses_pairing_grants
  ADD COLUMN paired_device_id BIGINT UNSIGNED NULL,
  ADD KEY idx_glasses_pairing_device (paired_device_id);
ALTER TABLE glasses_employee_runtimes
  ADD COLUMN profile_confirmed_at DATETIME(6) NULL;
