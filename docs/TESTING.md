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
- Restore from backup, and the application running against a restored database.
- Dependency vulnerability status and penetration testing.
