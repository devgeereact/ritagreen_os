-- 005 — right to work is not yet a dated credential.
--
-- The requirement catalogue in 003 declared right_to_work as expiring, which is
-- what it should be. But the evidence behind it is still `workers.right_to_work`,
-- a TINYINT(1) with no date attached, so the engine correctly classified every
-- worker who HAS right to work as "undated" -- incomplete evidence -- and blocked
-- them from every service.
--
-- That is the engine being right about a model that is not ready. Until
-- Milestone 3 replaces the boolean with a check record carrying a method, a
-- date, a verifier and an expiry, the requirement is satisfied on presence.
--
-- Flip `expires` back to 1 as part of Milestone 3, once there is a date to
-- check. This is a one-row data change precisely because rules are configuration
-- rather than code -- see docs/UK_GOVERNANCE.md.

UPDATE compliance_requirements
   SET expires = 0,
       description = CONCAT(description, ' Treated as non-expiring until right-to-work checks carry a date (Milestone 3).')
 WHERE req_key = 'right_to_work';
