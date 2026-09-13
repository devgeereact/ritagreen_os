-- 028 — attendance and location data gets a retention policy and a way to archive.
--
-- docs/PRIVACY.md records shift_events as personal data about the worker:
-- clock-in and clock-out times, LOCATION, notes and a signature name. It is one
-- of the more intrusive things the system holds, and it had no retention policy
-- at all and no deleted_at, so the only disposal available was a hard DELETE --
-- which is not a disposal a retention policy should have to use. Exactly the gap
-- migration 020 closed for the public intake tables.
--
-- The period is NULL, like every other one. retention-sweep.php reports and does
-- nothing where a period is unset, and a period in code is not a decision
-- anybody made. See docs/RETENTION.md.

ALTER TABLE shift_events ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE shift_events ADD INDEX IF NOT EXISTS idx_se_deleted (deleted_at);

INSERT IGNORE INTO retention_policies (record_type, label, retain_months, basis, action_at_end, active)
VALUES (
  'attendance_events',
  'Attendance, check calls and location',
  NULL,
  'Clock-in and clock-out times, location and check calls. Location is the most intrusive thing recorded about a worker day to day, so the period should be the shortest that still answers a pay query or an incident.',
  'review',
  1
);
