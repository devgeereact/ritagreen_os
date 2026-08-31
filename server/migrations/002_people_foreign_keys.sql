-- 002 — constrain the person links.
--
-- Split from 001 deliberately. Adding a foreign key fails if any existing row
-- holds a value that does not resolve, so these must not run until the backfill
-- has populated person_id and its review list has been cleared. Running 001,
-- then the backfill, then this, is the safe order.
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
