-- 018 — make the seeded rules match what was actually enforced before.
--
-- Migration 003 claimed, in its own comment, that the seeded rules "reproduce
-- what scheduling.php already enforces in code, so wiring the engine in changes
-- no behaviour on day one". That was wrong, and it was caught on production.
--
-- scheduling.php enforced exactly three things: a right to work, a current DBS
-- for care, and a current SIA licence for security. 003 additionally made a
-- signed contract, identity and references mandatory. Those are all things the
-- business SHOULD hold -- but they were never recorded as evidence in this
-- system, so on the day the engine went live every worker became not-eligible
-- and the deployment gate would have refused every assignment.
--
-- That is the exact failure 003's own comment warned about: "discovering
-- overnight that half the workforce is blocked".
--
-- They drop to `recommended`. They still appear as warnings, they still cost
-- score, and the Action Centre still lists them -- so the gap is visible and
-- chaseable. Raising them back to mandatory is a one-row UPDATE per rule, done
-- deliberately once the evidence is actually on file:
--
--   UPDATE compliance_rules c JOIN compliance_requirements r ON r.id = c.requirement_id
--      SET c.weight = 'mandatory'
--    WHERE r.req_key = 'contract';
--
-- Count who it would block first:
--
--   SELECT COUNT(*) FROM person_services WHERE eligibility_status = 'not_eligible';

UPDATE compliance_rules c
  JOIN compliance_requirements r ON r.id = c.requirement_id
   SET c.weight = 'recommended'
 WHERE r.req_key IN ('contract', 'identity', 'references');
