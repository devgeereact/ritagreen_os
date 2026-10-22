-- 039: careers, the forms directory and company details, managed from the
-- Website area of the office app (2026 plan, file 06, section S7).
--
-- Three records the public website reads through endpoints that return an
-- explicit list of public fields, never whole rows:
--
--   website_forms     the approved Google Forms the site may embed or link to
--   vacancies         roles shown on /careers and /careers/:slug
--   company_settings  one row: name, numbers, hours, area, social links
--
-- Every statement is re-runnable: CREATE TABLE IF NOT EXISTS and INSERT IGNORE
-- against a primary key.

-- ---- Forms directory ----------------------------------------------------------
--
-- Only the form's id is stored, never the address somebody pasted. The website
-- builds the iframe and the "open in a new tab" link from the id, so a pasted
-- redirector, a script or a different host can never reach a public page: the
-- endpoint refuses anything that is not https://docs.google.com/forms/d/e/<id>/viewform
-- (rg_parse_google_form() in server/pure.php).
--
-- integration_id points at the forms-intake.php integration the form's Apps
-- Script posts to, so Settings can show each form's intake health beside it.
-- Changing the address keeps the row, and so keeps every role page that links to
-- it. The previous value is in the audit log (website_form.update).

CREATE TABLE IF NOT EXISTS website_forms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  purpose ENUM('recruitment','training','other') NOT NULL DEFAULT 'recruitment',
  service ENUM('care','academy','cleaning','security') NULL,
  google_form_id VARCHAR(200) NOT NULL,
  display ENUM('embedded','link') NOT NULL DEFAULT 'embedded',
  active TINYINT(1) NOT NULL DEFAULT 1,
  owner_id INT NULL,
  integration_id INT NULL,
  last_reviewed_at DATE NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  KEY idx_wf_active (active, deleted_at),
  KEY idx_wf_owner (owner_id),
  KEY idx_wf_integration (integration_id),
  CONSTRAINT fk_wf_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_wf_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE SET NULL,
  CONSTRAINT fk_wf_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Vacancies ----------------------------------------------------------------
--
-- The slug is the role page's address. It is set when the vacancy is created and
-- fixed once it has been published, because by then it is in search results and
-- in posts on social media. Pay is free text and optional: the plan marks pay
-- "[confirm per role]", and a number the owner has not confirmed must not appear.
-- `checks` is prefilled per service from file 01 when left empty.
--
-- One intake path per role (file 06): a vacancy with a linked form takes
-- applications through that form only. Without one, the site falls back to the
-- short native form that posts to ats.php.

CREATE TABLE IF NOT EXISTS vacancies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  service ENUM('care','academy','cleaning','security') NOT NULL,
  location VARCHAR(160) NOT NULL DEFAULT '',
  hours_text VARCHAR(160) NOT NULL DEFAULT '',
  pay_text VARCHAR(160) NULL,
  summary TEXT NOT NULL,
  requirements TEXT NULL,
  checks TEXT NULL,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  form_id INT NULL,
  owner_id INT NULL,
  published_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_vacancy_slug (slug),
  KEY idx_vacancy_public (status, deleted_at, published_at),
  KEY idx_vacancy_form (form_id),
  KEY idx_vacancy_owner (owner_id),
  CONSTRAINT fk_vacancy_form FOREIGN KEY (form_id) REFERENCES website_forms(id) ON DELETE SET NULL,
  CONSTRAINT fk_vacancy_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_vacancy_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Company details and social links -------------------------------------------
--
-- One row (id 1). Edited by an owner in the Website area and read by every
-- public page through company.php. src/lib/company.ts keeps the same facts as the
-- fallback, so the site still shows a phone number if the API is down.
-- An empty social URL means "do not show that network".
--
-- Not app_settings (migration 038): that table holds owner-tunable numbers as
-- name/value pairs of VARCHAR(255) with defaults in rg_setting_defaults(). This
-- is one public record, published as a unit with an explicit field list, whose
-- addresses can exceed 255 characters, and whose public read must never be able
-- to return a setting that was not meant for the website. Typed columns make
-- that list the schema rather than a filter.

CREATE TABLE IF NOT EXISTS company_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  trading_name VARCHAR(160) NOT NULL,
  legal_name VARCHAR(160) NOT NULL,
  company_no VARCHAR(20) NOT NULL DEFAULT '',
  phone VARCHAR(40) NOT NULL,
  urgent_phone VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  office_hours VARCHAR(160) NOT NULL DEFAULT '',
  area VARCHAR(160) NOT NULL DEFAULT '',
  registered_address VARCHAR(300) NOT NULL DEFAULT '',
  instagram_url VARCHAR(300) NOT NULL DEFAULT '',
  facebook_url VARCHAR(300) NOT NULL DEFAULT '',
  linkedin_url VARCHAR(300) NOT NULL DEFAULT '',
  tiktok_url VARCHAR(300) NOT NULL DEFAULT '',
  whatsapp_url VARCHAR(300) NOT NULL DEFAULT '',
  updated_by INT NULL,
  updated_at DATETIME NULL,
  KEY idx_cs_updated_by (updated_by),
  CONSTRAINT fk_cs_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seeded from src/lib/company.ts as it stood when this was written. INSERT
-- IGNORE: re-running must not undo what the owner has since changed.
INSERT IGNORE INTO company_settings
  (id, trading_name, legal_name, company_no, phone, urgent_phone, email, office_hours, area,
   registered_address, instagram_url, facebook_url, linkedin_url, tiktok_url, whatsapp_url)
VALUES
  (1, 'RitaGreen Services', 'RitaGreen Services Ltd', '12528439', '0203 105 2218', '07377 563859',
   'hello@ritagreenservices.com', 'Monday to Friday, 9am to 5pm', 'London and surrounding boroughs',
   '', 'https://instagram.com/ritagreenservices__', 'https://facebook.com/ritagreenservices__',
   'https://linkedin.com/company/ritagreenservices__', '', '');
