-- 020 — data-subject requests, marketing consent, and the retention rows that
-- were missing.
--
-- Three gaps found by the privacy audit, all of the same kind: the system does
-- the right thing internally and offers no way in from outside.
--
-- 1. A person had no route to ask for anything. The privacy notice said "email
--    us", which is a mailbox, not a process: no record that a request arrived,
--    no clock, no way to show what was done about it.
-- 2. The notice claimed marketing was sent "with an opt-out". There was no
--    opt-out anywhere in the system, and the two client-facing enquiry forms
--    recorded no consent of any kind.
-- 3. Three tables holding personal data collected from the public had no
--    retention policy and no deleted_at at all.

-- ---- Data-subject requests ------------------------------------------------
--
-- Deliberately thin. It records that a request was made, what kind, and how it
-- was handled. It does NOT collect identity documents: verifying who somebody
-- is happens over a channel the requester already controls, not by asking them
-- to email a passport scan to a public form.
CREATE TABLE IF NOT EXISTS data_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NOT NULL,
  -- What is being asked for. 'other' exists because the list is not the limit
  -- of what somebody may ask.
  kind ENUM('access','correction','deletion','objection','restriction','portability','withdraw_consent','other')
    NOT NULL DEFAULT 'access',
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  -- How they relate to us, in their words: worker, applicant, client contact,
  -- someone we hold care records about. It decides where staff look.
  relationship VARCHAR(120) NULL,
  detail TEXT NULL,
  status ENUM('received','verifying','in_progress','completed','refused','withdrawn') NOT NULL DEFAULT 'received',
  -- Set when the request is acknowledged, so the clock is visible rather than
  -- remembered. No statutory deadline is written into this schema: the period
  -- and how it is counted are a matter for the business and its advisers.
  acknowledged_at DATETIME NULL,
  completed_at DATETIME NULL,
  -- Free text about how it was handled, for the file.
  outcome_note VARCHAR(1000) NULL,
  handled_by INT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_data_req_ref (ref),
  KEY idx_data_req_status (status, created_at),
  KEY idx_data_req_email (email),
  CONSTRAINT fk_data_req_user FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Marketing consent ----------------------------------------------------
--
-- No new table. consent_log already records (email, kind, granted, source, ip)
-- and is the right shape; it only ever held one-way records because nothing
-- ever wrote granted = 0. Marketing consent is the latest row for
-- kind = 'marketing', so an opt-out is a row, not a deletion — erasing the
-- evidence that somebody unsubscribed is how somebody gets re-subscribed.
--
-- The index makes "what is the latest marketing row for this address" a lookup
-- rather than a scan, which matters because it is checked before every send.
ALTER TABLE consent_log ADD INDEX idx_consent_email_kind (email, kind, id);

-- ---- Retention rows that were missing -------------------------------------
--
-- Public intake data was the one category with no policy row at all: an enquiry
-- from 2021 with a name, phone number and free-text message sat in the same
-- table as one from this morning, with nothing that would ever look at it.
-- Periods stay NULL like every other row: the value is the business's to set.
INSERT IGNORE INTO retention_policies (record_type, label, retain_months, basis, action_at_end) VALUES
  ('contact_enquiries', 'Website enquiries and quote requests', NULL, 'Set against how long an enquiry stays useful, and any contractual need.', 'review'),
  ('service_requests', 'Cover requests from the website', NULL, 'Set against how long a request stays useful.', 'review'),
  ('form_submissions', 'Raw form-integration payloads', NULL, 'Held only to diagnose a failed mapping; a short period is the point.', 'review'),
  ('data_requests', 'Data-subject request records', NULL, 'Set against the need to show how a request was handled.', 'review'),
  ('consent_log', 'Consent and opt-out records', NULL, 'An opt-out generally outlives the consent it withdraws.', 'review'),
  ('email_sms_log', 'Email and SMS delivery logs', NULL, 'Set against how long delivery evidence is needed.', 'review');

-- Archive-not-delete needs somewhere to record the archiving. These three
-- tables hold personal data and had no deleted_at, so the only disposal
-- available was a hard DELETE.
ALTER TABLE contact_enquiries ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
ALTER TABLE integration_submissions ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
