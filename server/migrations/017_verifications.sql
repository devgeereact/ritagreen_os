-- 017 — one verification lifecycle for everything that needs checking.
--
-- Verification is currently three different shapes. worker_references has a
-- status. right_to_work_checks has its own verified_by/verified_at. Everything
-- else -- an SIA licence, a DBS certificate, a qualification, a document -- has
-- nothing: a number typed into a field, with no record of whether anybody looked
-- at the register, who, or when.
--
-- One table, so "who verified this and when" has the same answer shape whatever
-- is being verified, and so a chasing list can be built once rather than per
-- credential.
--
-- right_to_work_checks is NOT folded in. It carries method, share codes and
-- recheck rules that belong to it alone, and flattening those into a generic
-- table would lose them. It stays specialised and this covers the rest.

CREATE TABLE IF NOT EXISTS verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  -- What is being verified. requirement_key ties it to the compliance catalogue
  -- so the engine can find it; subject_type/id points at the row it concerns.
  requirement_key VARCHAR(64) NOT NULL,
  subject_type ENUM('certification','licence','document','reference','qualification','other')
      NOT NULL DEFAULT 'document',
  subject_id INT NULL,
  subject_label VARCHAR(190) NULL,

  -- The lifecycle. 'received' and 'under_review' are separate because a document
  -- arriving and somebody actually checking it are different events, and
  -- collapsing them is how "we have it" becomes mistaken for "we checked it".
  status ENUM('requested','received','under_review','verified','rejected','expired')
      NOT NULL DEFAULT 'requested',

  requested_at DATETIME NULL,
  requested_by INT NULL,
  received_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  -- Who says so. A verification with no name against it is not a verification.
  verified_by INT NULL,
  verified_at DATETIME NULL,

  -- What was actually checked, in words. "Checked the SIA register" and "saw a
  -- photocopy" are not the same assurance, and only this field can tell them
  -- apart later.
  method VARCHAR(190) NULL,
  outcome_note VARCHAR(500) NULL,
  rejection_reason VARCHAR(500) NULL,
  evidence_file_id INT NULL,
  expires_on DATE NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_ver_person (person_id, requirement_key),
  KEY idx_ver_status (status, requested_at),
  KEY idx_ver_expiry (expires_on),
  CONSTRAINT fk_ver_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  CONSTRAINT fk_ver_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ver_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ver_file FOREIGN KEY (evidence_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carry across what is already known, HONESTLY.
--
-- An SIA number or DBS status recorded against a worker means somebody typed it
-- in. It does not mean anybody checked a register. These come in as 'received',
-- not 'verified', with a note saying exactly that -- marking them verified would
-- be inventing an assurance that was never given.
INSERT INTO verifications (person_id, requirement_key, subject_type, subject_label, status, received_at, outcome_note, expires_on)
SELECT w.person_id, 'sia_licence', 'licence', w.sia_number, 'received', w.created_at,
       'Carried over from workers.sia_number. A number was recorded; no check of the register was captured. Verify before relying on it.',
       w.sia_expiry
FROM workers w
WHERE w.person_id IS NOT NULL AND w.sia_number IS NOT NULL AND w.sia_number <> ''
  AND NOT EXISTS (SELECT 1 FROM verifications v WHERE v.person_id = w.person_id AND v.requirement_key = 'sia_licence');

INSERT INTO verifications (person_id, requirement_key, subject_type, subject_label, status, received_at, outcome_note, expires_on)
SELECT w.person_id, 'dbs', 'certification', w.dbs_status, 'received', w.created_at,
       'Carried over from workers.dbs_status. A status was recorded; no check was captured. Verify before relying on it.',
       w.dbs_expiry
FROM workers w
WHERE w.person_id IS NOT NULL AND w.dbs_status IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM verifications v WHERE v.person_id = w.person_id AND v.requirement_key = 'dbs');
