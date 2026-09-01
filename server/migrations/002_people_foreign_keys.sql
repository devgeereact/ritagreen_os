-- 002 — constrain the person links.
--
-- Split from 001 deliberately, so the constraints can be added once the person
-- rows exist.
--
-- NOTE, verified against a real database: a foreign key does NOT force every row
-- to be linked. It constrains non-NULL values only, so rows with person_id NULL
-- pass straight through it. This migration applied cleanly with an unlinked row
-- present. Migration 004 is what actually requires the link, by making the
-- column NOT NULL, and it carries a precondition the runner checks first.
--
-- ON DELETE RESTRICT throughout: a person with an employment record, an
-- application or a training history attached must not be deletable out from
-- under it. Removing a person goes through the archive and deletion-approval
-- workflow, never a cascade.

ALTER TABLE workers
  ADD CONSTRAINT fk_workers_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT;

ALTER TABLE applicants
  ADD CONSTRAINT fk_applicants_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT;

ALTER TABLE users
  ADD CONSTRAINT fk_users_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT;

ALTER TABLE enrolments
  ADD CONSTRAINT fk_enrolments_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT;
