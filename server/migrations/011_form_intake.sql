-- 011 — Google Forms intake.
--
-- The office already runs recruitment through Google Forms. The decision taken
-- was to keep that AND the native /apply page, with Forms as an INTEGRATION
-- rather than the database: the CMS owns the record, so Forms can be replaced
-- later without rebuilding anything.
--
-- The field mapping is configuration. A form that gains a question, or has one
-- reworded, must not require a code change -- otherwise the office edits the
-- form, submissions stop mapping, and nobody finds out until a candidate rings
-- up asking why they never heard back.

CREATE TABLE IF NOT EXISTS integrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  integration_key VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  kind ENUM('google_form','webhook','api') NOT NULL DEFAULT 'google_form',
  -- Which service and role submissions default to, where the form does not say.
  default_service ENUM('care','academy','cleaning','security') NULL,
  default_role VARCHAR(80) NULL,
  -- Hash of the shared secret, never the secret. A leaked database must not
  -- hand over the ability to post fake applications.
  secret_hash CHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  -- Health. Silence is the failure mode that matters: a form that stops feeding
  -- the system looks exactly like a quiet week.
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  last_failure_reason VARCHAR(500) NULL,
  submissions_total INT NOT NULL DEFAULT 0,
  -- Raise an alert when nothing has arrived for this long. NULL = do not watch.
  expect_within_hours SMALLINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_integration_key (integration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Form question -> CMS field. Editable by the business.
CREATE TABLE IF NOT EXISTS integration_field_maps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  integration_id INT NOT NULL,
  -- The question title exactly as it appears on the form.
  source_field VARCHAR(255) NOT NULL,
  target_field ENUM('name','email','phone','service','role_applied','postcode','notes',
                    'right_to_work','sia_number','previous_employer','training','references','ignore')
      NOT NULL DEFAULT 'ignore',
  -- Optional value translation, e.g. "Security Officer" -> "security".
  value_map TEXT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_map (integration_id, source_field),
  CONSTRAINT fk_map_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every submission, exactly as received, before any mapping.
--
-- Kept whether or not it maps cleanly. A submission that fails to map is a
-- person who applied and would otherwise vanish, and the raw payload is what
-- makes it recoverable once the mapping is fixed.
CREATE TABLE IF NOT EXISTS integration_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  integration_id INT NOT NULL,
  -- The form's own id for the response, so a redelivery cannot duplicate.
  external_id VARCHAR(190) NULL,
  raw_payload TEXT NOT NULL,
  status ENUM('received','mapped','failed','ignored') NOT NULL DEFAULT 'received',
  failure_reason VARCHAR(500) NULL,
  applicant_id INT NULL,
  person_id INT NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uniq_external (integration_id, external_id),
  KEY idx_sub_status (status, received_at),
  CONSTRAINT fk_sub_integration FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE SET NULL,
  CONSTRAINT fk_sub_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One integration per recruiting service, so a form can be swapped or paused
-- without affecting the others. Secrets are set from the Settings screen; an
-- integration with no secret refuses every submission.
INSERT IGNORE INTO integrations (integration_key, name, kind, default_service, expect_within_hours) VALUES
  ('careers_care',     'Careers form — Care',     'google_form', 'care',     168),
  ('careers_cleaning', 'Careers form — Cleaning', 'google_form', 'cleaning', 168),
  ('careers_security', 'Careers form — Security', 'google_form', 'security', 168),
  ('academy_enrol',    'Academy enrolment form',  'google_form', 'academy',  168);

-- A starting mapping for the questions these forms already ask. The office can
-- change any of it without a deployment.
INSERT IGNORE INTO integration_field_maps (integration_id, source_field, target_field, required)
SELECT i.id, m.src, m.tgt, m.req
FROM integrations i
JOIN (
  SELECT 'Full name' AS src, 'name' AS tgt, 1 AS req UNION ALL
  SELECT 'Email address', 'email', 1 UNION ALL
  SELECT 'Phone number', 'phone', 0 UNION ALL
  SELECT 'Which service are you applying for?', 'service', 0 UNION ALL
  SELECT 'Position applied for', 'role_applied', 0 UNION ALL
  SELECT 'Postcode', 'postcode', 0 UNION ALL
  SELECT 'Do you have the right to work in the UK?', 'right_to_work', 0 UNION ALL
  SELECT 'SIA licence number', 'sia_number', 0 UNION ALL
  SELECT 'Previous employer', 'previous_employer', 0 UNION ALL
  SELECT 'Relevant training', 'training', 0 UNION ALL
  SELECT 'References', 'references', 0 UNION ALL
  SELECT 'Anything else you would like us to know?', 'notes', 0
) AS m
WHERE i.kind = 'google_form';
