-- 035 — a suspension that can actually be recorded.
--
-- workers.status is ENUM('active','onboarding','inactive'). It has never held
-- 'suspended'.
--
-- rg_derive_worker_status() has branched on 'suspended' since migration 016 --
-- "a deliberate suspension is a decision, not a derivation, so it stands" -- and
-- that branch was unreachable, because the column cannot hold the value the
-- branch tests for. Suspending somebody was not a thing the system could be told.
--
-- The nearest available answer was 'inactive', which means something else: a
-- worker who is not currently engaged is not a worker who has been stood down
-- while something is looked into, and a compliance record that cannot tell those
-- apart is a compliance record that cannot answer the question anybody asks.

ALTER TABLE workers
  MODIFY COLUMN status ENUM('active','onboarding','inactive','suspended') NOT NULL DEFAULT 'onboarding';

-- No backfill. Nobody has been recorded as suspended, because nobody could be,
-- and reclassifying existing 'inactive' workers would be inventing a decision
-- somebody did not make.
