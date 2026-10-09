-- 041: when each scheduled job last ran (section S5, system health in Settings).
--
-- Nothing recorded it. System health inferred the mail drainer from the newest
-- email_log row and the backup from the newest file on disk, and had no way to
-- say anything about the expiry-alerts sweep or the retention sweep at all, so
-- a cron that was never set up looked exactly like a quiet night.
--
-- One row per job, written by rg_cron_started() / rg_cron_succeeded() in
-- lib.php. A job with no row has never reported, and Settings says "unknown"
-- rather than guessing. Not a log: only the latest run is kept.
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS. The table is not in schema.sql,
-- because nothing in schema.sql creates it (CLAUDE.md, "A statement belongs in
-- schema.sql only if the table it names is created in schema.sql").

CREATE TABLE IF NOT EXISTS cron_runs (
  job VARCHAR(64) NOT NULL PRIMARY KEY,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  -- Set only when the job reached its own success point. A run that died or
  -- refused part way has a finish time and no new success time.
  last_success_at DATETIME NULL,
  last_detail VARCHAR(255) NULL,
  runs INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
