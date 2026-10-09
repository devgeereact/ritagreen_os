-- 038: the rota. Draft and published shifts, sites as records, the rules the
-- server checks when somebody is put on a shift, and timesheets that start from
-- the clock.
--
-- Re-runnable apart from the two foreign keys on existing tables, which MySQL
-- cannot add IF NOT EXISTS (the same as migrations 027 and 029). migrate.php
-- applies a file once.

-- ---- Draft and published -----------------------------------------------------
--
-- shifts.status says who is on the shift (open, scheduled) and whether it
-- happened (completed, cancelled). Whether workers can see it is a separate fact,
-- so it is a separate column rather than more values in the same enum:
--
--   is_draft = 1               planned by the office, invisible to workers,
--                              offers and the client portal
--   is_draft = 0, open         published, anyone eligible may pick it up
--   is_draft = 0, scheduled    published and assigned to a named worker
--   cancelled, completed       unchanged
--
-- DEFAULT 0 so that every shift that already exists counts as published: they
-- were all visible to workers before this column existed, and hiding them would
-- take shifts off people's phones. Every office path that creates a shift now
-- sets is_draft = 1 explicitly.
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER is_draft;
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS published_by INT NULL AFTER published_at;
ALTER TABLE shifts ADD INDEX IF NOT EXISTS idx_shifts_draft_time (is_draft, starts_at);

-- A cancellation needs a reason, and the reason is what the worker is told.
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL AFTER published_by;
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER cancel_reason;
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS cancelled_by INT NULL AFTER cancelled_at;

-- ---- Sites as records --------------------------------------------------------
--
-- sites held a name and an address and nothing wrote to it but the seed. A
-- worker arriving at a care home needs the postcode, how to get in and who to
-- ask for; the office needs to know which training the site insists on.
ALTER TABLE sites ADD COLUMN IF NOT EXISTS postcode VARCHAR(12) NULL AFTER address;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS access_notes VARCHAR(1000) NULL AFTER postcode;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS contact_name VARCHAR(160) NULL AFTER access_notes;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(40) NULL AFTER contact_name;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER contact_phone;

-- What a site requires on top of the service's own rules. Each row names an
-- existing compliance requirement, so a site's training list is assessed by the
-- same evaluator as everything else (rg_load_rules), as a mandatory rule, rather
-- than by a second opinion about who may work.
CREATE TABLE IF NOT EXISTS site_requirements (
  site_id INT NOT NULL,
  requirement_id INT NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (site_id, requirement_id),
  KEY idx_sr_requirement (requirement_id),
  CONSTRAINT fk_sr_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_requirement FOREIGN KEY (requirement_id) REFERENCES compliance_requirements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shifts point at the site. site_name stays: it is what older shifts carry, and
-- it is history -- renaming a site must not rewrite where somebody worked.
ALTER TABLE shifts ADD COLUMN IF NOT EXISTS site_id INT NULL AFTER client_id;
ALTER TABLE shifts ADD INDEX IF NOT EXISTS idx_shifts_site_time (site_id, starts_at);

-- Link what can be linked unambiguously: the same client and the same name.
UPDATE shifts s
  JOIN sites t ON t.client_id = s.client_id AND t.name = s.site_name AND t.deleted_at IS NULL
   SET s.site_id = t.id
 WHERE s.site_id IS NULL;

ALTER TABLE shifts
  ADD CONSTRAINT fk_shifts_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL;

-- ---- Unavailability a worker has marked ---------------------------------------
--
-- workers.avail_days / avail_nights / avail_weekends are a standing pattern set
-- by the office. This is the worker saying "not on these dates". It is a warning
-- the office must acknowledge, not a block: the worker may change their mind,
-- and the office may have agreed the shift with them by phone.
--
-- Keyed by person, not worker: somebody unavailable is unavailable for every
-- service they work in.
CREATE TABLE IF NOT EXISTS availability_blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_ab_person_time (person_id, starts_at),
  CONSTRAINT fk_ab_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  CONSTRAINT fk_ab_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Settings ----------------------------------------------------------------
--
-- A number the owner may change belongs in a table, not in code (the same rule
-- as retention periods and pay floors). One row per setting; the names and
-- their defaults are fixed in rg_setting_defaults() in pure.php, so a name the
-- code does not know is never read.
CREATE TABLE IF NOT EXISTS app_settings (
  name VARCHAR(60) NOT NULL,
  value VARCHAR(255) NOT NULL,
  updated_by INT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (name),
  CONSTRAINT fk_as_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eleven hours between shifts: the daily rest in the Working Time Regulations.
-- A shorter gap is a warning the office acknowledges, not a refusal.
INSERT IGNORE INTO app_settings (name, value) VALUES ('rest_gap_min_hours', '11');

-- ---- Timesheets from the clock -----------------------------------------------
--
-- 'draft'   made from a clock-in and clock-out; the worker has not submitted it
-- 'pending' submitted (or logged by the office) and waiting for a decision.
--           Unchanged meaning, so every existing row keeps its place
-- 'queried' the office has asked the worker about it; decision_note says what
-- 'approved', 'rejected' unchanged (migration 021)
--
-- Only 'approved' reaches pay-lib.php, so neither new state is paid.
ALTER TABLE timesheets
  MODIFY COLUMN status ENUM('draft','pending','approved','rejected','queried') NOT NULL DEFAULT 'pending';

-- Where the hours came from, and when the worker put their name to them.
ALTER TABLE timesheets
  ADD COLUMN IF NOT EXISTS source ENUM('office','clock') NOT NULL DEFAULT 'office' AFTER break_minutes;
ALTER TABLE timesheets ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL AFTER source;

-- ---- Requests have an owner --------------------------------------------------
--
-- Cover requests and website enquiries arrive in two lists with nobody's name on
-- either, so "who is dealing with this?" had no answer but asking round. The
-- Rota's Requests page shows both with an owner. No foreign key: users are
-- archived, never deleted, and an owner who has left is still who owned it.
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER shift_id;
ALTER TABLE service_requests ADD INDEX IF NOT EXISTS idx_sr_owner (owner_id);
ALTER TABLE contact_enquiries ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER status;
ALTER TABLE contact_enquiries ADD INDEX IF NOT EXISTS idx_ce_owner (owner_id);
