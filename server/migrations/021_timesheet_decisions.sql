-- 021 — a timesheet can be rejected, and a decision leaves a trace.
--
-- timesheets.status was enum('pending','approved'). A manager reviewing hours
-- could therefore only ever agree with them. An entry logged against the wrong
-- worker, or for a shift that did not happen, had no state to move to: the only
-- ways out were to approve it and pay it, or to leave it pending forever, where
-- it sits in the "awaiting approval" total and looks like work still to do.
--
-- Approval also recorded who, in approved_by, but never when, and never why.
-- For a pay-bearing record that is half an audit trail.
--
-- 'rejected' entries are excluded from pay by the existing
-- `status = "approved"` filter in pay-lib.php, so no pay logic changes here.

ALTER TABLE timesheets
  MODIFY COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending';

-- When the decision was taken. Distinct from updated_at, which moves for any
-- edit, and from created_at, which is when the hours were logged.
ALTER TABLE timesheets
  ADD COLUMN IF NOT EXISTS decided_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by;

-- Why. Required by the endpoint on a rejection: a rejection a worker cannot
-- understand is a query to the office, not a decision.
ALTER TABLE timesheets
  ADD COLUMN IF NOT EXISTS decision_note VARCHAR(255) NULL DEFAULT NULL AFTER decided_at;

-- Approving and rejecting both filter by status over a date range; the existing
-- index leads on worker_id, which does not serve that.
ALTER TABLE timesheets
  ADD INDEX IF NOT EXISTS idx_ts_status_date (status, work_date);
