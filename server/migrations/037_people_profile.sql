-- 037: the person profile: a second emergency contact, a recorded suspension,
-- onboarding checklists per service, and training records that link rather than
-- copy.
--
-- Every statement here is re-runnable: ADD COLUMN IF NOT EXISTS, CREATE TABLE IF
-- NOT EXISTS, INSERT IGNORE against a unique key, and a MODIFY that leaves an
-- already-nullable column as it is.

-- ---- A second emergency contact ---------------------------------------------
--
-- Migration 001 gave people one emergency contact. The profile asks for a second,
-- because the first is often a partner who is at work at the same hours.

ALTER TABLE people ADD COLUMN IF NOT EXISTS emergency_contact2_name VARCHAR(160) NULL AFTER emergency_contact_relationship;
ALTER TABLE people ADD COLUMN IF NOT EXISTS emergency_contact2_phone VARCHAR(40) NULL AFTER emergency_contact2_name;
ALTER TABLE people ADD COLUMN IF NOT EXISTS emergency_contact2_relationship VARCHAR(80) NULL AFTER emergency_contact2_phone;

-- ---- Who suspended a worker, when and why ------------------------------------
--
-- Migration 035 let workers.status hold 'suspended', and nothing recorded why. A
-- suspension is a decision somebody is answerable for, in the same way an
-- approval is (migration 034), so it carries the same three facts.

ALTER TABLE workers ADD COLUMN IF NOT EXISTS suspended_at DATETIME NULL AFTER approval_note;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS suspended_by INT NULL AFTER suspended_at;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS suspension_reason VARCHAR(255) NULL AFTER suspended_by;

-- ---- Onboarding checklists per service ---------------------------------------
--
-- ats.php seeded the same five free-text tasks for every service, so a cleaner
-- was asked for a DBS check and a security officer was never asked for an SIA
-- licence. One list per service, edited by the office, seeds the checklist when
-- onboarding starts. Ad-hoc tasks can still be added to any one person.
--
-- One table keyed by service rather than a template table and an item table:
-- there is exactly one checklist per service, and a second table would only hold
-- the service name.

CREATE TABLE IF NOT EXISTS onboarding_template_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service ENUM('care','academy','cleaning','security') NOT NULL,
  -- Matches onboarding_tasks.label, which is what the item becomes.
  label VARCHAR(160) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  -- Retired rather than deleted, so a checklist seeded last year can still be
  -- traced to the list it came from.
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_template_item (service, label),
  KEY idx_template_service (service, active, sort_order),
  CONSTRAINT fk_template_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Defaults from the checks each service must hold (docs/Plans 2026/01, "The four
-- services"). Conditional checks -- an NMC PIN for nurses only, DBS for a cleaner
-- only where the site requires it, first aid only where required -- are left
-- out, because a task that does not apply to the person cannot be finished and
-- would hold up their approval. The office adds those to the one person.
INSERT IGNORE INTO onboarding_template_items (service, label, sort_order) VALUES
  ('care', 'Right to work checked', 10),
  ('care', 'Enhanced DBS check submitted', 20),
  ('care', 'Two references received', 30),
  ('care', 'Care Certificate or equivalent on file', 40),
  ('care', 'Mandatory training booked', 50),
  ('care', 'Employment contract signed', 60),

  ('cleaning', 'Right to work checked', 10),
  ('cleaning', 'Two references received', 20),
  ('cleaning', 'COSHH awareness completed', 30),
  ('cleaning', 'Employment contract signed', 40),

  ('security', 'Right to work checked', 10),
  ('security', 'SIA licence checked on the public register', 20),
  ('security', 'Two references received', 30),
  ('security', 'Employment contract signed', 40),

  ('academy', 'Right to work checked', 10),
  ('academy', 'Trainer qualifications on file', 20),
  ('academy', 'Enhanced DBS check submitted', 30),
  ('academy', 'Two references received', 40),
  ('academy', 'Employment contract signed', 50);

-- ---- Training records that link to the person ---------------------------------
--
-- enrolments.learner_name is NOT NULL because Academy enrolments were typed in
-- by name. A training record added from the person profile carries person_id
-- (migration 001) and reads the name from people; writing the name into this row
-- as well would be a second copy of the person. Nullable, so it need not be.

ALTER TABLE enrolments MODIFY COLUMN learner_name VARCHAR(160) NULL;
