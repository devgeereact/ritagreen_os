# Testing

Four suites, in increasing order of what they need and what they prove.

| Command | Needs | Proves |
|---|---|---|
| `npm run typecheck` | node | the TypeScript compiles |
| `npm test` | node | the frontend units, including `src/lib/access.ts` |
| `npm run test:php` | php | the pure server logic, and a source-level guard check |
| `npm run test:db` | php, MySQL | the endpoints, against a real database, over HTTP |

All four run in CI on every pull request. None of them uses a real credential,
reaches an external service, or touches a database anybody works in.

---

## `npm run test:db` — the database-backed HTTP suite

`npm run test:php` says in its own header that anything needing a database is out
of its scope. That left authorisation, service scope, state transitions and
persistence covered by nothing, and those are the parts that decide who can read
a worker's compliance record. This suite covers them by making real HTTP requests
to a real PHP server in front of a real database.

### What one run does

1. Drops and recreates `ritagreen_os_test`.
2. Applies `server/schema.sql`, statement by statement.
3. Applies every migration with `server/migrate.php --go`, and asserts that the
   number recorded equals the number of files.
4. Applies them a second time, and asserts nothing further is applied.
5. Seeds fixtures with `server/seed.php --confirm`.
6. Runs `server/backfill-people.php --go` twice, and asserts the second run
   creates no person.
7. Starts `php -S 127.0.0.1:8123 -t server`.
8. Runs every file in `server/tests/db/cases/` in filename order.
9. Stops the server and drops the database.

Step 2 and step 3 are the reason a fresh install is a thing that is asserted
rather than remembered. It had stopped working: `schema.sql` altered a table it
never creates and repeated three statements the migrations own, so a new database
failed at migration 020 with eleven migrations unapplied.

### Setup, once

The suite needs a MySQL or MariaDB it can create a database in. On macOS with
Homebrew:

```sh
brew services start mariadb      # or mysql
mysql <<'SQL'
CREATE USER IF NOT EXISTS 'rg_test'@'localhost'   IDENTIFIED BY 'rg_test_local_only';
CREATE USER IF NOT EXISTS 'rg_test'@'127.0.0.1'   IDENTIFIED BY 'rg_test_local_only';
GRANT ALL PRIVILEGES ON `ritagreen\_os\_test%`.* TO 'rg_test'@'localhost';
GRANT ALL PRIVILEGES ON `ritagreen\_os\_test%`.* TO 'rg_test'@'127.0.0.1';
GRANT CREATE, DROP ON *.* TO 'rg_test'@'localhost';
GRANT CREATE, DROP ON *.* TO 'rg_test'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

That password is not a secret. It grants access to a database whose entire
contents are rebuilt from `seed.php` on every run, on a server listening on
localhost. Do not reuse it for anything else, and do not grant this user access
to `ritagreen_os_dev` — the name check in `server/tests/db/bootstrap.php` is what
stops the harness dropping a database somebody is working in, and a narrow grant
is the second lock on the same door.

### Running

```sh
npm run test:db                 # build, run everything, tear down
npm run test:db -- --keep       # leave the database and the server up afterwards
npm run test:db -- permissions  # only cases whose filename contains "permissions"
RG_TEST_VERBOSE=1 npm run test:db   # print every passing assertion, not just failures
```

`--keep` prints the database name and the path to the server log. Tear it down
by hand when you are done:

```sh
mysql -e 'DROP DATABASE ritagreen_os_test'
pkill -f 'php -S 127.0.0.1:8123'
```

Any run without `--keep` does both of those itself, including after a failure.

### It cannot reach your own database, and it cannot reach production

`server/lib.php` loads `server/config.php` unless the `RG_CONFIG` environment
variable names another file. The suite sets it to `server/tests/db/config.php`,
which declares `RG_ENV` as `local`, points at `ritagreen_os_test`, and leaves
every outbound integration at `CHANGE_ME` or `false`. Nothing in a test run can
send an email, a text message, a payment or an AI request.

Three separate things have to fail before a test run could touch real data:

- `RG_CONFIG` is refused unless the file it names declares `RG_ENV` as `'local'`,
  so it cannot redirect a deployed server at another database.
- `rgt_assert_disposable()` refuses any database name that is not
  `ritagreen_os_test`, optionally suffixed, before it issues a `DROP`.
- `seed.php` and `migrate.php` each refuse a database that is not declared local.

Override the connection with `RG_TEST_DB_HOST`, `RG_TEST_DB_USER`,
`RG_TEST_DB_PASS` and `RG_TEST_DB_NAME` if your MySQL is set up differently. The
name still has to start with `ritagreen_os_test`.

### Writing a case

A case is a PHP file in `server/tests/db/cases/`, named `NNN_subject.php`. It is
run with three variables already in scope:

| Variable | What it is |
|---|---|
| `$client` | an `RgtClient` with no token — the unauthenticated caller |
| `$db` | a `mysqli` on the test database, for arranging rows and checking results |
| `$base` | the server's base URL |

```php
Rgt::group('leave — a worker sees only their own');

$worker = $client->login('amara.login@local.invalid');
$res = $worker->get('/leave.php?worker_id=' . $someoneElse);

Rgt::denied('a worker cannot read another worker\'s leave', $res);
Rgt::status('but can read their own', $worker->get('/leave.php'), 200);
Rgt::same('and gets only their own rows', count($res->get('items', [])), 0);
```

`Rgt::denied()` accepts 401 or 403 and fails anything else, including a 200 with
somebody else's record in it. `Rgt::status()` quotes the response body when it
does not match, so a failure says what the server actually answered.

Seeded fixture accounts all share the password `LocalDev123!`:

| Email | Role | Notes |
|---|---|---|
| `admin@local.invalid` | admin | no division, sees everything |
| `manager@local.invalid` | manager | division `care`, **not** scoped |
| `scoped@local.invalid` | manager | division `security`, `scope_to_division = 1` |
| `trainer@local.invalid` | trainer | division `academy` |
| `editor@local.invalid` | editor | no division |
| `employee@local.invalid` | employee | division `cleaning`, no worker record |
| `amara.login@local.invalid` | employee | linked to a worker, so **not** staff |
| `priya@local.invalid` | client | client portal login |

### Fixture email addresses

`local.invalid`, `example.invalid` and `learner.invalid` are listed in
`RG_SYNTHETIC_EMAIL_DOMAINS` and discarded by `rg_normalise_email()`. They are the placeholders
the system generates for somebody who gave no address, and treating them as identities would
collapse every such person into one. A test about identity therefore cannot use one — use
`@example.test`, which is reserved by RFC 2606 and can never reach a real recipient.

### Concurrency

`server/tests/db/run.php` sets `PHP_CLI_SERVER_WORKERS=8`. Without it `php -S` handles one
request at a time, every "concurrent" test passes because the requests are simply queued, and a
race the production host hits under two simultaneous clicks is invisible. Two real races were
found this way and both had passed before the variable was set.

Write a concurrency case with `curl_multi`, and run it several times before believing it: the
duplicate-person race reproduced in two runs out of three, not in every one.

### Rate limiting

Signing in is limited to 10 attempts per IP in 300 seconds, and the whole suite
arrives from `127.0.0.1` in well under 300 seconds. The runner clears the window
between case files so the eighth account can sign in at all. The limit itself is
asserted in `server/tests/db/cases/010_authentication.php`, so clearing it
between files removes an obstacle rather than a check.

---

## Local development database

Separate from the test database, and not managed by the harness. See
[`RITAGREEN_SETUP.md`](RITAGREEN_SETUP.md) and the "Working here" section of
`CLAUDE.md`. In short:

```sh
cp server/config.local.php.example server/config.php    # if you have none
mysql -e 'CREATE DATABASE ritagreen_os_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql ritagreen_os_dev < server/schema.sql
php server/migrate.php --go
npm run seed -- --confirm
npm run dev:api     # PHP on 127.0.0.1:8122
npm run dev         # Vite on 5122
```

`config.local.php.example` connects as `root` with no password. That is not true
of a Homebrew MariaDB, which authenticates `root` over a unix socket and refuses
a TCP connection: PHP connects over TCP, so it fails with
`Access denied for user 'root'@'localhost'`. Give your own `config.php` a user
that can connect over TCP — the `rg_test` grant above does not cover
`ritagreen_os_dev`, deliberately.

## What none of these suites cover

Stated plainly, because a green run is not a claim beyond what was run:

- Browser behaviour: rendering, keyboard operation, focus, mobile layout,
  offline caching and account switching.
- Real SMTP, real IMAP, a real Google Form, Stripe, GoCardless or Twilio.
- Production configuration, the deployed PHP tier, or the live database.
- Restore from backup, and the application running against a restored database. Rehearsed by
  hand in S10 with `server/restore-check.php`: see `docs/runbooks/backup-and-restore.md`.
- The launch journeys in a browser, which are run by hand: see "2026 launch journeys" below.
- Dependency vulnerability status and penetration testing.

---

## Docker toolbox — machines without PHP or MySQL

Everything above assumes PHP 8.4 and a local MySQL or MariaDB. Where neither is
installed, run them in containers. Host networking keeps every address at
`127.0.0.1`, exactly as the suites expect. Local testing only.

```sh
# once: build the PHP image, start MariaDB, create the test user from "Setup, once"
docker build -t rg-php:8.4 -f scripts/docker/php84.Dockerfile scripts/docker
docker run -d --name rg-mariadb --network host \
  -e MARIADB_ROOT_PASSWORD=rg_root_local_only mariadb:11 --bind-address=127.0.0.1
docker exec -i rg-mariadb mariadb -uroot -prg_root_local_only < grants.sql   # the SQL block above

# every time
alias rgphp='docker run --rm --network host -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" rg-php:8.4'
rgphp php server/tests/run.php        # npm run test:php
rgphp php server/tests/db/run.php     # npm run test:db
rgphp php -S 127.0.0.1:8122 -t server # npm run dev:api (with a local server/config.php)
```

`docker start rg-mariadb` after a reboot. `pcntl` is in the image because the
database suite stops its PHP server with `SIGTERM`.

---

## 2026 launch journeys

The launch gate (launch plan Phase 5, section S10): the core journey and its refusals, exercised
in a real browser against the local build. Run by `scripts/launch-journeys.mjs` (headless
Chromium through `playwright-core`), against the disposable database the suite leaves behind
with `npm run test:db -- --keep`, synthetic data only, every integration off. Screenshots are
saved as `.playwright-mcp/s10-*.png` (gitignored).

How it was run on 10 October 2026: database `ritagreen_os_test_s10`; API
`php -S 127.0.0.1:8240 -t server` with `RG_CONFIG=server/tests/db/config.php`; the built app
(`npm run build`) served on 5160 with `*.php` proxied to the API (Vite's dev server hit the
file-watcher limit on that machine); `node scripts/launch-journeys.mjs <out dir>`. Every step
below passed in one run on a freshly built database.

"UI" means a person's clicks and typing in the browser; the result is then confirmed against
the database or the API. "API" means the request was made directly, which is how a refusal is
proved: a screen that hides a button proves nothing about the server.

| # | Journey | How | Result | Evidence |
|---|---|---|---|---|
| J01 | Public apply through the native form on `/careers`, at phone width | UI | PASS | Applicant created at stage `applied`; `s10-01-apply-sent-375.png` |
| J02 | Recruiter opens the candidate and moves them to screened | UI | PASS | `s10-02-ats-screened.png` |
| J03 | Start onboarding: worker created, healthcare template checklist, all six items ticked | UI | PASS | Worker with payroll number; `s10-03-onboarding-checklist.png` |
| J04 | Right to work recorded and signed off on the profile | UI | PASS | Requirement met, verifier named; `s10-04-rtw-recorded.png` |
| J05 | DBS certificate uploaded and verified with an expiry | UI | PASS | Requirement met, origin `verification`; `s10-05-dbs-verified.png` |
| J06 | Contract drafted, marked sent, signed copy attached, reviewed | UI | PASS | `s10-06-contract-reviewed.png` |
| J07 | Approved to work from the profile overview | UI | PASS | "Approved for work on ... by Olu Admin"; `s10-07-approved.png` |
| J08 | Owner creates the worker's portal login in Settings, Users | UI | PASS | The login signs in; `s10-08b-worker-login-created.png` |
| J08c | Office sets the pay rate and availability (days, nights, weekends) | UI | PASS | `s10-08c-worker-availability.png` |
| J09 | Office adds a draft shift; the worker cannot see it | UI, then API for the worker's view | PASS | `s10-09-shift-draft.png` |
| J10 | Office publishes the rota | UI | PASS | `s10-10-published.png` |
| J11 | Office offers the open shift to the new worker | UI | PASS | One offer row; `s10-11-offered.png` |
| J12 | Worker signs in on a phone and accepts the offer in the portal | UI | PASS | Shift assigned; `s10-12a-portal-offer-375.png`, `s10-12b-portal-accepted-375.png` |
| J13 | Worker clocks in and out in the portal | UI; the eight hours between are simulated by moving the clock-in back in the database | PASS | Draft timesheet of 480 minutes from the clock; `s10-13b-clocked-out-375.png` |
| J14 | Worker submits the timesheet with a 30-minute break | UI | PASS | Status `pending`; `s10-14-timesheet-submitted-375.png` |
| J15 | Office approves the timesheet | UI | PASS | Status `approved`; `s10-15-timesheet-approved.png` |
| N1 | An unapproved worker cannot be booked | API refusal; UI shows the reason | PASS | 409, blocking `employment`; `s10-N1-unapproved-profile.png` |
| N2 | A worker on approved leave cannot be booked | UI request and approval; API booking refusal | PASS | 409, "On approved leave"; `s10-N2a-leave-requested-375.png`, `s10-N2b-leave-approved.png` |
| N3 | A manager scoped to security is refused a care worker | API and UI | PASS | Profile, shift and timesheet all 403; `s10-N3-scoped-manager-refused.png` |
| N4 | A worker cannot read another worker's data | API and UI | PASS | Profile 403, document 403, another worker's timesheet 404, `/app` sends the worker to `/m`; `s10-N4-worker-refused-375.png` |
| N5 | Draft shifts are invisible to workers | API and UI | PASS | Not listed, claim by id 409; `s10-N5-draft-invisible-375.png` |

Also checked in the browser in S10, at 1280 and 375 pixels with no horizontal scroll and no
console errors: Settings, Data requests (a manager's request approved by the owner, the file
archived, the request completed); Settings, Features with the rota rules card (owner can edit,
manager reads only); the profile labelling checks valid only from a certificate, worker record,
reference or contract file; `/training` with a training form behind its load button; Today.

NOT TESTED in these journeys: a real Google Form submission, real email or SMS for the offer,
geolocation on a real phone (the headless browser refused it, so clock events carry no
position), the service worker and offline use (blocked for the run), screen readers and
keyboard-only use, and any browser other than Chromium.

