-- Gelato Restaurant AI: link time clock/attendance records to staff scheduling after all 20260913 migrations.
SET NAMES utf8mb4;

ALTER TABLE time_clock_entries
  ADD CONSTRAINT fk_time_clock_shift FOREIGN KEY (schedule_shift_id) REFERENCES schedule_shifts(id);

ALTER TABLE attendance_events
  ADD CONSTRAINT fk_attendance_shift FOREIGN KEY (schedule_shift_id) REFERENCES schedule_shifts(id);