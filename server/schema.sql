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
  KEY idx_programme (programme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  programme_id INT NULL,
  worker_id INT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_starts (starts_at)
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
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Announcements (communication hub) ----
CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  author_id INT NULL,
  audience ENUM('all','care','academy','cleaning','security') NOT NULL DEFAULT 'all',
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audience (audience)
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
  KEY idx_end (end_date)
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
  KEY idx_status (status)
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
  KEY idx_client (client_id)
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
  KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Shifts (scheduling & dispatch) ----
CREATE TABLE IF NOT EXISTS shifts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  client_id INT NULL,
  division ENUM('care','academy','cleaning','security') NOT NULL DEFAULT 'care',
  title VARCHAR(190) NOT NULL,
  site_name VARCHAR(190) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_worker_time (worker_id, starts_at),
  KEY idx_time (starts_at),
  CONSTRAINT fk_shifts_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
