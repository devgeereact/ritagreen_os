-- 007 — right-to-work verification is not yet mandatory.
--
-- 006 turned right to work into a check record. Every check carried over from
-- the old boolean is unverified, because nobody recorded who checked it or when
-- -- so with `requires_verification` on, the engine correctly blocks the entire
-- workforce as "on file but not yet verified".
--
-- Correct, and unusable. The requirement is therefore recorded but not enforced
-- until the office has worked through the backlog of legacy checks.
--
-- Turning it on is a one-row UPDATE and a business decision, not a development
-- task, which is the whole reason these rules live in the database:
--
--   UPDATE compliance_requirements SET requires_verification = 1, expires = 1
--    WHERE req_key = 'right_to_work';
--
-- Before running that, check how many people it would stand down:
--
--   SELECT COUNT(*) FROM people p
--    WHERE NOT EXISTS (SELECT 1 FROM right_to_work_checks r
--                       WHERE r.person_id = p.id AND r.verified_at IS NOT NULL);

UPDATE compliance_requirements
   SET requires_verification = 0,
       description = CONCAT(description, ' Verification not yet enforced: legacy checks carry no verifier. Turn on once the backlog is cleared.')
 WHERE req_key = 'right_to_work';
