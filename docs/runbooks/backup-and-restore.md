# Backup and restore

Owner: the company owner names one person responsible for this runbook and for the restore
rehearsal. Until named, it is the owner. A rehearsal is due every 90 days; Settings, System
health warns when it is older than that or has never been recorded.

## What is backed up, and what is not

| Part | How | Where |
|---|---|---|
| Database | `server/backup.php`, daily by cron. A gzipped `mysqldump --single-transaction` of the database named in `config.php` | `backups/ritagreen_os-YYYY-MM-DD_HHMMSS.sql.gz`, one level **above** the document root, never web-readable. Dumps older than 14 days are pruned |
| Documents | **Not** in the dump. The files live in `server/uploads/` on the host (32-character random names; the `files` table maps them to people) | Covered only by the host's own backup of the home directory (cPanel, Backup). Confirm with the host that it includes the document root and how long it is kept |
| `config.php` | Not backed up by the application, on purpose: it holds every secret | Keep a copy in the owner's password manager, never in Git, email or chat |
| Code | Git (`main` for the frontend build, `server/` uploaded by hand) | GitHub |

System health shows the newest dump's age (`backups`), the backup job's heartbeat (`cron`,
job `backup`) and the last recorded restore test (`restore_test`).

## Restore rehearsal (do this, then record it)

A rehearsal restores the latest dump into a **separate** database and proves it matches. It
never writes to the live database except one heartbeat row recording that the test passed.

1. In cPanel, MySQL Databases: create an empty database, for example
   `<account>_rg_restore`, and give the existing database user all privileges on it.
2. Run the backup now, so source and copy are close in time:
   `php ~/ritagreenservices.com/backup.php` (or wait for the nightly run).
3. Restore the newest dump into the new database (cPanel Terminal or SSH):
   ```bash
   DUMP=$(ls -t ~/backups/ritagreen_os-*.sql.gz | head -1)
   gunzip -c "$DUMP" | mysql -u <db user> -p <account>_rg_restore
   ```
4. Restore the documents from the host backup into a folder outside the document root, for
   example `~/restore-uploads/` (cPanel, Backup, restore the home directory files to that path,
   or copy `uploads/` out of the downloaded home backup).
5. Compare, and record the result if it passes:
   ```bash
   php ~/ritagreenservices.com/restore-check.php \
       --restored=<account>_rg_restore --uploads=$HOME/restore-uploads \
       --ignore=cron_runs,rate_limits,audit_log,sessions,tokens --record
   ```
   On a live system the source keeps changing after the dump, so leave out the tables that
   move with every request (`audit_log`, `sessions`, `tokens`, `rate_limits`, `cron_runs`). Any other
   difference is a failure: find out why before trusting the backups. Nothing is recorded on a
   failure.
6. Open Settings, System health: "Backup restore test" now reads "Last passed <date>".
7. Drop the rehearsal database and delete `~/restore-uploads/`. They are a full copy of
   personal data and must not be left lying about.

`restore-check.php` is a command-line tool; it refuses HTTP and `.htaccess` blocks it. It
refuses to compare the database against itself. It reads both databases and writes only the
`cron_runs` row for job `restore_test` (migration 043).

## Real restore after a loss

1. Stop writes: put the site in maintenance (rename `index.html` temporarily, or ask the host),
   so nobody works on a half-restored system.
2. Restore the newest good dump into the live database name (cPanel, phpMyAdmin import, or
   `gunzip -c <dump> | mysql -u <user> -p <db>`).
3. Restore `uploads/` from the host backup into the document root, keeping file names exactly.
4. Run `php migrate.php --status` and confirm the migration state matches what the release
   record says.
5. Run `restore-check.php` against a rehearsal copy if there is any doubt about which dump is
   good.
6. Sign in, open three person profiles and one document each, and check Today and System
   health. Then remove maintenance.
7. Write down what was lost between the dump and the failure, and tell the people affected.

## Evidence: local rehearsal, 10 October 2026 (S10)

Run against the disposable test database only (`ritagreen_os_test_s10`, built by
`server/tests/db/run.php -- --keep`, synthetic data) with `server/tests/db/config.php`
(`RG_ENV` local, every integration off). Nothing touched production.

| Step | Result |
|---|---|
| Two documents uploaded through `files.php` (a PDF and a text file) as `admin@local.invalid` | ids 23 and 24; download before backup matched the original (SHA-256 `022a5b72...ff9e`) |
| `php server/backup.php` | `Backup OK ... (50.8 KB)`; heartbeat recorded |
| `server/uploads/` copied as the host backup would | 8 files |
| Dump restored into `ritagreen_os_test_s10_restore` | exit 0 |
| `restore-check.php --restored=... --uploads=... --record` | PASS: 88 tables, 1240 rows, 5 documents identical; 19 document rows created by test fixtures have no file on the source either (reported, not a restore difference) |
| Negative: one `audit_log` row deleted from the copy | FAILED as expected, `audit_log: 290 rows, restored copy has 289`, exit 1, nothing recorded |
| API served from the restored database with the restored `uploads/` | Both documents downloaded through `files.php`; SHA-256 identical to the originals. A profile read answered 200 |
| System health on the source | `restore_test`: ok, "Last passed 2026-10-10 (88 tables, 1240 rows, 5 documents ...)"; `backups`: ok |

NOT TESTED: a restore on the live host, the host's own home-directory backup and its retention,
`mysqldump` availability and `shell_exec` on the live PHP, and restoring after a real loss.
