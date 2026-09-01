-- 010 — the employment relationship as a record.
--
-- Three gaps this closes.
--
-- 1. There is no employment contract. The `contracts` table is a COMMERCIAL
--    contract with a client -- scope, rate, frequency, SLA. Nothing describes
--    the agreement with a worker, so nothing can be sent, signed or versioned.
--
-- 2. The particulars Acas expects are not queryable. Storing them inside a PDF
--    means the system cannot report on them, alert on them, or answer a question
--    about them without a human opening a document.
--
-- 3. There is nowhere to record holiday. Holiday taken, carried over, holiday
--    pay and payments in lieu need to be kept, and the system has no table for
--    any of it.
--
-- Retention periods are NOT hard-coded here. They are settings, owned by the
-- business against its own legal advice -- see docs/UK_GOVERNANCE.md.

-- ---------------------------------------------------------------- contracts
CREATE TABLE IF NOT EXISTS employment_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  worker_id INT NULL,
  reference VARCHAR(32) NOT NULL,
  -- Versioned rather than edited. A signed contract is evidence of what was
  -- agreed at a point in time; changing it in place destroys that.
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_id INT NULL,
  engagement_type ENUM('employee','worker','bank','agency','self_employed','apprentice')
      NOT NULL DEFAULT 'worker',
  status ENUM('draft','sent','viewed','accepted','signed','declined','expired','superseded')
      NOT NULL DEFAULT 'draft',

  -- Acas written-statement particulars, as FIELDS. A PDF is the artefact; these
  -- are what makes them answerable.
  job_title VARCHAR(120) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  place_of_work VARCHAR(190) NULL,
  pay_rate_pence INT NULL,
  pay_frequency ENUM('hourly','daily','weekly','fortnightly','monthly') NULL,
  pay_interval_note VARCHAR(190) NULL,
  hours_pattern VARCHAR(255) NULL,
  guaranteed_hours DECIMAL(5,2) NULL,
  holiday_entitlement_days DECIMAL(5,2) NULL,
  holiday_entitlement_note VARCHAR(255) NULL,
  sick_pay_terms VARCHAR(500) NULL,
  other_paid_leave VARCHAR(500) NULL,
  notice_period_worker VARCHAR(120) NULL,
  notice_period_employer VARCHAR(120) NULL,
  probation_terms VARCHAR(255) NULL,
  training_entitlement VARCHAR(500) NULL,
  pension_terms VARCHAR(255) NULL,
  collective_agreements VARCHAR(255) NULL,

  -- The artefact and the signature.
  document_file_id INT NULL,
  signed_file_id INT NULL,
  sent_at DATETIME NULL,
  viewed_at DATETIME NULL,
  accepted_at DATETIME NULL,
  signed_at DATETIME NULL,
  signed_name VARCHAR(160) NULL,
  -- How the worker opens their copy without needing a login.
  access_token CHAR(40) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_contract_ref (reference),
  UNIQUE KEY uniq_contract_token (access_token),
  KEY idx_ec_person (person_id, status),
  KEY idx_ec_worker (worker_id),
  CONSTRAINT fk_ec_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ec_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ec_doc FOREIGN KEY (document_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT fk_ec_signed FOREIGN KEY (signed_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT fk_ec_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------- leave & absence
CREATE TABLE IF NOT EXISTS leave_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  worker_id INT NULL,
  kind ENUM('annual','sickness','unpaid','parental','bereavement','compassionate','jury_service','training','other')
      NOT NULL DEFAULT 'annual',
  status ENUM('requested','approved','declined','cancelled','taken') NOT NULL DEFAULT 'requested',
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  -- Hours rather than days: shift work is not made of whole days, and a holiday
  -- balance counted in days is wrong for anyone on variable hours.
  hours DECIMAL(6,2) NOT NULL DEFAULT 0,
  -- Holiday pay and payments in lieu, both of which must be recorded.
  paid_pence INT NULL,
  in_lieu TINYINT(1) NOT NULL DEFAULT 0,
  -- Carry-over is its own question at year end.
  carried_over TINYINT(1) NOT NULL DEFAULT 0,
  leave_year VARCHAR(9) NULL,
  reason VARCHAR(500) NULL,
  -- Return-to-work discussions after sickness.
  return_to_work_on DATE NULL,
  return_to_work_note VARCHAR(500) NULL,
  evidence_file_id INT NULL,
  requested_by INT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_leave_person (person_id, starts_on),
  KEY idx_leave_status (status, starts_on),
  KEY idx_leave_year (leave_year, kind),
  CONSTRAINT fk_leave_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
  CONSTRAINT fk_leave_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL,
  CONSTRAINT fk_leave_file FOREIGN KEY (evidence_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT fk_leave_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leave_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- document meta
-- files held a category and nothing else. A document with no issue date, no
-- expiry and no verifier cannot be evidence of anything.
ALTER TABLE files ADD COLUMN IF NOT EXISTS issued_on DATE NULL AFTER category;
ALTER TABLE files ADD COLUMN IF NOT EXISTS expires_on DATE NULL AFTER issued_on;
ALTER TABLE files ADD COLUMN IF NOT EXISTS verification_status
    ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified' AFTER expires_on;
ALTER TABLE files ADD COLUMN IF NOT EXISTS verified_by INT NULL AFTER verification_status;
ALTER TABLE files ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER verified_by;
ALTER TABLE files ADD COLUMN IF NOT EXISTS person_id INT NULL AFTER worker_id;
ALTER TABLE files ADD KEY IF NOT EXISTS idx_files_expiry (expires_on);
ALTER TABLE files ADD KEY IF NOT EXISTS idx_files_person (person_id);

-- Retention, as configuration. No period is set here: the values belong to the
-- business and its legal advice, not to this schema.
CREATE TABLE IF NOT EXISTS retention_policies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  record_type VARCHAR(64) NOT NULL,
  label VARCHAR(160) NOT NULL,
  -- NULL means "no period set yet", which is deliberately different from zero.
  -- A dashboard must be able to say "this has no policy", not silently imply
  -- that it should be deleted immediately.
  retain_months SMALLINT UNSIGNED NULL,
  basis VARCHAR(500) NULL,
  action_at_end ENUM('review','archive','delete') NOT NULL DEFAULT 'review',
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_retention_type (record_type),
  CONSTRAINT fk_ret_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO retention_policies (record_type, label, retain_months, basis, action_at_end) VALUES
  ('candidate_unsuccessful', 'Unsuccessful applications', NULL, 'Set against current ICO recruitment guidance.', 'review'),
  ('worker_employment', 'Employment records after leaving', NULL, 'Set against current guidance and any contractual requirement.', 'review'),
  ('right_to_work_check', 'Right-to-work checks after employment ends', NULL, 'Set against Home Office guidance.', 'review'),
  ('leave_records', 'Holiday and absence records', NULL, 'Set against current Acas guidance on keeping records.', 'review'),
  ('timesheets', 'Timesheets and pay records', NULL, 'Set against payroll record-keeping requirements.', 'review'),
  ('incidents', 'Incidents, including RIDDOR-reportable', NULL, 'Set against health and safety requirements.', 'review'),
  ('training_records', 'Training records and certificates', NULL, 'Set against sector requirements.', 'review'),
  ('audit_log', 'Audit log', NULL, 'Set against accountability requirements.', 'review');
