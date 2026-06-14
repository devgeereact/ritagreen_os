-- RitaGreenOS — database schema (build pass 1)
-- Import in cPanel -> phpMyAdmin against the project database.
-- Money is stored in pence (integers). Soft-delete via deleted_at on records
-- that must not be hard-deleted (users, clients, workers, certificates).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---- Smoke test ----
CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Reference: roles ----
CREATE TABLE IF NOT EXISTS roles (
  role_key VARCHAR(32) PRIMARY KEY,
  label VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles (role_key, label) VALUES
  ('super_admin', 'Super admin'),
  ('admin', 'Admin'),
  ('manager', 'Manager'),
  ('trainer', 'Trainer'),
  ('editor', 'Editor'),
  ('employee', 'Employee'),
  ('client', 'Client');

-- ---- Users ----
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin','manager','trainer','editor','employee','client') NOT NULL DEFAULT 'employee',
  division ENUM('care','academy','cleaning','security') NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed super-admin: dev@ritagreenservices.com / Testing123!
-- (bcrypt hash of Testing123!; change the password after first login.)
INSERT IGNORE INTO users (name, email, password_hash, role) VALUES
  ('RitaGreen Dev', 'dev@ritagreenservices.com',
   '$2y$12$Edlcu2ZtmatAFKEbK6OeJeZbgjMZEMx1xKQiyqF2Ee15QdycnAEN6', 'super_admin');

-- ---- Auth tokens ----
CREATE TABLE IF NOT EXISTS tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token_hash),
  KEY idx_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Audit log ----
CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Named counters (atomic document-number sequences) ----
-- next_seq() in lib.php increments val in one statement, so concurrent invoice/
-- quote/certificate creates never collide. Key format e.g. 'invoice-2026'.
CREATE TABLE IF NOT EXISTS counters (
  name VARCHAR(64) PRIMARY KEY,
  val INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Rate limiting (brute-force / spam throttle for public endpoints) ----
-- rate_limit() in lib.php writes one row per hit and counts within a window.
CREATE TABLE IF NOT EXISTS rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bucket_ip_time (bucket, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Clients ----
CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  type ENUM('care_home','nhs','landlord','event','private') NOT NULL DEFAULT 'private',
  contact_name VARCHAR(160) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  divisions VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('active','prospect','paused') NOT NULL DEFAULT 'prospect',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Sites (a client can have many) ----
CREATE TABLE IF NOT EXISTS sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  address VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_client (client_id),
  CONSTRAINT fk_sites_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Workers ----
CREATE TABLE IF NOT EXISTS workers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  role ENUM('carer','nurse','cleaner','guard','trainer') NOT NULL DEFAULT 'carer',
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  status ENUM('active','onboarding','inactive') NOT NULL DEFAULT 'onboarding',
  right_to_work TINYINT(1) NOT NULL DEFAULT 0,
  dbs_status ENUM('valid','pending','expired') NULL,
  dbs_expiry DATE NULL,
  sia_number VARCHAR(40) NULL,
  sia_expiry DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Worker certifications (renewals tracked) ----
CREATE TABLE IF NOT EXISTS certifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  type VARCHAR(80) NOT NULL,
  number VARCHAR(80) NULL,
  expiry DATE NULL,
  status ENUM('valid','pending','expired') NOT NULL DEFAULT 'valid',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_worker (worker_id),
  CONSTRAINT fk_cert_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Contact enquiries (public form) ----
CREATE TABLE IF NOT EXISTS contact_enquiries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  division ENUM('care','academy','cleaning','security','other') NOT NULL DEFAULT 'other',
  message TEXT NOT NULL,
  status ENUM('new','handled') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Academy: programmes / enrolments / sessions ----
CREATE TABLE IF NOT EXISTS programmes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'academy',
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrolments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  programme_id INT NOT NULL,
  learner_name VARCHAR(160) NOT NULL,
  status ENUM('enrolled','in_progress','completed','withdrawn') NOT NULL DEFAULT 'enrolled',
  certificate_public_id VARCHAR(40) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_programme (programme_id),
  CONSTRAINT fk_enrol_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  programme_id INT NULL,
  worker_id INT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_starts (starts_at),
  KEY idx_sess_programme (programme_id),
  KEY idx_sess_worker (worker_id),
  CONSTRAINT fk_sess_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
  CONSTRAINT fk_sess_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Invoices (money in pence) ----
CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(32) NOT NULL,
  client_id INT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  net_pence INT NOT NULL DEFAULT 0,
  vat_pence INT NOT NULL DEFAULT 0,
  total_pence INT NOT NULL DEFAULT 0,
  status ENUM('draft','sent','paid','overdue','void') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_number (number),
  KEY idx_status (status),
  KEY idx_inv_client (client_id),
  CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Timesheets (time tracking) ----
CREATE TABLE IF NOT EXISTS timesheets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  work_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  minutes INT NOT NULL DEFAULT 0,
  status ENUM('pending','approved') NOT NULL DEFAULT 'pending',
  approved_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_worker_date (worker_id, work_date),
  KEY idx_ts_approver (approved_by),
  CONSTRAINT fk_ts_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Announcements (communication hub) ----
CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  author_id INT NULL,
  audience ENUM('all','care','academy','cleaning','security') NOT NULL DEFAULT 'all',
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audience (audience),
  KEY idx_ann_author (author_id),
  CONSTRAINT fk_ann_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Contracts / recurring services ----
CREATE TABLE IF NOT EXISTS contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  title VARCHAR(190) NOT NULL,
  scope TEXT NULL,
  rate_pence INT NOT NULL DEFAULT 0,
  frequency ENUM('weekly','fortnightly','monthly','quarterly') NOT NULL DEFAULT 'monthly',
  sla_hours INT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_status (status),
  KEY idx_end (end_date),
  KEY idx_contract_client (client_id),
  CONSTRAINT fk_contract_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Quotes / estimates ----
CREATE TABLE IF NOT EXISTS quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(32) NOT NULL,
  client_id INT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  title VARCHAR(190) NOT NULL,
  net_pence INT NOT NULL DEFAULT 0,
  vat_pence INT NOT NULL DEFAULT 0,
  total_pence INT NOT NULL DEFAULT 0,
  status ENUM('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  invoice_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_quote_number (number),
  KEY idx_status (status),
  KEY idx_quote_client (client_id),
  KEY idx_quote_invoice (invoice_id),
  CONSTRAINT fk_quote_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_quote_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Jobs / work orders ----
CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  type ENUM('care_visit','clean','guard_shift','course','other') NOT NULL DEFAULT 'other',
  title VARCHAR(190) NOT NULL,
  priority ENUM('emergency','high','standard') NOT NULL DEFAULT 'standard',
  status ENUM('booked','assigned','in_progress','done','cancelled') NOT NULL DEFAULT 'booked',
  scheduled_for DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_status (status),
  KEY idx_client (client_id),
  CONSTRAINT fk_jobs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Certificates (public verification) ----
CREATE TABLE IF NOT EXISTS certificates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL,
  learner_name VARCHAR(160) NOT NULL,
  course VARCHAR(190) NOT NULL,
  issued_on DATE NOT NULL,
  status ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_public (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demo certificate so the public /verify page can be tested.
INSERT IGNORE INTO certificates (public_id, learner_name, course, issued_on, status) VALUES
  ('RG-2026-00123', 'Jane Doe', 'Health & Social Care (Level 3)', '2026-03-15', 'issued');

-- ---- Files / document centre ----
CREATE TABLE IF NOT EXISTS files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stored_name VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  category ENUM('contract','certificate','policy','rams','id','other') NOT NULL DEFAULT 'other',
  client_id INT NULL,
  worker_id INT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_category (category),
  KEY idx_files_client (client_id),
  KEY idx_files_worker (worker_id),
  KEY idx_files_uploader (uploaded_by),
  CONSTRAINT fk_files_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_files_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL,
  CONSTRAINT fk_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Build pass 2 — Sprint A §4 Compliance hardening
-- Renewal reminder dedup log, company insurance register, reference checks,
-- plus DBS update-service + re-check scheduling fields on workers.
-- Re-importing this file is safe: tables use IF NOT EXISTS and the worker
-- columns use ADD COLUMN IF NOT EXISTS (MariaDB on the shared host supports it).
-- =====================================================================

-- ---- Renewal reminder log (dedup for the 90/60/30/expired digest) ----
-- expiry-alerts.php writes one row per (credential, tier) it has emailed about,
-- so a 60-day nudge never fires twice. credential_key is e.g. 'dbs:12',
-- 'sia:12', 'cert:88', 'ins:3'. tier is 'd90' | 'd60' | 'd30' | 'expired'.
CREATE TABLE IF NOT EXISTS compliance_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  credential_key VARCHAR(64) NOT NULL,
  tier ENUM('d90','d60','d30','expired') NOT NULL,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cred_tier (credential_key, tier),
  KEY idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Company insurance & certificate register (company level) ----
-- EL must be at least £5m to win contracts; cover_pence stored in pence.
CREATE TABLE IF NOT EXISTS insurance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('employers_liability','public_liability','professional_indemnity','other') NOT NULL DEFAULT 'public_liability',
  insurer VARCHAR(160) NOT NULL,
  policy_no VARCHAR(80) NULL,
  cover_pence BIGINT NOT NULL DEFAULT 0,
  start_date DATE NULL,
  expiry DATE NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_ins_expiry (expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Reference checks (2+ per worker, gaps explained) ----
CREATE TABLE IF NOT EXISTS worker_references (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  referee_name VARCHAR(160) NOT NULL,
  referee_org VARCHAR(160) NULL,
  relationship VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  status ENUM('requested','received','verified','gap_explained') NOT NULL DEFAULT 'requested',
  gap_note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ref_worker (worker_id),
  CONSTRAINT fk_ref_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- DBS update-service + re-check scheduling on workers ----
ALTER TABLE workers
  ADD COLUMN IF NOT EXISTS dbs_update_service TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS recheck_due DATE NULL;

-- =====================================================================
-- Build pass 2 — Sprint A §11 Timesheets → Payroll
-- Pay rate + type on the worker, configurable pay-rule multipliers, a UK
-- bank-holiday calendar, and holiday accrual. payroll.php turns approved
-- timesheets into a pay run + payslips from these.
-- =====================================================================

-- Pay rate + type + accrued holiday on the worker.
ALTER TABLE workers
  ADD COLUMN IF NOT EXISTS pay_rate_pence INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS pay_type ENUM('paye','umbrella') NOT NULL DEFAULT 'paye',
  ADD COLUMN IF NOT EXISTS holiday_hours DECIMAL(8,2) NOT NULL DEFAULT 0;

-- ---- Pay-rule multipliers (uplifts) ----
-- payroll.php applies the single highest day premium (night/weekend/bank
-- holiday) per entry, and overtime to hours beyond the weekly threshold.
-- multiplier is the rate factor: 1.50 = time-and-a-half.
CREATE TABLE IF NOT EXISTS pay_rules (
  rule_key ENUM('night','weekend','bank_holiday','overtime') PRIMARY KEY,
  multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  meta VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO pay_rules (rule_key, multiplier, meta) VALUES
  ('night', 1.30, 'Shifts starting 20:00–05:59'),
  ('weekend', 1.25, 'Saturday & Sunday'),
  ('bank_holiday', 2.00, 'Listed UK bank holidays'),
  ('overtime', 1.50, 'Hours beyond 40 in an ISO week');

-- ---- UK bank-holiday calendar (England & Wales) ----
CREATE TABLE IF NOT EXISTS bank_holidays (
  holiday_date DATE PRIMARY KEY,
  name VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO bank_holidays (holiday_date, name) VALUES
  ('2026-01-01', "New Year's Day"),
  ('2026-04-03', 'Good Friday'),
  ('2026-04-06', 'Easter Monday'),
  ('2026-05-04', 'Early May bank holiday'),
  ('2026-05-25', 'Spring bank holiday'),
  ('2026-08-31', 'Summer bank holiday'),
  ('2026-12-25', 'Christmas Day'),
  ('2026-12-28', 'Boxing Day (substitute)');

-- =====================================================================
-- Build pass 2 — Sprint A §12 Billing hardening
-- Invoice due dates (for aged debt), credit notes / refunds.
-- Margin reconciliation is computed live in margin.php (no new table).
-- =====================================================================

-- Due date drives the aged-debt buckets. Set on create = invoice date + terms.
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS due_date DATE NULL;

-- ---- Credit notes / refunds against an invoice ----
CREATE TABLE IF NOT EXISTS credit_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(32) NOT NULL,
  invoice_id INT NOT NULL,
  amount_pence INT NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cn_number (number),
  KEY idx_cn_invoice (invoice_id),
  CONSTRAINT fk_cn_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_cn_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Shifts (scheduling & dispatch) ----
-- worker_id is nullable: an "open" shift has no worker until one accepts a
-- broadcast offer (see shift_offers).
CREATE TABLE IF NOT EXISTS shifts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NULL,
  client_id INT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  title VARCHAR(190) NOT NULL,
  site_name VARCHAR(190) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('open','scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_worker_time (worker_id, starts_at),
  KEY idx_time (starts_at),
  CONSTRAINT fk_shifts_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Build pass 4 — Payments (GoCardless + Stripe polish), Auth (sessions /
-- lock screen / Google SSO / self-registration), WhatsApp.
-- =====================================================================

-- Device / session tracking on tokens (for the active-sessions list + lock).
ALTER TABLE tokens
  ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL;

-- Stripe polish: public pay link token + partial-payment tracking on invoices.
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS pay_token CHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS amount_paid_pence INT NOT NULL DEFAULT 0;

-- GoCardless Direct Debit mandates (one active per client).
CREATE TABLE IF NOT EXISTS mandates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  gc_customer_id VARCHAR(64) NULL,
  gc_mandate_id VARCHAR(64) NULL,
  status ENUM('pending','active','failed','cancelled') NOT NULL DEFAULT 'pending',
  reference VARCHAR(40) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mandate_client (client_id),
  KEY idx_mandate_ref (reference),
  CONSTRAINT fk_mandate_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GoCardless payments raised against a mandate (linked to an invoice).
CREATE TABLE IF NOT EXISTS dd_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NULL,
  mandate_id INT NULL,
  gc_payment_id VARCHAR(64) NULL,
  amount_pence INT NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'pending_submission',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ddp_invoice (invoice_id),
  KEY idx_ddp_gc (gc_payment_id),
  CONSTRAINT fk_ddp_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_ddp_mandate FOREIGN KEY (mandate_id) REFERENCES mandates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Build pass 3 — Missing sections:
-- §15 Incidents & Safeguarding, §8 Field Ops, §17 Client Portal,
-- §18 Worker PWA, §13 Recruitment/ATS.
-- =====================================================================

-- Link user accounts to a client (portal) or a worker (mobile app).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS client_id INT NULL,
  ADD COLUMN IF NOT EXISTS worker_id INT NULL;

-- ---- §15 Incidents, accidents, safeguarding, complaints ----
CREATE TABLE IF NOT EXISTS incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NOT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  type ENUM('accident','incident','near_miss','safeguarding','complaint') NOT NULL DEFAULT 'incident',
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  title VARCHAR(190) NOT NULL,
  detail TEXT NOT NULL,
  client_id INT NULL,
  site_name VARCHAR(190) NULL,
  worker_id INT NULL,
  riddor TINYINT(1) NOT NULL DEFAULT 0,
  safeguarding TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('open','investigating','actioned','closed') NOT NULL DEFAULT 'open',
  reported_by INT NULL,
  occurred_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_inc_ref (ref),
  KEY idx_inc_status (status),
  KEY idx_inc_type (type),
  CONSTRAINT fk_inc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_inc_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL,
  CONSTRAINT fk_inc_user FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incident_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  incident_id INT NOT NULL,
  note VARCHAR(500) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ia_incident (incident_id),
  CONSTRAINT fk_ia_incident FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- §8 Field ops: clock-in/out, check-ins, panic, sign-off, logs ----
CREATE TABLE IF NOT EXISTS shift_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shift_id INT NOT NULL,
  worker_id INT NULL,
  kind ENUM('clock_in','clock_out','check_in','panic','note','task','signoff') NOT NULL DEFAULT 'note',
  note VARCHAR(500) NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  signer VARCHAR(160) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_se_shift (shift_id),
  KEY idx_se_kind (kind),
  CONSTRAINT fk_se_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
  CONSTRAINT fk_se_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- §13 Recruitment / ATS ----
CREATE TABLE IF NOT EXISTS applicants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  role_applied VARCHAR(80) NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  stage ENUM('applied','screened','interview','vetting','offer','onboarded','rejected') NOT NULL DEFAULT 'applied',
  source VARCHAR(80) NULL,
  notes TEXT NULL,
  worker_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_app_stage (stage),
  CONSTRAINT fk_app_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onboarding_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT NOT NULL,
  label VARCHAR(160) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ot_applicant (applicant_id),
  CONSTRAINT fk_ot_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Build pass 2 — Sprint D: Auth (§1), Certificates (§10)
-- TOTP 2FA, password resets, CPD hours on programmes.
-- =====================================================================

-- ---- Per-user TOTP 2FA (RFC 6238) ----
CREATE TABLE IF NOT EXISTS user_2fa (
  user_id INT PRIMARY KEY,
  secret VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Password reset tokens (single-use, short-lived) ----
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CPD / training hours per programme (feeds certificates + CPD tracking).
ALTER TABLE programmes
  ADD COLUMN IF NOT EXISTS cpd_hours INT NOT NULL DEFAULT 0;

-- =====================================================================
-- Build pass 2 — Sprint C: CRM (§6), Bookings (§7), Admin/GDPR (§20)
-- Pipeline stage + account manager + SLA + rate cards on clients, client
-- cover-request bookings, and a consent log for GDPR.
-- =====================================================================

-- Pipeline + account management on clients.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS stage ENUM('lead','qualified','proposal','won','lost') NOT NULL DEFAULT 'lead',
  ADD COLUMN IF NOT EXISTS account_manager_id INT NULL,
  ADD COLUMN IF NOT EXISTS sla_response_hours INT NULL;

-- ---- Rate cards: agreed charge per client / division / role ----
CREATE TABLE IF NOT EXISTS rate_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  role VARCHAR(80) NOT NULL DEFAULT 'standard',
  charge_pence INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rc_client (client_id),
  CONSTRAINT fk_rc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Service requests / bookings (client-facing front door) ----
CREATE TABLE IF NOT EXISTS service_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NULL,
  contact_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  site_name VARCHAR(190) NULL,
  detail TEXT NOT NULL,
  preferred_start DATETIME NULL,
  preferred_end DATETIME NULL,
  status ENUM('new','quoted','confirmed','declined') NOT NULL DEFAULT 'new',
  shift_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sr_status (status),
  KEY idx_sr_client (client_id),
  CONSTRAINT fk_sr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- GDPR consent log (public form opt-ins) ----
CREATE TABLE IF NOT EXISTS consent_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  kind VARCHAR(40) NOT NULL DEFAULT 'contact',
  granted TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(80) NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consent_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Build pass 2 — Sprint B: Workforce (§3), Scheduling (§5), Comms (§14)
-- Skills + availability for smart matching, recurring shift templates, open-
-- shift broadcast offers, in-app notifications, SMS log.
-- =====================================================================

-- Open shifts need a nullable worker + the 'open' status (for existing installs).
ALTER TABLE shifts MODIFY COLUMN worker_id INT NULL;
ALTER TABLE shifts MODIFY COLUMN status ENUM('open','scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled';

-- ---- Skills catalogue + worker skills (feeds smart matching) ----
CREATE TABLE IF NOT EXISTS skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  category ENUM('care','academy','cleaning','security','general') NOT NULL DEFAULT 'general',
  UNIQUE KEY uniq_skill (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO skills (name, category) VALUES
  ('Dementia care', 'care'),
  ('Medication trained', 'care'),
  ('Manual handling', 'care'),
  ('Senior carer', 'care'),
  ('Door supervision', 'security'),
  ('CCTV operator', 'security'),
  ('ACS vetted', 'security'),
  ('COSHH trained', 'cleaning'),
  ('Deep cleaning', 'cleaning'),
  ('First aid', 'general'),
  ('Driver', 'general');

CREATE TABLE IF NOT EXISTS worker_skills (
  worker_id INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (worker_id, skill_id),
  CONSTRAINT fk_ws_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Availability & travel preferences on the worker ----
ALTER TABLE workers
  ADD COLUMN IF NOT EXISTS home_postcode VARCHAR(12) NULL,
  ADD COLUMN IF NOT EXISTS travel_radius_mi INT NOT NULL DEFAULT 10,
  ADD COLUMN IF NOT EXISTS avail_days TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS avail_nights TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS avail_weekends TINYINT(1) NOT NULL DEFAULT 0;

-- ---- Recurring shift templates ----
CREATE TABLE IF NOT EXISTS shift_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  client_id INT NULL,
  site_name VARCHAR(190) NULL,
  weekday TINYINT NOT NULL,            -- 1=Mon .. 7=Sun (ISO)
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tmpl_active (active),
  CONSTRAINT fk_tmpl_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Open-shift broadcast offers (first-to-accept) ----
CREATE TABLE IF NOT EXISTS shift_offers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shift_id INT NOT NULL,
  worker_id INT NOT NULL,
  token CHAR(40) NOT NULL,
  status ENUM('sent','accepted','declined','expired') NOT NULL DEFAULT 'sent',
  responded_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_offer_token (token),
  KEY idx_offer_shift (shift_id),
  KEY idx_offer_worker (worker_id),
  CONSTRAINT fk_offer_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
  CONSTRAINT fk_offer_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- In-app notifications (the bell) ----
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,                    -- null = broadcast to all staff
  type VARCHAR(40) NOT NULL DEFAULT 'info',
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NULL,
  url VARCHAR(190) NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, read_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- SMS send log (audit of outbound texts) ----
CREATE TABLE IF NOT EXISTS sms_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(40) NOT NULL,
  body VARCHAR(500) NOT NULL,
  status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  meta VARCHAR(190) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sms_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
