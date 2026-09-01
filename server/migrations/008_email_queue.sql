-- 008 — templated, queued, logged email.
--
-- send_mail() opens a socket and speaks SMTP inside the web request. A slow mail
-- server therefore slows the application, and a failed send is lost: nothing
-- records that it was attempted, so nobody finds out an applicant never got
-- their confirmation.
--
-- Three tables: what we send, what is waiting to go, and what happened.

-- What we send. Bodies are plain text with {{placeholders}}; the business can
-- edit the wording without a deployment, which is the point.
CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  category ENUM('recruitment','verification','onboarding','compliance','training','operations','other')
      NOT NULL DEFAULT 'other',
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  -- Placeholders this template expects, comma separated, so the editor can show
  -- them and a send can be checked before it goes out.
  placeholders VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_template_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What is waiting to go. Drained by cron, never by a web request.
CREATE TABLE IF NOT EXISTS email_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  reply_to VARCHAR(190) NULL,
  template_key VARCHAR(64) NULL,
  -- What this email is about, so a person record can show its own correspondence.
  related_type VARCHAR(40) NULL,
  related_id INT NULL,
  status ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  -- Lets a reminder be queued now and sent later.
  send_after DATETIME NULL,
  queued_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY idx_queue_status (status, send_after),
  KEY idx_queue_related (related_type, related_id),
  CONSTRAINT fk_queue_user FOREIGN KEY (queued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What happened. Kept after the queue row is pruned, because "did we tell them"
-- is a question asked months later.
CREATE TABLE IF NOT EXISTS email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue_id INT NULL,
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  template_key VARCHAR(64) NULL,
  related_type VARCHAR(40) NULL,
  related_id INT NULL,
  outcome ENUM('sent','failed','skipped') NOT NULL,
  detail VARCHAR(500) NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_recipient (recipient),
  KEY idx_log_outcome (outcome, created_at),
  KEY idx_log_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the templates the system already sends by hand-built strings, so the
-- wording becomes editable rather than living in PHP.
INSERT IGNORE INTO email_templates (template_key, name, category, subject, body, placeholders) VALUES
  ('application_received', 'Application received', 'recruitment',
   'We have your application, {{first_name}}',
   'Hi {{first_name}},\n\nThanks for applying to RitaGreen {{service}}. We have your application and will be in touch once we have reviewed it.\n\nIf anything changes in the meantime, just reply to this email.\n\nRitaGreen Services',
   'first_name,service'),
  ('application_unsuccessful', 'Application unsuccessful', 'recruitment',
   'Your application to RitaGreen',
   'Hi {{first_name}},\n\nThank you for your interest in RitaGreen {{service}}. On this occasion we will not be taking your application further.\n\nWe keep applications on file, and you are welcome to apply again.\n\nRitaGreen Services',
   'first_name,service'),
  ('document_request', 'Document request', 'verification',
   'We need one more thing from you',
   'Hi {{first_name}},\n\nBefore we can complete your onboarding we need: {{items}}.\n\nYou can reply to this email with them attached.\n\nRitaGreen Services',
   'first_name,items'),
  ('credential_expiring', 'Credential expiring', 'compliance',
   'Your {{credential}} expires on {{expiry}}',
   'Hi {{first_name}},\n\nYour {{credential}} is due to expire on {{expiry}}.\n\nWe need an up-to-date copy before then, or we will not be able to schedule you after that date.\n\nRitaGreen Services',
   'first_name,credential,expiry'),
  ('credential_expired', 'Credential expired', 'compliance',
   'Your {{credential}} has expired',
   'Hi {{first_name}},\n\nOur records show your {{credential}} expired on {{expiry}}. Until it is renewed we cannot schedule you for work that requires it.\n\nPlease send us an up-to-date copy.\n\nRitaGreen Services',
   'first_name,credential,expiry'),
  ('right_to_work_recheck', 'Right to work recheck', 'compliance',
   'We need to recheck your right to work',
   'Hi {{first_name}},\n\nYour right-to-work check is due to be repeated by {{due}}. Please get in touch so we can arrange it.\n\nRitaGreen Services',
   'first_name,due'),
  ('worker_approved', 'Worker approved', 'onboarding',
   'You are all set, {{first_name}}',
   'Hi {{first_name}},\n\nYour onboarding is complete and you are cleared to work. Your payroll number is {{payroll_no}}.\n\nYou can sign in to see your shifts, documents and training at {{portal_url}}.\n\nRitaGreen Services',
   'first_name,payroll_no,portal_url');
