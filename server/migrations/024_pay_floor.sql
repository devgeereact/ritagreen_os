-- 024 — a configurable pay floor, so a sub-minimum rate is visible.
--
-- Nothing anywhere in the system checked that a rate was lawful. workers.php
-- accepts any non-negative pay_rate_pence and pay_compute() pays whatever it
-- finds, so a mistyped rate of 850 instead of 1850 is paid without comment.
--
-- This is a FLOOR, not an implementation of the National Minimum Wage. NMW has
-- age bands and an apprentice rate, and workers carries no date of birth, so a
-- single figure cannot decide lawfulness for everybody. What it can do is say
-- "this is below the number you told us to expect", which is what catches the
-- typo and the rate that was never updated in April.
--
-- Empty by default, deliberately, and the same reasoning as retention periods:
-- an unset floor checks nothing, and a wrong floor in code is worse than no
-- floor at all. The business sets it, with the date it took effect.
--
-- Seed, once the current figures are confirmed against gov.uk:
--   INSERT INTO pay_floors (effective_from, rate_pence, label)
--   VALUES ('2026-04-01', 0, 'National Living Wage, 21 and over');

CREATE TABLE IF NOT EXISTS pay_floors (
  effective_from DATE PRIMARY KEY,
  rate_pence INT NOT NULL,
  label VARCHAR(120) NOT NULL DEFAULT '',
  set_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_floor_user FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
