-- 042: spreadsheet import (2026 plan, file 06, "Spreadsheet import", section S8).
--
-- Google Sheets responses, or a list of staff contact details, uploaded as CSV
-- in Recruitment and turned into applicants and contact updates through the
-- people spine. Three tables:
--
--   sheet_import_mappings  column heading -> field, saved per form or source and reused
--   sheet_imports          one upload: file name, who, when, counts
--   sheet_import_rows      one spreadsheet row: its cells while the import is open,
--                          then only what happened to it
--
-- The uploaded file itself is never stored. Its parsed cells are held in
-- sheet_import_rows.cells_json while the import is being mapped and previewed.
-- Applying clears the cells of every row except the failed ones (kept for the
-- failures download); closing the import clears those too. What stays is the
-- file name, the row number, the outcome, the reason and the records it made or
-- changed, which is what an audit question needs. See docs/RETENTION.md.
--
-- Every statement is re-runnable.

CREATE TABLE IF NOT EXISTS sheet_import_mappings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  -- A mapping can belong to a form in the forms directory (migration 039), so
  -- the next download of that form's Sheet maps with no questions asked.
  website_form_id INT NULL,
  purpose ENUM('applications','staff_updates') NOT NULL DEFAULT 'applications',
  default_service ENUM('care','academy','cleaning','security') NULL,
  -- {"normalised heading": "field"}. Fields are checked against
  -- RG_IMPORT_FIELDS in server/pure.php on save; anything else is dropped.
  columns_json TEXT NOT NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_sim_name (name),
  KEY idx_sim_form (website_form_id),
  CONSTRAINT fk_sim_form FOREIGN KEY (website_form_id) REFERENCES website_forms(id) ON DELETE SET NULL,
  CONSTRAINT fk_sim_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sim_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sheet_imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(190) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  mapping_id INT NULL,
  -- The mapping actually used, copied at the time, so a later edit to the saved
  -- mapping does not rewrite what this import did.
  columns_json TEXT NULL,
  headers_json TEXT NOT NULL,
  purpose ENUM('applications','staff_updates') NOT NULL DEFAULT 'applications',
  default_service ENUM('care','academy','cleaning','security') NULL,
  status ENUM('open','applied','closed') NOT NULL DEFAULT 'open',
  row_count INT NOT NULL DEFAULT 0,
  created_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  skipped_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL,
  applied_by INT NULL,
  closed_at DATETIME NULL,
  KEY idx_si_created (created_at),
  KEY idx_si_creator (created_by),
  CONSTRAINT fk_si_mapping FOREIGN KEY (mapping_id) REFERENCES sheet_import_mappings(id) ON DELETE SET NULL,
  CONSTRAINT fk_si_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_si_applied_by FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sheet_import_rows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  import_id INT NOT NULL,
  -- The row number a person sees in the spreadsheet; the header is row 1.
  row_no INT NOT NULL,
  -- Parsed cells, heading => value. Cleared on apply (except failed rows) and on close.
  cells_json MEDIUMTEXT NULL,
  -- rg_import_fingerprint(): the same response, whatever file it arrives in.
  fingerprint CHAR(64) NOT NULL,
  -- Set only once the row has created or updated something. UNIQUE, so the
  -- database itself refuses a second import of the same row, including from two
  -- simultaneous applies: a check and a write in two statements is not a check.
  applied_fingerprint CHAR(64) NULL,
  response_id VARCHAR(190) NULL,
  response_at DATETIME NULL,
  outcome ENUM('pending','created','updated','skipped','failed') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NULL,
  person_id INT NULL,
  applicant_id INT NULL,
  -- Field names changed by an update, never their values (the values are in people).
  changed_fields VARCHAR(500) NULL,
  applied_at DATETIME NULL,
  UNIQUE KEY uniq_sir_row (import_id, row_no),
  UNIQUE KEY uniq_sir_applied (applied_fingerprint),
  KEY idx_sir_response (response_id),
  KEY idx_sir_person (person_id),
  KEY idx_sir_applicant (applicant_id),
  CONSTRAINT fk_sir_import FOREIGN KEY (import_id) REFERENCES sheet_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_sir_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
  CONSTRAINT fk_sir_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A retention row with no period, like every other (docs/RETENTION.md). The
-- sweep reports it as configured but unmapped until somebody decides how long a
-- failed row's cells may wait for a person to fix them.
INSERT IGNORE INTO retention_policies (record_type, label, retain_months, basis, action_at_end) VALUES
  ('sheet_import_rows', 'Spreadsheet import rows (failed rows of open imports)', NULL,
   'OWNER: how long a failed row may wait to be fixed before its cells are cleared', 'review');
