-- 032 — the payroll counter must know what has already been allocated.
--
-- next_payroll_no() formats next_seq($conn, 'payroll'), and next_seq() starts a
-- named counter at 1 the first time it is asked for one. workers.payroll_no is
-- UNIQUE (migration 001).
--
-- Those two facts do not sit together on any database that already holds
-- workers with payroll numbers and has no 'payroll' row in counters -- which is
-- every database where the numbers were assigned before the counter existed, or
-- restored from a dump that did not carry the counters table. The next worker
-- created would be handed RG-000001, the unique key would refuse it, and the
-- conversion would fail with a 500 that says nothing about why.
--
-- This sets the counter to the highest number already in use. It never lowers
-- it, so re-running changes nothing, and it never renumbers a worker: an
-- allocated payroll number is permanent.

INSERT IGNORE INTO counters (name, val) VALUES ('payroll', 0);

UPDATE counters c
   SET c.val = GREATEST(
       c.val,
       COALESCE((SELECT MAX(CAST(SUBSTRING(w.payroll_no, 4) AS UNSIGNED))
                   FROM workers w
                  WHERE w.payroll_no REGEXP '^RG-[0-9]+$'), 0)
   )
 WHERE c.name = 'payroll';
