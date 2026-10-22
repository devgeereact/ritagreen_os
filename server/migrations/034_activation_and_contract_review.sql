-- 034 — activation is somebody's decision, and a contract is reviewed by somebody.
--
-- rg_derive_worker_status() already weighs outstanding onboarding tasks, a signed
-- contract and eligibility. What it could not weigh is whether anybody actually
-- said yes, because nothing recorded that. "Every box is ticked" and "a person
-- with the authority to do so approved this" are different facts, and only the
-- second is an approval.
--
-- The same is true one level down: employment_contracts records that a contract
-- was signed, and nothing recorded that anybody read the signed copy.

-- ---- Who approved this worker for work --------------------------------------

ALTER TABLE workers ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER derived_at;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER approved_at;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS approval_note VARCHAR(255) NULL AFTER approved_by;
ALTER TABLE workers ADD INDEX IF NOT EXISTS idx_workers_approved (approved_at);

ALTER TABLE workers
  ADD CONSTRAINT fk_workers_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Every worker already marked active is treated as approved, dated from when
-- their record was created, with NO approver named.
--
-- This is the whole reason this is a backfill rather than a fresh start.
-- Enforcing a new approval requirement against an existing workforce would mark
-- every current worker unapproved the moment it shipped, and an unapproved worker
-- cannot be assigned -- so a policy change would have stood the company down
-- overnight. See docs/LEAN-CMS-LAUNCH-PLAN.md, Phase 3: report the affected
-- records, do not silently tighten.
--
-- approved_by is NULL because nobody can now say who approved them. The note
-- says so, rather than naming somebody who did not.
UPDATE workers
   SET approved_at = COALESCE(approved_at, created_at),
       approval_note = 'Active before approval was recorded — approver unknown (migration 034)'
 WHERE approved_at IS NULL
   AND status = 'active';

-- ---- Who read the signed contract -------------------------------------------

ALTER TABLE employment_contracts ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER signed_name;
ALTER TABLE employment_contracts ADD COLUMN IF NOT EXISTS reviewed_by INT NULL AFTER reviewed_at;
ALTER TABLE employment_contracts ADD COLUMN IF NOT EXISTS review_note VARCHAR(500) NULL AFTER reviewed_by;
ALTER TABLE employment_contracts ADD INDEX IF NOT EXISTS idx_ec_review (status, reviewed_at);

ALTER TABLE employment_contracts
  ADD CONSTRAINT fk_ec_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

-- No backfill here. A contract that has not been reviewed has not been reviewed,
-- and there are no rows in this table on any database yet -- the feature it
-- belongs to was never built. Inventing a review date would be inventing the
-- review.
