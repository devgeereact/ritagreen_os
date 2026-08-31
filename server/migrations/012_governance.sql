-- 012 — deletion approval and export logging.
--
-- Two gaps with the same shape: an action that removes or copies personal data
-- currently leaves no trace of who decided it was alright.
--
-- Ordinary staff get "request deletion", not "delete". Somebody else approves,
-- and both halves are recorded. An employment record removed on one person's say
-- so, with no reason and no second pair of eyes, is exactly the thing an audit
-- asks about.

CREATE TABLE IF NOT EXISTS deletion_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT NOT NULL,
  -- What is being removed, in words, captured at request time. The record may be
  -- gone by the time anybody reads this.
  entity_label VARCHAR(255) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  -- 'archive' keeps the row and hides it; 'delete' removes it. Archive is the
  -- default everywhere it is defensible.
  disposition ENUM('archive','delete') NOT NULL DEFAULT 'archive',
  status ENUM('requested','approved','rejected','completed','cancelled') NOT NULL DEFAULT 'requested',
  requested_by INT NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  -- The four-eyes rule: the approver must not be the requester. Enforced in
  -- code, since MySQL cannot express it as a constraint here.
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  completed_at DATETIME NULL,
  KEY idx_del_status (status, requested_at),
  KEY idx_del_entity (entity_type, entity_id),
  CONSTRAINT fk_del_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_del_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every export of personal data, logged.
--
-- An export is a copy of personal data leaving the system. Where it goes next is
-- outside the system's control, which is exactly why what left, when, and at
-- whose request has to be recorded.
CREATE TABLE IF NOT EXISTS export_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  export_type VARCHAR(64) NOT NULL,
  format ENUM('csv','pdf','xlsx','json') NOT NULL DEFAULT 'csv',
  -- How many records left, and roughly what they covered.
  record_count INT NULL,
  scope VARCHAR(255) NULL,
  -- Why. Blank is allowed but visible, so a pattern of unexplained exports is
  -- something a reviewer can actually see.
  purpose VARCHAR(500) NULL,
  contains_personal_data TINYINT(1) NOT NULL DEFAULT 1,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_export_user (user_id, created_at),
  KEY idx_export_type (export_type, created_at),
  CONSTRAINT fk_export_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
