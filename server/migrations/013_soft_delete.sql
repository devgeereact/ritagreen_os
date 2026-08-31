-- 013 — soft delete where losing a row loses evidence.
--
-- 11 of the original 50 tables had deleted_at. The gaps showed up the first time
-- the deletion workflow tried to archive an applicant: it could not, because
-- there was nowhere to record that it had been archived, leaving only a hard
-- DELETE as the option. That is exactly backwards.
--
-- These five were chosen because a removed row destroys something that has to
-- survive: a recruitment decision, a financial record, a statutory report, pay
-- evidence, or a worked shift. Anything with a cascade below it matters twice
-- over -- deleting an invoice takes its credit notes, deleting an incident takes
-- its actions.

ALTER TABLE applicants ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE invoices   ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE incidents  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE timesheets ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE shifts     ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;

-- timesheets had neither deleted_at nor updated_at, so an approved timesheet
-- could be altered with no trace of when.
ALTER TABLE timesheets ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Every list query filters on deleted_at, and none of these were indexed for it.
ALTER TABLE applicants ADD KEY IF NOT EXISTS idx_applicants_deleted (deleted_at);
ALTER TABLE invoices   ADD KEY IF NOT EXISTS idx_invoices_deleted (deleted_at);
ALTER TABLE incidents  ADD KEY IF NOT EXISTS idx_incidents_deleted (deleted_at);
ALTER TABLE timesheets ADD KEY IF NOT EXISTS idx_timesheets_deleted (deleted_at);
ALTER TABLE shifts     ADD KEY IF NOT EXISTS idx_shifts_deleted (deleted_at);

-- shifts.client_id was joined in scheduling.php with neither a foreign key nor
-- an index, while shift_templates.client_id in the same build pass had both.
ALTER TABLE shifts ADD KEY IF NOT EXISTS idx_shifts_client (client_id);
