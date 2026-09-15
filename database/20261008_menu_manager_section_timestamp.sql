-- Editable menu category timestamp required by Menu Manager
ALTER TABLE menu_sections
  ADD COLUMN IF NOT EXISTS updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER status;
