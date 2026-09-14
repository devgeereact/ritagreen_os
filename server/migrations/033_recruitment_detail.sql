-- 033 — the records a recruiter actually keeps.
--
-- The pipeline had a stage and a list of label/done tasks, and nothing else. No
-- named recruiter, so "who is dealing with this candidate" was answered in
-- somebody's head. No interview record, so the date, the interviewer and what was
-- decided lived in a calendar and an inbox. No owner or due date on a task, so a
-- checklist could not be chased. No history, so a stage that moved backwards left
-- no trace of having moved forwards. No reason on a rejection, which is the entry
-- somebody asks about six months later.
--
-- Four small additions, not a workflow engine. The stage model stays a fixed list.

-- ---- Who owns the candidate, and why they left the pipeline ----------------

ALTER TABLE applicants ADD COLUMN IF NOT EXISTS recruiter_id INT NULL AFTER source;
ALTER TABLE applicants ADD COLUMN IF NOT EXISTS outcome_reason VARCHAR(500) NULL AFTER notes;
ALTER TABLE applicants ADD INDEX IF NOT EXISTS idx_applicants_recruiter (recruiter_id, stage);

-- ON DELETE SET NULL, not CASCADE. A recruiter leaving the company must not take
-- the candidates they were handling with them.
ALTER TABLE applicants
  ADD CONSTRAINT fk_applicants_recruiter FOREIGN KEY (recruiter_id) REFERENCES users(id) ON DELETE SET NULL;

-- 'withdrawn' is the candidate's own exit, and it is not a rejection. Recording
-- both as 'rejected' loses the difference between somebody the company turned
-- down and somebody who took another job -- which is the difference between a
-- pipeline problem and a market one.
ALTER TABLE applicants
  MODIFY COLUMN stage ENUM('applied','screened','interview','vetting','offer','onboarding','onboarded','rejected','withdrawn')
    NOT NULL DEFAULT 'applied';

-- ---- Stage history ---------------------------------------------------------
--
-- audit_log already records the change, and it is the wrong place to read this
-- from: it is a security record covering every table, and a recruiter looking at
-- one candidate should not be given a tool that pages through everybody's.

CREATE TABLE IF NOT EXISTS applicant_stage_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT NOT NULL,
  from_stage VARCHAR(20) NULL,          -- NULL for the row that records the application arriving
  to_stage VARCHAR(20) NOT NULL,
  reason VARCHAR(500) NULL,
  changed_by INT NULL,                  -- NULL when the public form or an integration did it
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ash_applicant (applicant_id, changed_at),
  CONSTRAINT fk_ash_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ash_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every application that already exists gets its current stage as its first
-- history row, so the screen is not empty for the people already in the pipeline.
-- changed_by is NULL because nobody can now say who moved them, and inventing an
-- attribution is worse than admitting there is none.
INSERT INTO applicant_stage_history (applicant_id, from_stage, to_stage, reason, changed_by, changed_at)
SELECT a.id, NULL, a.stage, 'Recorded when stage history was added', NULL, a.created_at
  FROM applicants a
 WHERE NOT EXISTS (SELECT 1 FROM applicant_stage_history h WHERE h.applicant_id = a.id);

-- ---- Interviews ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS applicant_interviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT NOT NULL,
  -- Wall-clock, like shifts.starts_at. An interview is at 10:00 in the office's
  -- day; it is not an instant to be converted.
  scheduled_at DATETIME NOT NULL,
  -- A named person, not free text. "Interviewed by Mara" has to survive Mara
  -- changing her surname.
  interviewer_id INT NULL,
  -- Where or how. Free text on purpose: "Zoom", "Head office", "by phone".
  location VARCHAR(160) NULL,
  outcome ENUM('scheduled','passed','failed','no_show','cancelled') NOT NULL DEFAULT 'scheduled',
  notes TEXT NULL,
  decided_at DATETIME NULL,
  decided_by INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Soft delete, like every other record about a person. A cancelled interview
  -- is recorded as cancelled; it is not erased.
  deleted_at TIMESTAMP NULL,
  KEY idx_ai_applicant (applicant_id, scheduled_at),
  KEY idx_ai_interviewer (interviewer_id, scheduled_at),
  KEY idx_ai_upcoming (outcome, scheduled_at),
  CONSTRAINT fk_ai_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_interviewer FOREIGN KEY (interviewer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Tasks that can be chased ----------------------------------------------

ALTER TABLE onboarding_tasks ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER label;
ALTER TABLE onboarding_tasks ADD COLUMN IF NOT EXISTS due_on DATE NULL AFTER owner_id;
ALTER TABLE onboarding_tasks ADD COLUMN IF NOT EXISTS done_at DATETIME NULL AFTER done;
ALTER TABLE onboarding_tasks ADD COLUMN IF NOT EXISTS done_by INT NULL AFTER done_at;
ALTER TABLE onboarding_tasks ADD INDEX IF NOT EXISTS idx_ot_owner (owner_id, done, due_on);

ALTER TABLE onboarding_tasks
  ADD CONSTRAINT fk_ot_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE onboarding_tasks
  ADD CONSTRAINT fk_ot_done_by FOREIGN KEY (done_by) REFERENCES users(id) ON DELETE SET NULL;

-- Tasks already marked done have no completion time, and NOW() would be a lie
-- about when the work happened. They stay NULL, and the screen says "not
-- recorded" rather than showing today's date for something done in March.
