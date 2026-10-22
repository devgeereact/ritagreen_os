-- 022 — a pay rate is a thing that changed on a date, not a single current value.
--
-- pay_compute() read workers.pay_rate_pence, which is whatever the rate is
-- today. Giving somebody a rise therefore re-priced every period they had ever
-- worked: re-open last quarter's pay run after a rate change and it returns
-- different money for work that was already paid. A pay run has to be
-- reproducible, and it was not.
--
-- workers.pay_rate_pence stays as the current rate — it is what the workers
-- screen shows and what a new shift is costed at. This table is the history
-- behind it, and the pay engine reads the rate that was in force on the day the
-- work was done.

CREATE TABLE IF NOT EXISTS worker_pay_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  rate_pence INT NOT NULL,
  pay_type ENUM('paye','umbrella') NOT NULL DEFAULT 'paye',
  -- The first day this rate applies. A rate has no end date: the next row's
  -- effective_from ends it, which makes an overlap impossible to express.
  effective_from DATE NOT NULL,
  set_by INT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  -- One rate per worker per day. A correction replaces the row for that date
  -- rather than stacking a second one nobody can order.
  UNIQUE KEY uniq_worker_effective (worker_id, effective_from),
  KEY idx_wpr_lookup (worker_id, effective_from),
  CONSTRAINT fk_wpr_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
  CONSTRAINT fk_wpr_user FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill every existing worker at 2000-01-01.
--
-- Deliberately far enough back to precede any timesheet, so every pay run that
-- has already been produced returns exactly the same figures after this
-- migration as before it. A migration that quietly changes historical pay is
-- worse than the defect it fixes.
INSERT IGNORE INTO worker_pay_rates (worker_id, rate_pence, pay_type, effective_from, note)
SELECT id, pay_rate_pence, pay_type, '2000-01-01', 'Backfilled from workers.pay_rate_pence (migration 022)'
FROM workers;
