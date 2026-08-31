-- 004 — require the person link.
--
-- @precondition: SELECT (SELECT COUNT(*) FROM workers WHERE person_id IS NULL)
--                     + (SELECT COUNT(*) FROM applicants WHERE person_id IS NULL)
--                     + (SELECT COUNT(*) FROM users WHERE person_id IS NULL)
-- @precondition-message: Records are still unlinked. Run: php server/backfill-people.php --go
--
-- This is the migration that actually enforces the spine, and it must run only
-- after the backfill has linked every row.
--
-- Migration 002 added the foreign keys, and a foreign key was initially assumed
-- to be what would block on an unresolved match. It is not: a foreign key
-- constrains non-NULL values only, so every unlinked row sailed through it.
-- Verified against a real database — 002 applied cleanly with a NULL person_id
-- still present. NOT NULL is the constraint that refuses to apply while any
-- record is unlinked, which is the property that was wanted.
--
-- If this fails, the backfill has not finished. Find what is left with:
--
--   SELECT 'workers' t, id, name FROM workers WHERE person_id IS NULL
--   UNION ALL SELECT 'applicants', id, name FROM applicants WHERE person_id IS NULL
--   UNION ALL SELECT 'users', id, name FROM users WHERE person_id IS NULL;
--
-- Do not resolve those by guessing. Ambiguous groups are ambiguous for a reason.
--
-- enrolments is deliberately excluded: it carries only a learner_name, with no
-- email or phone to match on, so those rows are linked by hand as the Academy
-- module is rebuilt. Forcing NOT NULL there would mean inventing links.

-- Do not depend on the server's sql_mode. Verified: with STRICT_TRANS_TABLES the
-- statements below fail with error 1265 and change nothing, which is what is
-- wanted. Without it, MySQL would silently coerce every unlinked person_id to 0
-- and the spine would be quietly wrong instead of loudly unfinished.
SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES');

ALTER TABLE workers    MODIFY COLUMN person_id INT NOT NULL;
ALTER TABLE applicants MODIFY COLUMN person_id INT NOT NULL;
ALTER TABLE users      MODIFY COLUMN person_id INT NOT NULL;
