-- 001 — the person spine.
--
-- A person exists once. Candidate, worker, learner and login become roles that
-- person holds, rather than separate records that happen to share a name.
--
-- Before this, ats.php COPIED name, email and phone from applicants into a new
-- workers row on onboarding, and again from a learner into an applicant, so one
-- human could exist three times and the copies drifted apart independently.
--
-- This migration only ADDS. It does not drop or rename a single existing column,
-- so the application keeps working unchanged while the backfill runs and the
-- endpoints are moved over one at a time. Removing the duplicated columns is a
-- later migration, once nothing reads them.

-- The human. One row, forever.
CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(160) NOT NULL,
  preferred_name VARCHAR(80) NULL,
  date_of_birth DATE NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  address_line1 VARCHAR(190) NULL,
  address_line2 VARCHAR(190) NULL,
  city VARCHAR(80) NULL,
  postcode VARCHAR(16) NULL,
  emergency_contact_name VARCHAR(160) NULL,
  emergency_contact_phone VARCHAR(40) NULL,
  emergency_contact_relationship VARCHAR(80) NULL,
  -- The identity used to decide two records are the same human: 'e:<email>' or
  -- 'p:<digits>', from rg_person_match_key() in pure.php. UNIQUE so the database
  -- itself refuses a second person with the same key -- the guarantee the old
  -- copy-on-onboard behaviour had no way to make. NULL where a person has
  -- neither an email nor a usable phone; MySQL permits many NULLs in a UNIQUE
  -- index, so those rows coexist and are reconciled by hand.
  match_key VARCHAR(200) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_people_match (match_key),
  KEY idx_people_email (email),
  KEY idx_people_phone (phone),
  KEY idx_people_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which services a person may work in, and whether they currently may.
--
-- This is what workers.division could never express: one person eligible for
-- Security and Cleaning at once, with a different answer for each. Every column
-- below is CALCULATED by the compliance engine and must never be set by hand.
CREATE TABLE IF NOT EXISTS person_services (
  person_id INT NOT NULL,
  service ENUM('care','academy','cleaning','security') NOT NULL,
  -- Does the business intend this person to work in this service at all?
  -- Separate from whether they currently may: someone can be intended for
  -- Security and simultaneously not eligible for it.
  active TINYINT(1) NOT NULL DEFAULT 1,
  eligibility_status ENUM('eligible','eligible_with_warnings','not_eligible','unassessed')
      NOT NULL DEFAULT 'unassessed',
  eligibility_reason VARCHAR(500) NULL,
  eligibility_score TINYINT UNSIGNED NULL,
  assessed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, service),
  KEY idx_ps_status (service, eligibility_status),
  CONSTRAINT fk_ps_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link the existing records to the person they describe.
--
-- Nullable, because the backfill has not run yet and because a row whose person
-- could not be matched automatically must remain visible for review rather than
-- being dropped. ON DELETE RESTRICT: a person with employment history attached
-- cannot be deleted out from under it.
ALTER TABLE workers    ADD COLUMN IF NOT EXISTS person_id INT NULL AFTER id;
ALTER TABLE applicants ADD COLUMN IF NOT EXISTS person_id INT NULL AFTER id;
ALTER TABLE users      ADD COLUMN IF NOT EXISTS person_id INT NULL AFTER id;
ALTER TABLE enrolments ADD COLUMN IF NOT EXISTS person_id INT NULL AFTER id;

ALTER TABLE workers    ADD KEY IF NOT EXISTS idx_workers_person (person_id);
ALTER TABLE applicants ADD KEY IF NOT EXISTS idx_applicants_person (person_id);
ALTER TABLE users      ADD KEY IF NOT EXISTS idx_users_person (person_id);
ALTER TABLE enrolments ADD KEY IF NOT EXISTS idx_enrolments_person (person_id);

-- The payroll number: unique, permanent, never reused, assigned on activation.
-- It belongs to the employment record rather than the person, because a person
-- may hold other roles without ever being employed.
ALTER TABLE workers ADD COLUMN IF NOT EXISTS payroll_no VARCHAR(20) NULL AFTER person_id;
ALTER TABLE workers ADD UNIQUE KEY IF NOT EXISTS uniq_workers_payroll (payroll_no);
