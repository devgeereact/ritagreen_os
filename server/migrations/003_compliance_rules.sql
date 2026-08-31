-- 003 — the compliance rule set.
--
-- Storage for the engine already built and tested in server/pure.php
-- (rg_evaluate_eligibility). The point of holding rules in the database rather
-- than in code is that guidance changes: when a requirement is added, or its
-- weight changes for one service, that must be a setting the business edits,
-- not a development task. See docs/UK_GOVERNANCE.md.

-- The catalogue: everything that can be required of anybody.
CREATE TABLE IF NOT EXISTS compliance_requirements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  req_key VARCHAR(64) NOT NULL,
  label VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  -- Where the evidence lives, so the evaluator knows what to load.
  evidence_source ENUM('worker_field','certification','right_to_work','licence','reference','document','training')
      NOT NULL DEFAULT 'certification',
  -- Does this credential carry an expiry date? A requirement that should expire
  -- but has no date recorded is incomplete evidence, not valid evidence.
  expires TINYINT(1) NOT NULL DEFAULT 1,
  -- Must a named person have checked it? An unverified document is a claim.
  requires_verification TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_req_key (req_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What each service requires, and how strongly.
--
-- worker_role NULL means the rule applies to every role in that service. A row
-- naming a role overrides the service-wide rule for that role.
CREATE TABLE IF NOT EXISTS compliance_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service ENUM('care','academy','cleaning','security') NOT NULL,
  worker_role ENUM('carer','nurse','cleaner','guard','trainer') NULL,
  requirement_id INT NOT NULL,
  weight ENUM('mandatory','recommended','optional') NOT NULL DEFAULT 'mandatory',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rule (service, worker_role, requirement_id),
  KEY idx_rule_service (service, active),
  CONSTRAINT fk_rule_requirement FOREIGN KEY (requirement_id) REFERENCES compliance_requirements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the catalogue.
INSERT IGNORE INTO compliance_requirements (req_key, label, description, evidence_source, expires, requires_verification) VALUES
  ('right_to_work', 'Right to work', 'Evidence of the right to work in the UK, with the check recorded.', 'right_to_work', 1, 1),
  ('identity',      'Identity',      'Verified proof of identity.', 'document', 0, 1),
  ('dbs',           'DBS check',     'Disclosure and Barring Service check appropriate to the role.', 'worker_field', 1, 1),
  ('sia_licence',   'SIA licence',   'A valid SIA licence covering the role being worked.', 'licence', 1, 1),
  ('references',    'References',    'Employment references obtained and checked.', 'reference', 0, 1),
  ('contract',      'Signed contract', 'A signed contract of employment or engagement.', 'document', 0, 0),
  ('mandatory_training', 'Mandatory training', 'The mandatory training set for the service.', 'training', 1, 0),
  ('care_certificate', 'Care Certificate', 'The Care Certificate, or evidence of equivalent induction.', 'training', 0, 0),
  ('first_aid',     'First aid',     'A current first aid qualification.', 'certification', 1, 0),
  ('moving_handling', 'Moving and handling', 'Practical moving and handling training.', 'certification', 1, 0),
  ('coshh',         'COSHH awareness', 'Control of Substances Hazardous to Health awareness.', 'certification', 1, 0),
  ('conflict_management', 'Conflict management', 'De-escalation and conflict management training.', 'certification', 1, 0),
  ('nmc_registration', 'NMC registration', 'Current Nursing and Midwifery Council registration.', 'certification', 1, 1);

-- Seed the rules.
--
-- These reproduce what scheduling.php already enforces in code -- right to work
-- everywhere, DBS for care, SIA for security -- so wiring the engine in changes
-- no behaviour on day one. Everything beyond that starts as recommended, so the
-- business can raise a requirement to mandatory deliberately rather than
-- discovering overnight that half the workforce is blocked.
INSERT IGNORE INTO compliance_rules (service, worker_role, requirement_id, weight)
SELECT s.service, NULL, r.id, s.weight
FROM compliance_requirements r
JOIN (
  SELECT 'care' AS service, 'right_to_work' AS req_key, 'mandatory' AS weight UNION ALL
  SELECT 'care', 'identity', 'mandatory' UNION ALL
  SELECT 'care', 'dbs', 'mandatory' UNION ALL
  SELECT 'care', 'references', 'mandatory' UNION ALL
  SELECT 'care', 'contract', 'mandatory' UNION ALL
  SELECT 'care', 'care_certificate', 'recommended' UNION ALL
  SELECT 'care', 'mandatory_training', 'recommended' UNION ALL
  SELECT 'care', 'moving_handling', 'recommended' UNION ALL
  SELECT 'care', 'first_aid', 'recommended' UNION ALL

  SELECT 'security', 'right_to_work', 'mandatory' UNION ALL
  SELECT 'security', 'identity', 'mandatory' UNION ALL
  SELECT 'security', 'sia_licence', 'mandatory' UNION ALL
  SELECT 'security', 'references', 'mandatory' UNION ALL
  SELECT 'security', 'contract', 'mandatory' UNION ALL
  SELECT 'security', 'conflict_management', 'recommended' UNION ALL
  SELECT 'security', 'first_aid', 'recommended' UNION ALL

  SELECT 'cleaning', 'right_to_work', 'mandatory' UNION ALL
  SELECT 'cleaning', 'identity', 'mandatory' UNION ALL
  SELECT 'cleaning', 'references', 'mandatory' UNION ALL
  SELECT 'cleaning', 'contract', 'mandatory' UNION ALL
  SELECT 'cleaning', 'coshh', 'recommended' UNION ALL

  SELECT 'academy', 'right_to_work', 'mandatory' UNION ALL
  SELECT 'academy', 'identity', 'mandatory' UNION ALL
  SELECT 'academy', 'contract', 'mandatory' UNION ALL
  SELECT 'academy', 'dbs', 'recommended'
) AS s ON s.req_key = r.req_key;

-- A nurse additionally needs current NMC registration. Role-specific rules sit
-- alongside the service-wide ones rather than replacing them.
INSERT IGNORE INTO compliance_rules (service, worker_role, requirement_id, weight)
SELECT 'care', 'nurse', id, 'mandatory' FROM compliance_requirements WHERE req_key = 'nmc_registration';
