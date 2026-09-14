-- 031 — starting onboarding is not finishing it.
--
-- ats.php?action=onboard created a worker with status 'onboarding' and seeded a
-- checklist of unfinished tasks, and in the same breath set the application's
-- stage to 'onboarded'. The pipeline therefore said a candidate was onboarded
-- while the onboarding had not started being done, and there was no stage left to
-- mean "this person is actually cleared and working".
--
-- 'onboarding' is added between 'offer' and 'onboarded'. The onboard branch now
-- writes 'onboarding'; 'onboarded' becomes the completed state, reached by the
-- activation step rather than by the conversion.

ALTER TABLE applicants
  MODIFY COLUMN stage ENUM('applied','screened','interview','vetting','offer','onboarding','onboarded','rejected')
    NOT NULL DEFAULT 'applied';

-- Existing rows are reclassified by what is true of them rather than in bulk.
-- 'onboarded' was only ever written by the conversion, so it always meant
-- "onboarding started"; but a row whose checklist is finished is correctly
-- described by the word and keeps it. Nothing is lost either way -- the checklist
-- is the evidence, and it is untouched.
UPDATE applicants a
   SET a.stage = 'onboarding'
 WHERE a.stage = 'onboarded'
   AND EXISTS (SELECT 1 FROM onboarding_tasks t WHERE t.applicant_id = a.id AND t.done = 0);

-- A conversion that had never seeded a checklist at all cannot be said to have
-- completed one either.
UPDATE applicants a
   SET a.stage = 'onboarding'
 WHERE a.stage = 'onboarded'
   AND NOT EXISTS (SELECT 1 FROM onboarding_tasks t WHERE t.applicant_id = a.id);
