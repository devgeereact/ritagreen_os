-- 016 — derived worker status, and training from anywhere.
--
-- workers.status is active/onboarding/inactive, set by hand. A status somebody
-- has to remember to change is a status that is wrong, and this one decides
-- whether a person appears in the workforce the office schedules from.
--
-- It becomes derived. The manual column stays as the INTENT -- what the business
-- means to do with this person -- and the derived column is what is actually
-- true given the evidence. Keeping them apart matters: "we intend to employ them
-- and they are not yet cleared" is a real and common state, and collapsing it
-- into one field loses exactly the information the office needs.

ALTER TABLE workers ADD COLUMN IF NOT EXISTS derived_status
    ENUM('applicant','pre_employment','onboarding','active','on_leave','suspended','inactive','left')
    NULL AFTER status;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS derived_status_reason VARCHAR(255) NULL AFTER derived_status;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS derived_at DATETIME NULL AFTER derived_status_reason;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS left_on DATE NULL AFTER derived_at;
ALTER TABLE workers ADD KEY IF NOT EXISTS idx_workers_derived (derived_status);

-- ---------------------------------------------------------- training providers
-- programmes were implicitly RitaGreen Academy's. A worker who did their first
-- aid with St John Ambulance has training the system cannot record, so it either
-- goes in as a certification with no provenance or does not go in at all.
CREATE TABLE IF NOT EXISTS training_providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  kind ENUM('internal','external') NOT NULL DEFAULT 'external',
  accreditation VARCHAR(190) NULL,
  contact_name VARCHAR(160) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  website VARCHAR(255) NULL,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE programmes  ADD COLUMN IF NOT EXISTS provider_id INT NULL AFTER division;
ALTER TABLE enrolments  ADD COLUMN IF NOT EXISTS provider_id INT NULL AFTER programme_id;
-- Training done elsewhere has no programme of ours, so an enrolment must be able
-- to name the course itself.
ALTER TABLE enrolments  ADD COLUMN IF NOT EXISTS external_course VARCHAR(190) NULL AFTER provider_id;
ALTER TABLE enrolments  ADD COLUMN IF NOT EXISTS completed_on DATE NULL;
ALTER TABLE enrolments  ADD COLUMN IF NOT EXISTS expires_on DATE NULL;
ALTER TABLE enrolments  ADD COLUMN IF NOT EXISTS evidence_file_id INT NULL;
ALTER TABLE enrolments  ADD KEY IF NOT EXISTS idx_enrolments_provider (provider_id);
ALTER TABLE enrolments  ADD KEY IF NOT EXISTS idx_enrolments_expiry (expires_on);

-- programme_id must become optional for externally-delivered training.
ALTER TABLE enrolments MODIFY COLUMN programme_id INT NULL;

INSERT IGNORE INTO training_providers (name, kind, accreditation) VALUES
  ('RitaGreen Academy', 'internal', 'Care Certificate, mandatory training'),
  ('St John Ambulance', 'external', 'First aid'),
  ('Highfield', 'external', 'Awarding organisation'),
  ('Other external provider', 'external', NULL);

-- Everything already recorded was ours.
UPDATE programmes SET provider_id = (SELECT id FROM training_providers WHERE name = 'RitaGreen Academy')
 WHERE provider_id IS NULL;
UPDATE enrolments SET provider_id = (SELECT id FROM training_providers WHERE name = 'RitaGreen Academy')
 WHERE provider_id IS NULL AND programme_id IS NOT NULL;
