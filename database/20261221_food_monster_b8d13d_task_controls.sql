-- FM-B8D13D: cooperative cancellation intent for owner-of-turn Agent work.
-- A cancellation request never claims an in-flight routine write was undone.
ALTER TABLE food_monster_agent_task_runs
  ADD COLUMN cancel_requested_at DATETIME(6) NULL AFTER lease_expires_at;
