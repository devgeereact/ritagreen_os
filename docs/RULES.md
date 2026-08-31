# Rules

Non-negotiable engineering and product rules for this repository. Each one exists because
breaking it has a specific, known cost.

---

## Data and records

**1. Compliance status is calculated, never set.**
No user interface may offer an editable "compliant" field, and no endpoint may accept one. The
engine derives eligibility from evidence and rules. If a status can be typed in, it will be
typed in wrongly and then trusted.

**2. A person exists once.**
Never copy identity fields between tables to create a related record. Link with `person_id`.
This rule exists because `server/ats.php` currently breaks it and produces up to three copies
of the same human.

**3. Never hard-delete an employment record.**
Workers, people, clients, certificates, documents and audit entries are soft-deleted via
`deleted_at` and archived. Ordinary staff get *archive* or *request deletion*, never *delete*.
Deletion requires approval and is itself recorded: who requested, who approved, why, when.

**4. Verification requires evidence.**
A verification record without a verifier, a timestamp, an outcome and a document reference is
not a verification. Do not add a boolean "verified" column to anything.

**5. Money is integers in pence.**
Never a float. This is already true throughout the schema — keep it true.

**6. The server is the source of truth.**
The browser never holds authoritative compliance state. Offline caching is read-only. Anything
the client asserts is re-checked server-side before it is acted on.

## Security

**7. No secret in a tracked file.**
Credentials live only in `server/config.php` on the server, which is gitignored.
`server/config.sample.php` is the template and carries `CHANGE_ME` placeholders only. Never a
real value in `.md`, `.example`, a comment, a commit message or a CI log.

**8. Nothing secret reaches the client bundle.**
There are currently no `VITE_*` variables and no `.env`, and all third-party API calls are
proxied server-side. Preserve that. A key placed in the frontend is a published key.

**9. Every mutating endpoint checks the role server-side.**
Client-side gating is a user-experience convenience and nothing more. Read endpoints need role
checks too — several currently lack them.

**10. Prepared statements, always.**
Every query goes through `mysqli` prepared statements with bound parameters. No string
interpolation into SQL, including for values that "cannot" contain anything dangerous.

**11. Uploaded files are never served directly.**
Uploads stay outside the reachable path — `.htaccess` blocks `^uploads/` — and are streamed
through `files.php` behind an authentication check, with a randomised stored name.

**12. Never put a backup inside a webroot.**
A `.bak` or `.sql` file in a document root is served as plain text. Backups go above the
document root. `server/backup.php` already does this correctly.

## Development

**13. Development never points at production.**
`vite.config.ts` currently proxies to the live domain. Until a local PHP and MySQL environment
exists, no schema migration may be written or run. This is a hard gate on Milestone 1.

**14. Schema changes are numbered migrations.**
New work goes in `server/migrations/NNN-description.sql`, applied in order and recorded.
`server/schema.sql` stays as the from-scratch baseline. The current single re-runnable
build-pass file is not a safe basis for an identity migration.

**15. A destructive migration is preceded by a restore-tested backup.**
Not a backup — a backup that has been restored somewhere and verified. An untested backup is
an assumption.

**16. Never add `dangerous-clean-slate` to the deploy workflow.**
It would delete `config.php`, the PHP endpoints and `.htaccess` from the server. The existing
exclusion list in `.github/workflows/deploy.yml` is deliberate and correct.

**17. Branch, pull request, merge.**
Never commit to `main` directly. Push to `main` deploys to production.

**18. CI must be green before merge.**
Typecheck, lint with zero warnings, build, `php -l` across every PHP file, and the
documentation consistency check.

## Documentation

**19. Documentation states what the code does, not what the plan says.**
Every factual claim in `docs/` is verifiable against the source. A document that restates an
intention as if it were an implementation is worse than no document, because it is trusted.
This repository has already been burned by this: `CLAUDE.md` described React 18 in JavaScript
with a single `api.php`, and `DESIGN-SYSTEM.md` was marked "LOCKED" while specifying a palette
the application had stopped using.

**20. One source of truth per fact.**
When a fact would live in two files, one of them links to the other. Duplicated facts drift,
and the stale copy is the one that gets trusted.

**21. Finishing a module means updating `docs/STATUS.json`.**
Then regenerate `docs/PROGRESS.md` with `scripts/docs_sync.py` and append to
`docs/CHANGELOG.md`. CI enforces that `PROGRESS.md` matches `STATUS.json`.

## Product

**22. Compliance language is "supports", never "ensures".**
The system supports compliance with UK requirements and company policy. It does not make the
company compliant, and no interface copy, document or marketing claim may say that it does.

**23. Compliance requirements are configuration, not code.**
Rules change with guidance. If a requirement is hard-coded, changing it becomes a development
task, which means it will lag. The business owns the policy values.

**24. Ineligibility blocks the action.**
A warning is read after the fact. The assignment is refused, and the specific reason is shown.

**25. Non-goals stay non-goals.**
No payroll processing, no accounting, no LMS, no CRM, no AI hiring decisions, no surveillance.
See `docs/PRD.md` §6. Scope creep into any of these is how this system becomes unfinishable.
