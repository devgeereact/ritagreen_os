-- 030 — a work order can name who is doing it.
--
-- jobs.status has had 'assigned' in its enum since the table was created and
-- there was no assignee column anywhere, so the status was decorative: a job
-- could be marked assigned and nothing recorded who to. docs/SCHEMA.md has
-- flagged it, unresolved.
--
-- SET NULL rather than CASCADE: losing the assignee when a worker record goes is
-- acceptable, losing the work order is not.

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS assigned_to INT NULL AFTER client_id;
ALTER TABLE jobs ADD INDEX IF NOT EXISTS idx_jobs_assignee (assigned_to, status);
ALTER TABLE jobs
  ADD CONSTRAINT fk_jobs_worker FOREIGN KEY (assigned_to) REFERENCES workers(id) ON DELETE SET NULL;
