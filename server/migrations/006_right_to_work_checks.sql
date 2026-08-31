-- 006 — right to work becomes evidence.
--
-- It was `workers.right_to_work TINYINT(1)`: no check method, no date, no
-- verifier, no evidence, no expiry, no audit trail. The single field that most
-- needs a trail had none, and it is the weakest point in the system in legal
-- terms.
--
-- A check is now a record. The old boolean is NOT dropped -- endpoints still
-- read it, and it is backfilled from these rows -- so nothing breaks while the
-- interface catches up. Dropping it is a later migration.
--
-- Deliberately NOT hard-coding what a valid check looks like. Permitted methods
-- and recheck intervals change, and encoding today's guidance in a schema is how
-- software goes quietly stale. The system records what was done, by whom, when,
-- and what it showed. See docs/UK_GOVERNANCE.md.

CREATE TABLE IF NOT EXISTS right_to_work_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  -- How the check was carried out. Free-ish rather than a tight ENUM, because
  -- the permitted methods are set by guidance, not by this schema.
  method ENUM('online_share_code','manual_document','idsp_digital','other') NOT NULL DEFAULT 'manual_document',
  method_note VARCHAR(190) NULL,
  -- The reference the method produced, where it produces one.
  reference VARCHAR(80) NULL,
  checked_on DATE NOT NULL,
  outcome ENUM('pending','valid','invalid','requires_follow_up') NOT NULL DEFAULT 'pending',
  -- Whether a further check is due, and when. A time-limited right to work is
  -- the case this exists for.
  follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
  recheck_due DATE NULL,
  -- The evidence itself, and who says so.
  evidence_file_id INT NULL,
  verified_by INT NULL,
  verified_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rtw_person (person_id, checked_on),
  KEY idx_rtw_recheck (recheck_due),
  CONSTRAINT fk_rtw_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  CONSTRAINT fk_rtw_file FOREIGN KEY (evidence_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT fk_rtw_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carry the existing boolean across as a historical check, so nobody appears to
-- have lost their right to work overnight.
--
-- Marked 'other' with an explicit note, and NOT verified: nobody can say who
-- checked it or when, because that was never recorded. Presenting it as a
-- verified check would be inventing evidence. It reads as an unverified
-- historical assertion, which is exactly what it is.
INSERT INTO right_to_work_checks (person_id, method, method_note, checked_on, outcome, notes)
SELECT DISTINCT w.person_id, 'other',
       'Migrated from the legacy right_to_work flag',
       COALESCE(DATE(w.created_at), CURDATE()),
       'valid',
       'Carried over from workers.right_to_work. No method, verifier or date was recorded at the time, so this is an unverified historical assertion, not a completed check. Re-check before relying on it.'
FROM workers w
WHERE w.right_to_work = 1
  AND w.person_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM right_to_work_checks r WHERE r.person_id = w.person_id);
