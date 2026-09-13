-- 029 — an unpaid break, and the shift the hours belong to.
--
-- Two gaps between the rota and the money.
--
-- 1. There was no break at all. Gross hours were paid door to door, so a
--    twelve-hour shift with an unpaid half-hour break was paid twelve hours. For
--    care and security, where unpaid breaks are normal, that is a standing
--    over-payment with nowhere to record the truth.
--
-- 2. A timesheet had no link to the shift it was worked on. Hours were recorded
--    independently of the rota and of the clock events in shift_events, so the
--    three could only be reconciled by eye and nothing could tell you whether a
--    scheduled shift had been claimed twice or not at all.
--
-- shift_id is nullable and stays that way: hours are still recorded for work
-- that never had a rota entry -- a call-out, a shift covered informally -- and
-- forcing a link would make those unrecordable.

ALTER TABLE timesheets
  ADD COLUMN IF NOT EXISTS break_minutes INT NOT NULL DEFAULT 0 AFTER minutes;

ALTER TABLE timesheets
  ADD COLUMN IF NOT EXISTS shift_id INT NULL AFTER worker_id;

ALTER TABLE timesheets ADD INDEX IF NOT EXISTS idx_ts_shift (shift_id);

-- SET NULL, not CASCADE: a timesheet is a pay record and outlives the rota entry
-- it came from. Losing the link is acceptable; losing the hours is not.
ALTER TABLE timesheets
  ADD CONSTRAINT fk_ts_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL;
